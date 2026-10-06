<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ToolsCommand;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * The bouquet scan (`tools bouquets`, started after a stream or a series is
 * deleted and after a bouquet is saved or deleted) takes out of each bouquet
 * what no longer exists. It works on a bouquet as every writer does
 * (BouquetService::lock()): the bouquet is held from the read of its lists to
 * their write, what exists is asked while it is held, so an item made while
 * the scan runs stays, and a bouquet whose lists do not change is not written.
 *
 * Each delete starts a scan of its own, and every scan holds each bouquet in
 * turn, so the writers of a bouquet wait behind all that run at once: one
 * scan runs, one waits for it, and a scan that finds one waiting leaves it
 * the work.
 */
final class AuditBouquetFollowScanTest extends TestCase {
	private TestDb $db;

	private QueryLogDb $rLog;

	private PDO $rOther;

	protected function setUp(): void {
		$this->db = new TestDb();
		foreach (['bouquets', 'streams', 'streams_series'] as $rTable) {
			$this->db->exec(InstallSchema::table($rTable));
		}
		$this->db->exec('INSERT INTO `streams` (`id`, `type`) VALUES (10, 1), (11, 1), (20, 2), (40, 4)');
		$this->db->exec("INSERT INTO `streams_series` (`id`, `title`) VALUES (5, 'Show')");
		$this->rOther = TestDb::connect($this->db->schema());
		$this->rLog = new QueryLogDb($this->db);
		DatabaseFactory::set($this->rLog);
		// Both read DatabaseFactory's handle, never one another test injected.
		foreach ([ToolsCommand::class, BouquetService::class] as $rClass) {
			(new ReflectionProperty($rClass, 'db'))->setValue(null, null);
		}
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
	}

	private function bouquet(int $rID, ?string $rChannels, ?string $rMovies, ?string $rRadios, ?string $rSeries): void {
		$this->db->query('INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_channels`, `bouquet_movies`, `bouquet_radios`, `bouquet_series`) VALUES (?, ?, ?, ?, ?, ?);', $rID, 'Bouquet ' . $rID, $rChannels, $rMovies, $rRadios, $rSeries);
	}

	/** @return list<mixed> A bouquet's four lists as stored: channels, movies, radios, series. */
	private function lists(int $rID): array {
		$this->db->query('SELECT `bouquet_channels`, `bouquet_movies`, `bouquet_radios`, `bouquet_series` FROM `bouquets` WHERE `id` = ?;', $rID);
		return array_values($this->db->get_raw_row() ?? []);
	}

	/** @return list<string> */
	private function bouquetWrites(): array {
		return array_values(array_filter($this->rLog->writes(), static fn(string $rQuery): bool => str_starts_with($rQuery, 'UPDATE `bouquets`')));
	}

	/** Whether another session can have that bouquet now; 'scan' and 'scan_next' for the scan's own turn and the place behind it. */
	private function free(int|string $rID): int {
		return (int) $this->rOther->query("SELECT IS_FREE_LOCK(CONCAT(DATABASE(), '.bouquet_" . $rID . "'));")->fetchColumn();
	}

	private function scan(): void {
		(new ReflectionMethod(ToolsCommand::class, 'processBouquets'))->invoke(new ToolsCommand());
	}

	public function testAnItemMadeWhileAScanRunsStaysInItsBouquet(): void {
		$this->bouquet(1, '[10,99]', '[20]', '[40]', '[5]');
		// Another request, once the scan runs and before it reads the bouquet:
		// a channel is created, then added to the bouquet.
		$this->rLog->rBefore = function (string $rQuery): void {
			if (str_starts_with($rQuery, 'SELECT * FROM `bouquets`')) {
				$this->rLog->rBefore = null;
				$this->db->exec('INSERT INTO `streams` (`id`, `type`) VALUES (30, 1)');
				$this->db->exec("UPDATE `bouquets` SET `bouquet_channels` = '[10,99,30]' WHERE `id` = 1");
			}
		};

		$this->scan();

		$this->assertNull($this->rLog->rBefore, 'the scan read the bouquet');
		$this->assertSame(['[10,30]', '[20]', '[40]', '[5]'], $this->lists(1));
	}

