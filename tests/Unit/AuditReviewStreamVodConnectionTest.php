<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\FanoutSyncCommand;
use XcVm\Core\Util\Encryption;
use XcVm\Tests\Support\InstallSchema;

/**
 * The connection a movie request with a Range goes on under (vod.php): where
 * the xc_fanout daemon serves the file, where the worker of an earlier request
 * has not left the connection yet, and where ip_subnet_match makes a subnet
 * one viewer.
 *
 * vod.php runs for real in a child PHP, with the constants, caches and request
 * StreamingRequestBootstrap leaves it, against this test's database.
 */
final class AuditReviewStreamVodConnectionTest extends TestCase {
	private const PASS = 'test-live-pass';
	private const IP = '10.0.0.1';
	private const AGENT = 'player/1.0';
	private const LINE = 9;
	private const UUID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const EARLIER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

	/** The settings cache of the server that answers: its own store, PHP serving the files, error pages that name their code. */
	private const SETTINGS = [
		'live_streaming_pass' => self::PASS, 'secure_stream_tokens' => 1, 'debug_show_errors' => 1,
		'send_server_header' => '', 'send_protection_headers' => 0, 'send_altsvc_header' => 0, 'send_unique_header' => '',
		'use_buffer' => 1, 'redis_handler' => 0, 'fanout_enabled' => 0, 'ip_subnet_match' => 0, 'restrict_same_ip' => 1,
		'monitor_connection_status' => 0, 'vod_bitrate_plus' => 0, 'vod_limit_perc' => 0, 'read_buffer_size' => 8192, 'client_logs_save' => 0,
		'save_closed_connection' => 0,
	];

	private TestDb $rDb;

	private string $rHome;

