<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Util\Encryption;
use XcVm\Tests\Support\InstallSchema;

/**
 * What the stream entry scripts do with the connection a request finds (the
 * worker it names, the id it is kept under), the rate a catch-up is throttled
 * to, a line's name and password as the request gives them, and which of a
 * playlist's segment links is a catch-up one.
 *
 * vod.php, timeshift.php and segment.php run for real in a child PHP, with the
 * constants, caches and request StreamingRequestBootstrap leaves them, against
 * this test's database. Where a script cannot be run to the lines under test,
 * those are read from it and run in a child PHP.
 */
final class AuditStreamEntryTest extends TestCase {
	private const PASS = 'test-live-pass';
	private const IP = '10.0.0.1';
	private const AGENT = 'player/1.0';
	private const LINE = 9;
	private const UUID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const EARLIER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
	private const OTHER = 'cccccccccccccccccccccccccccccccc';

	/** The settings cache of the server that answers: its own store, PHP serving the files, error pages that name their code. */
	private const SETTINGS = [
		'live_streaming_pass' => self::PASS, 'secure_stream_tokens' => 1, 'debug_show_errors' => 1,
		'send_server_header' => '', 'send_protection_headers' => 0, 'send_altsvc_header' => 0, 'send_unique_header' => '',
		'use_buffer' => 1, 'redis_handler' => 0, 'fanout_enabled' => 0, 'ip_subnet_match' => 0, 'restrict_same_ip' => 1,
		'monitor_connection_status' => 0, 'vod_bitrate_plus' => 0, 'vod_limit_perc' => 0, 'read_buffer_size' => 8192, 'client_logs_save' => 0,
		'encrypt_hls' => 0,
	];

	private TestDb $rDb;

	private string $rHome;

	/** The top of an hour, two hours back. */
	private int $rStart;

