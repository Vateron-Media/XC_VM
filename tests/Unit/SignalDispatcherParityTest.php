<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\SignalDispatcher;

/**
 * SignalDispatcher replaced 47 direct `INSERT INTO signals` statements. Its
 * legacy SQL sink must write the same rows they wrote, since the signals
 * daemon, cron:root_signals and the cache handler read them unchanged.
 */
final class SignalDispatcherParityTest extends TestCase {

	/** A DatabaseHandler stand-in that records every query. */
	private function recorder(int $rPendingCount = 0): object {
		return new class($rPendingCount) {
			/** @var list<array{string, list<mixed>}> */
			public array $rQueries = [];
			public function __construct(private int $rPending) {
			}
			public function query(string $rSql, mixed ...$rParams): bool {
				$this->rQueries[] = [$rSql, $rParams];
				return true;
			}
			public function get_row(): array {
				return ['count' => $this->rPending];
			}
		};
	}

	protected function tearDown(): void {
		SignalDispatcher::useSink(null);
	}

	public function testKillWritesPidRowsWithTheDatabaseClock(): void {
		$rDb = $this->recorder();
		SignalDispatcher::kill(3, 4242, false, $rDb);
		SignalDispatcher::kill(3, 4243, true, $rDb);
		$this->assertSame([
			['INSERT INTO `signals`(`pid`, `server_id`, `time`) VALUES(?,?,UNIX_TIMESTAMP());', [4242, 3]],
			['INSERT INTO `signals`(`pid`, `server_id`, `rtmp`, `time`) VALUES(?,?,?,UNIX_TIMESTAMP());', [4243, 3, 1]],
		], $rDb->rQueries);
	}

	public function testRootActionWritesJsonEncodedCustomData(): void {
		$rDb = $this->recorder();
		$rBefore = time();
		SignalDispatcher::rootAction(5, ['action' => 'set_port', 'type' => 1, 'ports' => [80]], $rDb);
		SignalDispatcher::rootAction(5, '{"action":"x"}', $rDb);
		[$rSql, $rParams] = $rDb->rQueries[0];
		$this->assertSame('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?,?,?);', $rSql);
		$this->assertSame(5, $rParams[0]);
		$this->assertGreaterThanOrEqual($rBefore, $rParams[1]);
		$this->assertSame(json_encode(['action' => 'set_port', 'type' => 1, 'ports' => [80]]), $rParams[2]);
		$this->assertSame('{"action":"x"}', $rDb->rQueries[1][1][2], 'pre-encoded JSON passes through');
	}

	public function testANonPositivePidIsNeverSignalled(): void {
		$rDb = $this->recorder();
		$this->assertFalse(SignalDispatcher::kill(3, 0, true, $rDb));
		$this->assertFalse(SignalDispatcher::kill(3, -1, false, $rDb));
		$this->assertSame([], $rDb->rQueries);
	}

	public function testUpdateBinariesNowMatchesStatusCommandsCleanup(): void {
		// The panel wrote '{"action": "update_binaries"}' (with a space) while
		// StatusCommand deletes json_encode(['action' => 'update_binaries']),
		// so those rows were never cleaned up. Every action is json_encode()d now.
		$rDb = $this->recorder();
		SignalDispatcher::rootAction(2, ['action' => 'update_binaries'], $rDb);
		$this->assertSame(json_encode(['action' => 'update_binaries']), $rDb->rQueries[0][1][2]);
	}

	public function testCacheSignalsAndTheirDedupe(): void {
		$rDb = $this->recorder(0);
		SignalDispatcher::cache(1, ['type' => 'update_line', 'id' => 9], true, false, $rDb);
		$this->assertSame('SELECT COUNT(*) AS `count` FROM `signals` WHERE `server_id` = ? AND `cache` = 1 AND `custom_data` = ?;', $rDb->rQueries[0][0]);
		$this->assertSame('INSERT INTO `signals`(`server_id`, `cache`, `time`, `custom_data`) VALUES(?,?,?,?);', $rDb->rQueries[1][0]);
		$this->assertSame([1, 1], array_slice($rDb->rQueries[1][1], 0, 2));
		$this->assertSame('{"type":"update_line","id":9}', $rDb->rQueries[1][1][3]);

		$rPending = $this->recorder(1);
		SignalDispatcher::cache(1, ['type' => 'update_line', 'id' => 9], true, false, $rPending);
		$this->assertCount(1, $rPending->rQueries, 'an identical pending signal is not duplicated');

		$rDbClock = $this->recorder();
		SignalDispatcher::cache(4, ['type' => 'drop_con', 'uuid' => 'u'], false, true, $rDbClock);
		$this->assertSame('INSERT INTO `signals`(`server_id`, `cache`, `time`, `custom_data`) VALUES(?,?,UNIX_TIMESTAMP(),?);', $rDbClock->rQueries[0][0]);
	}

	public function testCacheBatchIsOneMultiRowInsert(): void {
		$rDb = $this->recorder();
		SignalDispatcher::cacheBatch(6, [['type' => 'delete_con', 'uuid' => 'a'], ['type' => 'delete_con', 'uuid' => 'b']], 1700000000, $rDb);
		$this->assertSame([
			['INSERT INTO `signals`(`server_id`, `cache`, `time`, `custom_data`) VALUES(?,?,?,?),(?,?,?,?);',
				[6, 1, 1700000000, '{"type":"delete_con","uuid":"a"}', 6, 1, 1700000000, '{"type":"delete_con","uuid":"b"}']],
		], $rDb->rQueries);
		SignalDispatcher::cacheBatch(6, [], null, $rDb);
		$this->assertCount(1, $rDb->rQueries, 'nothing to write, no query');
	}

	#[\PHPUnit\Framework\Attributes\Group('skip-on-panel')]
	public function testNoCodeWritesSignalsDirectlyAnyMore(): void {
		$rRoot = MAIN_HOME;
		$rIt = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rRoot, FilesystemIterator::SKIP_DOTS));
		$rOffenders = [];
		foreach ($rIt as $rFile) {
			$rPath = $rFile->getPathname();
			if ($rFile->getExtension() !== 'php' || str_contains($rPath, '/vendor/') || str_contains($rPath, '/Core/Cluster/')) {
				continue;
			}
			if (preg_match('/INSERT INTO `signals`/', (string) file_get_contents($rPath))) {
				$rOffenders[] = substr($rPath, strlen($rRoot));
			}
		}
		$this->assertSame([], $rOffenders, 'use SignalDispatcher');
	}
}
