<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Localization\Translator;
use XcVm\Public\Controllers\Admin\Ajax\SearchAjaxController;

/**
 * SearchAjaxController — the `?action=search` JSON must match
 * docs/adr/search-json-contract.md exactly (the client in
 * src/Public/assets/admin/js/search.js renders only that shape).
 *
 * The DB-bound search() exits via json(), so these tests drive the pure item
 * builders (buildItem/envelope) through reflection with fixture rows and a
 * seeded admin identity (Authorization reads $rUserInfo/$rPermissions/$db).
 */
final class SearchAjaxControllerTest extends TestCase {
	private const ENTITIES = ['stream', 'movie', 'channel', 'radio', 'episode', 'series', 'user', 'line', 'mag', 'enigma'];

	private const TABLES = [
		'lines' => ['Lines', 'line?id=', '', 'id', 'username'],
		'mag_devices' => ['MAG Devices', 'mag?id=', '', 'mag_id', 'mac'],
		'enigma2_devices' => ['Enigma2 Devices', 'enigma?id=', '', 'device_id', 'mac'],
		'users' => ['Users', 'user?id=', '', 'id', 'username'],
		'streams' => ['Streams', 'stream_view?id=', '', 'id', 'stream_display_name'],
		'streams_series' => ['TV Series', 'serie?id=', '', 'id', 'title'],
	];

	private SearchAjaxController $controller;

	private string $langDir;

