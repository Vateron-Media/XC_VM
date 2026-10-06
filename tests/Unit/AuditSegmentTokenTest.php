<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Util\Encryption;
use XcVm\Streaming\Delivery\HLSGenerator;
use XcVm\Tests\Support\InstallSchema;

if (!defined('SERVER_ID')) {
	define('SERVER_ID', 1);
}

/**
 * The links a viewer follows once auth.php has redirected it: the catch-up
 * playlist and its segment links (timeshift.php mints them, segment.php reads
 * them back), a live playlist's (HLSGenerator), a catch-up recording, a movie,
 * a thumbnail and a subtitle. Each endpoint runs for real in a child PHP, with
 * the constants, caches and request StreamingRequestBootstrap leaves it,
 * against this test's database.
 */
final class AuditSegmentTokenTest extends TestCase {
	private const PASS = 'test-live-pass';
	private const IP = '10.0.0.1';
	private const AGENT = 'player/1.0';
	private const LINE = 9;
	private const UUID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const EARLIER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

	/** The settings cache of the server that answers: its own store, no fanout daemon, error pages that name their code. */
	private const SETTINGS = [
		'live_streaming_pass' => self::PASS, 'secure_stream_tokens' => 1, 'debug_show_errors' => 1,
		'send_server_header' => '', 'send_protection_headers' => 0, 'send_altsvc_header' => 0, 'send_unique_header' => '',
		'use_buffer' => 1, 'redis_handler' => 0, 'fanout_enabled' => 0, 'ip_subnet_match' => 0, 'restrict_same_ip' => 1,
		'monitor_connection_status' => 0, 'vod_bitrate_plus' => 0, 'vod_limit_perc' => 0, 'read_buffer_size' => 8192, 'client_logs_save' => 0,
	];

	private TestDb $rDb;

	private string $rHome;

