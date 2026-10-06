<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\ConstantsInitializer;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Module\AdminApiRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Api\AdminApiController;
use XcVm\Public\Controllers\Api\AdminAPIWrapper;
use XcVm\Tests\Support\InstallSchema;

/**
 * A save of an edit starts from the record as stored, not from the cleaned
 * row (which turns < and > into entities, CRLF into LF and trims): text the
 * edit does not send is written back as it is stored. Each service is edited
 * here through the admin API with one field, and a text column it does not
 * send keeps its stored value.
 */
final class AuditDecisionStoredEditTest extends TestCase {
	private const KEY = '11111111111111111111111111111111';

	/** Text the cleaned row would rewrite. */
	private const TEXT = '<b>&amp;</b>';

	/** Every class that may hold a database handle of an earlier test. */
	private const SERVICES = [
		\XcVm\Domain\Bouquet\BouquetService::class, \XcVm\Domain\Line\LineService::class, \XcVm\Domain\Stream\ConnectionTracker::class,
		\XcVm\Domain\Device\MagService::class, \XcVm\Domain\Device\EnigmaService::class, \XcVm\Domain\User\UserService::class,
		\XcVm\Domain\User\UserRepository::class, \XcVm\Domain\User\GroupService::class, \XcVm\Domain\Line\PackageService::class,
		\XcVm\Domain\Stream\ProviderService::class, \XcVm\Domain\Stream\CategoryService::class, \XcVm\Domain\Stream\StreamConfigRepository::class,
		\XcVm\Domain\Stream\StreamService::class, \XcVm\Domain\Stream\StreamRepository::class, \XcVm\Domain\Stream\ChannelService::class,
		\XcVm\Domain\Stream\RadioService::class, \XcVm\Domain\Vod\MovieService::class, \XcVm\Domain\Vod\EpisodeService::class,
		\XcVm\Domain\Vod\SeriesService::class, \XcVm\Domain\Epg\EpgService::class, \XcVm\Domain\Server\ServerService::class,
		\XcVm\Domain\Server\ServerRepository::class,
	];

	private TestDb $rDb;

	/** @var array<string, mixed> what this test replaced, put back after it */
	private array $rBefore = [];

	protected function setUp(): void {
		foreach (ConstantsInitializer::statuses() + ['SERVER_ID' => 1] as $rName => $rValue) {
			defined($rName) || define($rName, $rValue);
		}
		if (!defined('CACHE_TMP_PATH')) {
			$rDir = dirname(__DIR__) . '/.tmp/cache/';
			@mkdir($rDir, 0755, true);
			define('CACHE_TMP_PATH', $rDir);
		}

		$this->rDb = new TestDb();
		foreach (['users', 'users_groups', 'lines', 'lines_live', 'bouquets', 'signals', 'mag_devices', 'enigma2_devices', 'users_packages', 'providers', 'streams_categories', 'streams', 'streams_servers', 'streams_options', 'streams_episodes', 'streams_series', 'epg', 'servers'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('024_add_custom_data_to_lines'));
		$this->rDb->exec('CREATE TABLE `category_template_items` (`id` int AUTO_INCREMENT PRIMARY KEY, `template_id` int, `category_id` int)');
		$this->rDb->exec('CREATE TABLE `watch_refresh` (`id` int AUTO_INCREMENT PRIMARY KEY, `type` int, `stream_id` int, `status` int)');
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_admin`, `is_reseller`, `allowed_pages`) VALUES (1, 'Administrators', 1, 0, '[]'), (2, 'Resellers', 0, 1, '[]')");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `status`, `timezone`, `api_key`) VALUES (1, 'admin', 1, 1, '', '" . self::KEY . "')");
		$this->rDb->exec("INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_order`, `bouquet_channels`, `bouquet_movies`, `bouquet_radios`, `bouquet_series`) VALUES (1, 'One', 1, '[]', '[]', '[]', '[]')");

		$this->rBefore = ['rServers' => $GLOBALS['rServers'] ?? null, 'rSettings' => $GLOBALS['rSettings'] ?? null, 'settings' => SettingsManager::getAll(), 'request' => RequestManager::getAll()];
		$GLOBALS['rServers'] = [1 => ['is_main' => 1]];
		$GLOBALS['rSettings'] = ['download_images' => 0, 'live_streaming_pass' => 'pass'];
		SettingsManager::set(['enable_cache' => 0, 'redis_handler' => 0, 'download_images' => 0, 'cluster_kill_on_line_disable' => 1]);

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		AdminApiRegistry::reset();
		foreach (self::SERVICES as $rClass) {
			$rOwn = new ReflectionProperty($rClass, 'db');
			$this->rBefore[$rClass] = $rOwn->getValue();
			$rOwn->setValue(null, $this->rDb);
		}
	}

