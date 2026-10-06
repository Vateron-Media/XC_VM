<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

if (!defined('STATUS_SUCCESS')) {
	define('STATUS_SUCCESS', 1);
}

/**
 * BouquetService::addItems() and removeItems() read one of a bouquet's lists
 * and write it back whole. Several writers work on one bouquet at the same
 * time (a folder import runs `thread_count` processes), and each one's change
 * is kept: a bouquet is held for one writer from the read to the write. The
 * page that reorders a bouquet's lists waits for that writer before it writes.
 */
final class AuditBouquetItemsTest extends TestCase {
	/** Processes writing to the bouquet at once, and the items each one writes. */
	private const WRITERS = 4;
	private const EACH = 50;

	private TestDb $db;

	protected function setUp(): void {
		$this->db = new TestDb();
		$this->db->exec(InstallSchema::table('bouquets'));
		$this->db->query('INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_channels`, `bouquet_movies`, `bouquet_radios`, `bouquet_series`) VALUES (1, ?, ?, ?, ?, ?);', 'Films', '[]', '[5,6]', '[]', '[]');
		BouquetService::setDb($this->db);
	}

	protected function tearDown(): void {
		(new ReflectionProperty(BouquetService::class, 'db'))->setValue(null, null);
	}

	/** @return list<int> */
	private function movies(): array {
		$this->db->query('SELECT `bouquet_movies` FROM `bouquets` WHERE `id` = 1;');
		return json_decode($this->db->get_col(), true);
	}

	/** @return list<list<int>> The items of each writer: no two share one. */
	private static function shares(): array {
		return array_map(static fn(int $rWriter): array => range($rWriter * 1000 + 1, $rWriter * 1000 + self::EACH), range(1, self::WRITERS));
	}

	/**
	 * Call BouquetService::$rMethod on the movies of bouquet 1 for every item of
	 * $rShares, one item a call, each share in a process of its own, all at once.
	 *
	 * @param list<list<int>> $rShares
	 */
	private function inParallel(string $rMethod, array $rShares): void {
		$rWriters = [];
		foreach ($rShares as $rShare) {
			$rCode = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
				. '$db = new TestDb();'
				. '$db->exec("USE `' . $this->db->schema() . '`");'
				. '\XcVm\Domain\Bouquet\BouquetService::setDb($db);'
				. 'echo "ready\n";'
				. 'fgets(STDIN);' // every writer starts on the same word
				. 'foreach (' . var_export($rShare, true) . ' as $rID) {'
				. ' \XcVm\Domain\Bouquet\BouquetService::' . $rMethod . '("movie", 1, $rID);'
				. '}';
			$rProc = proc_open([PHP_BINARY, '-r', $rCode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
			$rWriters[] = [$rProc, $rPipes];
		}
		foreach ($rWriters as [, $rPipes]) {
			$this->assertSame("ready\n", fgets($rPipes[1]), 'a writer did not start');
		}
		foreach ($rWriters as [, $rPipes]) {
			fwrite($rPipes[0], "go\n");
		}
		foreach ($rWriters as [$rProc, $rPipes]) {
			$rErr = (string) stream_get_contents($rPipes[2]);
			$this->assertSame(0, proc_close($rProc), $rErr);
		}
	}

	public function testItemsAddedByParallelWritersAreAllInTheBouquet(): void {
		$rShares = self::shares();

		$this->inParallel('addItems', $rShares);

		$rMovies = $this->movies();
		sort($rMovies);
		$this->assertSame(array_merge([5, 6], ...$rShares), $rMovies);
	}

	public function testItemsRemovedByParallelWritersAreAllOutOfTheBouquet(): void {
		$rShares = self::shares();
		$this->db->query('UPDATE `bouquets` SET `bouquet_movies` = ? WHERE `id` = 1;', json_encode(array_merge([5, 6], ...$rShares)));

		$this->inParallel('removeItems', $rShares);

		$this->assertSame([5, 6], $this->movies());
	}

	/**
	 * Note in $rDuring, each time a list of a bouquet is written, whether another
	 * session could have bouquet 1 at that moment (1) or not (0).
	 *
	 * @param list<int> $rDuring
	 * @return Closure(int): int Whether another session can have that bouquet now.
	 */
	private function watch(array &$rDuring): Closure {
		$rOther = TestDb::connect($this->db->schema());
		$rFree = static fn(int $rBouquetID): int => (int) $rOther->query("SELECT IS_FREE_LOCK(CONCAT(DATABASE(), '.bouquet_', " . $rBouquetID . '));')->fetchColumn();
		$rLog = new QueryLogDb($this->db);
		$rLog->rBefore = static function (string $rQuery) use (&$rDuring, $rFree): void {
			if (str_starts_with($rQuery, 'UPDATE `bouquets`')) {
				$rDuring[] = $rFree(1);
			}
		};
		BouquetService::setDb($rLog);

		return $rFree;
	}

	public function testABouquetIsHeldForOneWriterWhileAListIsWritten(): void {
		$rDuring = [];
		$rFree = $this->watch($rDuring);

		BouquetService::addItems('movie', 1, [8]);
		BouquetService::removeItems('movie', 1, [5]);
		BouquetService::addItems('movie', 404, [8]);

		$this->assertSame([6, 8], $this->movies());
		$this->assertSame([0, 0], $rDuring, 'no other writer can have the bouquet while its list is written');
		$this->assertSame([1, 1], [$rFree(1), $rFree(404)], 'and any can once it is: also a bouquet that is not there');
	}

	public function testABouquetIsHeldWhileItsListsAreReordered(): void {
		$rDuring = [];
		$rFree = $this->watch($rDuring);

		$rReturn = BouquetService::reorder(['reorder' => '1', 'stream_order_array' => json_encode(['stream' => [], 'series' => [], 'movie' => [6, 5], 'radio' => []])]);

		$this->assertSame(['status' => STATUS_SUCCESS, 'data' => ['insert_id' => '1']], $rReturn);
		$this->assertSame([6, 5], $this->movies());
		$this->assertSame([0], $rDuring, 'no other writer can have the bouquet while its lists are written');
		$this->assertSame(1, $rFree(1), 'and any can once they are');
	}
}
