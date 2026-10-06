<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\PlaylistGenerator;
use XcVm\Tests\Support\InstallSchema;

/**
 * "Cache Playlists for" keeps a line's playlist for that many seconds, under a
 * name made of everything the list is built from: another host or scheme, a
 * changed password or another `key` gets a list of its own. A list is stored
 * whole or not at all; with the setting at 0 nothing is stored.
 *
 * A download ends in exit(), so each runs PlaylistGenerator::generate() in a
 * child PHP over the test's schema.
 */
final class PlaylistCacheTest extends TestCase {
	private const SETTINGS = [
		'enable_cache' => 0, 'cache_playlists' => 300, 'encrypt_playlist' => 0, 'encrypt_playlist_restreamer' => 0, 'use_mdomain_in_lists' => 0,
		'keep_protocol' => 1, 'live_streaming_pass' => 'playlist-test-pass', 'secure_stream_tokens' => 0, 'channel_number_type' => 'bouquet',
		'vod_sort_newest' => 0, 'redis_handler' => 0, 'case_sensitive_line' => 1, 'debug_show_errors' => 1, 'county_override_1st' => 0,
		'show_isps' => 0, 'server_name' => 'Panel', 'playlist_from_mysql' => 0, 'cloudflare' => 0,
	];

	private const CHILD = <<<'PHP'
<?php
final class XC_VM {
	public static function db_connect(bool $rMigrate = false) {
		return TestDb::connect($GLOBALS['rIn']['schema']);
	}
}

require %BOOTSTRAP%;

$rIn = json_decode($argv[1], true);

define('SERVER_ID', 1);
defined('XC_VM_VERSION') || define('XC_VM_VERSION', 'test');
foreach (['CACHE_TMP_PATH' => 'cache', 'STREAMS_TMP_PATH' => 'streams', 'SERIES_TMP_PATH' => 'series', 'LINES_TMP_PATH' => 'lines', 'PLAYLIST_PATH' => 'playlists'] as $rName => $rSub) {
	defined($rName) || define($rName, $rIn['dir'] . $rSub . '/');
}
$_SERVER = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_HOST' => $rIn['host'], 'SERVER_PORT' => $rIn['port'], 'REQUEST_URI' => '/get.php'] + ($rIn['port'] == 443 ? ['HTTPS' => 'on'] : []) + $_SERVER;
$rSettings = $rIn['settings'];
$rServers = [1 => ['server_protocol' => 'http', 'enable_proxy' => 0, 'domain_name' => '', 'server_ip' => '192.0.2.1', 'http_broadcast_port' => 80, 'https_broadcast_port' => 443, 'rtmp_port' => 8880, 'server_type' => 0, 'is_main' => 1]];
\XcVm\Core\Config\SettingsManager::set($rSettings);
\XcVm\Infrastructure\Database\DatabaseFactory::connect();

$rUserInfo = \XcVm\Domain\User\UserRepository::getUserInfo(null, 'viewer', $rIn['password'], true);
\XcVm\Domain\Stream\PlaylistGenerator::generate($rUserInfo, 'm3u_plus', 'ts', $rIn['key'] === null ? null : explode(',', $rIn['key']), $rIn['nocache']);
PHP;

	private TestDb $rDb;

	private string $rDir;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['lines', 'mag_devices', 'bouquets', 'streams', 'streams_types', 'streams_categories', 'output_devices', 'output_formats'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		foreach (['streams_types', 'output_devices', 'output_formats'] as $rTable) {
			$this->rDb->exec(self::installRows($rTable));
		}
		$this->rDb->exec("INSERT INTO `streams_categories` (`id`, `category_type`, `category_name`, `parent_id`, `cat_order`) VALUES (1, 'live', 'News', 0, 1), (3, 'movie', 'Films', 0, 1)");
		$this->rDb->exec("INSERT INTO `streams` (`id`, `type`, `category_id`, `stream_display_name`, `stream_icon`, `order`, `target_container`) VALUES (7, 1, '[1]', 'Channel Seven', '', 1, NULL), (9, 1, '[1]', 'Channel Nine', '', 2, NULL), (30, 2, '[3]', 'A Film', '', 1, 'mp4')");
		$this->rDb->query('INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_channels`, `bouquet_movies`, `bouquet_radios`, `bouquet_series`) VALUES (1, ?, ?, ?, ?, ?)', 'Basic', '[7,9]', '[30]', '[]', '[]');
		$this->rDb->query('INSERT INTO `lines` (`username`, `password`, `bouquet`, `allowed_outputs`, `allowed_ips`, `allowed_ua`, `access_token`, `enabled`) VALUES (?, ?, ?, ?, ?, ?, ?, 1)', 'viewer', 'secret', '[1]', '[1,2]', '[]', '[]', ''); // no token: the URLs carry the password

		$this->rDir = sys_get_temp_dir() . '/xcvm-playlist-cache-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		foreach (['cache', 'streams', 'series', 'lines', 'playlists'] as $rSub) {
			mkdir($this->rDir . $rSub, 0777, true);
		}
		file_put_contents($this->rDir . 'child.php', str_replace('%BOOTSTRAP%', var_export(dirname(__DIR__) . '/bootstrap.php', true), self::CHILD));
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** The install's rows of a table: its INSERT in database.sql. */
	private static function installRows(string $rTable): string {
		$rSql = (string) file_get_contents(MAIN_HOME . 'bin/install/database.sql');
		$rStart = strpos($rSql, 'INSERT INTO `' . $rTable . '`');
		return substr($rSql, $rStart, strpos($rSql, ";\n", $rStart) - $rStart);
	}