	public function testABouquetWhoseListsDoNotChangeIsNotWritten(): void {
		$this->bouquet(1, '[10,11]', '[20]', '[40]', '[5]');
		$this->bouquet(2, '[]', '[]', '[]', '[]');

		$this->scan();

		$this->assertSame([], $this->bouquetWrites());
		$this->assertSame(['[10,11]', '[20]', '[40]', '[5]'], $this->lists(1));
	}

	public function testABouquetStaysAsItIsWhenWhatExistsCannotBeRead(): void {
		$this->bouquet(1, '[10,99]', '[20]', '[40]', '[5,77]');
		$this->rLog->rRefuse = '/^SELECT `id` FROM `streams`/';

		$this->scan();

		$this->assertSame([], $this->bouquetWrites());
		$this->assertSame(['[10,99]', '[20]', '[40]', '[5,77]'], $this->lists(1));
		$this->assertSame(1, $this->free(1), 'and it is not left held');
	}

	public function testWhatIsGoneLeavesABouquetThatIsHeldWhileItIsWritten(): void {
		$this->bouquet(1, '[10,11]', '[20]', '[40]', '[5]');
		$this->bouquet(2, '[11,99,"10",0,-4]', null, '[98,40]', '{"0":77,"2":5}');
		$rDuring = [];
		$this->rLog->rBefore = function (string $rQuery, array $rArgs) use (&$rDuring): void {
			if (str_starts_with($rQuery, 'UPDATE `bouquets`')) {
				$rDuring[] = [(int) end($rArgs), $this->free((int) end($rArgs))];
			}
		};

		$this->scan();

		$this->assertSame(['[11,10]', '[]', '[40]', '[5]'], $this->lists(2), 'what exists stays, in its order, as a list of numbers');
		$this->assertSame([[2, 0]], $rDuring, 'no other writer can have the bouquet while its lists are written');
		$this->assertSame([1, 1], [$this->free(1), $this->free(2)], 'and any can once the scan is over');
	}

	public function testAScanLeavesTheWorkToOneThatAlreadyWaits(): void {
		$this->bouquet(1, '[10,99]', '[20]', '[40]', '[5]');
		// Another scan waits for the one that runs: it starts after this one was asked for.
		$this->rOther->query("SELECT GET_LOCK(CONCAT(DATABASE(), '.bouquet_scan_next'), 0);");

		$this->scan();

		$this->assertSame([], array_values(preg_grep('/`bouquets`/', $this->rLog->rQueries)), 'no bouquet is read or written');
		$this->assertSame(['[10,99]', '[20]', '[40]', '[5]'], $this->lists(1));
	}

	public function testAScanRunsWhenItCannotLearnWhetherOneWaits(): void {
		$this->bouquet(1, '[10,99]', '[20]', '[40]', '[5]');
		// The last answer on the connection is a 0, and the question is refused: that is not "one waits".
		$this->db->query('SELECT 0;');
		$this->rLog->rRefuse = '/GET_LOCK/';

		$this->scan();

		$this->assertSame(['[10]', '[20]', '[40]', '[5]'], $this->lists(1));
	}

	public function testOneScanRunsAndTheNextCanWaitBehindIt(): void {
		$this->bouquet(1, '[10,99]', '[20]', '[40]', '[5]');
		$rSeen = [];
		$this->rLog->rBefore = function (string $rQuery) use (&$rSeen): void {
			if (str_contains($rQuery, 'GET_LOCK') && str_contains($rQuery, ".bouquet_scan'")) {
				$rSeen['asking for its turn'] = [$this->free('scan_next')];
			} elseif (str_starts_with($rQuery, 'UPDATE `bouquets`')) {
				$rSeen['writing'] = [$this->free('scan'), $this->free('scan_next')];
			}
		};

		$this->scan();

		$this->assertSame(['asking for its turn' => [0], 'writing' => [0, 1]], $rSeen, 'it keeps the place behind the running scan until it has the turn, then frees that place for the next');
		$this->assertSame(['[10]', '[20]', '[40]', '[5]'], $this->lists(1));
		$this->assertSame([1, 1], [$this->free('scan'), $this->free('scan_next')], 'and gives the turn back when it is over');
	}
}