	protected function tearDown(): void {
		foreach (self::SERVICES as $rClass) {
			(new ReflectionProperty($rClass, 'db'))->setValue(null, $this->rBefore[$rClass]);
		}
		RequestManager::set($this->rBefore['request']);
		AdminApiRegistry::reset();
		DatabaseFactory::reset();
		SettingsManager::set($this->rBefore['settings']);
		AdminAPIWrapper::$db = null;
		AdminAPIWrapper::$rKey = null;
		$GLOBALS['rServers'] = $this->rBefore['rServers'];
		$GLOBALS['rSettings'] = $this->rBefore['rSettings'];
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rAdminUserInfo'], $GLOBALS['rPermissions'], $GLOBALS['_ERRORS']);
	}

	/**
	 * Each service: the edit action, the record's id, the one field sent, the
	 * text columns (table, id column, id, column) the edit does not send, and
	 * the rows it starts from.
	 *
	 * @return array<string, array{string, int, array<string, mixed>, list<array{string, string, int, string}>, list<string>}>
	 */
	public static function records(): array {
		$rLine = 'INSERT INTO `lines` (`id`, `member_id`, `username`, `password`, `exp_date`, `admin_enabled`, `enabled`, `bouquet`, `allowed_outputs`, `max_connections`, `is_mag`, `is_e2`, `allowed_ips`, `created_at`) VALUES ';
		$rStream = 'INSERT INTO `streams` (`id`, `type`, `category_id`, `stream_display_name`, `stream_source`, `notes`) VALUES ';

		return [
			'MAG device and its line' => ['edit_mag', 4, ['admin_notes' => 'changed'], [['mag_devices', 'mag_id', 4, 'ver'], ['lines', 'id', 9, 'reseller_notes']], [
				$rLine . "(9, 1, 'devuser', 'devpass', NULL, 1, 1, '[1]', '[1,2]', 1, 1, 0, '[]', 1700000000)",
				"INSERT INTO `mag_devices` (`mag_id`, `user_id`, `mac`, `lock_device`) VALUES (4, 9, '00:1A:79:00:00:04', 1)",
			]],
			'Enigma2 device and its line' => ['edit_enigma', 4, ['admin_notes' => 'changed'], [['enigma2_devices', 'device_id', 4, 'enigma_version'], ['lines', 'id', 10, 'reseller_notes']], [
				$rLine . "(10, 1, 'devuser', 'devpass', NULL, 1, 1, '[1]', '[1,2]', 1, 0, 1, '[]', 1700000000)",
				"INSERT INTO `enigma2_devices` (`device_id`, `user_id`, `mac`, `lock_device`) VALUES (4, 10, '00:1A:79:00:00:04', 1)",
			]],
			'user' => ['edit_user', 6, ['notes' => 'changed'], [['users', 'id', 6, 'reseller_dns']], [
				"INSERT INTO `users` (`id`, `username`, `password`, `member_group_id`, `status`, `credits`, `notes`, `api_key`, `timezone`) VALUES (6, 'reseller', 'hash', 2, 1, 10, 'old', '', '')",
			]],
			'group' => ['edit_group', 5, ['group_name' => 'Renamed'], [['users_groups', 'group_id', 5, 'total_allowed_gen_in']], [
				"INSERT INTO `users_groups` (`group_id`, `group_name`, `is_admin`, `is_reseller`, `allowed_pages`, `notice_html`, `subresellers`) VALUES (5, 'Sellers', 0, 1, '[]', '', '[]')",
			]],
			'package' => ['edit_package', 3, ['package_name' => 'Renamed'], [['users_packages', 'id', 3, 'trial_duration_in']], [
				"INSERT INTO `users_packages` (`id`, `package_name`, `groups`, `bouquets`) VALUES (3, 'Pack', '[]', '[1]')",
			]],
			'provider' => ['edit_provider', 2, ['name' => 'Renamed'], [['providers', 'id', 2, 'data']], [
				"INSERT INTO `providers` (`id`, `name`, `ip`, `port`, `username`, `password`, `enabled`) VALUES (2, 'Upstream', 'provider.example', 8080, 'name', 'secret', 1)",
			]],
			'category' => ['edit_category', 2, ['is_adult' => '1'], [['streams_categories', 'id', 2, 'category_name']], [
				"INSERT INTO `streams_categories` (`id`, `category_type`, `category_name`, `parent_id`, `cat_order`, `is_adult`) VALUES (2, 'live', 'News', 0, 4, 0)",
			]],
			'bouquet' => ['edit_bouquet', 1, ['bouquet_order' => '2'], [['bouquets', 'id', 1, 'bouquet_name']], []],
			'EPG source' => ['edit_epg', 2, ['epg_name' => 'Renamed'], [['epg', 'id', 2, 'data']], [
				"INSERT INTO `epg` (`id`, `epg_name`, `epg_file`, `days_keep`, `offset`) VALUES (2, 'Guide', 'http://epg.example/guide.xml', 7, 0)",
			]],
			'series' => ['edit_series', 70, ['plot' => 'changed'], [['streams_series', 'id', 70, 'cast']], [
				"INSERT INTO `streams_series` (`id`, `title`, `category_id`, `cover`, `backdrop_path`) VALUES (70, 'Series', '[1]', '', '[]')",
			]],
			'stream' => ['edit_stream', 20, ['notes' => 'changed'], [['streams', 'id', 20, 'stream_display_name']], [
				$rStream . "(20, 1, '[1]', 'Live', '[\"http://source.example/1.ts\"]', 'old')",
				'INSERT INTO `streams_servers` (`stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES (20, 1, NULL, 0)',
			]],
			'created channel' => ['edit_channel', 30, ['notes' => 'changed'], [['streams', 'id', 30, 'stream_display_name']], [
				"INSERT INTO `streams` (`id`, `type`, `category_id`, `stream_display_name`, `stream_source`, `notes`, `movie_properties`, `transcode_profile_id`) VALUES (30, 3, '[1]', 'Channel', '[\"s:1:/media/a.mp4\"]', 'old', '{\"type\":1}', 0)",
				'INSERT INTO `streams_servers` (`stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES (30, 1, NULL, 1)',
			]],
			'station' => ['edit_station', 40, ['notes' => 'changed'], [['streams', 'id', 40, 'custom_map']], [
				$rStream . "(40, 4, '[1]', 'Radio', '[\"http://radio.example/live\"]', 'old')",
				'INSERT INTO `streams_servers` (`stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES (40, 1, NULL, 0)',
			]],
			'movie' => ['edit_movie', 50, ['notes' => 'changed'], [['streams', 'id', 50, 'custom_map']], [
				"INSERT INTO `streams` (`id`, `type`, `category_id`, `stream_display_name`, `stream_source`, `notes`, `target_container`, `movie_properties`) VALUES (50, 2, '[1]', 'Movie', '[\"s:1:/media/movie.mp4\"]', 'old', 'mp4', '{}')",
				'INSERT INTO `streams_servers` (`stream_id`, `server_id`, `on_demand`) VALUES (50, 1, 0)',
			]],
			'episode' => ['edit_episode', 60, ['notes' => 'changed'], [['streams', 'id', 60, 'stream_display_name']], [
				"INSERT INTO `streams_series` (`id`, `title`) VALUES (70, 'Series')",
				"INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `stream_source`, `notes`, `target_container`, `movie_properties`) VALUES (60, 5, 'Episode', '[\"s:1:/media/s01e01.mkv\"]', 'old', 'mkv', '{}')",
				'INSERT INTO `streams_episodes` (`season_num`, `episode_num`, `series_id`, `stream_id`) VALUES (1, 1, 70, 60)',
				'INSERT INTO `streams_servers` (`stream_id`, `server_id`, `on_demand`) VALUES (60, 1, 0)',
			]],
			'proxy' => ['edit_proxy', 3, ['network_interface' => 'eth1'], [['servers', 'id', 3, 'server_name']], [
				"INSERT INTO `servers` (`id`, `server_name`, `server_type`, `server_ip`, `enabled`) VALUES (3, 'Proxy', 1, '192.0.2.3', 1)",
			]],
		];
	}

