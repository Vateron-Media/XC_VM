<?php

use PHPUnit\Framework\TestCase;

/**
 * Saving a bouquet from the admin form looks its posted items up by id. The
 * four lists of `bouquet_data` reach the database as integers only: the
 * series list like the stream, movie and radio lists beside it.
 *
 * A save answers through the admin globals and starts the background rebuild,
 * so each one runs in a child PHP against its own empty database, with a
 * stand-in for the PHP binary the rebuild would be started with.
 */
final class AuditBouquetSaveTest extends TestCase {
	/**
	 * Save a bouquet holding $rPosted as an administrator, with the series 7, 8 and 9 on the panel:
	 * a new one, or the bouquet $rEdit, which holds series 9 until then.
	 *
	 * @return array{status: int, insert_id: mixed, queries: list<string>, series: mixed, free: list<int>}
	 *         `free`: whether another session could have the bouquet $rEdit when its row was written, then after the save.
	 */
	private function save(array $rPosted, ?int $rEdit = null): array {
		$rCode = 'define("PHP_BIN", "/bin/true");'
			. 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
			. 'error_reporting(E_ERROR | E_PARSE);'
			. 'define("STATUS_SUCCESS", 1);'
			. 'define("STATUS_FAILURE", 0);'
			. '$db = new TestDb();'
			. 'foreach (["bouquets", "streams", "streams_series"] as $rTable) {'
			. ' $db->exec(\XcVm\Tests\Support\InstallSchema::table($rTable));'
			. '}'
			. '$db->exec("INSERT INTO `streams` (`id`, `type`, `stream_display_name`) VALUES (1, 1, \'News\'), (2, 2, \'Film\'), (4, 4, \'Radio\')");'
			. '$db->exec("INSERT INTO `streams_series` (`id`, `title`) VALUES (7, \'First\'), (8, \'Second\'), (9, \'Third\')");'
			. '$rUserInfo = ["id" => 1, "member_group_id" => 1];'
			. '$rPermissions = ["is_admin" => 1, "advanced" => []];'
			// The insert id as the panel's connection gives it: the last statement's, not the last one there was.
			. '$rLive = new class($db) extends \XcVm\Core\Database\DatabaseHandler {'
			. ' public function __construct(private TestDb $rDb) { $this->dbh = true; }'
			. ' public function query($query, ...$args): bool { return $this->rDb->query($query, ...$args); }'
			. ' public function get_rows($use_id = false, $column_as_id = "", $unique_row = true, $sub_row_id = "") { return $this->rDb->get_rows($use_id, $column_as_id, $unique_row, $sub_row_id); }'
			. ' public function get_row() { return $this->rDb->get_row(); }'
			. ' public function get_raw_row(): ?array { return $this->rDb->get_raw_row(); }'
			. ' public function num_rows() { return $this->rDb->num_rows(); }'
			. ' public function last_insert_id() { return (int) $this->rDb->pdo->lastInsertId(); }'
			. '};'
			. '$rLog = new \XcVm\Tests\Support\QueryLogDb($rLive);'
			. '\XcVm\Domain\Bouquet\BouquetService::setDb($rLog);'
			. '$rForm = ["bouquet_name" => "Sports", "bouquet_data" => ' . var_export(json_encode($rPosted), true) . '];'
			. '$rFree = [];'
			. ($rEdit === null ? '' : '$rForm["edit"] = ' . $rEdit . ';'
				. '$db->exec("INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_channels`, `bouquet_movies`, `bouquet_radios`, `bouquet_series`, `bouquet_order`) VALUES (' . $rEdit . ', \'Sport\', \'[]\', \'[]\', \'[]\', \'[9]\', 1)");'
				. '$rOther = TestDb::connect($db->schema());'
				. '$rAsk = static function () use (&$rFree, $rOther): void {'
				. ' $rFree[] = (int) $rOther->query("SELECT IS_FREE_LOCK(CONCAT(DATABASE(), \'.bouquet_' . $rEdit . '\'))")->fetchColumn();'
				. '};'
				. '$rLog->rBefore = static function (string $rQuery) use ($rAsk): void {'
				. ' if (str_starts_with($rQuery, "REPLACE INTO `bouquets`")) {'
				. '  $rAsk();'
				. ' }'
				. '};')
			. '$rReturn = \XcVm\Domain\Bouquet\BouquetService::process($rForm);'
			. ($rEdit === null ? '' : '$rAsk();')
			. '$db->query("SELECT `bouquet_series` FROM `bouquets`;");'
			. 'echo json_encode(["status" => $rReturn["status"], "insert_id" => $rReturn["data"]["insert_id"] ?? null, "queries" => $rLog->rQueries, "series" => json_decode((string) $db->get_col(), true), "free" => $rFree]);';

		$rProc = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-r', $rCode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = stream_get_contents($rPipes[2]);
		proc_close($rProc);

		$rAnswer = json_decode($rOut, true);
		$this->assertIsArray($rAnswer, $rOut . $rErr);

		return $rAnswer;
	}

	/** @return list<string> The statements of $rQueries that read `streams_series`. */
	private static function seriesLookups(array $rQueries): array {
		return array_values(array_filter($rQueries, static fn(string $rQuery): bool => str_contains($rQuery, '`streams_series`')));
	}

	public function testTheSeriesOfABouquetAreSaved(): void {
		$rSaved = $this->save(['stream' => [1], 'movies' => [2], 'radios' => [4], 'series' => [7, 9, 404]]);

		$this->assertSame(1, $rSaved['status']);
		$this->assertSame([7, 9], $rSaved['series'], 'the posted series the panel has');
	}

	public function testTheSeriesListIsLookedUpAsIntegers(): void {
		$rSaved = $this->save(['stream' => [], 'movies' => [], 'radios' => [], 'series' => ['7', '8) OR (1=1']]);

		$this->assertSame(['SELECT `id` FROM `streams_series` WHERE `id` IN (7,8);'], self::seriesLookups($rSaved['queries']));
		$this->assertSame(1, $rSaved['status']);
		$this->assertSame([7, 8], $rSaved['series']);
	}

	public function testASeriesListWithoutAnIdLooksNothingUp(): void {
		$rSaved = $this->save(['stream' => [], 'movies' => [], 'radios' => [], 'series' => ['x', '0', '-3']]);

		$this->assertSame([], self::seriesLookups($rSaved['queries']));
		$this->assertSame(1, $rSaved['status']);
		$this->assertSame([], $rSaved['series']);
	}

	/**
	 * A writer that adds an item reads a list of the bouquet and writes it back
	 * (BouquetService::addItems()); a save of the bouquet that came between the
	 * two would have that list put back as it was. The save waits its turn.
	 */
	public function testAnEditedBouquetIsHeldWhileItsListsAreSaved(): void {
		$rSaved = $this->save(['stream' => [1], 'movies' => [2], 'radios' => [4], 'series' => [8, 7]], 3);

		$this->assertSame(1, $rSaved['status']);
		$this->assertSame(3, (int) $rSaved['insert_id'], 'the save answers with the bouquet it wrote');
		$this->assertSame([8, 7], $rSaved['series']);
		$this->assertSame([0, 1], $rSaved['free'], 'no other writer can have the bouquet while its lists are saved, and any can after');
	}
}
