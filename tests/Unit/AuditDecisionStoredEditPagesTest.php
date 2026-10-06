<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\ConstantsInitializer;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Admin;
use XcVm\Tests\Support\InstallSchema;

/**
 * The page without its permission check and its layout: what it hands the view.
 */
trait StoredEditPageShown {
	/** @var array<string, mixed> */
	public array $rShown = [];

	protected function requirePermission() {
	}

	protected function render(string $view, array $data = []) {
		$this->rShown = $data;
	}
}

/**
 * An admin edit page shows the record as stored: its form escapes what it
 * prints and posts it back, so text read through the row cleaner ('&lt;'
 * for '<') would be saved escaped. Each page's view prints every value of
 * the record escaped.
 */
final class AuditDecisionStoredEditPagesTest extends TestCase {
	/** Text the cleaned row would rewrite. */
	private const TEXT = '<b>&amp;</b>';

	/** Every class that may hold a database handle of an earlier test. */
	private const SERVICES = [
		\XcVm\Domain\Bouquet\BouquetService::class, \XcVm\Domain\Line\PackageService::class, \XcVm\Domain\Stream\CategoryService::class,
		\XcVm\Domain\Stream\StreamConfigRepository::class, \XcVm\Domain\Stream\StreamRepository::class, \XcVm\Domain\Vod\SeriesService::class,
		\XcVm\Domain\Epg\EpgService::class, \XcVm\Domain\User\UserRepository::class, \XcVm\Domain\Stream\CategoryTemplateService::class,
	];

	/** Each edit page's view => the variable that holds its record. */
	private const VIEWS = [
		'epg' => 'rEPGArr', 'stream_category' => 'rCategoryArr', 'package' => 'rPackage', 'provider' => 'rProvider', 'proxy' => 'rServerArr',
		'mag' => 'rDevice', 'enigma' => 'rDevice', 'user' => 'rUser', 'group' => 'rGroup', 'bouquet' => 'rBouquetArr', 'stream' => 'rStream',
		'created_channel' => 'rChannel', 'movie' => 'rMovie', 'episode' => 'rEpisode', 'serie' => 'rSeriesArr',
	];

	private TestDb $rDb;

	/** @var array<string, mixed> what this test replaced, put back after it */
	private array $rBefore = [];

	protected function setUp(): void {
		foreach (ConstantsInitializer::statuses() + ['SERVER_ID' => 1] as $rName => $rValue) {
			defined($rName) || define($rName, $rValue);
		}

		$this->rDb = new TestDb();
		foreach (['users', 'users_groups', 'lines', 'bouquets', 'mag_devices', 'enigma2_devices', 'users_packages', 'providers', 'streams_categories', 'streams', 'streams_servers', 'streams_options', 'streams_episodes', 'streams_series', 'streams_arguments', 'profiles', 'epg', 'servers'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('021_add_category_templates'));
		$this->rDb->exec(InstallSchema::migration('024_add_custom_data_to_lines'));

		$this->rBefore = ['request' => RequestManager::getAll(), 'settings' => SettingsManager::getAll()];
		foreach (['db', 'rServers', 'rProxyServers', 'rPermissions', 'rUserInfo', 'rAdminUserInfo', 'rSettings'] as $rName) {
			$this->rBefore['global'][$rName] = $GLOBALS[$rName] ?? null;
		}
		$GLOBALS['db'] = $this->rDb;
		$GLOBALS['rServers'] = [1 => ['id' => 1, 'server_name' => 'Main', 'is_main' => 1, 'server_type' => 0, 'server_online' => 1, 'video_devices' => [], 'audio_devices' => []]];
		$GLOBALS['rProxyServers'] = [3 => ['id' => 3, 'server_type' => 1]];
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => []];
		$GLOBALS['rUserInfo'] = $GLOBALS['rAdminUserInfo'] = ['id' => 1, 'member_group_id' => 1];
		$GLOBALS['rSettings'] = ['tmdb_language' => 'en'];
		SettingsManager::set(['enable_cache' => 0, 'redis_handler' => 0]);
		DatabaseFactory::set($this->rDb);
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
		SettingsManager::set($this->rBefore['settings']);
		DatabaseFactory::reset();
		foreach ($this->rBefore['global'] as $rName => $rValue) {
			$GLOBALS[$rName] = $rValue;
		}
	}