	/**
	 * @param array<string, mixed>                          $rEdit
	 * @param list<array{string, string, int, string}>      $rText
	 * @param list<string>                                  $rSetup
	 */
	#[DataProvider('records')]
	public function testAnEditWritesBackTheTextItDoesNotSendAsStored(string $rAction, int $rID, array $rEdit, array $rText, array $rSetup): void {
		foreach ($rSetup as $rQuery) {
			$this->rDb->exec($rQuery);
		}
		foreach ($rText as [$rTable, $rColumn, $rRowID, $rField]) {
			$this->rDb->query('UPDATE `' . $rTable . '` SET `' . $rField . '` = ? WHERE `' . $rColumn . '` = ?', self::TEXT, $rRowID);
		}

		RequestManager::set(['api_key' => self::KEY, 'action' => $rAction, 'id' => (string) $rID] + $rEdit);
		ob_start();
		try {
			(new AdminApiController())->index();
		} finally {
			$rBody = (string) ob_get_clean();
		}
		$this->assertSame('STATUS_SUCCESS', json_decode($rBody, true)['status'] ?? $rBody);

		foreach ($rText as [$rTable, $rColumn, $rRowID, $rField]) {
			$this->rDb->query('SELECT `' . $rField . '` FROM `' . $rTable . '` WHERE `' . $rColumn . '` = ?', $rRowID);
			$this->assertSame(self::TEXT, $this->rDb->get_raw_row()[$rField] ?? null, $rTable . '.' . $rField . ' as stored');
		}
	}
}