	/** @var list<resource> the stand-in workers this test started */
	private array $rWorkers = [];

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec(InstallSchema::table('lines_live'));
		$this->rHome = sys_get_temp_dir() . '/xcvm-stream-entry-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		foreach (['archive', 'vod', 'streams', 'cons', 'cache', 'divergence', 'signals'] as $rDir) {
			mkdir($this->rHome . $rDir, 0777, true);
		}
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
			foreach (['ARCHIVE_PATH' => 'archive', 'VOD_PATH' => 'vod', 'STREAMS_PATH' => 'streams', 'CONS_TMP_PATH' => 'cons', 'CACHE_TMP_PATH' => 'cache', 'DIVERGENCE_TMP_PATH' => 'divergence', 'SIGNALS_PATH' => 'signals'] as $rName => $rDir) {
				define($rName, MAIN_HOME . $rDir . '/');
			}
			$rIn = json_decode((string) file_get_contents(MAIN_HOME . 'request.json'), true);
			$_SERVER = $rIn['server'] + $_SERVER;
			$_GET = $rRequest = $rIn['get'];
			$rSettings = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'settings'));
			$rServers = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'servers'));

			PHP);
		$this->rStart = intdiv(time(), 3600) * 3600 - 7200;
	}

	protected function tearDown(): void {
		foreach ($this->rWorkers as $rWorker) {
			proc_terminate($rWorker, 9);
			proc_close($rWorker);
		}
		exec('rm -rf ' . escapeshellarg($this->rHome));
	}

	/**
	 * One request to a stream endpoint, as nginx hands it over.
	 *
	 * @param array<string, string> $rGet
	 * @param array<string, string> $rServer
	 * @param string|null $rScript a copy of the endpoint's script to run in its place
	 * @return string the response body
	 */
	private function request(string $rEndpoint, array $rGet, array $rServer = [], ?string $rScript = null): string {
		file_put_contents($this->rHome . 'request.json', (string) json_encode(['get' => $rGet, 'server' => $rServer + ['REMOTE_ADDR' => self::IP, 'HTTP_USER_AGENT' => self::AGENT]]));
		$rEnv = ['XCVM_TEST_HOME' => $this->rHome, 'XCVM_TEST_SRC' => MAIN_HOME, 'XCVM_TEST_SCHEMA' => $this->rDb->schema(), 'XCVM_TEST_EXTRA' => OPENSSL_EXTRA, 'PATH' => (string) getenv('PATH')] + TestDb::env();
		$rCommand = [...xcvm_test_child_php(), '-d', 'auto_prepend_file=' . $this->rHome . 'prepend.php', '-d', 'date.timezone=UTC', '-d', 'display_errors=stderr', $rScript ?? MAIN_HOME . 'Public/stream/' . $rEndpoint . '.php'];
		$rProc = proc_open($rCommand, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->rHome . 'body', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes, $this->rHome, $rEnv);
		$this->assertIsResource($rProc);
		proc_close($rProc);
		return (string) file_get_contents($this->rHome . 'body');
	}

	/**
	 * Lines read from the entry script $rFile, from $rFrom to $rTo ($rTo with
	 * them when $rThrough), run in a child PHP with the script's imports and
	 * these variables. What the child printed: $rResult as JSON once the lines
	 * ran, `refused <code>` for an error answer, `answered <status>` when they
	 * ended the request themselves, or the TypeError that ended them.
	 *
	 * @param array<string, mixed> $rVars `_GET` is the request's query string
	 */
	private function lines(string $rFile, string $rFrom, string $rTo, array $rVars, string $rResult, string $rSetup = '', bool $rThrough = false): string {
		$rSource = (string) file_get_contents(MAIN_HOME . $rFile);
		$rStart = strpos($rSource, $rFrom);
		$this->assertNotFalse($rStart, $rFile . ' has no: ' . $rFrom);
		$rEnd = strpos($rSource, $rTo, $rStart);
		$this->assertNotFalse($rEnd, $rFile . ' has no: ' . $rTo);
		preg_match_all('/^use [^;]+;$/m', $rSource, $rUses);

		file_put_contents($this->rHome . 'lines.php', '<?php require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ";\n"
			. implode("\n", $rUses[0]) . "\n"
			. '\XcVm\Core\Error\ErrorResponder::$throwInsteadOfExit = true;' . "\n"
			. $rSetup . "\n"
			. 'register_shutdown_function(static function () { if (empty($GLOBALS["rRan"])) { echo "answered " . var_export(http_response_code(), true); } });' . "\n"
			. '$rVars = ' . var_export($rVars, true) . ';' . "\n"
			. '$_GET = $rVars["_GET"] ?? []; unset($rVars["_GET"]); extract($rVars);' . "\n"
			. 'ob_start();' . "\n"
			. 'try {' . "\n" . substr($rSource, $rStart, $rEnd - $rStart + ($rThrough ? strlen($rTo) : 0)) . "\n"
			. '	$rRan = json_encode(' . $rResult . ');' . "\n"
			. '} catch (\XcVm\Core\Error\ErrorResponseException $e) { $rRan = "refused " . ($e->errorCode ?: "404"); }' . "\n"
			. 'catch (\TypeError $e) { $rRan = "TypeError: " . $e->getMessage(); }' . "\n"
			. 'ob_end_clean();' . "\n"
			. 'echo $rRan;');

		$rProc = proc_open([PHP_BINARY, $this->rHome . 'lines.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		fclose($rPipes[1]);
		proc_close($rProc);
		return $rOut;
	}

	private function seal(string $rData): string {
		return Encryption::mintToken($rData, self::PASS, OPENSSL_EXTRA, true);
	}

	/**
	 * The token auth.php seals for vod.php.
	 *
	 * @param array<string, mixed> $rOver
	 */
	private function movie(array $rOver = []): string {
		return $this->seal((string) json_encode($rOver + [
			'stream_id' => 300, 'username' => 'line', 'password' => 'secret', 'extension' => 'mp4', 'type' => 'movie', 'pid' => 1,
			'channel_info' => ['stream_id' => 300, 'bitrate' => 0, 'target_container' => 'mp4', 'redirect_id' => 1, 'originator_id' => null, 'pid' => 0, 'proxy' => null],
			'user_info' => ['id' => self::LINE, 'max_connections' => 0, 'pair_id' => null, 'con_isp_name' => '', 'is_restreamer' => 0],
			'country_code' => 'PT', 'activity_start' => time(), 'is_mag' => false, 'uuid' => self::UUID, 'http_range' => null,
		]));
	}

	/**
	 * The token auth.php seals for timeshift.php: a recording, one minute of it.
	 *
	 * @param array<string, mixed> $rOver
	 */
	private function recording(array $rOver = []): string {
		return $this->seal((string) json_encode($rOver + [
			'stream' => 55, 'username' => 'line', 'password' => 'secret', 'extension' => 'ts', 'pid' => 1,
			'start' => (string) $this->rStart, 'duration' => 1, 'redirect_id' => 1, 'originator_id' => null,
			'user_info' => ['id' => self::LINE, 'max_connections' => 0, 'pair_id' => null, 'con_isp_name' => '', 'is_restreamer' => 0],
			'country_code' => 'PT', 'activity_start' => time(), 'uuid' => self::UUID, 'http_range' => null,
		]));
	}

	/** A recorded minute of a channel, under the name ArchiveCommand gives it. */
	private function minute(int $rStreamID, int $rTime, string $rBytes): string {
		$rName = gmdate('Y-m-d:H-i', $rTime) . '.ts';
		@mkdir($this->rHome . 'archive/' . $rStreamID);
		file_put_contents($this->rHome . 'archive/' . $rStreamID . '/' . $rName, $rBytes);
		return $rName;
	}

	/** A connection of the line the store already holds: the same player, at $rIP. */
	private function connection(string $rUUID, string $rContainer, int $rStreamID, string $rIP, int $rPID, int $rEnded = 0): void {
		$this->rDb->query('INSERT INTO `lines_live` (`user_id`, `stream_id`, `server_id`, `user_agent`, `user_ip`, `container`, `pid`, `date_start`, `hls_last_read`, `hls_end`, `uuid`) VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?)', self::LINE, $rStreamID, self::AGENT, $rIP, $rContainer, $rPID, time(), time(), $rEnded, $rUUID);
	}

	/** @return array<string, int> whether each of the store's connections has ended, by uuid */
	private function ended(): array {
		$this->rDb->query('SELECT `uuid`, `hls_end` FROM `lines_live` ORDER BY `activity_id`');
		return array_map('intval', array_column($this->rDb->get_rows(), 'hls_end', 'uuid'));
	}

	/**
	 * A process that is a PHP-FPM worker to ProcessManager::isRunning(): its
	 * executable is named as one. It serves nobody, and sleeps.
	 *
	 * @return resource
	 */
	private function worker() {
		if (!is_file($this->rHome . 'php-fpm-worker')) {
			copy(trim((string) shell_exec('command -v sleep')), $this->rHome . 'php-fpm-worker');
			chmod($this->rHome . 'php-fpm-worker', 0755);
		}
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

	/** Is the stand-in worker still running, a moment after a request that might have stopped it? */
	private function running($rWorker): bool {
		for ($i = 0; $i < 40; $i++) {
			if (!proc_get_status($rWorker)['running']) {
				return false;
			}
			usleep(5000);
		}
		return true;
	}

	// ── The worker a connection names ────────────────────────────────

	public function testAMovieLinkAskedForAgainLeavesTheWorkerOfItsEndedConnectionRunning(): void {
		file_put_contents($this->rHome . 'vod/300.mp4', 'MOVIE');
		$rWorker = $this->worker();
		// The connection this link opened has ended: its worker serves another request now.
		$this->connection(self::UUID, 'VOD', 300, self::IP, proc_get_status($rWorker)['pid'], 1);

		$this->assertSame('MOVIE', $this->request('vod', ['token' => $this->movie()]));
		$this->assertTrue($this->running($rWorker), 'the worker of an ended connection was stopped');
	}

	public function testAMovieLinkAskedForAgainStillTakesOverFromTheWorkerOfItsOpenConnection(): void {
		file_put_contents($this->rHome . 'vod/300.mp4', 'MOVIE');
		$rWorker = $this->worker();
		$this->connection(self::UUID, 'VOD', 300, self::IP, proc_get_status($rWorker)['pid']);

		$this->assertSame('MOVIE', $this->request('vod', ['token' => $this->movie()]));
		$this->assertFalse($this->running($rWorker), 'the worker still serving the connection is replaced');
	}

	public function testARecordingLinkAskedForAgainLeavesTheWorkerOfItsEndedConnectionRunning(): void {
		$this->minute(55, $this->rStart, 'AAAA');
		$rWorker = $this->worker();
		$this->connection(self::UUID, 'ts', 55, self::IP, proc_get_status($rWorker)['pid'], 1);

		$this->assertSame('AAAA', $this->request('timeshift', ['token' => $this->recording()]));
		$this->assertTrue($this->running($rWorker), 'the worker of an ended connection was stopped');
	}

	public function testARecordingLinkAskedForAgainStillTakesOverFromTheWorkerOfItsOpenConnection(): void {
		$this->minute(55, $this->rStart, 'AAAA');
		$rWorker = $this->worker();
		$this->connection(self::UUID, 'ts', 55, self::IP, proc_get_status($rWorker)['pid']);

		$this->assertSame('AAAA', $this->request('timeshift', ['token' => $this->recording()]));
		$this->assertFalse($this->running($rWorker), 'the worker still serving the connection is replaced');
	}

	// ── The id a movie seek keeps its connection under ───────────────

	public function testAMovieSeekThatKeepsAnEarlierConnectionEndsItWhenItEnds(): void {
		file_put_contents($this->rHome . 'vod/300.mp4', 'MOVIE');
		// The same player and address, the movie asked for again with a Range: a link of its own, the earlier connection.
		$this->connection(self::EARLIER, 'VOD', 300, self::IP, 0);

		$this->assertSame('MOVIE', $this->request('vod', ['token' => $this->movie()], ['HTTP_RANGE' => 'bytes=0-']));
		$this->assertSame([self::EARLIER => 1], $this->ended(), 'the connection the seek kept is still counted after it ended');
		$this->assertSame([], array_diff(scandir($this->rHome . 'cons'), ['.', '..']), 'no marker is left for either id');
	}

	public function testAMovieSeekThatKeepsAnEarlierConnectionGoesOnPastItsCheckIn(): void {
		// Three read buffers of movie, and the script with its five minutes between check-ins made none.
		file_put_contents($this->rHome . 'vod/300.mp4', str_repeat('M', 3 * 8192));
		$rScript = str_replace('300 > time() - $rLastCheck', '0 > time() - $rLastCheck', (string) file_get_contents(MAIN_HOME . 'Public/stream/vod.php'), $rCount);
		$this->assertSame(1, $rCount, 'vod.php checks in every five minutes');
		file_put_contents($this->rHome . 'vod-checkin.php', $rScript);
		$this->connection(self::EARLIER, 'VOD', 300, self::IP, 0);

		$this->assertSame(3 * 8192, strlen($this->request('vod', ['token' => $this->movie()], ['HTTP_RANGE' => 'bytes=0-'], $this->rHome . 'vod-checkin.php')), 'the response ended at its check-in');
		$this->assertSame([self::EARLIER => 1], $this->ended());
	}

	public function testAMovieSeekWithAConnectionOfItsOwnLeavesTheEarlierOneOpen(): void {
		file_put_contents($this->rHome . 'vod/300.mp4', 'MOVIE');
		// The line's other device, on another network.
		$this->connection(self::EARLIER, 'VOD', 300, '10.9.9.9', 0);

		$this->assertSame('MOVIE', $this->request('vod', ['token' => $this->movie()], ['HTTP_RANGE' => 'bytes=0-']));
		$this->assertSame([self::EARLIER => 0, self::UUID => 1], $this->ended());
	}

	public function testAMovieSeekThatKeepsAnEarlierConnectionIsStillTheLinesNewestRequest(): void {
		file_put_contents($this->rHome . 'vod/300.mp4', 'MOVIE');
		$this->rDb->exec(InstallSchema::table('streams_servers'));
		$rWorker = $this->worker();
		// A line of one connection. Its movie's connection ended, and another device has played a channel since.
		$this->connection(self::EARLIER, 'VOD', 300, self::IP, 0, 1);
		$this->connection(self::OTHER, 'ts', 55, '10.9.9.9', proc_get_status($rWorker)['pid']);
		$rLine = ['id' => self::LINE, 'max_connections' => 1, 'pair_id' => null, 'con_isp_name' => '', 'is_restreamer' => 0];

		$this->assertSame('MOVIE', $this->request('vod', ['token' => $this->movie(['user_info' => $rLine])], ['HTTP_RANGE' => 'bytes=0-']));
		// The newest request is the one the line keeps: the seek, under the connection it took up again.
		$this->assertFalse($this->running($rWorker), 'the line plays on two devices');
		$this->assertSame([self::EARLIER => 1], $this->ended(), 'only the connection the seek kept is left, ended with its response');
	}

	// ── The rate a recording is throttled to ─────────────────────────

	public function testARecordingIsThrottledToTheRateOfTheMinutesItHolds(): void {
		$rScript = 'Public/stream/timeshift.php';
		$rSource = (string) file_get_contents(MAIN_HOME . $rScript);
		$rLength = substr($rSource, (int) strpos($rSource, 'function getLength('));
		// The bytes a second past vod_limit_perc: $rHeld recorded minutes of 600 kB each, $rAsked asked for.
		$rRate = fn(int $rAsked, int $rHeld, int $rPlus = 0): float => (float) json_decode($this->lines(
			$rScript,
			'$rSize = getLength($rQueue) - $rOffset;',
			'if ($rFileDaemon) {',
			['rQueue' => array_fill(0, $rHeld, ['filename' => 'minute.ts', 'filesize' => 600000]), 'rOffset' => 0, 'rDuration' => $rAsked, 'rSettings' => ['vod_bitrate_plus' => $rPlus]],
			'$rDownloadBytes',
			$rLength
		));

		// 600 kB a minute is 10 kB a second, however many minutes the link asks for.
		$this->assertEqualsWithDelta(10000.0, $rRate(2, 2), 0.01, 'every minute asked for is held');
		$this->assertEqualsWithDelta(10000.0, $rRate(60, 2), 0.01, 'a programme still on air');
		$this->assertEqualsWithDelta(10000.0, $rRate(3600 * 6, 5), 0.01, 'a link by its time of day');
		$this->assertEqualsWithDelta(11000.0, $rRate(60, 2, 10), 0.01, 'with vod_bitrate_plus on top');
	}

	// ── A line's token, name and password as the request gives them ──

	/** The line lookup's stand-in, with the lookup's own parameter types: it knows no line, and notes who it was asked for. */
	private function lookup(): string {
		preg_match('/public static function getStreamingUserInfo\(([^)]*)\)/', (string) file_get_contents(MAIN_HOME . 'Domain/User/UserRepository.php'), $rSignature);
		$this->assertNotEmpty($rSignature);
		return 'class LineLookup { public static function getStreamingUserInfo(' . $rSignature[1] . ') { $GLOBALS["rAsked"] = [$rUsername, $rPassword]; return null; } } class_alias("LineLookup", UserRepository::class);';
	}

	/** What the lines of $rFile from $rFrom to their lookup do with this request: who they ask the lookup for, or how they refuse. */
	private function credentials(string $rFile, string $rFrom, string $rLookup, array $rRequest): string {
		return $this->lines($rFile, $rFrom, $rLookup, ['rRequest' => $rRequest, 'rSettings' => [], 'rCached' => false, 'rBouquets' => [], 'rIP' => self::IP], '$rAsked ?? null', $this->lookup(), true);
	}

	public function testAuthRefusesALinesNameOrPasswordSentAsAList(): void {
		$rAuth = fn(array $rRequest): string => $this->credentials('Public/stream/auth.php', '$rUsername = $rRequest[\'username\']', '$rUsername, $rPassword, false, false, $rIP);', $rRequest);

		$this->assertSame('refused INVALID_CREDENTIALS', $rAuth(['username' => ['line'], 'password' => 'secret']));
		$this->assertSame('refused INVALID_CREDENTIALS', $rAuth(['username' => 'line', 'password' => ['secret']]));
		// Plain values go to the lookup as they are, and a request with neither as before.
		$this->assertSame('["line","secret"]', $rAuth(['username' => 'line', 'password' => 'secret']));
		$this->assertSame('[null,null]', $rAuth([]));
	}

	public function testAuthRefusesALinesTokenSentAsAList(): void {
		$rAuth = fn(array $rRequest): string => $this->credentials('Public/stream/auth.php', '$rAccessToken = $rRequest[\'token\']', '$rAccessToken, null, false, false, $rIP);', $rRequest);

		$this->assertSame('refused INVALID_CREDENTIALS', $rAuth(['token' => [str_repeat('a', 32)]]));
		$this->assertSame('["' . str_repeat('a', 32) . '",null]', $rAuth(['token' => str_repeat('a', 32)]));
	}

	public function testAuthsCheckForAKnownLineReadsOnlyPlainNamesAndPasswords(): void {
		touch($this->rHome . 'cache/line_c_line_secret');
		// The check auth.php makes before anything else, where lines are cached and unknown ones ignored.
		$rCheck = fn(array $rGet, int $rCase = 0): string => $this->lines(
			'Public/stream/auth.php',
			'if (($rSettings[\'ignore_invalid_users\'] && $rSettings[\'enable_cache\'])) {',
			'if (($rSettings[\'enable_cache\'] && !$rSettings[\'show_not_on_air_video\']',
			['_GET' => $rGet, 'rSettings' => ['ignore_invalid_users' => 1, 'enable_cache' => 1, 'case_sensitive_line' => $rCase]],
			'"goes on"',
			'define("LINES_TMP_PATH", ' . var_export($this->rHome . 'cache/', true) . ');'
		);

		$this->assertSame('"goes on"', $rCheck(['username' => 'Line', 'password' => 'secret']));
		$this->assertSame('refused INVALID_CREDENTIALS', $rCheck(['username' => 'Line', 'password' => 'secret'], 1));
		$this->assertSame('refused INVALID_CREDENTIALS', $rCheck(['username' => 'guess', 'password' => 'secret']));
		// A list is no cached line's name: it goes on to the lookup's own read, which refuses it.
		$this->assertSame('"goes on"', $rCheck(['username' => ['line'], 'password' => 'secret']));
		$this->assertSame('"goes on"', $rCheck(['username' => 'line', 'password' => ['secret']]));
	}

	public function testRtmpRefusesALinesNameOrPasswordSentAsAList(): void {
		$rRtmp = fn(array $rRequest): string => $this->credentials('Public/stream/rtmp.php', '$rUsername = $rRequest[\'username\']', '$rUsername, $rPassword, true, false, $rIP);', $rRequest);

		$this->assertSame('answered 404', $rRtmp(['username' => ['line'], 'password' => 'secret']));
		$this->assertSame('answered 404', $rRtmp(['username' => 'line', 'password' => ['secret']]));
		$this->assertSame('["line","secret"]', $rRtmp(['username' => 'line', 'password' => 'secret']));
		$this->assertSame('[null,null]', $rRtmp([]));
	}

	public function testAnEndpointsLinkSentAsAListIsNoLink(): void {
		foreach (['vod', 'timeshift', 'live'] as $rEndpoint) {
			$this->assertStringContainsString('<h2>NO_TOKEN_SPECIFIED</h2>', $this->request($rEndpoint, ['token' => ['link']]), $rEndpoint);
			// A request without one, and a plain value that is no link, are answered as before.
			$this->assertStringContainsString('<h2>NO_TOKEN_SPECIFIED</h2>', $this->request($rEndpoint, []), $rEndpoint);
			$this->assertStringContainsString('<h2>LB_TOKEN_INVALID</h2>', $this->request($rEndpoint, ['token' => 'link']), $rEndpoint);
		}
	}

	public function testAuthRefusesAStreamOrExtensionSentAsAList(): void {
		// What auth.php reads the request's type, stream and extension as.
		$rRead = fn(array $rGet): string => $this->lines('Public/stream/auth.php', '$rType = (isset($_GET[\'type\'])', 'if ($rExtension) {', ['_GET' => $rGet], '[$rType, $rStreamID, $rExtension]');

		$this->assertSame('refused INVALID_STREAM_ID', $rRead(['stream' => '5', 'extension' => ['ts']]));
		$this->assertSame('refused INVALID_STREAM_ID', $rRead(['stream' => ['5'], 'extension' => 'ts']));
		$this->assertSame('refused INVALID_STREAM_ID', $rRead(['type' => 'movie', 'stream' => ['5.mp4']]));
		// Plain values are read as before: a channel, a movie by its file name, a movie's segment.
		$this->assertSame('["live",5,"ts"]', $rRead(['stream' => '5', 'extension' => ' TS ']));
		$this->assertSame('["movie",5,"mp4"]', $rRead(['type' => 'movie', 'stream' => '5.mp4']));
		$this->assertSame('["movie",5,"ts"]', $rRead(['type' => 'movie', 'stream' => '5/seg_3.ts']));
	}

	public function testProbeAnswersAPathSentAsAListAsOneItDoesNotKnow(): void {
		$this->assertStringContainsString('404 Not Found', $this->request('probe', ['data' => ['path']]));
		$this->assertStringContainsString('404 Not Found', $this->request('probe', ['data' => base64_encode('nothing')]));
	}

	// ── Which of a playlist's segment links is a recording's ─────────

	/**
	 * segment.php's answer to the link a live playlist gives this line for the
	 * segment 55_7.ts, its fields as HLSGenerator writes them: name, password,
	 * address, stream, segment, connection id, server, codec, on demand.
	 */
	private function liveSegment(string $rUsername, int $rOnDemand): string {
		return $this->request('segment', ['token' => $this->seal(implode('/', [$rUsername, 'secret', self::IP, '55', '55_7.ts', self::UUID, '1', 'h264', (string) $rOnDemand]))]);
	}

	public function testALivePlaylistsSegmentIsServedToALineNamedAsARecordingsLinkBegins(): void {
		// The server encrypts a live segment for its first viewer: the copy it makes shows the segment was served.
		file_put_contents($this->rHome . 'cache/settings', igbinary_serialize(['encrypt_hls' => 1] + self::SETTINGS));
		[$rKey, $rIV] = [str_repeat('k', 16), str_repeat('i', 16)];
		file_put_contents($this->rHome . 'streams/55_.key', $rKey);
		file_put_contents($this->rHome . 'streams/55_.iv', $rIV);
		file_put_contents($this->rHome . 'streams/55_7.ts', 'SEGMENT');
		touch($this->rHome . 'cons/' . self::UUID);

		foreach (['line', 'TS'] as $rUsername) {
			@unlink($this->rHome . 'streams/55_7.ts.enc');
			$this->assertSame('', $this->liveSegment($rUsername, 0), 'line ' . $rUsername);
			$this->assertSame(openssl_encrypt('SEGMENT', 'aes-128-cbc', $rKey, OPENSSL_RAW_DATA, $rIV), @file_get_contents($this->rHome . 'streams/55_7.ts.enc'), 'line ' . $rUsername);
			// An on-demand channel's segment is handed over as it is: no error page in its place.
			$this->assertSame('', $this->liveSegment($rUsername, 1), 'line ' . $rUsername . ', on demand');
		}
	}

	public function testARecordingsLinkIsStillReadAsOneForALineOfThatName(): void {
		$rName = $this->minute(55, $this->rStart, 'AAAA');
		touch($this->rHome . 'cons/' . self::UUID);

		foreach (['line', 'TS'] as $rUsername) {
			$rLink = $this->seal(implode('/', ['TS', $rUsername, 'secret', self::IP, '2', (string) $this->rStart, '55_' . $rName . '_0', self::UUID, '1']));
			$this->assertSame('AAAA', $this->request('segment', ['token' => $rLink]), 'line ' . $rUsername);
		}
	}
}
