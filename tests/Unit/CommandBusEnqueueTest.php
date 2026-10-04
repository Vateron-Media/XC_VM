<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Core\Logging\FileLogger;
use XcVm\Domain\Cluster\ClusterBus;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterRoute;
use XcVm\Domain\Cluster\CommandBus;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * The panel's Database::query() catches every PDOException and answers
 * false: a refused INSERT (the unique `(server_id, seq)` a concurrent
 * enqueue took, a lost connection) does not throw. CommandBus::enqueue()
 * relied on a throw to retry, so it woke the node and returned a cmd_id for
 * a row it never stored, and ClusterRoute reported the command queued.
 * TestDb lets the exception through, which hid it; this double answers as
 * Database does.
 */
final class CommandBusEnqueueTest extends TestCase {
	private const SID = 17;

	private TestDb $rInner;

	/** Database's contract over rInner (falseDb()). */
	private DatabaseHandler $rDb;

	private FakeClusterCrypto $rCrypto;

	private string $rLog;

	protected function setUp(): void {
		$this->rInner = new TestDb();
		foreach (['029_create_cluster_nodes', '030_create_cluster_commands'] as $rName) {
			$rSql = (string) file_get_contents(MAIN_HOME . 'migrations/database/up/' . $rName . '.sql');
			$this->rInner->exec((string) $rSql);
		}
		$this->rInner->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `root_ready` tinyint(1) NOT NULL DEFAULT 0');
		$this->rDb = self::falseDb($this->rInner);
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix(1800000000000);
		ClusterBus::useSocket(sys_get_temp_dir() . '/no-such-bus-' . bin2hex(random_bytes(4)) . '/cluster.sock');
		SettingsManager::set(['cluster_api_enabled' => 1]);
		$this->rCrypto = new FakeClusterCrypto();
		ClusterRoute::useCrypto(fn() => $this->rCrypto);
		NodeRegistry::startEnrolment(self::SID, '0f8fad5b-d9cb-469f-a165-70867728950e', random_bytes(32), random_bytes(32), 1);
		NodeRegistry::update(self::SID, ['state' => 'active', 'mode' => 1, 'flows' => NodeRegistry::FLOW_COMMANDS]);
		$this->rLog = (string) tempnam(sys_get_temp_dir(), 'xcvm-log');
		FileLogger::setLogFile($this->rLog);
	}

	protected function tearDown(): void {
		FileLogger::setLogFile(null);
		@unlink($this->rLog);
		ClusterRoute::useCrypto(null);
		ClusterBus::useSocket(null);
		ClusterClock::fix(null);
		SettingsManager::set([]);
		DatabaseFactory::reset();
	}

	/** @return list<array{0: int, 1: string, 2: string}> [seq, cmd_id, type] */
	private function rows(): array {
		$this->rInner->query('SELECT `seq`, `cmd_id`, `type` FROM `cluster_commands` WHERE `server_id` = ? ORDER BY `seq`', self::SID);
		return array_map(static fn(array $rRow): array => [(int) $rRow['seq'], (string) $rRow['cmd_id'], (string) $rRow['type']], $this->rInner->get_rows());
	}

	/** A concurrent enqueue takes the seq: the write answers false, and the next try takes the one after. */
	public function testARefusedWriteIsRetriedWithAFreshSeq(): void {
		$this->rDb->rRace = function (): void {
			$this->rInner->query("INSERT INTO `cluster_commands` (`server_id`, `seq`, `cmd_id`, `type`, `class`, `payload`, `sig`, `state`, `created_at`, `exp`) VALUES (?, 1, 'other', 'conn.drop', 'R', '{}', '', 'queued', 0, 1800000600)", self::SID);
		};
		$rCmdID = CommandBus::enqueue($this->rCrypto, self::SID, 'conn.drop', ['uuid' => 'v1']);
		$this->assertSame([[1, 'other', 'conn.drop'], [2, $rCmdID, 'conn.drop']], $this->rows());
		$this->assertSame([2, 1], [$this->rDb->rInserts, $this->rDb->rRefused], 'the first try was refused, the second stored');
		$this->assertSame(2, json_decode(CommandBus::pending(self::SID, 1)[0]['doc'], true)['seq'], 'the signed seq is the stored one');
	}

