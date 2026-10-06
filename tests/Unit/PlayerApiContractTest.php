<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Public\Controllers\Api\PlayerApiController;
use XcVm\Tests\Support\InstallSchema;

/**
 * The player_api list actions answer the same JSON
 * whether the panel serves them from the cache files or from the database.
 *
 * A panel serves both: `$rCached` is CacheReader::isReady(), so every panel
 * answers from the database from boot until the first full cache pass, and
 * from the cache files after it.
 *
 * No production seam: the controller runs in a child PHP as Public/index.php
 * runs it (the harness of AuditClientApiTest), and the cache files are written
 * by production's writers (CacheEngineCronJob::generateStreams through
 * StreamCacheBuilder; the series block of the full pass, copied here because
 * it is inline in loadCron()).
 */
final class PlayerApiContractTest extends TestCase {
	private const SETTINGS = [
		'flood_limit' => 0, 'flood_seconds' => 60, 'flood_ips_exclude' => '', 'bruteforce_username_attempts' => 10, 'bruteforce_mac_attempts' => 5,
		'bruteforce_frequency' => 300, 'auth_flood_limit' => 0, 'enable_cache' => 1, 'redis_handler' => 0, 'case_sensitive_line' => 1,
		'county_override_1st' => 0, 'show_isps' => 0, 'force_epg_timezone' => 0, 'default_timezone' => 'UTC', 'disable_player_api' => 0,
		'legacy_panel_api' => 0, 'disable_enigma2' => 0, 'keep_protocol' => 0, 'use_mdomain_in_lists' => 0, 'message_of_day' => '',
		'server_name' => 'Panel', 'vod_sort_newest' => 0, 'channel_number_type' => 'bouquet', 'live_streaming_pass' => 'client-api-test-pass',
		'secure_stream_tokens' => 0, 'debug_show_errors' => 1, 'api_redirect' => 0, 'show_category_duplicates' => 0, 'movie_year_append' => 0,
		'cloudflare' => 0, 'api_container' => 'ts',
	];

	private const PRELUDE = <<<'PHP'
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
foreach (['CACHE_TMP_PATH' => 'cache', 'FLOOD_TMP_PATH' => 'flood', 'LINES_TMP_PATH' => 'lines', 'STREAMS_TMP_PATH' => 'streams', 'SERIES_TMP_PATH' => 'series', 'EPG_PATH' => 'epg'] as $rName => $rSub) {
	define($rName, $rIn['dir'] . $rSub . '/');
}
$_SERVER = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_HOST' => 'panel.test', 'REQUEST_URI' => '/player_api.php', 'SERVER_PROTOCOL' => 'HTTP/1.1'] + $_SERVER;
$rSettings = $rIn['settings'];
$rServers = [1 => ['server_protocol' => 'http', 'enable_proxy' => 0, 'domain_name' => 'panel.test', 'server_ip' => '192.0.2.1', 'http_broadcast_port' => 80, 'https_broadcast_port' => 443, 'rtmp_port' => 8880, 'server_type' => 0, 'is_main' => 1]];
\XcVm\Core\Config\SettingsManager::set($rSettings);
\XcVm\Core\Cluster\NodeFlows::usePath($rIn['dir'] . 'flows.json');
\XcVm\Infrastructure\Database\DatabaseFactory::connect();
PHP;

	private const REQUEST = <<<'PHP'
$rCached = $rIn['cached'];
$rRequest = $rIn['request'];
\XcVm\Core\Http\RequestManager::set($rRequest);
$rController = new \XcVm\Public\Controllers\Api\PlayerApiController();
register_shutdown_function([$rController, 'shutdown']);
$rController->index();
PHP;

	/** What the cache engine writes for the streams and the series: production's writers. */
	private const WARM = <<<'PHP'
$rJob = new \XcVm\Cli\CronJobs\CacheEngineCronJob();
$rStreams = new ReflectionMethod($rJob, 'generateStreams');
$rStreams->setAccessible(true);
$db->query('SELECT `id` FROM `streams`');
$rStreams->invoke($rJob, null, null, array_map('intval', $db->get_column()));
// The series block of the full pass (CacheEngineCronJob::loadCron, default branch).
$cacheInitTime = [];
$db->query('SELECT `series_id`, MAX(`streams`.`added`) AS `last_modified` FROM `streams_episodes` LEFT JOIN `streams` ON `streams`.`id` = `streams_episodes`.`stream_id` GROUP BY `series_id`;');
foreach ($db->get_rows() as $rRow) {
	$cacheInitTime[$rRow['series_id']] = $rRow['last_modified'];
}
$db->query('SELECT * FROM `streams_series`;');
foreach ($db->result->fetchAll(\PDO::FETCH_ASSOC) as $rRow) {
	if (isset($cacheInitTime[$rRow['id']])) {
		$rRow['last_modified'] = $cacheInitTime[$rRow['id']];
	}
	\XcVm\Core\Cache\FileCache::writeAtomic(SERIES_TMP_PATH . 'series_' . $rRow['id'], igbinary_serialize($rRow));
}
echo 'warm';
PHP;

