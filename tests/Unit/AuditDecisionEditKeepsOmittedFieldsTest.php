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
 * An edit through the admin API keeps every field the request leaves out:
 * the panel's forms post all of them, a request of the API only the ones it
 * changes. Each action is edited here with one field, and every other field
 * of the record (and of the rows that belong to it) stays as stored.
 */
final class AuditDecisionEditKeepsOmittedFieldsTest extends TestCase {
	private const KEY = '11111111111111111111111111111111';

	/** Every class that may hold a database handle of an earlier test. */
	private const SERVICES = [
		\XcVm\Domain\Bouquet\BouquetService::class, \XcVm\Domain\Line\LineService::class, \XcVm\Domain\Stream\ConnectionTracker::class,
		\XcVm\Domain\Device\MagService::class, \XcVm\Domain\Device\EnigmaService::class, \XcVm\Domain\User\UserService::class,
		\XcVm\Domain\User\UserRepository::class, \XcVm\Domain\User\GroupService::class, \XcVm\Domain\Line\PackageService::class,
		\XcVm\Domain\Stream\ProviderService::class, \XcVm\Domain\Security\BlocklistService::class, \XcVm\Domain\Stream\CategoryService::class,
		\XcVm\Domain\Stream\CategoryTemplateService::class, \XcVm\Domain\Stream\ProfileService::class, \XcVm\Domain\Stream\StreamConfigRepository::class,
		\XcVm\Domain\Stream\StreamService::class, \XcVm\Domain\Stream\StreamRepository::class, \XcVm\Domain\Stream\ChannelService::class,
		\XcVm\Domain\Stream\RadioService::class, \XcVm\Domain\Vod\MovieService::class, \XcVm\Domain\Vod\EpisodeService::class,
		\XcVm\Domain\Vod\SeriesService::class,
	];

	private TestDb $rDb;

	/** @var array<string, mixed> what this test replaced, put back after it */
	private array $rBefore = [];

