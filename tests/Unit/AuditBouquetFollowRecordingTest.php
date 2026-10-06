<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Stream\RecordingFinalizer;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * A finished recording's VOD goes into the recording's bouquets
 * (RecordingFinalizer::create()). The movie list of a bouquet is read and
 * written back whole, as every writer of a bouquet does: the bouquet is held
 * for the finalizer from its read to its write (BouquetService::lock()), and
 * a bouquet that has the VOD already is not written again.
 */
final class AuditBouquetFollowRecordingTest extends TestCase {
	private TestDb $db;

	private QueryLogDb $rLog;

	private PDO $rOther;

	/** @var list<int> At each write of a bouquet: whether another session could have had that bouquet (1) or not (0). */
	private array $rDuring = [];

	protected function setUp(): void {
		$this->db = new TestDb();
		foreach (['streams', 'recordings', 'bouquets'] as $rTable) {
			$this->db->exec(InstallSchema::table($rTable));
		}
		$this->db->query('INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_movies`) VALUES (9, ?, ?), (8, ?, NULL);', 'Films', '[700]', 'New');
		$this->rOther = TestDb::connect($this->db->schema());
		$this->rLog = new QueryLogDb($this->db);
		$this->rLog->rBefore = function (string $rQuery, array $rArgs): void {
			if (str_starts_with($rQuery, 'UPDATE `bouquets`')) {
				$this->rDuring[] = $this->free((int) end($rArgs));
			}
		};
		DatabaseFactory::set($this->rLog);
		// Both read DatabaseFactory's handle, never one another test injected.
		foreach ([RecordingFinalizer::class, BouquetService::class] as $rClass) {
			(new ReflectionProperty($rClass, 'db'))->setValue(null, null);
		}
		EventDispatcher::resetInstance();
	}

	protected function tearDown(): void {
		EventDispatcher::resetInstance();
		DatabaseFactory::reset();
	}

	/** Recording 1 of server 5, to go into these bouquets. */
	private function recording(string $rBouquets): void {
		$this->db->query('INSERT INTO `recordings` (`id`, `stream_id`, `category_id`, `bouquets`, `title`, `description`, `start`, `end`, `source_id`, `status`) VALUES (1, 100, ?, ?, ?, ?, 1800000000, 1800003600, 5, 1);', '[3]', $rBouquets, 'Match', 'Final');
	}

	/** Whether another session can have that bouquet now. */
	private function free(int $rBouquetID): int {
		return (int) $this->rOther->query("SELECT IS_FREE_LOCK(CONCAT(DATABASE(), '.bouquet_', " . $rBouquetID . '));')->fetchColumn();
	}

	private function movies(int $rBouquetID): mixed {
		$this->db->query('SELECT `bouquet_movies` FROM `bouquets` WHERE `id` = ?;', $rBouquetID);
		return $this->db->get_col();
	}

	/** @return list<string> */
	private function bouquetWrites(): array {
		return array_values(array_filter($this->rLog->writes(), static fn(string $rQuery): bool => str_starts_with($rQuery, 'UPDATE `bouquets`')));
	}

	public function testABouquetIsHeldForOneWriterWhileAFinishedRecordingIsAdded(): void {
		$this->recording('[9]');

		$rID = RecordingFinalizer::create(1, 5, null);

		$this->assertSame('[700,' . $rID . ']', $this->movies(9));
		$this->assertSame([0], $this->rDuring, 'no other writer can have the bouquet while its list is written');
		$this->assertSame(1, $this->free(9), 'and any can once it is');
	}

	public function testABouquetThatHasTheRecordingAlreadyIsNotWrittenAgain(): void {
		$this->recording('[9,9]');

		$rID = RecordingFinalizer::create(1, 5, null);

		$this->assertSame('[700,' . $rID . ']', $this->movies(9));
		$this->assertCount(1, $this->bouquetWrites());
	}

	public function testARecordingGoesIntoABouquetWithNoListYetAndPastOneThatIsGone(): void {
		$this->recording('[404,8]');

		$rID = RecordingFinalizer::create(1, 5, null);

		$this->assertIsInt($rID);
		$this->assertSame('[' . $rID . ']', $this->movies(8));
		$this->assertSame([1, 1], [$this->free(404), $this->free(8)], 'neither bouquet stays held');
	}
}