	private TestDb $rDb;

	private string $rDir;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['lines', 'mag_devices', 'activation_codes', 'output_formats', 'bouquets', 'blocked_ips', 'cluster_changes', 'streams', 'streams_types', 'streams_servers', 'streams_categories', 'streams_series', 'streams_episodes'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec("INSERT INTO `streams_types` (`type_id`, `type_name`, `type_key`, `type_output`, `live`) VALUES (1, 'Live Streams', 'live', 'live', 1), (2, 'Movies', 'movie', 'movie', 0), (3, 'Created Channels', 'created_live', 'live', 1), (4, 'Radio Stations', 'radio_streams', 'live', 1), (5, 'TV Series', 'series', 'series', 0)");
		$this->rDb->exec("INSERT INTO `output_formats` (`access_output_id`, `output_name`, `output_key`, `output_ext`) VALUES (1, 'HLS', 'm3u8', 'm3u8'), (2, 'MPEGTS', 'ts', 'ts')");
		$this->rDb->exec("INSERT INTO `streams_categories` (`id`, `category_type`, `category_name`, `cat_order`) VALUES (1, 'live', 'News', 1), (2, 'live', 'Sport', 2), (3, 'movie', 'Cinema', 3), (4, 'movie', 'Kids', 4), (5, 'series', 'Drama', 5), (6, 'series', 'Comedy', 6)");

		// Live: plain, two categories, a name with markup characters, a radio.
		$this->stream(1, 1, 'News 24', '[1]', ['stream_icon' => 'http://images.test/n.png', 'channel_id' => 'news.24', 'added' => 1700000000]);
		$this->stream(2, 1, 'Sport & News <HD>', '[2,1]', ['custom_sid' => ':0:1:', 'tv_archive_server_id' => 1, 'tv_archive_duration' => 3]);
		$this->stream(3, 4, ' Radio One ', '[1]', []);
		// Movies: with properties, without, a year, no year.
		$this->stream(10, 2, 'A Film', '[3]', ['year' => 2020, 'target_container' => 'mp4', 'added' => 1700000100, 'movie_properties' => json_encode(['movie_image' => 'http://images.test/f.jpg', 'rating' => '7.5', 'plot' => 'Plot <b>one</b>', 'cast' => 'A, B', 'director' => 'D', 'genre' => 'G', 'release_date' => '2020-01-02', 'youtube_trailer' => '', 'episode_run_time' => 90])]);
		$this->stream(11, 2, 'Other Film', '[4,3]', ['target_container' => 'mkv']);
		// Series: with a year and a run time, without either; episodes for the first.
		$this->rDb->query('INSERT INTO `streams_series` (`id`, `title`, `category_id`, `cover`, `plot`, `cast`, `rating`, `director`, `genre`, `release_date`, `last_modified`, `episode_run_time`, `backdrop_path`, `youtube_trailer`, `year`) VALUES (20, ?, ?, ?, ?, ?, 8, ?, ?, ?, 1700000200, 45, ?, ?, 2019)', 'Own Show', '[5]', 'http://images.test/s.jpg', 'A <plot>', 'X, Y', 'Dir', 'Drama', '2019-05-06', '["http://images.test/b1.jpg","http://images.test/b2.jpg"]', 'abc');
		$this->rDb->query('INSERT INTO `streams_series` (`id`, `title`, `category_id`, `backdrop_path`) VALUES (21, ?, ?, ?)', 'Bare Show', '[6,5]', '[]');
		$this->stream(30, 5, 'Own Show S01E01', '[5]', ['target_container' => 'mp4', 'added' => 1700000300]);
		$this->rDb->exec('INSERT INTO `streams_episodes` (`season_num`, `episode_num`, `series_id`, `stream_id`) VALUES (1, 1, 20, 30)');

		$this->rDb->query('INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_channels`, `bouquet_movies`, `bouquet_radios`, `bouquet_series`) VALUES (1, ?, ?, ?, ?, ?)', 'Basic', '[1,2]', '[10,11]', '[3]', '[20,21]');
		$rLine = ['username' => 'viewer', 'password' => 'secret', 'bouquet' => '[1]', 'allowed_outputs' => '[1,2]', 'allowed_ips' => '[]', 'allowed_ua' => '[]', 'access_token' => md5('viewer')];
		$this->rDb->query('INSERT INTO `lines` (`' . implode('`, `', array_keys($rLine)) . '`) VALUES (' . implode(', ', array_fill(0, count($rLine), '?')) . ')', ...array_values($rLine));

		$this->rDir = sys_get_temp_dir() . '/xcvm-api-contract-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		foreach (['cache', 'flood', 'lines', 'streams', 'series', 'epg'] as $rSub) {
			mkdir($this->rDir . $rSub, 0777, true);
		}
		$rPrelude = str_replace('%BOOTSTRAP%', var_export(dirname((string) (new ReflectionClass(TestDb::class))->getFileName(), 2) . '/bootstrap.php', true), self::PRELUDE);
		file_put_contents($this->rDir . 'request.php', $rPrelude . "\n" . self::REQUEST);
		file_put_contents($this->rDir . 'warm.php', $rPrelude . "\n" . self::WARM);
		$this->assertSame('warm', $this->child('warm.php', []));
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** @param array<string, mixed> $rColumns */
	private function stream(int $rID, int $rType, string $rName, string $rCategories, array $rColumns): void {
		$rColumns = ['id' => $rID, 'type' => $rType, 'stream_display_name' => $rName, 'category_id' => $rCategories] + $rColumns;
		$this->rDb->query('INSERT INTO `streams` (`' . implode('`, `', array_keys($rColumns)) . '`) VALUES (' . implode(', ', array_fill(0, count($rColumns), '?')) . ')', ...array_values($rColumns));
	}