	protected function setUp(): void {
		foreach (ConstantsInitializer::statuses() + ['SERVER_ID' => 1] as $rName => $rValue) {
			defined($rName) || define($rName, $rValue);
		}

		$this->rDb = new TestDb();
		foreach (['users', 'users_groups', 'lines', 'lines_live', 'bouquets', 'signals', 'mag_devices', 'enigma2_devices', 'users_packages', 'providers', 'hmac_keys', 'rtmp_ips', 'streams_categories', 'profiles', 'streams', 'streams_servers', 'streams_options', 'streams_episodes', 'streams_series', 'servers'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('024_add_custom_data_to_lines'));
		$this->rDb->exec('CREATE TABLE `category_template_items` (`id` int AUTO_INCREMENT PRIMARY KEY, `template_id` int, `category_id` int)');
		$this->rDb->exec('CREATE TABLE `watch_refresh` (`id` int AUTO_INCREMENT PRIMARY KEY, `type` int, `stream_id` int, `status` int)');
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_admin`, `is_reseller`, `allowed_pages`) VALUES (1, 'Administrators', 1, 0, '[]')");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `status`, `timezone`, `api_key`) VALUES (1, 'admin', 1, 1, '', '" . self::KEY . "')");
		$this->rDb->exec("INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_order`, `bouquet_channels`, `bouquet_movies`, `bouquet_radios`, `bouquet_series`) VALUES (1, 'One', 1, '[]', '[]', '[]', '[]'), (2, 'Two', 2, '[20,30]', '[50]', '[40]', '[70]'), (3, 'Three', 3, '[20]', '[50]', '[40]', '[70]')");

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
	 * Run an edit action of the API with these fields and no other: the status of the answer.
	 *
	 * @param array<string, mixed> $rFields
	 */
	private function edit(string $rAction, int $rID, array $rFields): ?string {
		RequestManager::set(['api_key' => self::KEY, 'action' => $rAction, 'id' => (string) $rID] + $rFields);
		ob_start();
		try {
			(new AdminApiController())->index();
		} finally {
			$rBody = (string) ob_get_clean();
		}
		return json_decode($rBody, true)['status'] ?? $rBody;
	}

	/** @return list<array<string, mixed>> the rows as stored */
	private function rows(string $rQuery, ...$rArgs): array {
		$this->rDb->query($rQuery, ...$rArgs);
		return $this->rDb->get_raw_rows();
	}

	/** @return array<string, mixed> the row as stored */
	private function row(string $rTable, string $rColumn, int $rID): array {
		return $this->rows('SELECT * FROM `' . $rTable . '` WHERE `' . $rColumn . '` = ?', $rID)[0] ?? [];
	}

	/** @return array<string, mixed> what a stream has besides its own row: servers, options, bouquets */
	private function streamParts(int $rID): array {
		return [
			'servers' => $this->rows('SELECT `server_id`, `parent_id`, `on_demand` FROM `streams_servers` WHERE `stream_id` = ? ORDER BY `server_id`', $rID),
			'options' => $this->rows('SELECT `argument_id`, `value` FROM `streams_options` WHERE `stream_id` = ? ORDER BY `argument_id`', $rID),
			'bouquets' => $this->rows('SELECT `id`, `bouquet_channels`, `bouquet_movies`, `bouquet_radios`, `bouquet_series` FROM `bouquets` ORDER BY `id`'),
		];
	}

	/** A line with a value of its own in every field the device forms post. */
	private function deviceLine(int $rID): void {
		$this->rDb->query(
			'INSERT INTO `lines` (`id`, `member_id`, `username`, `password`, `exp_date`, `admin_enabled`, `enabled`, `admin_notes`, `bouquet`, `allowed_outputs`, `max_connections`, `is_restreamer`, `is_trial`, `is_mag`, `is_e2`,'
			. ' `is_isplock`, `allowed_ips`, `created_at`, `as_number`, `isp_desc`, `pair_id`) VALUES (?, 1, ?, ?, 1900000000, 1, 1, ?, ?, ?, 1, 0, 1, ?, ?, 1, ?, 1700000000, ?, ?, NULL)',
			$rID,
			'dev<user>',
			'dev<pass>',
			'note <b>',
			'[2,3]',
			'[1,2]',
			$rID == 9 ? 1 : 0,
			$rID == 9 ? 0 : 1,
			'["192.0.2.7"]',
			'AS64500',
			'Example ISP'
		);
	}

	/** @return array<string, array{string, string, string}> action, device table, its id column */
	public static function devices(): array {
		return ['edit_mag' => ['edit_mag', 'mag_devices', 'mag_id'], 'edit_enigma' => ['edit_enigma', 'enigma2_devices', 'device_id']];
	}

	#[DataProvider('devices')]
	public function testADeviceEditKeepsItsLineAndItsOwnFields(string $rAction, string $rTable, string $rColumn): void {
		$this->deviceLine($rAction === 'edit_mag' ? 9 : 10);
		$this->rDb->query('INSERT INTO `' . $rTable . '` (`' . $rColumn . '`, `user_id`, `mac`, `lock_device`) VALUES (4, ?, ?, 1)', $rAction === 'edit_mag' ? 9 : 10, '00:1A:79:00:00:04');
		$rLine = $this->row('lines', 'id', $rAction === 'edit_mag' ? 9 : 10);
		$rDevice = $this->row($rTable, $rColumn, 4);

		$this->assertSame('STATUS_SUCCESS', $this->edit($rAction, 4, ['admin_notes' => 'changed']));

		$this->assertEquals(['admin_notes' => 'changed'] + $rLine, $this->row('lines', 'id', $rLine['id']), 'the line: bouquets, expiry, ISP, allowed IPs and switches as stored');
		$this->assertEquals($rDevice, $this->row($rTable, $rColumn, 4), 'the device: its MAC and its lock');
	}

	public function testALineEditKeepsTheCredentialsAsStored(): void {
		$this->rDb->query("INSERT INTO `lines` (`id`, `member_id`, `username`, `password`, `exp_date`, `enabled`, `admin_enabled`, `max_connections`, `bouquet`, `allowed_outputs`, `created_at`) VALUES (8, 1, ?, ?, NULL, 1, 1, 1, '[]', '[1]', 1700000000)", 'us<er>', 'pa<ss>');

		$this->assertSame('STATUS_SUCCESS', $this->edit('edit_line', 8, ['max_connections' => '3']));

		$rLine = $this->row('lines', 'id', 8);
		$this->assertSame(['us<er>', 'pa<ss>', 3], [$rLine['username'], $rLine['password'], (int) $rLine['max_connections']]);
	}

	public function testAUserEditKeepsTheNameTheGroupAndThePackageOverrides(): void {
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_admin`, `is_reseller`, `allowed_pages`) VALUES (2, 'Resellers', 0, 1, '[]')");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `password`, `member_group_id`, `status`, `credits`, `notes`, `override_packages`, `api_key`, `timezone`) VALUES (6, 'reseller', 'hash', 2, 1, 10, 'old', '{\"3\":{\"assign\":1,\"official_credits\":5}}', '', '')");
		$rStored = $this->row('users', 'id', 6);

		$this->assertSame('STATUS_SUCCESS', $this->edit('edit_user', 6, ['notes' => 'changed']));

		$this->assertEquals(['notes' => 'changed'] + $rStored, $this->row('users', 'id', 6));
	}

	public function testAGroupEditKeepsItsSwitchesListsNoticeAndPackages(): void {
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_admin`, `is_reseller`, `can_delete`, `delete_users`, `allowed_pages`, `create_sub_resellers`, `reseller_client_connection_logs`, `can_view_vod`, `allow_download`, `allow_restrictions`, `allow_change_username`, `allow_change_password`, `allow_change_bouquets`, `notice_html`, `subresellers`)"
			. " VALUES (5, 'Sellers', 0, 1, 1, 1, '[\"lines\",\"mags\"]', 1, 1, 1, 1, 1, 1, 1, 1, '&lt;b&gt;Hi&lt;/b&gt;', '[2,5]')");
		$this->rDb->exec("INSERT INTO `users_packages` (`id`, `package_name`, `groups`, `bouquets`) VALUES (3, 'Pack', '[5]', '[]')");
		$rStored = $this->row('users_groups', 'group_id', 5);