	/** The top of an hour, two hours back: one instant in each of the three ways a start is written. */
	private int $rStart;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec(InstallSchema::table('lines_live'));
		$this->rHome = sys_get_temp_dir() . '/xcvm-segment-token-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
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
		exec('rm -rf ' . escapeshellarg($this->rHome));
	}

	/**
	 * One request to a stream endpoint, as nginx hands it over.
	 *
	 * @param array<string, string> $rGet
	 * @param array<string, string> $rServer
	 * @return array{0: string, 1: bool} the response body, and whether the endpoint ended within $rSeconds
	 */
	private function request(string $rEndpoint, array $rGet, array $rServer = [], int $rSeconds = 60): array {
		file_put_contents($this->rHome . 'request.json', (string) json_encode(['get' => $rGet, 'server' => $rServer + ['REMOTE_ADDR' => self::IP, 'HTTP_USER_AGENT' => self::AGENT]]));
		$rEnv = ['XCVM_TEST_HOME' => $this->rHome, 'XCVM_TEST_SRC' => MAIN_HOME, 'XCVM_TEST_SCHEMA' => $this->rDb->schema(), 'XCVM_TEST_EXTRA' => OPENSSL_EXTRA, 'PATH' => (string) getenv('PATH')] + TestDb::env();
		$rCommand = [...xcvm_test_child_php(), '-d', 'auto_prepend_file=' . $this->rHome . 'prepend.php', '-d', 'date.timezone=UTC', '-d', 'display_errors=stderr', MAIN_HOME . 'Public/stream/' . $rEndpoint . '.php'];
		$rProc = proc_open($rCommand, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->rHome . 'body', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes, $this->rHome, $rEnv);
		$this->assertIsResource($rProc);
		$rUntil = microtime(true) + $rSeconds;
		while (($rRunning = proc_get_status($rProc)['running']) && microtime(true) < $rUntil) {
			usleep(10000);
		}
		if ($rRunning) {
			proc_terminate($rProc, 9);
		}
		proc_close($rProc);
		return [(string) file_get_contents($this->rHome . 'body'), !$rRunning];
	}

	private function seal(string $rData): string {
		return Encryption::mintToken($rData, self::PASS, OPENSSL_EXTRA, true);
	}

	/**
	 * The catch-up token auth.php seals for timeshift.php.
	 *
	 * @param array<string, mixed> $rOver
	 */
	private function catchUp(array $rOver = []): string {
		return $this->seal((string) json_encode($rOver + [
			'stream' => 55, 'username' => 'line', 'password' => 'secret', 'extension' => 'm3u8', 'pid' => 1,
			'start' => (string) $this->rStart, 'duration' => 2, 'redirect_id' => 1, 'originator_id' => null,
			'user_info' => ['id' => self::LINE, 'max_connections' => 0, 'pair_id' => null, 'con_isp_name' => '', 'is_restreamer' => 0],
			'country_code' => 'PT', 'activity_start' => time(), 'uuid' => self::UUID, 'http_range' => null,
		]));
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
	 * The answering server's settings cache, with these values changed.
	 *
	 * @param array<string, mixed> $rOver
	 */
	private function settings(array $rOver): void {
		file_put_contents($this->rHome . 'cache/settings', igbinary_serialize($rOver + self::SETTINGS));
	}

	/** A connection of the line the store already holds: the same player, at $rIP. */
	private function connection(string $rUUID, string $rContainer, int $rStreamID, string $rIP, ?int $rPID): void {
		$this->rDb->query('INSERT INTO `lines_live` (`user_id`, `stream_id`, `server_id`, `user_agent`, `user_ip`, `container`, `pid`, `date_start`, `hls_last_read`, `hls_end`, `uuid`) VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, 0, ?)', self::LINE, $rStreamID, self::AGENT, $rIP, $rContainer, $rPID, time(), time(), $rUUID);
	}

	/** @return array<string, array<string, mixed>> the store's connections, by uuid */
	private function connections(): array {
		$this->rDb->query('SELECT `uuid`, `container`, `user_ip`, `pid` FROM `lines_live` ORDER BY `activity_id`');
		return array_column($this->rDb->get_rows(), null, 'uuid');
	}

	/** A recorded minute of a channel, under the name ArchiveCommand gives it. */
	private function minute(int $rStreamID, int $rTime, string $rBytes): string {
		$rName = gmdate('Y-m-d:H-i', $rTime) . '.ts';
		@mkdir($this->rHome . 'archive/' . $rStreamID);
		file_put_contents($this->rHome . 'archive/' . $rStreamID . '/' . $rName, $rBytes);
		return $rName;
	}

	/**
	 * The fields of each segment link of a playlist, as segment.php splits them.
	 *
	 * @return list<list<string>>
	 */
	private function links(string $rPlaylist): array {
		preg_match_all('#^/hls/(\S+)$#m', $rPlaylist, $rLinks);
		return array_map(static fn(string $rToken): array => explode('/', (string) Encryption::readToken($rToken, self::PASS, OPENSSL_EXTRA, true)), $rLinks[1]);
	}

	/** The body segment.php answers a catch-up segment link of these fields with. */
	private function segment(string ...$rFields): string {
		return $this->request('segment', ['token' => $this->seal(implode('/', $rFields))])[0];
	}

	// ── The catch-up playlist's segment links ─────────────────────────

	public function testASegmentLinkCarriesTheStartAsTheTimeItWasReadAs(): void {
		$rFirst = $this->minute(55, $this->rStart, 'AAAA');
		$rSecond = $this->minute(55, $this->rStart + 60, 'BBBB');

		// The last form is read with seconds after its minute too.
		$rTime = gmdate('Y-m-d:H-i', $this->rStart);
		foreach ([(string) $this->rStart, gmdate('Ymd-H', $this->rStart), $rTime, $rTime . ':00', $rTime . '-00'] as $rWritten) {
			[$rPlaylist] = $this->request('timeshift', ['token' => $this->catchUp(['start' => $rWritten])]);
			$this->assertSame([
				['TS', 'line', 'secret', self::IP, '2', (string) $this->rStart, '55_' . $rFirst . '_0', self::UUID, '1'],
				['TS', 'line', 'secret', self::IP, '2', (string) $this->rStart, '55_' . $rSecond . '_0', self::UUID, '1'],
			], $this->links($rPlaylist), 'start=' . $rWritten);
		}
	}

	public function testAStartThatIsNotATimeGetsNoPlaylist(): void {
		$this->minute(55, $this->rStart, 'AAAA');
		$rTime = gmdate('Y-m-d:H-i', $this->rStart);

		foreach ([$rTime . ':x/y', $rTime . '/x', $rTime . ':00:00', 'yesterday', '', null, ['a'], true, 1.5] as $rWritten) {
			[$rBody] = $this->request('timeshift', ['token' => $this->catchUp(['start' => $rWritten])]);
			$this->assertStringContainsString('<h2>NO_TIMESTAMP</h2>', $rBody, 'start=' . json_encode($rWritten));
			$this->assertSame([], $this->links($rBody), 'start=' . json_encode($rWritten));
		}
	}

	public function testARecordedMinuteIsServedToTheViewerWhoseLinkItIs(): void {
		$rName = $this->minute(55, $this->rStart, 'AAAA');
		touch($this->rHome . 'cons/' . self::UUID);

		$this->assertSame('AAAA', $this->segment('TS', 'line', 'secret', self::IP, '2', (string) $this->rStart, '55_' . $rName . '_0', self::UUID, '1'));
		// A link handed out before the start was written as a time: still its viewer's.
		$this->assertSame('AAAA', $this->segment('TS', 'line', 'secret', self::IP, '2', gmdate('Y-m-d:H-i', $this->rStart), '55_' . $rName . '_0', self::UUID, '1'));
	}

	public function testACatchUpLinkWithMoreFieldsThanItsNineIsRefused(): void {
		$rName = $this->minute(55, $this->rStart, 'AAAA');
		$this->minute(77, $this->rStart, 'OTHER CHANNEL');
		touch($this->rHome . 'cons/' . self::UUID);

		// Three fields too many: the server, the marker and the recording are not read from where a link of nine has them.
		$rBody = $this->segment('TS', 'line', 'secret', self::IP, '2', gmdate('Y-m-d:H-i', $this->rStart) . ':x', '77_' . $rName . '_0', '', '1', '55_' . $rName . '_0', self::UUID, '1');
		$this->assertStringNotContainsString('OTHER CHANNEL', $rBody);
		$this->assertStringContainsString('404 Not Found', $rBody);
	}

	public function testACatchUpSegmentIsServedOnlyWhileItsViewersMarkerExists(): void {
		$rName = $this->minute(55, $this->rStart, 'AAAA');
		// The markers' directory holds other files too: only a connection id names a marker.
		touch($this->rHome . 'cons/' . md5(self::IP) . '_isp');
		mkdir($this->rHome . 'cons/55');

		foreach (['', md5(self::IP) . '_isp', '55', self::UUID] as $rUUID) {
			$rBody = $this->segment('TS', 'line', 'secret', self::IP, '2', (string) $this->rStart, '55_' . $rName . '_0', $rUUID, '1');
			$this->assertStringContainsString('404 Not Found', $rBody, 'uuid=' . $rUUID);
		}
	}

	public function testOnlyARecordedMinuteIsServedFromAChannelsArchive(): void {
		$rName = $this->minute(55, $this->rStart, 'AAAA');
		file_put_contents($this->rHome . 'archive/55/' . $rName . '.offset', '188');
		touch($this->rHome . 'cons/' . self::UUID);

		$rBody = $this->segment('TS', 'line', 'secret', self::IP, '2', (string) $this->rStart, '55_' . $rName . '.offset_0', self::UUID, '1');
		$this->assertStringContainsString('404 Not Found', $rBody);
	}

	// ── The scan for a catch-up's recorded minutes ────────────────────

	public function testTheScanForRecordedMinutesEndsAtThePresentOne(): void {
		$rFirst = $this->minute(55, $this->rStart, 'AAAA');
		$rSecond = $this->minute(55, $this->rStart + 60, 'BBBB');

		// A duration of centuries: one name to look for per minute of it.
		[$rPlaylist, $rEnded] = $this->request('timeshift', ['token' => $this->catchUp(['duration' => 2000000000])], [], 10);
		$this->assertTrue($rEnded, 'the scan went on past the present minute');
		$this->assertSame(['55_' . $rFirst . '_0', '55_' . $rSecond . '_0'], array_column($this->links($rPlaylist), 6));
	}

	public function testEveryMinuteUpToThePresentOneIsStillFound(): void {
		$rNow = time();
		$rNames = [$this->minute(55, $rNow - 120, 'A'), $this->minute(55, $rNow - 60, 'B'), $this->minute(55, $rNow, 'C')];

		[$rPlaylist] = $this->request('timeshift', ['token' => $this->catchUp(['start' => (string) ($rNow - 120), 'duration' => 60])]);
		$this->assertSame(array_map(static fn(string $rName): string => '55_' . $rName . '_0', $rNames), array_column($this->links($rPlaylist), 6));
	}

	// ── The connection of a request that carries a Range ──────────────

	public function testACatchUpSeekIsNotMatchedToAPlaylistViewersConnection(): void {
		$this->minute(55, $this->rStart, 'AAAA');
		// The same line, player, channel and address, watching the channel's playlist.
		$this->connection(self::EARLIER, 'hls', 55, self::IP, null);

		[$rBody] = $this->request('timeshift', ['token' => $this->catchUp(['extension' => 'ts', 'duration' => 1])], ['HTTP_RANGE' => 'bytes=0-']);
		$this->assertSame('AAAA', $rBody);
		$rRows = $this->connections();
		$this->assertNull($rRows[self::EARLIER]['pid'], 'the playlist viewer\'s connection is left as it was');
		$this->assertSame('ts', $rRows[self::UUID]['container'] ?? null, 'the recording\'s viewer has a connection of its own');
	}

	public function testAMovieSeekFromAnotherAddressIsAConnectionOfItsOwn(): void {
		file_put_contents($this->rHome . 'vod/300.mp4', 'MOVIE');
		// The line's other device on another network: the same player, the same movie.
		$this->connection(self::EARLIER, 'VOD', 300, '10.9.9.9', 0);

		[$rBody] = $this->request('vod', ['token' => $this->movie()], ['HTTP_RANGE' => 'bytes=0-']);
		$this->assertStringNotContainsString('<h2>IP_MISMATCH</h2>', $rBody);
		$this->assertSame('MOVIE', $rBody);
		$rRows = $this->connections();
		$this->assertSame('10.9.9.9', $rRows[self::EARLIER]['user_ip']);
		$this->assertSame(self::IP, $rRows[self::UUID]['user_ip'] ?? null);
	}

	public function testAMovieSeekFromTheSameAddressKeepsItsEarlierConnection(): void {
		file_put_contents($this->rHome . 'vod/300.mp4', 'MOVIE');
		$this->connection(self::EARLIER, 'VOD', 300, self::IP, 0);

		[$rBody] = $this->request('vod', ['token' => $this->movie()], ['HTTP_RANGE' => 'bytes=0-']);
		$this->assertSame('MOVIE', $rBody);
		$this->assertSame([self::EARLIER], array_keys($this->connections()));
	}

	public function testWhereASubnetIsOneViewerAMovieSeekFromItKeepsItsEarlierConnection(): void {
		$this->settings(['ip_subnet_match' => 1]);
		file_put_contents($this->rHome . 'vod/300.mp4', 'MOVIE');
		// The same device, seen at another address of its subnet.
		$this->connection(self::EARLIER, 'VOD', 300, '10.0.0.77', 0);

		[$rBody] = $this->request('vod', ['token' => $this->movie()], ['HTTP_RANGE' => 'bytes=0-']);
		$this->assertSame('MOVIE', $rBody);
		$this->assertSame([self::EARLIER], array_keys($this->connections()));
	}

	public function testWhereASubnetIsOneViewerAMovieSeekFromAnotherSubnetIsAConnectionOfItsOwn(): void {
		$this->settings(['ip_subnet_match' => 1]);
		file_put_contents($this->rHome . 'vod/300.mp4', 'MOVIE');
		$this->connection(self::EARLIER, 'VOD', 300, '10.9.9.9', 0);

		[$rBody] = $this->request('vod', ['token' => $this->movie()], ['HTTP_RANGE' => 'bytes=0-']);
		$this->assertSame('MOVIE', $rBody);
		$rRows = $this->connections();
		$this->assertSame('10.9.9.9', $rRows[self::EARLIER]['user_ip']);
		$this->assertSame(self::IP, $rRows[self::UUID]['user_ip'] ?? null);
	}

	public function testAMovieLinkIsRefusedAwayFromTheAddressOfItsOwnConnection(): void {
		file_put_contents($this->rHome . 'vod/300.mp4', 'MOVIE');
		// The connection this very link opened, from another network.
		$this->connection(self::UUID, 'VOD', 300, '10.9.9.9', 0);

		foreach ([0, 1] as $rSubnet) {
			$this->settings(['ip_subnet_match' => $rSubnet]);
			[$rBody] = $this->request('vod', ['token' => $this->movie()], ['HTTP_RANGE' => 'bytes=0-']);
			$this->assertStringContainsString('<h2>IP_MISMATCH</h2>', $rBody, 'ip_subnet_match=' . $rSubnet);
			$this->assertSame([self::UUID], array_keys($this->connections()), 'ip_subnet_match=' . $rSubnet);
		}
	}

	// ── The thumbnail's and the subtitle's links ──────────────────────

	public function testAThumbnailLinkIsOneThatNamesWhenItExpires(): void {
		file_put_contents($this->rHome . 'streams/55_.jpg', 'JPEG');
		$rThumb = fn(array $rData): string => $this->request('thumb', ['token' => $this->seal((string) json_encode($rData))])[0];

		// As auth.php makes it, then past its time.
		$this->assertSame('JPEG', $rThumb(['stream' => 55, 'expires' => time() + 5]));
		$this->assertStringContainsString('<h2>TOKEN_EXPIRED</h2>', $rThumb(['stream' => 55, 'expires' => time() - 5]));
		// The channel's catch-up link names the same stream and no time to expire at.
		$this->assertStringContainsString('<h2>TOKEN_EXPIRED</h2>', $this->request('thumb', ['token' => $this->catchUp()])[0]);
	}

	public function testASubtitleLinkIsOneThatNamesWhenItExpires(): void {
		file_put_contents($this->rHome . 'vod/300_0.srt', 'SUBTITLE');
		$rSubtitle = fn(array $rData): string => $this->request('subtitle', ['token' => $this->seal((string) json_encode($rData))])[0];

		$this->assertSame('SUBTITLE', $rSubtitle(['stream_id' => 300, 'sub_id' => 0, 'webvtt' => 0, 'expires' => time() + 5]));
		$this->assertStringContainsString('<h2>TOKEN_EXPIRED</h2>', $rSubtitle(['stream_id' => 300, 'sub_id' => 0, 'webvtt' => 0, 'expires' => time() - 5]));
		// The movie's own link names the same stream and no time to expire at.
		$this->assertStringContainsString('<h2>TOKEN_EXPIRED</h2>', $this->request('subtitle', ['token' => $this->movie()])[0]);
	}

	// ── A name that holds the separator of a segment link's fields ────

	public function testALivePlaylistsLinksKeepTheirFieldsInPlaceWhateverTheViewerIsCalled(): void {
		$rSettings = self::SETTINGS + ['encrypt_hls' => 0, 'allow_cdn_access' => 0];
		file_put_contents($this->rHome . 'streams/55_.m3u8', "#EXTM3U\n#EXTINF:10.0,\n55_7.ts\n");
		$rOnDisk = fn(...$rViewer): string => (string) HLSGenerator::generateHLS($rSettings, $this->rHome . 'streams/55_.m3u8', $rViewer[0], $rViewer[1], 55, self::UUID, self::IP, $rViewer[2], $rViewer[3], 'h264', 0, 1, null);
		$rDaemon = fn(...$rViewer): string => (string) HLSGenerator::tokenizeDaemonPlaylist("#EXTM3U\n#EXTINF:10.0,\n7.ts\n", $rSettings, $rViewer[0], $rViewer[1], 55, self::UUID, self::IP, $rViewer[2], $rViewer[3], 'h264', 0, 1, null);

		// A line's username and password, then a signed link's identifier.
		foreach ([['li/ne', 'se/cr/et', null, ''], [null, null, 3, 'cus/tomer']] as $rViewer) {
			foreach (['55_7.ts' => $rOnDisk(...$rViewer), '55_d7.ts' => $rDaemon(...$rViewer)] as $rSegment => $rPlaylist) {
				$rFields = $this->links($rPlaylist)[0] ?? [];
				// segment.php reads the address, stream, segment, uuid, server, codec and on-demand flag at 2 to 8.
				$this->assertSame([self::IP, '55', $rSegment, self::UUID, (string) SERVER_ID, 'h264', '0'], array_slice($rFields, 2), $rSegment);
				$this->assertSame($rViewer[2] === null ? 'li%2Fne' : 'HMAC#3', $rFields[0]);
			}
		}
	}

	public function testACatchUpLinkIsServedToALineWhosePasswordHoldsASlash(): void {
		$this->minute(55, $this->rStart, 'AAAA');

		[$rPlaylist] = $this->request('timeshift', ['token' => $this->catchUp(['username' => 'li/ne', 'password' => 'se/cr/et', 'duration' => 1])]);
		$this->assertCount(9, $this->links($rPlaylist)[0] ?? []);
		preg_match('#^/hls/(\S+)$#m', $rPlaylist, $rLink);
		$this->assertSame('AAAA', $this->request('segment', ['token' => $rLink[1]])[0]);
	}
}
