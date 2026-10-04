<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\AgentClient;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\QueueSink;
use XcVm\Core\Cluster\ReplicaBoot;

/**
 * The encoding queue seam (plan, Phase 5). `queue` is MAIN's table: a legacy
 * node reads and writes it over its own connection, a node whose CONTENT flow
 * is on asks MAIN through its agent, and a node in mode 2 without the flow can
 * do neither — it must say so rather than dial MAIN's database.
 */
final class ClusterQueueTest extends TestCase {

	private string $rDir;

	public static function setUpBeforeClass(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 5);
		}
	}

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-queue-' . getmypid();
		@mkdir($this->rDir, 0o777, true);
		// A load balancer, so mode 2 can refuse: MAIN's own build never does.
		NodeRole::useMainBuild(false);
		// No agent runs here, and mainRetrying()'s waits are not worth two minutes.
		AgentClient::useSocket($this->rDir . '/absent.sock');
		AgentClient::useSleep(static function (int $rSec): void {
		});
	}

	protected function tearDown(): void {
		NodeFlows::usePath(null);
		NodeRole::useMainBuild(null);
		AgentClient::useSocket(null);
		AgentClient::useSleep(null);
		ReplicaBoot::reset();
		array_map('unlink', glob($this->rDir . '/*') ?: []);
		@rmdir($this->rDir);
	}

	/** @param list<array<string, mixed>> $rRows Rows every SELECT returns. */
	private function recorder(array $rRows = [], bool $rResult = true): object {
		return new class($rRows, $rResult) {
			/** @var list<array{string, list<mixed>}> */
			public array $rQueries = [];
			/** @param list<array<string, mixed>> $rRows */
			public function __construct(private array $rRows, private bool $rResult) {
			}
			public function query(string $rSql, mixed ...$rParams): bool {
				$this->rQueries[] = [$rSql, $rParams];
				return $this->rResult;
			}
			/** @return list<array<string, mixed>> */
			public function get_rows(): array {
				return $this->rRows;
			}
		};
	}

	private function flows(int $rMode, int $rFlows): void {
		file_put_contents($this->rDir . '/flows.json', (string) json_encode(['mode' => $rMode, 'flows' => $rFlows, 'state' => 'active', 'features' => []]));
		NodeFlows::usePath($this->rDir . '/flows.json');
		ReplicaBoot::reset();
	}

	public function testAMovieReplacesWhatIsQueuedForTheSameStream(): void {
		$rDb = $this->recorder();
		$this->assertTrue(QueueSink::enqueue('movie', [7, 7, 0, '9'], 5, $rDb));

		$this->assertSame('DELETE FROM `queue` WHERE `stream_id` IN (?,?) AND `server_id` = ?;', $rDb->rQueries[0][0]);
		$this->assertSame([7, 9, 5], $rDb->rQueries[0][1]);
		$this->assertSame('INSERT INTO `queue`(`type`, `stream_id`, `server_id`, `added`) VALUES (?, ?, ?, ?),(?, ?, ?, ?);', $rDb->rQueries[1][0]);
		$this->assertSame(['movie', 7, 5, 'movie', 9, 5], array_values(array_filter($rDb->rQueries[1][1], static fn($rV): bool => $rV !== $rDb->rQueries[1][1][3])));
	}

	public function testAChannelAlreadyQueuedIsLeftAlone(): void {
		$rDb = $this->recorder([['stream_id' => 7]]);
		$this->assertTrue(QueueSink::enqueue('channel', [7], 5, $rDb));
		$this->assertCount(1, $rDb->rQueries, 'the existing row was re-inserted');
		$this->assertStringStartsWith('SELECT `stream_id` FROM `queue`', $rDb->rQueries[0][0]);
	}

	public function testNothingToQueueAndUnknownTypes(): void {
		$rDb = $this->recorder();
		$this->assertFalse(QueueSink::enqueue('movie', [], 5, $rDb));
		$this->assertFalse(QueueSink::enqueue('movie', [0, -3], 5, $rDb));
		$this->assertFalse(QueueSink::enqueue('nope', [7], 5, $rDb));
		$this->assertNull(QueueSink::claim('nope', 5, $rDb));
		$this->assertSame([], $rDb->rQueries);
	}

	public function testClaimReadsRunningAndPendingRowsOfThisNode(): void {
		$rDb = $this->recorder([['id' => 3, 'pid' => 900, 'stream_id' => 7]]);
		$rOut = QueueSink::claim('movie', 2, $rDb);

		$this->assertSame(['running' => [['id' => 3, 'pid' => 900]], 'pending' => [['id' => 3, 'stream_id' => 7]]], $rOut);
		$this->assertStringContainsString('`pid` IS NOT NULL', $rDb->rQueries[0][0]);
		$this->assertStringContainsString('LIMIT 2;', $rDb->rQueries[1][0]);
		$this->assertSame([SERVER_ID, 'movie'], $rDb->rQueries[1][1]);
	}

	public function testClaimAsksForNoPendingRowWhenNoSlotIsFree(): void {
		$rDb = $this->recorder();
		$this->assertSame(['running' => [], 'pending' => []], QueueSink::claim('movie', 0, $rDb));
		$this->assertCount(1, $rDb->rQueries);
	}

	public function testUpdateRecordsPidsAndDropsFinishedRows(): void {
		$rDb = $this->recorder();
		$this->assertTrue(QueueSink::update([3 => 900, 0 => 1, 4 => 0], [5, 5, -1], $rDb));

		$this->assertSame(['UPDATE `queue` SET `pid` = ? WHERE `id` = ? AND `server_id` = ?;', [900, 3, SERVER_ID]], $rDb->rQueries[0]);
		$this->assertSame(['DELETE FROM `queue` WHERE `id` IN (5) AND `server_id` = ?;', [SERVER_ID]], $rDb->rQueries[1]);
		$this->assertTrue(QueueSink::update([], [], $rDb));
		$this->assertCount(2, $rDb->rQueries);
	}

	public function testTheContentFlowTakesTheQueueOffTheDatabase(): void {
		$this->flows(1, NodeFlows::CONTENT);
		$rDb = $this->recorder();

		// No agent answers this socket, so each call fails — but none of them
		// may fall back to MAIN's table.
		$this->assertFalse(QueueSink::enqueue('movie', [7], (int) SERVER_ID, $rDb));
		$this->assertNull(QueueSink::claim('movie', 5, $rDb));
		$this->assertFalse(QueueSink::update([3 => 900], [], $rDb));
		$this->assertSame([], $rDb->rQueries);

		// Another server's rows are not this node's work: that stays SQL.
		$this->assertTrue(QueueSink::enqueue('movie', [7], 9, $rDb));
		$this->assertNotSame([], $rDb->rQueries);
	}

	public function testModeTwoWithoutTheFlowQueuesNothingAtAll(): void {
		$this->flows(2, NodeFlows::TELEMETRY);
		$this->assertTrue(NodeRole::refusesConnects());
		$rDb = $this->recorder();

		$this->assertFalse(QueueSink::enqueue('movie', [7], (int) SERVER_ID, $rDb));
		$this->assertNull(QueueSink::claim('movie', 5, $rDb));
		$this->assertFalse(QueueSink::update([3 => 900], [], $rDb));
		$this->assertSame([], $rDb->rQueries);
	}

	#[\PHPUnit\Framework\Attributes\Group('skip-on-panel')]
	public function testTheQueueTableHasNoWriterLeftOutsideTheSeam(): void {
		$rRoot = MAIN_HOME;
		// The seam itself, and the two admin surfaces that queue or cancel work
		// on any server: they run on MAIN, which owns the table. NodeQueue, the
		// seam's MAIN half, runs QueueSink's statements and writes none itself.
		$rAllowed = [
			'Core/Cluster/QueueSink.php',
			'Domain/Stream/ChannelService.php', 'Controllers/Admin/Ajax/MiscAjaxController.php',
		];

		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rRoot, FilesystemIterator::SKIP_DOTS)) as $rFile) {
			$rPath = $rFile->getPathname();
			if ($rFile->getExtension() !== 'php' || str_contains($rPath, '/vendor/')) {
				continue;
			}
			foreach ($rAllowed as $rSkip) {
				if (str_ends_with($rPath, $rSkip)) {
					continue 2;
				}
			}
			$this->assertSame(
				0,
				preg_match('/(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`queue`/i', (string) file_get_contents($rPath)),
				$rPath . ' writes the queue behind QueueSink'
			);
		}
	}
}
