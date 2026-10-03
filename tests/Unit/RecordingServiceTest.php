<?php

use XcVm\Core\Config\ConstantsInitializer;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Stream\StreamsChangedEvent;
use XcVm\Domain\Stream\RecordingService;
use PHPUnit\Framework\TestCase;

/**
 * RecordingService on the TestDb: scheduling validates and stores a recording,
 * deleting removes it, and both tell the recorded stream's node (R2). Under
 * SQLite, QueryHelper::verifyPostTable()'s `information_schema.columns` is an
 * attached table.
 */
final class RecordingServiceTest extends TestCase {

	private const COLUMNS = ['stream_id' => 'int', 'created_id' => 'int', 'category_id' => 'longtext', 'bouquets' => 'longtext', 'title' => 'mediumtext', 'start' => 'int', 'end' => 'int', 'source_id' => 'int', 'status' => 'tinyint'];

	private TestDb $db;
	private $globalDbBefore;
	/** @var int[][] */
	private array $changed = [];

	protected function setUp(): void {
		foreach (ConstantsInitializer::statuses() as $rName => $rValue) {
			if (!defined($rName)) {
				define($rName, $rValue);
			}
		}
		$this->db = new TestDb();
		if ($this->db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
			$this->markTestSkipped('Builds its own information_schema; SQLite only.');
		}
		$rColumns = [];
		foreach (array_keys(self::COLUMNS) as $rName) {
			$rColumns[] = '`' . $rName . '` TEXT';
		}
		$this->db->exec('CREATE TABLE recordings (id INTEGER PRIMARY KEY AUTOINCREMENT, ' . implode(', ', $rColumns) . ');');
		$this->db->pdo->exec("ATTACH DATABASE ':memory:' AS `information_schema`");
		$this->db->pdo->exec('CREATE TABLE `information_schema`.`columns` (`table_schema` text, `table_name` text, `column_name` text, `column_default` text, `is_nullable` text, `data_type` text, `ordinal_position` int)');
		$this->db->pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'xc_vm', 0);
		$rPosition = 0;
		foreach (self::COLUMNS as $rName => $rType) {
			$this->db->query('INSERT INTO `information_schema`.`columns` VALUES (?, ?, ?, ?, ?, ?, ?)', 'xc_vm', 'recordings', $rName, 'NULL', 'YES', $rType, ++$rPosition);
		}

		RecordingService::setDb($this->db);
		$this->globalDbBefore = $GLOBALS['db'] ?? null;
		$GLOBALS['db'] = $this->db;
		EventDispatcher::listen(StreamsChangedEvent::class, function (StreamsChangedEvent $rEvent): void {
			$this->changed[] = $rEvent->streamIds;
		});
	}

	protected function tearDown(): void {
		EventDispatcher::unlisten(StreamsChangedEvent::class);
		$GLOBALS['db'] = $this->globalDbBefore;
	}

	public function testScheduleRefusesAMissingTitleOrSource(): void {
		$this->assertSame(STATUS_NO_TITLE, RecordingService::schedule(['source_id' => 6])['status']);
		$this->assertSame(STATUS_NO_SOURCE, RecordingService::schedule(['title' => 'News'])['status']);
		$this->assertSame([], RecordingService::getAll());
		$this->assertSame([], $this->changed);
	}

	public function testScheduleStoresTheRecordingAndTellsItsStream(): void {
		$rResult = RecordingService::schedule(['title' => 'News', 'source_id' => 6, 'stream_id' => 11, 'start' => 100, 'end' => 200, 'bouquets' => ['3', '4'], 'category_id' => ['7']]);

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$rRows = RecordingService::getAll();
		$this->assertCount(1, $rRows);
		$this->assertSame(['News', '[3,4]', '[7]'], [$rRows[0]['title'], $rRows[0]['bouquets'], $rRows[0]['category_id']]);
		$this->assertSame([[11]], $this->changed);
	}

	public function testGetAllListsNewestFirst(): void {
		$this->db->exec("INSERT INTO recordings (id, title) VALUES (1, 'old'), (2, 'new');");

		$this->assertSame(['new', 'old'], array_column(RecordingService::getAll(), 'title'));
	}

	public function testDeleteRemovesTheRowAndTellsItsStream(): void {
		$this->db->exec("INSERT INTO recordings (id, stream_id, source_id, title) VALUES (5, 11, 6, 'News');");

		$this->assertTrue(RecordingService::delete(5));
		$this->assertSame([], RecordingService::getAll());
		$this->assertSame([[11]], $this->changed);

		RecordingService::delete(99);
		$this->assertSame([[11]], $this->changed, 'an unknown id changes nothing');
	}
}