	/** @param array<string, mixed> $rIn */
	private function child(string $rScript, array $rIn): string {
		$rIn += ['schema' => $this->rDb->schema(), 'dir' => $this->rDir, 'settings' => self::SETTINGS];
		$rProc = proc_open([...xcvm_test_child_php(), '-d', 'display_errors=stderr', $this->rDir . $rScript, (string) json_encode($rIn)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		proc_close($rProc);
		$this->assertSame('', $rErr, $rOut);
		return $rOut;
	}

	/** @param array<string, mixed> $rRequest */
	private function answer(bool $rCached, array $rRequest): string {
		return $this->child('request.php', ['cached' => $rCached, 'request' => ['username' => 'viewer', 'password' => 'secret'] + $rRequest]);
	}

	/** @return array<string, array{0: array<string, mixed>}> */
	public static function listRequests(): array {
		return [
			'live' => [['action' => 'get_live_streams']],
			'live by category' => [['action' => 'get_live_streams', 'category_id' => '1']],
			'vod' => [['action' => 'get_vod_streams']],
			'vod by category' => [['action' => 'get_vod_streams', 'category_id' => '3']],
			'series' => [['action' => 'get_series']],
			'series by category' => [['action' => 'get_series', 'category_id' => '5']],
		];
	}

	/**
	 * A decoded answer without the differences between the two modes that are
	 * known and pinned by the tests below, so that any other difference fails.
	 *
	 * @param list<array<string, mixed>> $rRows
	 * @return list<array<string, mixed>>
	 */
	private static function withoutKnownDifferences(string $rAction, array $rRows): array {
		foreach ($rRows as &$rRow) {
			// 1. The database answer is escaped and trimmed (Database::clean_row), the cache answer is not.
			array_walk_recursive($rRow, static function (&$rValue): void {
				if (is_string($rValue)) {
					$rValue = \XcVm\Core\Database\Database::parseCleanValue($rValue);
				}
			});
			// 2. A live channel's `added`: a number from the cache, a string from the database.
			if ($rAction === 'get_live_streams') {
				$rRow['added'] = (string) $rRow['added'];
			}
			if ($rAction === 'get_series') {
				// 3. No year: null from the database, '' from the cache. No run time: 0 and '0'.
				$rRow['year'] = (string) $rRow['year'];
				$rRow['episode_run_time'] = (string) $rRow['episode_run_time'];
				// 4. The cache answers with the newest episode's time, the database with the series row's.
				unset($rRow['last_modified']);
			}
		}
		return $rRows;
	}

	/** @param array<string, mixed> $rRequest */
	#[DataProvider('listRequests')]
	public function testAListIsTheSameFromTheCacheAndFromTheDatabase(array $rRequest): void {
		$rFromDb = json_decode($this->answer(false, $rRequest), true);
		$rFromCache = json_decode($this->answer(true, $rRequest), true);
		$this->assertNotSame([], $rFromDb);
		$this->assertSame(self::withoutKnownDifferences($rRequest['action'], $rFromDb), self::withoutKnownDifferences($rRequest['action'], $rFromCache));
	}

	/** The cache answer, the one an app normally gets: every field and its type. */
	public function testTheVodListAnApplicationGets(): void {
		$this->assertSame(
			[
				['num' => 1, 'name' => 'A Film (2020)', 'title' => 'A Film', 'year' => '2020', 'stream_type' => 'movie', 'stream_id' => 10, 'stream_icon' => 'http://images.test/f.jpg', 'rating' => 7.5, 'rating_5based' => 3.8, 'added' => '1700000100', 'plot' => 'Plot <b>one</b>', 'cast' => 'A, B', 'director' => 'D', 'genre' => 'G', 'release_date' => '2020-01-02', 'youtube_trailer' => '', 'episode_run_time' => 90, 'category_id' => '3', 'category_ids' => [3], 'container_extension' => 'mp4', 'custom_sid' => '', 'direct_source' => ''],
			],
			array_slice(json_decode($this->answer(true, ['action' => 'get_vod_streams', 'category_id' => '3']), true), 0, 1)
		);
	}

	// ── The known differences, as they are today ────────────────────

	public function testADatabaseAnswerIsEscapedAndTrimmedAndACacheAnswerIsNot(): void {
		$rRequest = ['action' => 'get_live_streams'];
		$this->assertSame(['News 24', 'Sport & News &lt;HD&gt;', 'Radio One'], array_column(json_decode($this->answer(false, $rRequest), true), 'name'));
		$this->assertSame(['News 24', 'Sport & News <HD>', ' Radio One '], array_column(json_decode($this->answer(true, $rRequest), true), 'name'));
	}

	public function testALiveChannelsAddedTimeIsAStringFromTheDatabaseAndANumberFromTheCache(): void {
		$rRequest = ['action' => 'get_live_streams', 'category_id' => '1'];
		$this->assertSame('1700000000', json_decode($this->answer(false, $rRequest), true)[0]['added']);
		$rFromCache = json_decode($this->answer(true, $rRequest), true)[0];
		$this->assertSame(1700000000, $rFromCache['added']);
		// The cache answer, the one an app normally gets: every field and its type.
		$this->assertSame(['num' => 1, 'name' => 'News 24', 'stream_type' => 'live', 'stream_id' => 1, 'stream_icon' => 'http://images.test/n.png', 'epg_channel_id' => 'news.24', 'added' => 1700000000, 'custom_sid' => '', 'tv_archive' => 0, 'direct_source' => '', 'tv_archive_duration' => 0, 'category_id' => '1', 'category_ids' => [1], 'thumbnail' => ''], $rFromCache);
	}

	public function testASeriesWithoutAYearOrARunTimeIsAnsweredDifferently(): void {
		$rRequest = ['action' => 'get_series', 'category_id' => '6'];
		$rFromDb = json_decode($this->answer(false, $rRequest), true)[0];
		$rFromCache = json_decode($this->answer(true, $rRequest), true)[0];
		$this->assertSame([null, 0], [$rFromDb['year'], $rFromDb['episode_run_time']]);
		$this->assertSame(['', '0'], [$rFromCache['year'], $rFromCache['episode_run_time']]);
	}

	public function testASeriesLastModifiedIsItsNewestEpisodesFromTheCacheAndItsOwnFromTheDatabase(): void {
		$rRequest = ['action' => 'get_series', 'category_id' => '5'];
		$this->assertSame('1700000200', json_decode($this->answer(false, $rRequest), true)[0]['last_modified']);
		$rFromCache = json_decode($this->answer(true, $rRequest), true)[0];
		$this->assertSame('1700000300', $rFromCache['last_modified']);
		// The cache answer: every field and its type.
		$this->assertSame(['num' => 1, 'name' => 'Own Show (2019)', 'title' => 'Own Show', 'year' => '2019', 'stream_type' => 'series', 'series_id' => 20, 'cover' => 'http://images.test/s.jpg', 'plot' => 'A <plot>', 'cast' => 'X, Y', 'director' => 'Dir', 'genre' => 'Drama', 'release_date' => '2019-05-06', 'releaseDate' => '2019-05-06', 'last_modified' => '1700000300', 'rating' => '8', 'rating_5based' => 4, 'backdrop_path' => ['http://images.test/b1.jpg', 'http://images.test/b2.jpg'], 'youtube_trailer' => 'abc', 'episode_run_time' => '45', 'category_id' => '5', 'category_ids' => [5]], $rFromCache);
	}
}