	/** One download: the playlist body. */
	private function download(array $rSettings = [], string $rHost = 'panel.test', int $rPort = 80, ?string $rKey = null, bool $rNoCache = false, string $rPassword = 'secret'): string {
		$rIn = ['schema' => $this->rDb->schema(), 'dir' => $this->rDir, 'settings' => $rSettings + self::SETTINGS, 'host' => $rHost, 'port' => $rPort, 'key' => $rKey, 'nocache' => $rNoCache, 'password' => $rPassword];
		$rProc = proc_open([...xcvm_test_child_php(), '-d', 'display_errors=stderr', $this->rDir . 'child.php', (string) json_encode($rIn)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		proc_close($rProc);
		$this->assertSame('', $rErr, $rOut);
		$this->assertStringStartsWith('#EXTM3U', $rOut);
		return $rOut;
	}

	/** @return list<string> the files in the playlist folder, temporary ones included */
	private function stored(): array {
		return array_values(array_diff(scandir($this->rDir . 'playlists'), ['.', '..']));
	}

	public function testWithTheSettingAt0NothingIsStored(): void {
		$rList = $this->download(['cache_playlists' => 0]);

		$this->assertStringContainsString('Channel Nine', $rList);
		$this->assertStringContainsString('A Film', $rList);
		$this->assertSame([], $this->stored());
	}

	public function testAStoredListIsServedForItsTimeAndNocacheRefreshesIt(): void {
		$rFirst = $this->download();
		$this->assertCount(1, $this->stored());
		$this->assertStringStartsNotWith('.', $this->stored()[0]);

		$this->rDb->exec("UPDATE `streams` SET `stream_display_name` = 'Channel Renamed' WHERE `id` = 9");
		$this->assertSame($rFirst, $this->download(), 'served from the stored list');

		$rFresh = $this->download([], 'panel.test', 80, null, true);
		$this->assertStringContainsString('Channel Renamed', $rFresh);
		$this->assertSame($rFresh, $this->download(), 'the stored list was refreshed');
	}

	public function testEachHostSchemeKeyAndPasswordGetsItsOwnList(): void {
		foreach ([1, 300] as $rTTL) {
			$rSettings = ['cache_playlists' => $rTTL];
			$this->download($rSettings);

			$this->assertStringContainsString('//other.test', $this->download($rSettings, 'other.test'), 'another host, TTL ' . $rTTL);
			$this->assertStringContainsString('https://panel.test', $this->download($rSettings, 'panel.test', 443), 'https, TTL ' . $rTTL);
			$this->assertStringNotContainsString('A Film', $this->download($rSettings, 'panel.test', 80, 'live'), 'key=live, TTL ' . $rTTL);

			$this->rDb->exec("UPDATE `lines` SET `password` = 'changed' WHERE `username` = 'viewer'");
			$rAfter = $this->download($rSettings, 'panel.test', 80, null, false, 'changed');
			$this->assertStringContainsString('/viewer/changed/', $rAfter, 'a changed password, TTL ' . $rTTL);
			$this->assertStringNotContainsString('/secret/', $rAfter);
			$this->rDb->exec("UPDATE `lines` SET `password` = 'secret' WHERE `username` = 'viewer'");
		}
	}

	public function testOnlyAPositiveSettingWithTheFreeSpaceFloorStores(): void {
		$rMay = new ReflectionMethod(PlaylistGenerator::class, 'mayCache');
		$rMay->setAccessible(true);
		$this->assertTrue($rMay->invoke(null, 300, 1e12));
		$this->assertTrue($rMay->invoke(null, '1', 1e12));
		$this->assertFalse($rMay->invoke(null, 300, 1e9), 'under 2 GiB free');
		$this->assertFalse($rMay->invoke(null, 0, 1e12));
		$this->assertFalse($rMay->invoke(null, 300, false));
	}

	public function testAShortWriteDropsTheStoredCopy(): void {
		$rPath = $this->rDir . 'playlists/.copy.tmp';
		$rFile = fopen('/dev/full', 'w');
		$rPut = new ReflectionMethod(PlaylistGenerator::class, 'cachePut');
		$rPut->setAccessible(true);
		$rArgs = [&$rFile, $rPath, str_repeat('x', 1 << 16)];
		$rPut->invokeArgs(null, $rArgs);
		$this->assertNull($rFile);
	}
}
