<?php

use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Stream\RadioService;
use PHPUnit\Framework\TestCase;

/**
 * RadioService pure helpers extracted from process() during decomposition.
 *
 * These lock the behaviour of the extracted logic so the surrounding 250-line
 * method can be refactored safely. Both helpers are private static and pure, so
 * they are exercised directly via reflection.
 */
final class RadioServiceTest extends TestCase {

	private TestDb $db;

	protected function setUp(): void {
		$this->db = new TestDb();
		$this->db->exec(
			'CREATE TABLE bouquets (id INTEGER PRIMARY KEY AUTO_INCREMENT, bouquet_name TEXT, bouquet_channels TEXT, bouquet_movies TEXT, bouquet_series TEXT, bouquet_radios TEXT, bouquet_order INTEGER DEFAULT 0);
			 CREATE TABLE streams_categories (id INTEGER PRIMARY KEY AUTO_INCREMENT, category_type TEXT, category_name TEXT, parent_id INTEGER, cat_order INTEGER, is_adult INTEGER);
			 CREATE TABLE streams_options (id INTEGER PRIMARY KEY AUTO_INCREMENT, stream_id INTEGER, argument_id INTEGER, value TEXT);
			 CREATE TABLE streams_servers (server_stream_id INTEGER PRIMARY KEY AUTO_INCREMENT, stream_id INTEGER, server_id INTEGER, parent_id INTEGER, on_demand INTEGER);'
		);
	}

	/** Read streams_servers rows for a stream, keyed by server_id. */
	private function serverRows(int $rStreamID): array {
		$this->db->query('SELECT `server_id`, `parent_id`, `on_demand` FROM `streams_servers` WHERE `stream_id` = ? ORDER BY `server_id`;', $rStreamID);
		$out = [];
		foreach ($this->db->get_rows() as $rRow) {
			$out[(int) $rRow['server_id']] = ['parent' => $rRow['parent_id'], 'od' => (int) $rRow['on_demand']];
		}
		return $out;
	}

	private function call(string $method, ...$args) {
		$m = new ReflectionMethod(RadioService::class, $method);
		$m->setAccessible(true);
		return $m->invoke(null, ...$args);
	}

	/** Read streams_options for a stream as [argument_id => value]. */
	private function options(int $rStreamID): array {
		$this->db->query('SELECT `argument_id`, `value` FROM `streams_options` WHERE `stream_id` = ? ORDER BY `argument_id`;', $rStreamID);
		$out = [];
		foreach ($this->db->get_rows() as $rRow) {
			$out[(int) $rRow['argument_id']] = $rRow['value'];
		}
		return $out;
	}

	// ── buildAutoRestart ─────────────────────────────────────────────

	public function testAutoRestartEmptyWhenNoDays(): void {
		$this->assertSame('', $this->call('buildAutoRestart', []));
	}

	public function testAutoRestartBuildsScheduleFromValidInput(): void {
		$out = $this->call('buildAutoRestart', [
			'days_to_restart' => ['mon', 'tue'],
			'time_to_restart' => '03:30',
		]);
		$this->assertSame(['days' => ['mon', 'tue'], 'at' => '03:30'], $out);
	}

	public function testAutoRestartReindexesAssociativeDays(): void {
		$out = $this->call('buildAutoRestart', [
			'days_to_restart' => [1 => 'mon', 3 => 'wed'],
			'time_to_restart' => '23:59',
		]);
		$this->assertSame(['days' => ['mon', 'wed'], 'at' => '23:59'], $out, 'days reindexed to a list');
	}

	public function testAutoRestartEmptyOnInvalidTime(): void {
		$this->assertSame('', $this->call('buildAutoRestart', [
			'days_to_restart' => ['mon'],
			'time_to_restart' => '25:00',
		]));
	}

	public function testAutoRestartEmptyWhenTimeMissing(): void {
		// days set but no time_to_restart — must not warn, and yields no schedule.
		$this->assertSame('', $this->call('buildAutoRestart', ['days_to_restart' => ['mon']]));
	}

	// ── resolveSelectedIds ───────────────────────────────────────────