	/** @var list<resource> the stand-in workers this test started */
	private array $rWorkers = [];

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec(InstallSchema::table('lines_live'));
		$this->rHome = sys_get_temp_dir() . '/xcvm-vod-connection-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		foreach (['vod', 'cons', 'cache', 'divergence', 'signals'] as $rDir) {
			mkdir($this->rHome . $rDir, 0777, true);
		}
		file_put_contents($this->rHome . 'vod/300.mp4', 'MOVIE');
		file_put_contents($this->rHome . 'cache/settings', igbinary_serialize(self::SETTINGS));
		file_put_contents($this->rHome . 'cache/servers', igbinary_serialize([1 => ['time_offset' => 0]]));
		file_put_contents($this->rHome . 'prepend.php', <<<'PHP'
			<?php
			// What StreamingRequestBootstrap leaves an endpoint: a deploy root (a
			// throwaway one), its constants, the settings and servers caches and the
			// request; and an xcvm_core whose database is the test's schema.
			define('MAIN_HOME', getenv('XCVM_TEST_HOME'));
			final class XC_VM {
				public static function db_connect(bool $rMigrate = false) {
					$rDsn = preg_replace('/;?dbname=[^;]*/', '', (string) getenv('XCVM_TEST_DB_DSN')) . ';dbname=' . getenv('XCVM_TEST_SCHEMA');
					return new PDO($rDsn, getenv('XCVM_TEST_DB_USER') ?: null, getenv('XCVM_TEST_DB_PASS') ?: null);
				}
				// The server keeps its connections in the table: it runs no Redis.
				public static function redis_connect() {
					return null;
				}
			}
			// A server whose daemon serves files (daemon()): its control socket, and its client's stand-in.
			if (is_file(MAIN_HOME . 'ctl.sock')) {
				define('FANOUT_CTL_SOCK', MAIN_HOME . 'ctl.sock');
				require MAIN_HOME . 'daemon.php';
			}
			require getenv('XCVM_TEST_SRC') . 'vendor/autoload.php';
			if (!function_exists('igbinary_unserialize')) {
				function igbinary_serialize($rValue) {
					return serialize($rValue);
				}
				function igbinary_unserialize($rValue) {
					return unserialize($rValue);
				}
			}
			define('OPENSSL_EXTRA', getenv('XCVM_TEST_EXTRA'));
			define('SERVER_ID', 1);
			define('HOST', '127.0.0.1');
			foreach (['VOD_PATH' => 'vod', 'CONS_TMP_PATH' => 'cons', 'CACHE_TMP_PATH' => 'cache', 'DIVERGENCE_TMP_PATH' => 'divergence', 'SIGNALS_PATH' => 'signals'] as $rName => $rDir) {
				define($rName, MAIN_HOME . $rDir . '/');
			}
			$rIn = json_decode((string) file_get_contents(MAIN_HOME . 'request.json'), true);
			$_SERVER = $rIn['server'] + $_SERVER;
			$_GET = $rRequest = $rIn['get'];
			$rSettings = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'settings'));
			$rServers = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'servers'));

			PHP);
	}

	protected function tearDown(): void {
		foreach ($this->rWorkers as $rWorker) {
			proc_terminate($rWorker, 9);
			proc_close($rWorker);
		}
		exec('rm -rf ' . escapeshellarg($this->rHome));
	}

	/**
	 * The answering server's settings cache, with these values changed.
	 *
	 * @param array<string, mixed> $rOver
	 */
	private function settings(array $rOver): void {
		file_put_contents($this->rHome . 'cache/settings', igbinary_serialize($rOver + self::SETTINGS));
	}

	/**
	 * The answering server's daemon serves files: fanout is on, its control
	 * socket is there, and its client is a stand-in that notes the viewer it
	 * is handed (`handed`) and the ones it is told to drop (`dropped`).
	 */
	private function daemon(): void {
		$this->settings(['fanout_enabled' => 1]);
		touch($this->rHome . 'ctl.sock');
		file_put_contents($this->rHome . 'daemon.php', <<<'PHP'
			<?php

			namespace XcVm\Streaming\Fanout;

			final class FanoutClient {
				public static function supports(string $rFeature, ?int $rNow = null): bool {
					return true;
				}

				public static function handOverFile(int $rStreamID, string $rUUID, array $rParts, string $rType, int $rLimitPerc, int $rRate, string $rRange = '', ?int $rNow = null): bool {
					file_put_contents(MAIN_HOME . 'handed', $rUUID);
					return true;
				}

				public static function dropConnection(string $rUUID): bool {
					file_put_contents(MAIN_HOME . 'dropped', $rUUID . "\n", FILE_APPEND);
					return true;
				}
			}

			PHP);
	}

	/**
	 * One request to vod.php, as nginx hands it over.
	 *
	 * @param array<string, mixed> $rToken what the link's token holds, over movie()'s
	 * @param array<string, string> $rServer
	 * @return string the response body
	 */
	private function request(array $rToken = [], array $rServer = []): string {
		file_put_contents($this->rHome . 'request.json', (string) json_encode(['get' => ['token' => $this->movie($rToken)], 'server' => $rServer + ['REMOTE_ADDR' => self::IP, 'HTTP_USER_AGENT' => self::AGENT, 'HTTP_RANGE' => 'bytes=0-']]));
		$rEnv = ['XCVM_TEST_HOME' => $this->rHome, 'XCVM_TEST_SRC' => MAIN_HOME, 'XCVM_TEST_SCHEMA' => $this->rDb->schema(), 'XCVM_TEST_EXTRA' => OPENSSL_EXTRA, 'PATH' => (string) getenv('PATH')] + TestDb::env();
		$rCommand = [...xcvm_test_child_php(), '-d', 'auto_prepend_file=' . $this->rHome . 'prepend.php', '-d', 'date.timezone=UTC', '-d', 'display_errors=stderr', MAIN_HOME . 'Public/stream/vod.php'];
		$rProc = proc_open($rCommand, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->rHome . 'body', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes, $this->rHome, $rEnv);
		$this->assertIsResource($rProc);
		proc_close($rProc);
		return (string) file_get_contents($this->rHome . 'body');
	}

	/**
	 * The token auth.php seals for vod.php: a link of its own, for the movie.
	 *
	 * @param array<string, mixed> $rOver
	 */
	private function movie(array $rOver = []): string {
		return Encryption::mintToken((string) json_encode($rOver + [
			'stream_id' => 300, 'username' => 'line', 'password' => 'secret', 'extension' => 'mp4', 'type' => 'movie', 'pid' => 1,
			'channel_info' => ['stream_id' => 300, 'bitrate' => 0, 'target_container' => 'mp4', 'redirect_id' => 1, 'originator_id' => null, 'pid' => 0, 'proxy' => null],
			'user_info' => ['id' => self::LINE, 'max_connections' => 0, 'pair_id' => null, 'con_isp_name' => '', 'is_restreamer' => 0],
			'country_code' => 'PT', 'activity_start' => time(), 'is_mag' => false, 'uuid' => self::UUID, 'http_range' => null,
		]), self::PASS, OPENSSL_EXTRA, true);
	}

	/** A connection to the movie the store already holds: the line's, with the same player, at $rIP. */
	private function connection(string $rUUID, string $rIP, int $rPID): void {
		$this->rDb->query('INSERT INTO `lines_live` (`user_id`, `stream_id`, `server_id`, `user_agent`, `user_ip`, `container`, `pid`, `date_start`, `hls_last_read`, `hls_end`, `uuid`) VALUES (?, 300, 1, ?, ?, ?, ?, ?, ?, 0, ?)', self::LINE, self::AGENT, $rIP, 'VOD', $rPID, time(), time(), $rUUID);
	}

	/** @return array<string, array<string, mixed>> the store's connections, by uuid */
	private function connections(): array {
		$this->rDb->query('SELECT `uuid`, `user_ip`, `pid`, `hls_end` FROM `lines_live` ORDER BY `activity_id`');
		return array_column($this->rDb->get_rows(), null, 'uuid');
	}

	/** @return list<array<string, mixed>> the open connections a daemon serves: what fanout_sync reads */
	private function daemonServed(): array {
		return array_values(array_filter($this->connections(), static fn(array $rRow): bool => (int) $rRow['pid'] === 0 && (int) $rRow['hls_end'] === 0));
	}

	/**
	 * A process that is a PHP-FPM worker to ProcessManager::isRunning(): its
	 * executable is named as one. It serves nobody, and sleeps.
	 *
	 * @return resource
	 */
	private function worker() {
		copy(trim((string) shell_exec('command -v sleep')), $this->rHome . 'php-fpm-worker');
		chmod($this->rHome . 'php-fpm-worker', 0755);
		$rWorker = proc_open([$this->rHome . 'php-fpm-worker', '60'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		$this->assertIsResource($rWorker);
		$this->rWorkers[] = $rWorker;
		// Until it is that executable: the pid is its parent's image for an instant.
		$rPID = proc_get_status($rWorker)['pid'];
		for ($i = 0; $i < 200 && basename((string) @readlink('/proc/' . $rPID . '/exe')) !== 'php-fpm-worker'; $i++) {
			usleep(5000);
		}
		return $rWorker;
	}

	// ── Where the daemon serves the file ─────────────────────────────

	public function testWhereTheDaemonServesAMovieSeekKeepsTheConnectionItIsCountedUnder(): void {
		$this->daemon();
		// The viewer's earlier request for the movie: the daemon served it, so its connection names no worker.
		$this->connection(self::EARLIER, self::IP, 0);

		$this->request();
		// The daemon counts a viewer under the uuid it is handed.
		$this->assertSame(self::UUID, @file_get_contents($this->rHome . 'handed'));
		$rOpen = $this->daemonServed();
		$this->assertContains(self::UUID, array_column($rOpen, 'uuid'), 'the viewer the daemon counts has no connection');

		// fanout_sync ends a daemon viewer that stays without an open connection for its grace.
		$rSync = (new ReflectionClass(FanoutSyncCommand::class))->newInstanceWithoutConstructor();
		$rKeep = static function (string $rUUID): void {
		};
		ob_start();
		$rSync->dropOrphans([self::UUID], $rOpen, 1000, $rKeep);
		$rDropped = $rSync->dropOrphans([self::UUID], $rOpen, 1020, $rKeep);
		ob_end_clean();
		$this->assertSame([], $rDropped, 'fanout_sync ends the seek');
	}

	public function testWhereTheDaemonServesAMovieSeekIsTheNewestRequestOfALineAtItsLimit(): void {
		$this->daemon();
		$this->rDb->exec(InstallSchema::table('streams_servers'));
		$this->connection(self::EARLIER, self::IP, 0);

		$this->request(['user_info' => ['id' => self::LINE, 'max_connections' => 1, 'pair_id' => null, 'con_isp_name' => '', 'is_restreamer' => 0]]);
		$this->assertSame(self::UUID, @file_get_contents($this->rHome . 'handed'));
		// The line keeps its newest request: the earlier connection is closed, and the daemon told to drop its viewer.
		$this->assertSame([self::UUID], array_column($this->daemonServed(), 'uuid'));
		$this->assertSame(self::EARLIER . "\n", @file_get_contents($this->rHome . 'dropped'));
	}

	public function testWhereTheDaemonServesAMovieLinkAskedForAgainKeepsItsOwnConnection(): void {
		$this->daemon();
		// The connection this very link opened: the player asks the link again with a Range.
		$this->connection(self::UUID, self::IP, 0);

		$this->request();
		$this->assertSame(self::UUID, @file_get_contents($this->rHome . 'handed'));
		$this->assertSame([self::UUID], array_column($this->daemonServed(), 'uuid'));
	}

	// ── Where a worker has not left the connection yet ───────────────

	public function testAMovieSeekEndsTheConnectionOfAWorkerThatHasNotYetLeftIt(): void {
		$rWorker = $this->worker();
		// The viewer's earlier request: its worker has not yet seen the player leave.
		$this->connection(self::EARLIER, self::IP, proc_get_status($rWorker)['pid']);

		$this->assertSame('MOVIE', $this->request());
		$this->assertTrue(proc_get_status($rWorker)['running'], 'a Range request stops no worker');
		// The seek took the connection over: it is ended with the seek's response, not left open under a worker that is gone.
		$this->assertSame([self::EARLIER => 1], array_map('intval', array_column($this->connections(), 'hls_end', 'uuid')));
	}

	// ── Where ip_subnet_match makes a subnet one viewer ──────────────

	public function testWhereASubnetIsOneViewerAnIPv6SeekFromAnotherNetworkIsAConnectionOfItsOwn(): void {
		$this->settings(['ip_subnet_match' => 1]);
		// The line's other device on another IPv6 network: the same player, the same movie.
		$this->connection(self::EARLIER, '2a00:1::99', 0);

		$this->assertSame('MOVIE', $this->request([], ['REMOTE_ADDR' => '2001:db8::7']));
		$rRows = $this->connections();
		$this->assertSame([0, 0], [(int) $rRows[self::EARLIER]['pid'], (int) $rRows[self::EARLIER]['hls_end']], 'the other device\'s connection is left as it was');
		$this->assertSame('2001:db8::7', $rRows[self::UUID]['user_ip'] ?? null);
	}

	public function testWhereASubnetIsOneViewerAnIPv6SeekFromItsOwnAddressKeepsItsEarlierConnection(): void {
		$this->settings(['ip_subnet_match' => 1]);
		$this->connection(self::EARLIER, '2001:db8::7', 0);

		$this->assertSame('MOVIE', $this->request([], ['REMOTE_ADDR' => '2001:db8::7']));
		$this->assertSame([self::EARLIER], array_keys($this->connections()));
	}
}