	/** A write that keeps being refused: no cmd_id for a row that is not there, and the route says so. */
	public function testAWriteNeverStoredIsNeverReportedQueued(): void {
		$this->rDb->rRefuseAll = true;
		try {
			CommandBus::enqueue($this->rCrypto, self::SID, 'conn.drop', ['uuid' => 'v1']);
			$this->fail('a cmd_id for a row never stored');
		} catch (\RuntimeException $rE) {
			$this->assertSame('Command conn.drop for server 17 not stored after ' . CommandBus::ATTEMPTS . ' tries', $rE->getMessage());
		}
		$this->assertSame(CommandBus::ATTEMPTS, $this->rDb->rInserts);
		$this->assertSame([], $this->rows());

		$this->assertSame([true, false], ClusterRoute::kill(self::SID, 4242, false), 'routed, not queued');
		$rLog = array_map(static fn(string $rLine): array => (array) json_decode((string) base64_decode($rLine), true), file($this->rLog, FILE_IGNORE_NEW_LINES) ?: []);
		$this->assertSame(['Command conn.kill_worker for server 17 not queued (Command conn.kill_worker for server 17 not stored after ' . CommandBus::ATTEMPTS . ' tries)'], array_column($rLog, 'message'));
	}

	/** A handle that throws instead (TestDb, PDO in exception mode) is retried the same way. */
	public function testAThrowingWriteIsRetriedToo(): void {
		$this->rDb->rThrow = true;
		$this->rDb->rRace = function (): void {
			$this->rInner->query("INSERT INTO `cluster_commands` (`server_id`, `seq`, `cmd_id`, `type`, `class`, `payload`, `sig`, `state`, `created_at`, `exp`) VALUES (?, 1, 'other', 'conn.drop', 'R', '{}', '', 'queued', 0, 1800000600)", self::SID);
		};
		$rCmdID = CommandBus::enqueue($this->rCrypto, self::SID, 'conn.drop', ['uuid' => 'v1']);
		$this->assertSame([[1, 'other', 'conn.drop'], [2, $rCmdID, 'conn.drop']], $this->rows());
	}

	/**
	 * Database's contract over a TestDb: a statement that fails answers false
	 * (it never throws), or, with rThrow, throws as TestDb does. rRace runs
	 * once, just before the first INSERT into cluster_commands; rRefuseAll
	 * refuses every one.
	 */
	private static function falseDb(TestDb $rInner): DatabaseHandler {
		return new class ($rInner) extends DatabaseHandler {
			/** @var (callable(): void)|null */
			public $rRace = null;

			public bool $rRefuseAll = false;

			public bool $rThrow = false;

			public int $rInserts = 0;

			public int $rRefused = 0;

			public function __construct(private TestDb $rInner) {
				// No parent constructor: it would connect to MySQL.
			}

			public function query($query, ...$args): bool {
				if (str_starts_with($query, 'INSERT INTO `cluster_commands`')) {
					$this->rInserts++;
					if ($this->rRace !== null) {
						($this->rRace)();
						$this->rRace = null;
					}
					if ($this->rRefuseAll) {
						$this->rRefused++;
						return false;
					}
				}
				try {
					return $this->rInner->query($query, ...$args);
				} catch (\PDOException $rE) {
					if ($this->rThrow) {
						throw $rE;
					}
					$this->rRefused += str_starts_with($query, 'INSERT INTO `cluster_commands`') ? 1 : 0;
					return false;
				}
			}

			public function get_row() {
				return $this->rInner->get_row();
			}

			public function get_raw_rows(): array {
				return $this->rInner->get_raw_rows();
			}

			public function get_raw_row(): ?array {
				return $this->rInner->get_raw_row();
			}

			public function get_rows($use_id = false, $column_as_id = '', $unique_row = true, $sub_row_id = '') {
				return $this->rInner->get_rows($use_id, $column_as_id, $unique_row, $sub_row_id);
			}

			public function num_rows(): int {
				return $this->rInner->num_rows();
			}
		};
	}
}