	public function testResolveSelectedIdsMapsCreatedNamesAndNumericIds(): void {
		$out = $this->call('resolveSelectedIds', ['NewBq', '5', 'not-a-number'], ['NewBq' => 42]);
		$this->assertSame([42, 5], $out, 'created-name -> new id; numeric -> int; junk skipped');
	}

	public function testResolveSelectedIdsPrefersCreatedMapOverNumeric(): void {
		// A created entry keyed by a numeric-looking name still resolves via the map.
		$out = $this->call('resolveSelectedIds', ['7'], ['7' => 99]);
		$this->assertSame([99], $out);
	}

	public function testResolveSelectedIdsKeepsDuplicatesAndOrder(): void {
		$this->assertSame([3, 3, 8], $this->call('resolveSelectedIds', ['3', '3', '8'], []));
	}

	public function testResolveSelectedIdsEmpty(): void {
		$this->assertSame([], $this->call('resolveSelectedIds', [], []));
	}

	// ── createMissingBouquets / createMissingCategories (TestDb) ──────

	public function testCreateMissingBouquetsInsertsAndMapsNames(): void {
		$map = $this->call('createMissingBouquets', $this->db, ['bouquet_create_list' => json_encode(['News', 'Sports'])]);
		$this->assertSame(['News' => 1, 'Sports' => 2], $map, 'name => new id');

		$this->db->query('SELECT `bouquet_name` FROM `bouquets` ORDER BY `id`;');
		$this->assertSame(['News', 'Sports'], $this->db->get_column());
	}

	public function testCreateMissingBouquetsEmptyWhenNoList(): void {
		$this->assertSame([], $this->call('createMissingBouquets', $this->db, []));
	}

	public function testCreateMissingCategoriesInsertsAndMapsNames(): void {
		$map = $this->call('createMissingCategories', $this->db, ['category_create_list' => json_encode(['Rock', 'Jazz'])]);
		$this->assertSame(['Rock' => 1, 'Jazz' => 2], $map);

		$this->db->query("SELECT `category_type` FROM `streams_categories` WHERE `id` = 1;");
		$this->assertSame('radio', $this->db->get_col(), 'categories are typed radio');
	}

	// ── saveStreamOptions (TestDb) ───────────────────────────────────

	public function testSaveStreamOptionsReplacesRowsWithMappedArguments(): void {
		// A stale row that the leading DELETE must clear.
		$this->db->query('INSERT INTO `streams_options` (`stream_id`, `argument_id`, `value`) VALUES (5, 99, ?);', 'stale');

		$this->call('saveStreamOptions', $this->db, 5, [
			'user_agent'         => 'UA',
			'cookie'             => 'C',
			'skip_ffprobe'       => 'on',
			'force_input_acodec' => 'aac',
			// http_proxy + headers intentionally absent
		]);

		$this->assertSame([1 => 'UA', 17 => 'C', 20 => 'aac', 21 => '1'], $this->options(5));
	}

	public function testSaveStreamOptionsWithNoFieldsJustClears(): void {
		$this->db->query('INSERT INTO `streams_options` (`stream_id`, `argument_id`, `value`) VALUES (7, 1, ?);', 'old');
		$this->call('saveStreamOptions', $this->db, 7, []);
		$this->assertSame([], $this->options(7), 'all options cleared, none re-added');
	}

	public function testSaveStreamOptionsSkipsBlankValues(): void {
		$this->call('saveStreamOptions', $this->db, 8, ['user_agent' => '', 'http_proxy' => 'p']);
		$this->assertSame([2 => 'p'], $this->options(8), 'empty user_agent skipped, http_proxy kept');
	}

	// ── syncServerTree (TestDb) ──────────────────────────────────────

	public function testSyncServerTreeInsertsTreeNodesSkippingRoot(): void {
		$tree = [
			['id' => '0', 'parent' => '#'],      // root — skipped
			['id' => '2', 'parent' => 'source'], // parent NULL
			['id' => '3', 'parent' => '2'],      // parent 2
		];
		$this->call('syncServerTree', $this->db, 10, $tree, ['2'], []);

		$rows = $this->serverRows(10);
		$this->assertSame([2, 3], array_keys($rows), 'root skipped; servers 2 and 3 attached');
		$this->assertNull($rows[2]['parent'], "'source' parent stored as NULL");
		$this->assertSame(1, $rows[2]['od'], 'server 2 flagged on-demand');
		$this->assertSame(2, (int) $rows[3]['parent']);
		$this->assertSame(0, $rows[3]['od']);
	}