		$this->assertSame('STATUS_SUCCESS', $this->edit('edit_group', 5, ['group_name' => 'Renamed']));

		$this->assertEquals(['group_name' => 'Renamed'] + $rStored, $this->row('users_groups', 'group_id', 5));
		$this->assertSame('[5]', $this->row('users_packages', 'id', 3)['groups'], 'the group stays in its package');
	}

	public function testAPackageEditKeepsItsSwitchesGroupsAndBouquets(): void {
		$this->rDb->exec("INSERT INTO `users_packages` (`id`, `package_name`, `is_trial`, `is_official`, `groups`, `bouquets`, `is_line`, `is_mag`, `is_e2`, `is_restreamer`, `is_isplock`, `output_formats`, `lock_device`, `check_compatible`)"
			. " VALUES (3, 'Pack', 1, 1, '[1,5]', '[2,3]', 1, 1, 1, 1, 1, '[1,2]', 1, 1)");
		$rStored = $this->row('users_packages', 'id', 3);

		$this->assertSame('STATUS_SUCCESS', $this->edit('edit_package', 3, ['package_name' => 'Renamed']));

		$this->assertEquals(['package_name' => 'Renamed'] + $rStored, $this->row('users_packages', 'id', 3));
	}

	public function testAProviderEditKeepsItsAddressAndSwitches(): void {
		$this->rDb->exec("INSERT INTO `providers` (`id`, `name`, `ip`, `port`, `username`, `password`, `enabled`, `ssl`, `hls`, `legacy`) VALUES (2, 'Upstream', 'provider.example', 8080, 'name', 'secret', 1, 1, 1, 1)");
		$rStored = $this->row('providers', 'id', 2);

		$this->assertSame('STATUS_SUCCESS', $this->edit('edit_provider', 2, ['name' => 'Renamed']));

		$this->assertEquals(['name' => 'Renamed'] + $rStored, $this->row('providers', 'id', 2));
	}

	public function testAnHmacEditKeepsItsKeyAndSwitch(): void {
		$this->rDb->exec("INSERT INTO `hmac_keys` (`id`, `key`, `notes`, `enabled`) VALUES (2, 'sealed-key', 'For the app', 1)");
		$rStored = $this->row('hmac_keys', 'id', 2);

		$this->assertSame('STATUS_SUCCESS', $this->edit('edit_hmac', 2, ['notes' => 'changed']));

		$this->assertEquals(['notes' => 'changed'] + $rStored, $this->row('hmac_keys', 'id', 2));
	}

	public function testAnRtmpAddressEditKeepsItsPasswordAndSwitches(): void {
		$this->rDb->exec("INSERT INTO `rtmp_ips` (`id`, `ip`, `password`, `notes`, `push`, `pull`) VALUES (2, '192.0.2.9', 'rtmppass', 'old', 1, 1)");
		$rStored = $this->row('rtmp_ips', 'id', 2);

		$this->assertSame('STATUS_SUCCESS', $this->edit('edit_rtmp_ip', 2, ['notes' => 'changed']));

		$this->assertEquals(['notes' => 'changed'] + $rStored, $this->row('rtmp_ips', 'id', 2));
	}

	public function testACategoryEditKeepsItsAdultSwitch(): void {
		$this->rDb->exec("INSERT INTO `streams_categories` (`id`, `category_type`, `category_name`, `parent_id`, `cat_order`, `is_adult`) VALUES (2, 'live', 'Adult', 0, 4, 1)");
		$rStored = $this->row('streams_categories', 'id', 2);

		$this->assertSame('STATUS_SUCCESS', $this->edit('edit_category', 2, ['category_name' => 'Renamed']));

		$this->assertEquals(['category_name' => 'Renamed'] + $rStored, $this->row('streams_categories', 'id', 2));
	}

	public function testABouquetEditKeepsItsLists(): void {
		$this->rDb->exec("INSERT INTO `streams` (`id`, `type`, `stream_display_name`) VALUES (20, 1, 'Live'), (30, 3, 'Channel'), (40, 4, 'Radio'), (50, 2, 'Movie')");
		$this->rDb->exec("INSERT INTO `streams_series` (`id`, `title`) VALUES (70, 'Series')");
		$rStored = $this->row('bouquets', 'id', 2);

		$this->assertSame('STATUS_SUCCESS', $this->edit('edit_bouquet', 2, ['bouquet_name' => 'Renamed']));

		$this->assertEquals(['bouquet_name' => 'Renamed'] + $rStored, $this->row('bouquets', 'id', 2));
	}

	public function testATranscodeProfileEditKeepsItsOptions(): void {
		$this->rDb->exec("INSERT INTO `profiles` (`profile_id`, `profile_name`, `profile_options`) VALUES (2, 'Old', '{}')");
		// The form's save first, as the panel stores a profile.
		$this->assertSame('STATUS_SUCCESS', $this->edit('edit_transcode_profile', 2, [
			'profile_name' => 'HD', 'gpu_device' => '0', 'software_decoding' => '0', 'video_codec_cpu' => 'libx264', 'preset_cpu' => 'fast', 'video_profile_cpu' => 'high',
			'audio_codec' => 'aac', 'video_bitrate' => '3000', 'audio_bitrate' => '128', 'min_tolerance' => '', 'max_tolerance' => '3500', 'buffer_size' => '6000', 'crf_value' => '',
			'aspect_ratio' => '16:9', 'framerate' => '25', 'samplerate' => '48000', 'audio_channels' => '2', 'threads' => '4', 'logo_path' => '', 'logo_pos' => '', 'scaling' => '1280:720', 'yadif_filter' => '1',
			'resize' => '', 'deint' => '0', 'video_codec_gpu' => '',
		]));
		$rStored = $this->row('profiles', 'profile_id', 2);

		$this->assertSame('STATUS_SUCCESS', $this->edit('edit_transcode_profile', 2, ['profile_name' => 'Renamed']));

		$this->assertEquals(['profile_name' => 'Renamed'] + $rStored, $this->row('profiles', 'profile_id', 2));
	}

	/** Servers 1 (from the source) and 2 (from 1, on demand), a bouquet of its kind, two source options. */
	private function streamOn(int $rID): void {
		$this->rDb->query('INSERT INTO `streams_servers` (`stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES (?, 1, NULL, 0), (?, 2, 1, 1)', $rID, $rID);
		$this->rDb->query("INSERT INTO `streams_options` (`stream_id`, `argument_id`, `value`) VALUES (?, 1, 'Agent <x>'), (?, 21, '1')", $rID, $rID);
	}

	public function testAStreamEditKeepsItsSourcesServersBouquetsScheduleOptionsAndSwitches(): void {
		$this->rDb->query(
			"INSERT INTO `streams` (`id`, `type`, `category_id`, `stream_display_name`, `stream_source`, `notes`, `auto_restart`, `transcode_profile_id`, `gen_timestamps`, `allow_record`, `rtmp_output`, `stream_all`, `direct_source`, `direct_proxy`, `read_native`, `fps_restart`, `adaptive_link`, `custom_sid`)"
			. " VALUES (20, 1, '[1]', 'Live', ?, 'old', ?, 0, 1, 1, 1, 1, 0, 0, 1, 1, '[21]', 'sid')",
			json_encode(['http://source.example/1.ts']),
			json_encode(['days' => ['Monday'], 'at' => '04:00'])
		);
		$this->streamOn(20);
		$rStored = $this->row('streams', 'id', 20);
		$rParts = $this->streamParts(20);

		$this->assertSame('STATUS_SUCCESS', $this->edit('edit_stream', 20, ['notes' => 'changed']));

		$this->assertEquals(['notes' => 'changed'] + $rStored, $this->row('streams', 'id', 20));
		$this->assertEquals($rParts, $this->streamParts(20));
	}

	public function testAStationEditKeepsItsSourcesServersBouquetsScheduleOptionsAndSwitch(): void {
		$this->rDb->query(
			"INSERT INTO `streams` (`id`, `type`, `category_id`, `stream_display_name`, `stream_source`, `notes`, `auto_restart`, `direct_source`, `probesize_ondemand`) VALUES (40, 4, '[1]', 'Radio', ?, 'old', ?, 1, 256000)",
			json_encode(['http://radio.example/live']),
			json_encode(['days' => ['Monday'], 'at' => '04:00'])
		);
		$this->streamOn(40);
		$rStored = $this->row('streams', 'id', 40);
		$rParts = $this->streamParts(40);

		$this->assertSame('STATUS_SUCCESS', $this->edit('edit_station', 40, ['notes' => 'changed']));

		$this->assertEquals(['notes' => 'changed'] + $rStored, $this->row('streams', 'id', 40));
		$this->assertEquals($rParts, $this->streamParts(40));
	}

	public function testACreatedChannelEditKeepsItsFilesServersBouquetsAndSwitches(): void {
		$this->rDb->query(
			"INSERT INTO `streams` (`id`, `type`, `category_id`, `stream_display_name`, `stream_source`, `notes`, `movie_properties`, `transcode_profile_id`, `movie_symlink`, `series_no`, `allow_record`, `rtmp_output`) VALUES (30, 3, '[1]', 'Channel', ?, 'old', '{\"type\":1}', 0, 0, 0, 1, 1)",
			json_encode(['s:1:/media/a.mp4'])
		);
		$this->rDb->exec('INSERT INTO `streams_servers` (`stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES (30, 1, NULL, 1)');
		$rStored = $this->row('streams', 'id', 30);
		$rParts = $this->streamParts(30);

		$this->assertSame('STATUS_SUCCESS', $this->edit('edit_channel', 30, ['notes' => 'changed']));

		$this->assertEquals(['notes' => 'changed'] + $rStored, $this->row('streams', 'id', 30));
		$this->assertEquals($rParts, $this->streamParts(30));
	}

	public function testAMovieEditKeepsItsDetailsSubtitlesServersBouquetsAndSwitches(): void {
		$this->rDb->exec("INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `target_container`) VALUES (50, 2, 'Movie', 'mp4')");
		$this->rDb->exec('INSERT INTO `streams_servers` (`stream_id`, `server_id`, `on_demand`) VALUES (50, 1, 0)');
		// The form's save first, as the panel stores a movie.
		$this->assertSame('STATUS_SUCCESS', $this->edit('edit_movie', 50, [
			'stream_display_name' => 'Movie', 'stream_source' => 's:1:/media/movie.mp4', 'movie_subtitles' => 's:1:/media/movie.srt', 'tmdb_id' => '603', 'movie_image' => 'http://img.example/p.jpg',
			'backdrop_path' => 'http://img.example/b.jpg', 'release_date' => '1999-03-31', 'episode_run_time' => '136', 'youtube_trailer' => 'abc', 'director' => 'Someone', 'cast' => 'A, B', 'plot' => 'Plot <i>',
			'country' => 'US', 'genre' => 'Action', 'rating' => '8.2', 'category_id' => ['1'], 'bouquets' => ['2', '3'], 'server_tree_data' => json_encode([['id' => 1, 'parent' => 'source']]),
			'read_native' => '1', 'direct_source' => '1', 'remove_subtitles' => '1', 'notes' => 'old',
		]));
		$rStored = $this->row('streams', 'id', 50);
		$rParts = $this->streamParts(50);

		$this->assertSame('STATUS_SUCCESS', $this->edit('edit_movie', 50, ['notes' => 'changed']));

		$this->assertEquals(['notes' => 'changed'] + $rStored, $this->row('streams', 'id', 50));
		$this->assertEquals($rParts, $this->streamParts(50));
	}

	public function testAnEpisodeEditKeepsItsDetailsPlaceAndServers(): void {
		$this->rDb->exec("INSERT INTO `streams_series` (`id`, `title`) VALUES (70, 'Series')");
		$this->rDb->exec("INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `target_container`) VALUES (60, 5, 'Episode', 'mkv')");
		$this->rDb->exec('INSERT INTO `streams_episodes` (`season_num`, `episode_num`, `series_id`, `stream_id`) VALUES (1, 1, 70, 60)');
		$this->rDb->exec('INSERT INTO `streams_servers` (`stream_id`, `server_id`, `on_demand`) VALUES (60, 1, 0)');
		// The form's save first, as the panel stores an episode.
		$this->assertSame('STATUS_SUCCESS', $this->edit('edit_episode', 60, [
			'stream_display_name' => 'Pilot', 'stream_source' => 's:1:/media/s01e02.mkv', 'movie_subtitles' => 's:1:/media/s01e02.srt', 'series' => '70', 'season_num' => '2', 'episode' => '3',
			'target_container' => 'mkv', 'release_date' => '2020-01-01', 'plot' => 'Plot <i>', 'episode_run_time' => '42', 'movie_image' => 'http://img.example/e.jpg', 'rating' => '7', 'tmdb_id' => '11',
			'server_tree_data' => json_encode([['id' => 1, 'parent' => 'source']]), 'read_native' => '1', 'movie_symlink' => '1', 'notes' => 'old',
		]));
		$rStored = $this->row('streams', 'id', 60);
		$rPlace = $this->rows('SELECT `season_num`, `episode_num`, `series_id` FROM `streams_episodes` WHERE `stream_id` = 60');
		$rParts = $this->streamParts(60);

		$this->assertSame('STATUS_SUCCESS', $this->edit('edit_episode', 60, ['notes' => 'changed']));

		$this->assertEquals(['notes' => 'changed'] + $rStored, $this->row('streams', 'id', 60));
		$this->assertEquals($rPlace, $this->rows('SELECT `season_num`, `episode_num`, `series_id` FROM `streams_episodes` WHERE `stream_id` = 60'));
		$this->assertEquals($rParts, $this->streamParts(60));
	}

	public function testASeriesEditKeepsItsTitleImagesCategoriesAndBouquets(): void {
		$this->rDb->query("INSERT INTO `streams_series` (`id`, `title`, `category_id`, `cover`, `cover_big`, `plot`, `backdrop_path`, `last_modified`) VALUES (70, 'Series', '[1]', 'http://img.example/c.jpg', 'http://img.example/c.jpg', 'old', ?, 1)", json_encode(['http://img.example/b.jpg']));
		$rStored = $this->row('streams_series', 'id', 70);
		$rBouquets = $this->streamParts(70)['bouquets'];

		$this->assertSame('STATUS_SUCCESS', $this->edit('edit_series', 70, ['plot' => 'changed']));

		$rRow = $this->row('streams_series', 'id', 70);
		$this->assertEquals(['plot' => 'changed', 'last_modified' => $rRow['last_modified']] + $rStored, $rRow);
		$this->assertEquals($rBouquets, $this->streamParts(70)['bouquets']);
	}

	public function testASwitchSentAsZeroOrEmptyIsOff(): void {
		$this->rDb->exec("INSERT INTO `providers` (`id`, `name`, `ip`, `port`, `username`, `password`, `enabled`, `ssl`, `hls`, `legacy`) VALUES (2, 'Upstream', 'provider.example', 8080, 'name', 'secret', 1, 1, 1, 1)");
		$rStored = $this->row('providers', 'id', 2);

		$this->assertSame('STATUS_SUCCESS', $this->edit('edit_provider', 2, ['ssl' => '0', 'hls' => '']));

		$this->assertEquals(['ssl' => 0, 'hls' => 0] + $rStored, $this->row('providers', 'id', 2));
	}

	public function testAListSentEmptyIsCleared(): void {
		$this->rDb->exec("INSERT INTO `streams_series` (`id`, `title`, `category_id`, `cover`, `backdrop_path`) VALUES (70, 'Series', '[1]', '', '[]')");

		$this->assertSame('STATUS_SUCCESS', $this->edit('edit_series', 70, ['category_id' => '', 'bouquets' => '']));

		$this->assertSame('[]', $this->row('streams_series', 'id', 70)['category_id']);
		$this->assertSame([[], []], array_map(fn(array $rBouquet): array => json_decode($rBouquet['bouquet_series'], true), array_slice($this->streamParts(70)['bouquets'], 1)));
	}

	/** edit_proxy edits a proxy, edit_server a server: neither turns one into the other. */
	public function testAServerAndAProxyAreEachEditedOnlyByTheirOwnAction(): void {
		$this->rDb->exec("INSERT INTO `servers` (`id`, `server_name`, `server_type`, `server_ip`) VALUES (2, 'Server', 0, '192.0.2.2'), (3, 'Proxy', 1, '192.0.2.3')");

		$this->assertSame('STATUS_FAILURE', $this->edit('edit_proxy', 2, ['server_name' => 'Renamed']));
		$this->assertSame('STATUS_FAILURE', $this->edit('edit_server', 3, ['server_name' => 'Renamed']));

		$this->assertSame(['Server', 0], [$this->row('servers', 'id', 2)['server_name'], (int) $this->row('servers', 'id', 2)['server_type']]);
		$this->assertSame(['Proxy', 1], [$this->row('servers', 'id', 3)['server_name'], (int) $this->row('servers', 'id', 3)['server_type']]);
	}
}
