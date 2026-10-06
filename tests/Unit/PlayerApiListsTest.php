<?php

use PHPUnit\Framework\TestCase;
use XcVm\Tests\Support\InstallSchema;

/**
 * get_vod_streams and get_live_streams read from the database (cache off) list
 * the line's streams in the line's order, each once, a category filter and a
 * page included; a movie's properties are its own, whatever came before it.
 *
 * A request ends in exit(), so each one runs in a child PHP, as in
 * AuditClientApiTest.
 */
final class PlayerApiListsTest extends TestCase {
	private const SETTINGS = [
		'flood_limit' => 0, 'flood_seconds' => 60, 'flood_ips_exclude' => '', 'bruteforce_username_attempts' => 10, 'bruteforce_mac_attempts' => 5,
		'bruteforce_frequency' => 300, 'auth_flood_limit' => 0, 'enable_cache' => 0, 'redis_handler' => 0, 'case_sensitive_line' => 1,
		'county_override_1st' => 0, 'show_isps' => 0, 'force_epg_timezone' => 0, 'default_timezone' => 'UTC', 'disable_player_api' => 0,
		'legacy_panel_api' => 0, 'disable_enigma2' => 0, 'keep_protocol' => 0, 'use_mdomain_in_lists' => 0, 'message_of_day' => '',
		'server_name' => 'Panel', 'vod_sort_newest' => 0, 'channel_number_type' => 'bouquet', 'live_streaming_pass' => 'lists-test-pass',
		'secure_stream_tokens' => 0, 'debug_show_errors' => 1, 'api_redirect' => 0, 'show_category_duplicates' => 0,
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
foreach (['CACHE_TMP_PATH' => 'cache', 'FLOOD_TMP_PATH' => 'flood', 'LINES_TMP_PATH' => 'lines', 'STREAMS_TMP_PATH' => 'streams', 'EPG_PATH' => 'epg'] as $rName => $rSub) {
	define($rName, $rIn['dir'] . $rSub . '/');
}
$_SERVER = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_HOST' => 'panel.test', 'REQUEST_URI' => '/player_api.php', 'SERVER_PROTOCOL' => 'HTTP/1.1'] + $_SERVER;
$rSettings = $rIn['settings'];
$rServers = [1 => ['server_protocol' => 'http', 'enable_proxy' => 0, 'domain_name' => 'panel.test', 'server_ip' => '192.0.2.1', 'http_broadcast_port' => 80, 'https_broadcast_port' => 443, 'rtmp_port' => 8880, 'server_type' => 0, 'is_main' => 1]];
$rCached = false;
$rRequest = $rIn['request'];
\XcVm\Core\Config\SettingsManager::set($rSettings);
\XcVm\Core\Http\RequestManager::set($rRequest);
\XcVm\Core\Cluster\NodeFlows::usePath($rIn['dir'] . 'flows.json');
\XcVm\Infrastructure\Database\DatabaseFactory::connect();

$rController = new \XcVm\Public\Controllers\Api\PlayerApiController();
register_shutdown_function([$rController, 'shutdown']);
$rController->index();
PHP;

	private TestDb $rDb;

	private string $rDir;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['lines', 'mag_devices', 'activation_codes', 'output_formats', 'bouquets', 'blocked_ips', 'cluster_changes', 'streams', 'streams_types'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec("INSERT INTO `streams_types` (`type_id`, `type_name`, `type_key`, `type_output`, `live`) VALUES (1, 'Live Streams', 'live', 'live', 1), (2, 'Movies', 'movie', 'movie', 0), (3, 'Created Channels', 'created_live', 'live', 1), (4, 'Radio Stations', 'radio_streams', 'live', 1), (5, 'TV Series', 'series', 'series', 0)");

		// Movies, listed out of id order. 10 has no properties right after 30, which has some;
		// 40 has broken JSON; 20 is in two categories.
		$this->stream(30, 2, '[3]', 'Thirty', '{"rating":"8.2","plot":"thirty plot","movie_image":""}', 3);
		$this->stream(10, 2, '[3]', 'Ten', null, 1);
		$this->stream(20, 2, '[3,4]', 'Twenty', '{"rating":"6","plot":"twenty plot"}', 4);
		$this->stream(40, 2, '[4]', 'Forty', '{"rating":', 2);
		$this->stream(50, 2, '[3]', 'Fifty', '{"plot":"fifty plot"}', 5);
		// Channels and a radio station; 7 is in the line's channels and radios both.
		$this->stream(9, 1, '[1]', 'Nine', null, 2);
		$this->stream(7, 1, '[1]', 'Seven', null, 3);
		$this->stream(8, 4, '[2]', 'Eight', null, 1);

		$this->rDb->query('INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_channels`, `bouquet_movies`, `bouquet_radios`, `bouquet_series`) VALUES (1, ?, ?, ?, ?, ?)', 'Basic', '[9,7]', '[30,10,20,40,50]', '[8,7]', '[]');
		$this->rDb->query('INSERT INTO `lines` (`username`, `password`, `bouquet`, `allowed_outputs`, `allowed_ips`, `allowed_ua`, `access_token`) VALUES (?, ?, ?, ?, ?, ?, ?)', 'viewer', 'secret', '[1]', '[1,2]', '[]', '[]', str_repeat('a', 32));

		$this->rDir = sys_get_temp_dir() . '/xcvm-api-lists-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		foreach (['cache', 'flood', 'lines', 'streams', 'epg'] as $rSub) {
			mkdir($this->rDir . $rSub, 0777, true);
		}
		file_put_contents($this->rDir . 'child.php', str_replace('%BOOTSTRAP%', var_export(dirname(__DIR__) . '/bootstrap.php', true), self::CHILD));
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function stream(int $rId, int $rType, string $rCategories, string $rName, ?string $rProperties, int $rOrder): void {
		$this->rDb->query('INSERT INTO `streams` (`id`, `type`, `category_id`, `stream_display_name`, `movie_properties`, `order`, `target_container`, `added`, `year`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', $rId, $rType, $rCategories, $rName, $rProperties, $rOrder, 'mp4', 1700000000 + $rId, 2000 + $rId);
	}

	/** @return list<array<string, mixed>> the list a request answered */
	private function list(string $rAction, array $rRequest = [], array $rSettings = []): array {
		$rIn = ['schema' => $this->rDb->schema(), 'dir' => $this->rDir, 'settings' => $rSettings + self::SETTINGS, 'request' => ['username' => 'viewer', 'password' => 'secret', 'action' => $rAction] + $rRequest];
		$rProc = proc_open([...xcvm_test_child_php(), '-d', 'display_errors=stderr', $this->rDir . 'child.php', (string) json_encode($rIn)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		proc_close($rProc);
		$this->assertSame('', $rErr, $rOut);
		$rList = json_decode($rOut, true);
		$this->assertIsArray($rList, $rOut);
		return $rList;
	}

	/** @return list<string> "<stream_id> <category_id> <rating> <plot>" for each movie listed */
	private function movies(array $rRequest = [], array $rSettings = []): array {
		return array_map(static fn(array $rRow): string => $rRow['stream_id'] . ' ' . $rRow['category_id'] . ' ' . json_encode($rRow['rating']) . ' ' . json_encode($rRow['plot']), $this->list('get_vod_streams', $rRequest, $rSettings));
	}

	public function testTheMoviesAreListedInTheLinesOrderWithTheirOwnProperties(): void {
		$this->assertSame(['30 3 8.2 "thirty plot"', '10 3 0 null', '20 3 6 "twenty plot"', '40 4 0 null', '50 3 0 "fifty plot"'], $this->movies());
		$this->assertSame(['30 3 8.2 "thirty plot"', '10 3 0 null', '20 3 6 "twenty plot"', '20 4 6 "twenty plot"', '40 4 0 null', '50 3 0 "fifty plot"'], $this->movies([], ['show_category_duplicates' => 1]));
	}

	public function testACategoryAndAPageKeepTheOrder(): void {
		$this->assertSame(['20 4 6 "twenty plot"', '40 4 0 null'], $this->movies(['category_id' => 4]));
		$this->assertSame(['30 3 8.2 "thirty plot"', '10 3 0 null', '20 3 6 "twenty plot"', '50 3 0 "fifty plot"'], $this->movies(['category_id' => 3]));
		$this->assertSame(['10 3 0 null', '20 3 6 "twenty plot"'], $this->movies(['params' => ['offset' => 1, 'items_per_page' => 2]]));
	}

	public function testManualNumberingListsByTheOrderColumn(): void {
		$this->assertSame(['10 3 0 null', '40 4 0 null', '30 3 8.2 "thirty plot"', '20 3 6 "twenty plot"', '50 3 0 "fifty plot"'], $this->movies([], ['channel_number_type' => 'manual']));
	}

	public function testChannelsAreListedOnceInTheLinesOrder(): void {
		$rIds = static fn(array $rList): array => array_column($rList, 'stream_id');
		$this->assertSame([9, 7, 8], $rIds($this->list('get_live_streams')));
		$this->assertSame([8, 9, 7], $rIds($this->list('get_live_streams', [], ['channel_number_type' => 'manual'])));
	}
}