	/**
	 * Each page: its controller, the request, the rows it shows, and the text
	 * cells (table, id column, id, column, where the view finds it).
	 *
	 * @return array<string, array{Closure, array<string, string>, list<string>, string, list<array{string, string, int, string, list<string>}>}>
	 */
	public static function pages(): array {
		$rLine = 'INSERT INTO `lines` (`id`, `member_id`, `username`, `password`, `bouquet`, `allowed_outputs`, `allowed_ips`, `created_at`) VALUES ';
		return [
			'EPG source' => [static fn() => new class extends Admin\EpgController { use StoredEditPageShown; }, ['id' => '2'], ["INSERT INTO `epg` (`id`, `epg_name`) VALUES (2, 'Guide')"], 'rEPGArr', [['epg', 'id', 2, 'epg_name', ['epg_name']]]],
			'category' => [static fn() => new class extends Admin\StreamCategoryController { use StoredEditPageShown; }, ['id' => '2'], ["INSERT INTO `streams_categories` (`id`, `category_type`, `category_name`) VALUES (2, 'live', 'News')"], 'rCategoryArr', [['streams_categories', 'id', 2, 'category_name', ['category_name']]]],
			'package' => [static fn() => new class extends Admin\PackageEditController { use StoredEditPageShown; }, ['id' => '3'], ["INSERT INTO `users_packages` (`id`, `package_name`, `groups`, `bouquets`) VALUES (3, 'Pack', '[]', '[]')"], 'rPackage', [['users_packages', 'id', 3, 'package_name', ['package_name']]]],
			'provider' => [static fn() => new class extends Admin\ProviderEditController { use StoredEditPageShown; }, ['id' => '2'], ["INSERT INTO `providers` (`id`, `name`, `ip`, `port`, `username`, `password`) VALUES (2, 'Upstream', 'provider.example', 8080, 'name', 'secret')"], 'rProvider', [['providers', 'id', 2, 'name', ['name']]]],
			'proxy' => [static fn() => new class extends Admin\ProxyController { use StoredEditPageShown; }, ['id' => '3'], ["INSERT INTO `servers` (`id`, `server_name`, `server_type`, `server_ip`) VALUES (3, 'Proxy', 1, '192.0.2.3')"], 'rServerArr', [['servers', 'id', 3, 'server_name', ['server_name']]]],
			'MAG device' => [static fn() => new class extends Admin\MagController { use StoredEditPageShown; }, ['id' => '4'], [$rLine . "(9, 1, 'devuser', 'devpass', '[]', '[1,2]', '[]', 1700000000)", "INSERT INTO `mag_devices` (`mag_id`, `user_id`, `mac`) VALUES (4, 9, '00:1A:79:00:00:04')"], 'rDevice', [['mag_devices', 'mag_id', 4, 'ver', ['ver']], ['lines', 'id', 9, 'admin_notes', ['user', 'admin_notes']]]],
			'Enigma2 device' => [static fn() => new class extends Admin\EnigmaController { use StoredEditPageShown; }, ['id' => '4'], [$rLine . "(10, 1, 'devuser', 'devpass', '[]', '[1,2]', '[]', 1700000000)", "INSERT INTO `enigma2_devices` (`device_id`, `user_id`, `mac`) VALUES (4, 10, '00:1A:79:00:00:04')"], 'rDevice', [['enigma2_devices', 'device_id', 4, 'enigma_version', ['enigma_version']], ['lines', 'id', 10, 'admin_notes', ['user', 'admin_notes']]]],
			'user' => [static fn() => new class extends Admin\UserController { use StoredEditPageShown; }, ['id' => '6'], ["INSERT INTO `users` (`id`, `username`, `member_group_id`, `status`, `notes`) VALUES (6, 'reseller', 2, 1, 'old')"], 'rUser', [['users', 'id', 6, 'notes', ['notes']]]],
			'group' => [static fn() => new class extends Admin\GroupEditController { use StoredEditPageShown; }, ['id' => '5'], ["INSERT INTO `users_groups` (`group_id`, `group_name`, `is_admin`, `is_reseller`, `allowed_pages`, `notice_html`, `subresellers`) VALUES (5, 'Sellers', 0, 1, '[]', '', '[]')"], 'rGroup', [['users_groups', 'group_id', 5, 'group_name', ['group_name']]]],
			'bouquet' => [static fn() => new class extends Admin\BouquetController { use StoredEditPageShown; }, ['id' => '1'], ["INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_channels`, `bouquet_movies`, `bouquet_radios`, `bouquet_series`) VALUES (1, 'One', '[]', '[]', '[]', '[]')"], 'rBouquetArr', [['bouquets', 'id', 1, 'bouquet_name', ['bouquet_name']]]],
			'stream' => [static fn() => new class extends Admin\StreamController { use StoredEditPageShown; }, ['id' => '20'], ["INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `category_id`, `stream_source`) VALUES (20, 1, 'Live', '[]', '[]')"], 'rStream', [['streams', 'id', 20, 'stream_display_name', ['stream_display_name']]]],
			'created channel' => [static fn() => new class extends Admin\CreatedChannelController { use StoredEditPageShown; }, ['id' => '30'], ["INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `category_id`, `stream_source`, `movie_properties`) VALUES (30, 3, 'Channel', '[]', '[]', '{\"type\":1}')"], 'rChannel', [['streams', 'id', 30, 'stream_display_name', ['stream_display_name']]]],
			'movie' => [static fn() => new class extends Admin\MovieController { use StoredEditPageShown; }, ['id' => '50'], ["INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `category_id`, `stream_source`, `movie_properties`) VALUES (50, 2, 'Movie', '[]', '[]', '{}')"], 'rMovie', [['streams', 'id', 50, 'stream_display_name', ['stream_display_name']]]],
			'episode' => [static fn() => new class extends Admin\EpisodeController { use StoredEditPageShown; }, ['id' => '60', 'sid' => '70'], ["INSERT INTO `streams_series` (`id`, `title`) VALUES (70, 'Series')", "INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `stream_source`, `movie_properties`) VALUES (60, 5, 'Episode', '[]', '{}')", 'INSERT INTO `streams_episodes` (`season_num`, `episode_num`, `series_id`, `stream_id`) VALUES (1, 1, 70, 60)'], 'rEpisode', [['streams', 'id', 60, 'stream_display_name', ['stream_display_name']]]],
			'series' => [static fn() => new class extends Admin\SerieController { use StoredEditPageShown; }, ['id' => '70'], ["INSERT INTO `streams_series` (`id`, `title`, `category_id`) VALUES (70, 'Series', '[]')"], 'rSeriesArr', [['streams_series', 'id', 70, 'title', ['title']]]],
		];
	}

