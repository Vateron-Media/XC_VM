<?php

use PHPUnit\Framework\TestCase;
use XcVm\Public\Controllers\Api\PlaylistApiController;
use XcVm\Tests\Support\InstallSchema;

/**
 * A playlist names every stored image by its public URL. An entry whose
 * category is unknown (the stream has none, or names one that was deleted)
 * goes through a second copy of the line that fills the template, and that
 * copy must resolve the `s:<server>:` reference of a stored image as the
 * first does.
 *
 * The generator ends the request itself, so each playlist is built in a child
 * PHP over the test's schema (the pattern of AuditClientApiTest).
 */
final class PlaylistLogoUrlTest extends TestCase {
	private const SETTINGS = [
		'flood_limit' => 0, 'flood_seconds' => 60, 'flood_ips_exclude' => '', 'bruteforce_username_attempts' => 10, 'bruteforce_mac_attempts' => 5,
		'bruteforce_frequency' => 300, 'auth_flood_limit' => 0, 'enable_cache' => 0, 'redis_handler' => 0, 'case_sensitive_line' => 1,
		'county_override_1st' => 0, 'show_isps' => 0, 'default_timezone' => 'UTC', 'keep_protocol' => 0, 'use_mdomain_in_lists' => 1,
		'server_name' => 'Panel', 'vod_sort_newest' => 0, 'channel_number_type' => 'bouquet', 'live_streaming_pass' => 'playlist-test-pass',
		'secure_stream_tokens' => 0, 'debug_show_errors' => 1, 'cloudflare' => 0, 'movie_year_append' => 0, 'cache_playlists' => 0,
		'playlist_from_mysql' => 1, 'encrypt_playlist' => 0, 'encrypt_playlist_restreamer' => 0, 'legacy_get' => 1, 'disable_playlist' => 0,
		'disable_playlist_restreamer' => 0, 'restrict_playlists' => 0, 'max_simultaneous_downloads' => 0, 'rtmp_random' => 0, 'block_proxies' => 0,
		'allow_countries' => ['ALL'], 'disallow_empty_user_agents' => 0, 'verify_host' => 0, 'ip_subnet_match' => 0,
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
foreach (['CACHE_TMP_PATH' => 'cache', 'FLOOD_TMP_PATH' => 'flood', 'LINES_TMP_PATH' => 'lines', 'STREAMS_TMP_PATH' => 'streams', 'SERIES_TMP_PATH' => 'series', 'EPG_PATH' => 'epg', 'PLAYLIST_PATH' => 'playlists'] as $rName => $rSub) {
	defined($rName) || define($rName, $rIn['dir'] . $rSub . '/');
}
$_SERVER = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_HOST' => 'panel.test', 'REQUEST_URI' => '/get.php', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'HTTP_USER_AGENT' => 'test/1.0', 'SERVER_PORT' => 80] + $_SERVER;
$rSettings = $rIn['settings'];
$rServers = [1 => ['id' => 1, 'server_protocol' => 'http', 'enable_proxy' => 0, 'domain_name' => 'panel.test', 'server_ip' => '192.0.2.1', 'http_broadcast_port' => 80, 'https_broadcast_port' => 443, 'rtmp_port' => 8880, 'server_type' => 0, 'is_main' => 1, 'domains' => ['urls' => ['panel.test']]]];
$rCached = false;
\XcVm\Core\Config\SettingsManager::set($rSettings);
\XcVm\Core\Http\RequestManager::set($rIn['request']);
\XcVm\Core\Cluster\NodeFlows::usePath($rIn['dir'] . 'flows.json');
\XcVm\Infrastructure\Database\DatabaseFactory::connect();
$db = \XcVm\Infrastructure\Database\DatabaseFactory::get();
$rController = new $rIn['controller']();
register_shutdown_function([$rController, 'shutdown']);
$rController->index();
PHP;

	private TestDb $rDb;

	private string $rDir;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['streams', 'streams_types', 'streams_series', 'streams_episodes', 'streams_categories', 'streams_servers', 'bouquets', 'lines', 'mag_devices', 'output_devices', 'output_formats', 'blocked_ips'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$rSql = (string) file_get_contents(MAIN_HOME . 'bin/install/database.sql');
		foreach (['streams_types', 'output_devices', 'output_formats'] as $rTable) {
			preg_match('/INSERT INTO `' . $rTable . '` .*?;\n/s', $rSql, $rM);
			$this->rDb->exec($rM[0]);
		}
		$this->rDb->exec("INSERT INTO `streams_categories` (`id`, `category_type`, `category_name`, `parent_id`, `cat_order`, `is_adult`) VALUES (1, 'live', 'News', 0, 1, 0), (2, 'movie', 'Films', 0, 2, 0)");
		foreach ([
			[1, 1, '[1]', 'In a category', 's:1:/images/a.png', ''],
			[2, 1, '[]', 'In no category', 's:1:/images/b.png', ''],
			[3, 1, '[99]', 'In a deleted category', 's:1:/images/c.png', ''],
			[4, 2, '[]', 'Movie in no category', '', '{"movie_image":"s:1:\/images\/e.jpg"}'],
			[5, 1, null, 'Remote logo, no category', 'https://logos.example.net/f.png', ''],
		] as [$rID, $rType, $rCategory, $rName, $rIcon, $rProperties]) {
			$this->rDb->query('INSERT INTO `streams` (`id`, `type`, `category_id`, `stream_display_name`, `stream_source`, `stream_icon`, `movie_properties`, `target_container`, `order`, `added`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', $rID, $rType, $rCategory, $rName, '[]', $rIcon, $rProperties, $rType == 2 ? 'mp4' : null, $rID, 1759700000);
		}
		$this->rDb->exec("INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_channels`, `bouquet_movies`, `bouquet_radios`, `bouquet_series`, `bouquet_order`) VALUES (1, 'All', '[1,2,3,5]', '[4]', '[]', '[]', 1)");
		$this->rDb->exec("INSERT INTO `lines` (`id`, `username`, `password`, `admin_enabled`, `enabled`, `bouquet`, `allowed_outputs`, `max_connections`, `allowed_ips`, `allowed_ua`, `created_at`) VALUES (1, 'viewer', 'secret', 1, 1, '[1]', '[1,2,3]', 1, '[]', '[]', 1759700000)");

		$this->rDir = sys_get_temp_dir() . '/xcvm-playlist-logo-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		foreach (['cache', 'flood', 'lines', 'streams', 'series', 'epg', 'playlists'] as $rSub) {
			mkdir($this->rDir . $rSub, 0777, true);
		}
		file_put_contents($this->rDir . 'child.php', str_replace('%BOOTSTRAP%', var_export(MAIN_HOME . '../tests/bootstrap.php', true), self::CHILD));
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function playlist(string $rDevice): string {
		$rIn = ['schema' => $this->rDb->schema(), 'dir' => $this->rDir, 'settings' => self::SETTINGS, 'request' => ['username' => 'viewer', 'password' => 'secret', 'type' => $rDevice, 'output' => 'ts'], 'controller' => PlaylistApiController::class];
		$rProc = proc_open([...xcvm_test_child_php(), '-d', 'display_errors=stderr', $this->rDir . 'child.php', (string) json_encode($rIn)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		proc_close($rProc);
		$this->assertSame('', $rErr, $rOut);
		return $rOut;
	}

	public function testAnEntryInNoCategoryCarriesThePublicUrlOfItsStoredLogo(): void {
		$rList = $this->playlist('m3u_plus');
		$this->assertStringContainsString('tvg-name="In a category" tvg-logo="http://panel.test:80/images/a.png" group-title="News"', $rList);
		$this->assertStringContainsString('tvg-name="In no category" tvg-logo="http://panel.test:80/images/b.png"', $rList);
		$this->assertStringContainsString('tvg-name="In a deleted category" tvg-logo="http://panel.test:80/images/c.png"', $rList);
		$this->assertStringContainsString('tvg-name="Movie in no category" tvg-logo="http://panel.test:80/images/e.jpg"', $rList);
		$this->assertStringContainsString('tvg-logo="https://logos.example.net/f.png"', $rList);
		$this->assertStringNotContainsString('"s:1:', $rList, 'no internal image reference reaches a viewer');
	}

	public function testTheSparkListDoesToo(): void {
		$this->assertStringNotContainsString('"s:1:', $this->playlist('spark'));
	}

	public function testAListWithoutLogosIsUnchanged(): void {
		$this->assertSame(5, substr_count($this->playlist('m3u'), '#EXTINF:-1,'));
	}
}