	protected function setUp(): void {
		$this->controller = new SearchAjaxController();

		// Isolated language dir: a missing-key lookup backfills the active
		// language file, which must never be the real en.ini.
		$this->langDir = sys_get_temp_dir() . '/xcvm_search_lang_' . bin2hex(random_bytes(4));
		mkdir($this->langDir);
		file_put_contents($this->langDir . '/en.ini', "[Language]\nadd_credits = \"Add Credits\"\ntv_series_btn = \"TV SERIES\"\nuser_btn = \"USER\"\n");
		Translator::init($this->langDir);

		SettingsManager::set(['datetime_format' => 'Y-m-d H:i:s']);
		$GLOBALS['db'] = new stdClass();
		$GLOBALS['rUserInfo'] = ['id' => 1, 'member_group_id' => 1];
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => [], 'all_reports' => []];
		$GLOBALS['rServers'] = [1 => ['server_name' => 'EU-1', 'server_online' => true]];
	}

	protected function tearDown(): void {
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions'], $GLOBALS['rServers']);
		SettingsManager::set([]);
		Translator::init(MAIN_HOME . 'Core/Localization/lang');
		array_map('unlink', glob($this->langDir . '/*') ?: []);
		@rmdir($this->langDir);
	}

	private function call(string $method, mixed ...$args): mixed {
		$ref = new ReflectionMethod(SearchAjaxController::class, $method);
		$ref->setAccessible(true);

		return $ref->invoke($this->controller, ...$args);
	}

	/** @param array<string, mixed> $overrides */
	private function ctx(array $overrides = []): array {
		return $overrides + [
			'rServerItems' => [], 'rServerCount' => [], 'rSeriesTitles' => [], 'rConnectionCount' => [],
			'rSeriesInfo' => [], 'rUsersCount' => [], 'rLinesCount' => [], 'rOwnerNames' => [],
			'rLinesInfo' => [], 'rLineConnectionCount' => [], 'rStreamNames' => [], 'rDeviceLines' => [],
			'rCategories' => [3 => ['category_name' => 'News']], 'rGroups' => [2 => ['group_name' => 'Resellers', 'is_reseller' => 1]],
			'rTables' => self::TABLES,
		];
	}

	private function streamRow(int $rType, array $extra = []): array {
		return $extra + [
			'table' => 'streams', 'id' => 512, 'type' => $rType, 'stream_display_name' => 'CNN HD',
			'category_id' => '[3]', 'movie_properties' => '{"movie_image":"poster.jpg","rating":"7"}', 'stream_icon' => 'cnn.png',
			'direct_source' => 0, 'on_demand' => 0, 'year' => 2021, 'stream_source' => '[]',
		];
	}

	private function lineRow(array $extra = []): array {
		return $extra + [
			'table' => 'lines', 'id' => 7, 'username' => 'line123', 'member_id' => 1, 'exp_date' => 0,
			'admin_enabled' => 1, 'enabled' => 1, 'is_restreamer' => 0, 'is_trial' => 1, 'last_activity_array' => '',
		];
	}

	/** Every action must be one of the four contract shapes, with exactly its keys. */
	private function assertContractActions(array $rActions): void {
		$rShapes = [
			'navigate' => ['icon', 'kind', 'target', 'title'],
			'api' => ['enabled', 'entity', 'icon', 'id', 'kind', 'sub', 'title'],
			'fingerprint' => ['context', 'enabled', 'icon', 'id', 'kind'],
			'credits' => ['icon', 'id', 'kind', 'title'],
		];

		foreach ($rActions as $rAction) {
			$this->assertArrayHasKey($rAction['kind'], $rShapes, 'unknown action kind');
			$rKeys = array_keys($rAction);
			sort($rKeys);
			$this->assertSame($rShapes[$rAction['kind']], $rKeys, 'action keys for kind ' . $rAction['kind']);

			if ($rAction['kind'] === 'api') {
				$this->assertContains($rAction['entity'], self::ENTITIES, 'api entity must be an item entity');
				$this->assertIsBool($rAction['enabled']);
				$this->assertIsInt($rAction['id'], 'api action carries the numeric id it acts on');
			}
		}
	}

	/** @return list<int> The `id` of every `api` action. */
	private function apiIds(array $rActions): array {
		return array_values(array_column(array_filter($rActions, fn($a) => $a['kind'] === 'api'), 'id'));
	}

	private function assertItemBase(array $rItem, string $rEntity): void {
		$this->assertSame(['data', 'entity', 'id', 'text', 'url'], (function (array $k) {
			sort($k);
			return $k;
		})(array_keys($rItem)));
		$this->assertSame($rEntity, $rItem['entity']);
		$this->assertIsArray($rItem['data']);
		$this->assertMatchesRegularExpression('/^[a-z0-9_]+#\d+$/', (string) $rItem['id']);
	}

	public function testLiveStreamItemMatchesContract(): void {
		$rItem = $this->call('buildItem', $this->streamRow(1), $this->ctx());
		$this->assertItemBase($rItem, 'stream');
		$this->assertSame('streams#512', $rItem['id']);
		$this->assertSame('stream_view?id=512', $rItem['url']);
		$d = $rItem['data'];
		$this->assertSame('live', $d['layout']);
		$this->assertSame(['url' => 'cnn.png', 'size' => 96], $d['image']);
		$this->assertSame(['text' => 'STREAM', 'variant' => 'success'], $d['badge']);
		$this->assertSame('News', $d['category']);
		$this->assertNull($d['rating']);
		// No server row + not direct -> status -1 (NO SERVERS) as a labelled badge.
		$this->assertSame(['kind' => 'status', 'code' => -1, 'label' => 'NO SERVERS', 'variant' => 'secondary'], $d['status']);
		$this->assertContractActions($d['actions']);
		$this->assertSame(['navigate', 'api', 'api', 'api', 'fingerprint'], array_column($d['actions'], 'kind'));
		$this->assertSame(['stream'], array_values(array_unique(array_column(array_filter($d['actions'], fn($a) => $a['kind'] === 'api'), 'entity'))));
		$this->assertSame([512, 512, 512], $this->apiIds($d['actions']));
	}

	public function testCreatedChannelApiEntityIsAnItemEntityNotThePageName(): void {
		$rItem = $this->call('buildItem', $this->streamRow(3), $this->ctx());
		$this->assertItemBase($rItem, 'channel');
		$this->assertContractActions($rItem['data']['actions']);
		$this->assertSame('created_channel?id=512', $rItem['data']['actions'][0]['target']);

		foreach ($rItem['data']['actions'] as $rAction) {
			if ($rAction['kind'] === 'api') {
				$this->assertNotSame('created_channel', $rAction['entity']);
			}
		}
	}

	public function testMovieItemCarriesRatingAndVodActions(): void {
		$rItem = $this->call('buildItem', $this->streamRow(2), $this->ctx());
		$this->assertItemBase($rItem, 'movie');
		$d = $rItem['data'];
		$this->assertSame('vod', $d['layout']);
		$this->assertSame(['url' => 'poster.jpg', 'size' => 512], $d['image']);
		$this->assertSame(['stars_full' => 3, 'half' => true, 'empty' => 1, 'year' => '2021'], $d['rating']);
		$this->assertContractActions($d['actions']);
		$this->assertSame(['movie'], array_values(array_unique(array_column(array_filter($d['actions'], fn($a) => $a['kind'] === 'api'), 'entity'))));
		$this->assertSame([512], array_values(array_unique($this->apiIds($d['actions']))));
	}

	public function testEpisodeAndRadioEntities(): void {
		$this->assertSame('episode', $this->call('buildItem', $this->streamRow(5), $this->ctx())['entity']);
		$rRadio = $this->call('buildItem', $this->streamRow(4), $this->ctx());
		$this->assertSame('radio', $rRadio['entity']);
		$this->assertContractActions($rRadio['data']['actions']);
	}

	public function testSeriesItemMatchesContract(): void {
		$rRow = ['table' => 'streams_series', 'id' => 9, 'title' => 'Breaking Bad', 'category_id' => '[3]', 'cover' => 'cover.jpg', 'rating' => 8, 'year' => 2008];
		$rItem = $this->call('buildItem', $rRow, $this->ctx(['rSeriesInfo' => [9 => [5, 62]]]));
		$this->assertItemBase($rItem, 'series');
		$d = $rItem['data'];
		$this->assertSame(['actions', 'badge', 'category', 'episodes', 'image', 'rating', 'seasons', 'title'], (function (array $k) {
			sort($k);
			return $k;
		})(array_keys($d)));
		$this->assertSame(5, $d['seasons']);
		$this->assertSame(62, $d['episodes']);
		$this->assertContractActions($d['actions']);
	}

	public function testUserItemApiActionsCarryTheUserId(): void {
		$rRow = ['table' => 'users', 'id' => 5, 'username' => 'reseller1', 'member_group_id' => 2, 'status' => 1, 'owner_id' => 0, 'credits' => 1000];
		$rItem = $this->call('buildItem', $rRow, $this->ctx());
		$this->assertItemBase($rItem, 'user');
		$d = $rItem['data'];
		$this->assertTrue($d['is_reseller']);
		$this->assertSame(1000, $d['credits']);
		$this->assertSame(['label' => 'Active', 'variant' => 'info'], $d['status']);
		$this->assertContractActions($d['actions']);
		$this->assertSame(['credits', 'navigate', 'api'], array_column($d['actions'], 'kind'));
		$this->assertSame(5, $d['actions'][0]['id']);
		$this->assertSame(5, $d['actions'][2]['id']);
	}

	public function testLineItemMatchesContract(): void {
		$rItem = $this->call('buildItem', $this->lineRow(), $this->ctx(['rLineConnectionCount' => [7 => 2]]));
		$this->assertItemBase($rItem, 'line');
		$d = $rItem['data'];
		$this->assertNull($d['device_type']);
		$this->assertNull($d['expires']);
		$this->assertSame(['online' => false, 'date' => null], $d['last_active']);
		$this->assertSame(['restreamer' => false, 'trial' => true], $d['flags']);
		$this->assertSame(2, $d['connections']);
		$this->assertContractActions($d['actions']);
		$this->assertSame(['navigate', 'api', 'api', 'api', 'fingerprint'], array_column($d['actions'], 'kind'));
		$this->assertTrue(end($d['actions'])['enabled']);
		$this->assertSame([7, 7, 7], $this->apiIds($d['actions']));
	}

	public function testDeviceItemsResolveBothDeviceTablesAndTargetTheOwningLine(): void {
		$rLine = $this->lineRow(['id' => 44, 'is_restreamer' => 1]);
		$rCtx = $this->ctx(['rDeviceLines' => [44 => $rLine]]);
		$rDevice = ['mac' => 'AA:BB:CC:DD:EE:FF', 'user_id' => 44, 'admin_enabled' => 1, 'enabled' => 0];

		$rMag = $this->call('buildItem', $rDevice + ['table' => 'mag_devices', 'mag_id' => 3], $rCtx);
		$this->assertItemBase($rMag, 'mag');
		$this->assertSame('mag_devices#3', $rMag['id']);
		$this->assertSame('mag', $rMag['data']['device_type']);
		$this->assertSame(['restreamer' => true, 'trial' => true], $rMag['data']['flags']);
		$this->assertContractActions($rMag['data']['actions']);
		$this->assertSame('mag?id=3', $rMag['data']['actions'][0]['target']);
		// Device actions act on the owning line: every api action carries its id, not the device's.
		$this->assertSame([44, 44, 44], $this->apiIds($rMag['data']['actions']));
		$rFp = array_values(array_filter($rMag['data']['actions'], fn($a) => $a['kind'] === 'fingerprint'))[0];
		$this->assertSame(44, $rFp['id']);

		$rEnigma = $this->call('buildItem', $rDevice + ['table' => 'enigma2_devices', 'device_id' => 8], $rCtx);
		$this->assertItemBase($rEnigma, 'enigma');
		$this->assertSame('enigma', $rEnigma['data']['device_type']);
		$this->assertSame('enigma?id=8', $rEnigma['data']['actions'][0]['target']);
		$this->assertContractActions($rEnigma['data']['actions']);
		$this->assertSame([44, 44, 44], $this->apiIds($rEnigma['data']['actions']));
	}

	public function testDeviceWithoutOwningLineIsDropped(): void {
		$rRow = ['table' => 'mag_devices', 'mag_id' => 3, 'mac' => 'AA', 'user_id' => 99, 'admin_enabled' => 1, 'enabled' => 1];
		$this->assertNull($this->call('buildItem', $rRow, $this->ctx()));
	}

	public function testNoPermissionMeansNoActions(): void {
		$GLOBALS['rPermissions']['is_admin'] = 0;
		$this->assertSame([], $this->call('buildItem', $this->streamRow(1), $this->ctx())['data']['actions']);
		$this->assertSame([], $this->call('buildItem', $this->lineRow(), $this->ctx())['data']['actions']);
	}

	public function testEnvelopeIsExactlyTheContract(): void {
		$rStream = $this->call('buildItem', $this->streamRow(1), $this->ctx());
		$rEnvelope = $this->call('envelope', [$rStream, null]);
		$this->assertSame(['result' => true, 'total_count' => 1, 'items' => [$rStream]], $rEnvelope);
	}

	public function testEmptyEnvelopeHasNoPlaceholderItem(): void {
		$this->assertSame(['result' => true, 'total_count' => 0, 'items' => []], $this->call('envelope', []));
		// Non-contract entities never reach the client.
		$rBogus = ['id' => 'x#1', 'url' => null, 'text' => 'x', 'entity' => 'no_results', 'data' => null];
		$this->assertSame(0, $this->call('envelope', [$rBogus])['total_count']);
	}

	public function testEnvelopeEncodesToJson(): void {
		$rJson = json_encode($this->call('envelope', [$this->call('buildItem', $this->lineRow(), $this->ctx())]));
		$this->assertIsString($rJson);
		$rDecoded = json_decode($rJson, true);
		$this->assertSame(1, $rDecoded['total_count']);
		$this->assertSame('line', $rDecoded['items'][0]['entity']);
	}
}