	/**
	 * @param Closure                                                   $rController
	 * @param array<string, string>                                     $rRequest
	 * @param list<string>                                              $rSetup
	 * @param list<array{string, string, int, string, list<string>}>    $rText
	 */
	#[DataProvider('pages')]
	public function testTheEditPageGetsItsRecordAsStored(Closure $rController, array $rRequest, array $rSetup, string $rKey, array $rText): void {
		foreach ($rSetup as $rQuery) {
			$this->rDb->exec($rQuery);
		}
		foreach ($rText as [$rTable, $rColumn, $rID, $rField]) {
			$this->rDb->query('UPDATE `' . $rTable . '` SET `' . $rField . '` = ? WHERE `' . $rColumn . '` = ?', self::TEXT, $rID);
		}
		RequestManager::set($rRequest);

		$rPage = $rController();
		$rPage->index();

		foreach ($rText as [$rTable, , , $rField, $rPath]) {
			$rValue = $rPage->rShown[$rKey] ?? null;
			foreach ($rPath as $rStep) {
				$rValue = $rValue[$rStep] ?? null;
			}
			$this->assertSame(self::TEXT, $rValue, $rTable . '.' . $rField . ' as stored');
		}
	}

	public function testEachEditPagePrintsItsRecordEscaped(): void {
		foreach (self::VIEWS as $rView => $rVar) {
			preg_match_all('/<\?=(.*?)\?>/s', (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/' . $rView . '.php'), $rPrints);
			$rChecked = 0;
			foreach ($rPrints[1] as $rPrint) {
				if (!str_contains($rPrint, '$' . $rVar . '[')) {
					continue;
				}
				$rChecked++;
				$this->assertMatchesRegularExpression("/htmlspecialchars\\(|\\(int\\) \\(?\\\$" . $rVar . '\[|\? \'(checked|selected)\'|^\s*json_encode\(/', $rPrint, $rView . ' prints as it is: ' . trim($rPrint));
			}
			$this->assertGreaterThan(0, $rChecked, $rView);
		}
		foreach (['header', 'footer', 'topbar', 'menu'] as $rLayout) {
			$rSource = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/' . $rLayout . '.php');
			foreach (array_unique(self::VIEWS) as $rVar) {
				$this->assertDoesNotMatchRegularExpression('/\$' . $rVar . '\b/', $rSource, $rLayout . ' uses $' . $rVar);
			}
		}
	}
}