	public function testSyncServerTreeUpdatesExistingAttachment(): void {
		$this->db->query('INSERT INTO `streams_servers` (`server_stream_id`, `stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES (55, 10, 2, 9, 0);');

		$this->call('syncServerTree', $this->db, 10, [['id' => '2', 'parent' => 'source']], ['2'], [2 => 55]);

		$rows = $this->serverRows(10);
		$this->assertCount(1, $rows, 'existing attachment updated, not duplicated');
		$this->assertNull($rows[2]['parent'], 'parent updated to NULL');
		$this->assertSame(1, $rows[2]['od'], 'on_demand updated to 1');
	}

	// ── syncBouquets (TestDb, via BouquetService) ────────────────────

	public function testSyncBouquetsAttachesSelectedAndDetachesOnEdit(): void {
		$this->db->query('INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_channels`, `bouquet_movies`, `bouquet_series`, `bouquet_radios`) VALUES (1, "A", "[]", "[]", "[]", "[]"), (2, "B", "[]", "[]", "[]", "[99]");');
		BouquetService::setDb($this->db);

		// Edit selecting only bouquet 1 → attach to 1, detach from 2.
		$this->call('syncBouquets', 99, [1], true);

		$this->assertSame([99], $this->radios(1), 'attached to the selected bouquet');
		$this->assertSame([], $this->radios(2), 'detached from the unselected bouquet on edit');
	}

	// ── computeCategoryChange (pure) ─────────────────────────────────

	public function testComputeCategoryChangeAddUnionsExisting(): void {
		// selected first, then existing not already selected.
		$this->assertSame([2, 3, 1], $this->call('computeCategoryChange', 'ADD', [1, 2], [2, 3]));
	}

	public function testComputeCategoryChangeDelRemovesSelectedFromExisting(): void {
		$this->assertSame([1, 3], $this->call('computeCategoryChange', 'DEL', [1, 2, 3], [2]));
	}

	public function testComputeCategoryChangeSetReplacesWithSelected(): void {
		$this->assertSame([5, 6], $this->call('computeCategoryChange', 'SET', [1, 2], [5, 6]), 'non-ADD/DEL type replaces');
	}

	public function testComputeCategoryChangeAddWithNoExisting(): void {
		$this->assertSame([5, 6], $this->call('computeCategoryChange', 'ADD', [], [5, 6]));
	}

	public function testComputeCategoryChangeDelWithNoSelectionKeepsAll(): void {
		$this->assertSame([1, 2, 3], $this->call('computeCategoryChange', 'DEL', [1, 2, 3], []));
	}

	// ── planBouquetChanges (pure) ────────────────────────────────────

	public function testPlanBouquetsSetAttachesSelectedDetachesRest(): void {
		$plan = $this->call('planBouquetChanges', 'SET', [1, 2], [['id' => 1], ['id' => 2], ['id' => 3]]);
		$this->assertSame([1, 2], $plan['add']);
		$this->assertSame([3], $plan['del'], 'detach from bouquets not selected');
	}

	public function testPlanBouquetsAddOnlyAttaches(): void {
		$plan = $this->call('planBouquetChanges', 'ADD', [5], [['id' => 1], ['id' => 5]]);
		$this->assertSame([5], $plan['add']);
		$this->assertSame([], $plan['del']);
	}

	public function testPlanBouquetsDelOnlyDetaches(): void {
		$plan = $this->call('planBouquetChanges', 'DEL', [7], [['id' => 7]]);
		$this->assertSame([], $plan['add']);
		$this->assertSame([7], $plan['del']);
	}

	/** Read a bouquet's bouquet_radios list. */
	private function radios(int $rBouquetID): array {
		$this->db->query('SELECT `bouquet_radios` FROM `bouquets` WHERE `id` = ?;', $rBouquetID);
		return json_decode($this->db->get_col(), true);
	}
}
