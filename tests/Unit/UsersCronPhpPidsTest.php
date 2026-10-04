<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\UsersCronJob;

/**
 * UsersCronJob — in Redis mode MAIN's cron:users decides whether a remote
 * node's PHP-served connection is still alive by looking its worker pid up in
 * that node's servers.php_pids.
 *
 * loadPHPPIDs: ServerRepository::getAll() no longer carries the column (it is
 * kept out of the per-request servers cache), so the reaper reads it with its
 * own query. "[]" means no worker is running; a node with no usable list maps
 * to null, which the reaper treats as "cannot tell" and keeps the connection.
 *
 * isRemoteWorkerRunning: the list is a snapshot taken just before the node's
 * heartbeat, so it can only rule on a pid assigned before it. A reused
 * connection (VOD range/seek, TS or timeshift reconnect) gets a new worker pid
 * but keeps its original date_start; the pid write stamps hls_last_read, so the
 * later of the two dates the pid.
 */
final class UsersCronPhpPidsTest extends TestCase {

	private const HEARTBEAT = 1700000000;

	private $rDbBackup;

	protected function setUp(): void {
		$this->rDbBackup = $GLOBALS['db'] ?? null;

		$rDb = new TestDb();
		$rDb->exec('CREATE TABLE servers (id INTEGER PRIMARY KEY AUTO_INCREMENT, php_pids TEXT);');
		$rDb->exec('INSERT INTO servers (id, php_pids) VALUES (1, \'[11,12]\'), (2, NULL), (3, \'not json\'), (4, \'["13"]\'), (5, \'[]\'), (6, \'null\');');
		$GLOBALS['db'] = $rDb;
	}

	protected function tearDown(): void {
		$GLOBALS['db'] = $this->rDbBackup;
	}

	private function invoke(string $rName, array $rArgs = []) {
		$rMethod = new ReflectionMethod(UsersCronJob::class, $rName);
		$rMethod->setAccessible(true);

		return $rMethod->invokeArgs($rMethod->isStatic() ? null : new UsersCronJob(), $rArgs);
	}

	private function running(int $rDateStart, int $rLastRead, ?array $rPIDs, ?array $rServer = ['last_check_ago' => self::HEARTBEAT]): bool {
		$rConnection = ['pid' => 12, 'date_start' => $rDateStart, 'hls_last_read' => $rLastRead];

		return $this->invoke('isRemoteWorkerRunning', [$rConnection, $rServer, $rPIDs]);
	}

	public function testReadsEveryNodesWorkerPidsFromTheServersTable(): void {
		$this->assertSame(
			[1 => [11, 12], 2 => null, 3 => null, 4 => [13], 5 => [], 6 => null],
			$this->invoke('loadPHPPIDs')
		);
	}

	public function testAPidAssignedBeforeTheHeartbeatIsLookedUp(): void {
		$rBefore = self::HEARTBEAT - 60;

		$this->assertTrue($this->running($rBefore, $rBefore, [11, 12]), 'listed worker');
		$this->assertFalse($this->running($rBefore, $rBefore, [11, 13]), 'worker missing from the list');
		$this->assertFalse($this->running($rBefore, $rBefore, []), 'no worker running on the node');
	}

	public function testAnUnknownListOrNodeKeepsTheConnection(): void {
		$rBefore = self::HEARTBEAT - 60;

		$this->assertTrue($this->running($rBefore, $rBefore, null), 'no usable php_pids');
		$this->assertTrue($this->running($rBefore, $rBefore, [11, 13], null), 'server row missing');
	}

	public function testAPidAssignedAfterTheHeartbeatIsNotJudged(): void {
		$this->assertTrue($this->running(self::HEARTBEAT, self::HEARTBEAT, [11, 13]), 'new connection');
		$this->assertTrue($this->running(self::HEARTBEAT - 3600, self::HEARTBEAT, [11, 13]), 'reused connection, new worker pid');
		$this->assertFalse($this->running(self::HEARTBEAT - 3600, self::HEARTBEAT - 1, [11, 13]), 'reused connection, pid assigned before the heartbeat');
	}

	public function testTheReaperIsWiredToTheHelpers(): void {
		$rPath = MAIN_HOME . 'Cli/CronJobs/UsersCronJob.php';
		$this->assertFileExists($rPath);
		$rSource = (string) file_get_contents($rPath);

		$this->assertSame(1, substr_count($rSource, '$this->rPHPPIDs = $this->loadPHPPIDs();'), 'cron:users must read php_pids itself');
		$this->assertSame(1, substr_count($rSource, '$this->isRemoteWorkerRunning('), 'the reaper must judge remote workers through the helper');
		$this->assertStringNotContainsString("\$rServer['php_pids']", $rSource, 'getAll() rows carry no php_pids');
	}

	/**
	 * A worker-served viewer is over when its worker is gone, five minutes
	 * after it ended, or after three missed check-ins even with its pid
	 * running: a worker whose close was never written leaves a pid PHP-FPM
	 * gives the next request.
	 */
	public function testASilentWorkersViewerIsClosedWhateverItsPid(): void {
		$rNow = 1800000000;
		$rGone = fn(int $rEnd, int $rAgo, bool $rRunning): bool => $this->invoke('workerGone', [['hls_end' => $rEnd, 'hls_last_read' => $rNow - $rAgo], $rRunning, $rNow]);
		$this->assertFalse($rGone(0, 299, true), 'checked in within the interval');
		$this->assertFalse($rGone(0, UsersCronJob::SILENT_WORKER - 1, true), 'two missed check-ins');
		$this->assertTrue($rGone(0, UsersCronJob::SILENT_WORKER, true), 'three: its pid serves someone else');
		$this->assertTrue($rGone(0, 10, false), 'its worker gone');
		$this->assertFalse($rGone(1, 100, true), 'ended a moment ago');
		$this->assertTrue($rGone(1, 300, true), 'ended five minutes ago');
	}

	/**
	 * In MySQL mode each server sweeps its own rows; a deleted or crashed
	 * one's stayed open for good. MAIN takes a row as orphaned when its server
	 * is gone, or when it has been silent for ORPHAN_AFTER.
	 */
	public function testAViewerNobodySweepsIsOrphaned(): void {
		$rNow = 1800000000;
		$rServers = [1 => ['is_main' => 1], 2 => []];
		$rOrphan = fn(int $rServer, int $rAgo, string $rContainer = 'ts', int $rPid = 4242): bool => $this->invoke('orphaned', [['server_id' => $rServer, 'hls_last_read' => $rNow - $rAgo, 'container' => $rContainer, 'pid' => $rPid], $rServers, $rNow]);
		$this->assertFalse($rOrphan(2, 60), 'a live server sweeps its own');
		$this->assertFalse($rOrphan(2, UsersCronJob::ORPHAN_AFTER - 1));
		$this->assertTrue($rOrphan(2, UsersCronJob::ORPHAN_AFTER), 'silent past the bound: its server does not sweep it');
		$this->assertTrue($rOrphan(2, UsersCronJob::ORPHAN_AFTER, 'hls', 0));
		$this->assertFalse($rOrphan(2, 86400, 'rtmp'), 'an RTMP viewer never checks in');
		$this->assertFalse($rOrphan(2, 86400, 'ts', 0), 'nor does a daemon-served one');
		$this->assertTrue($rOrphan(9, 5, 'rtmp'), 'its server deleted');
	}

	/** A viewer closed for silence ended when it was last heard, not when the sweep noticed. */
	public function testASilentViewersEndIsWhenItWasLastHeard(): void {
		$rNow = 1800000000;
		$rHeard = fn(array $rConnection): int => $this->invoke('lastHeard', [$rConnection + ['hls_end' => 0, 'container' => 'ts', 'pid' => 4242], $rNow]);
		$this->assertSame($rNow - 700, $rHeard(['hls_last_read' => $rNow - 1000]), 'a worker: its last check-in, plus one period');
		$this->assertSame($rNow, $rHeard(['hls_last_read' => $rNow - 100]), 'never past now');
		$this->assertSame($rNow - 1000, $rHeard(['hls_last_read' => $rNow - 1000, 'hls_end' => 1]), 'an ended one: its worker stamped the end');
		$this->assertSame($rNow - 40, $rHeard(['hls_last_read' => $rNow - 40, 'container' => 'hls']), 'HLS: its last read');
		$this->assertSame($rNow, $rHeard(['hls_last_read' => $rNow - 5000, 'container' => 'rtmp']), 'never checks in: now');
		$this->assertSame($rNow, $rHeard(['hls_last_read' => $rNow - 5000, 'pid' => 0]));
	}

	/** A Redis record carries no exp_date: the line's stands in, so an expired line is kicked in Redis mode too. */
	public function testAnExpiredLineIsKickedWhateverTheStore(): void {
		$rNow = self::HEARTBEAT;
		$this->assertTrue($this->invoke('lineExpired', [['uuid' => 'r'], (string) ($rNow - 1), $rNow]), 'a Redis record: the line\'s date');
		$this->assertFalse($this->invoke('lineExpired', [['uuid' => 'r'], (string) ($rNow + 60), $rNow]));
		$this->assertFalse($this->invoke('lineExpired', [['uuid' => 'r'], null, $rNow]), 'no date: never');
		$this->assertTrue($this->invoke('lineExpired', [['exp_date' => $rNow - 1], null, $rNow]), 'a lines_live row: its own');
		$this->assertFalse($this->invoke('lineExpired', [['exp_date' => null], null, $rNow]), 'an unlimited line');
		$rSource = (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/UsersCronJob.php');
		$this->assertStringContainsString('self::lineExpired($rConnection, $rExpDateArray[$rUserID] ?? null, $rStartTime)', $rSource, 'the reaper judges expiry through the helper');
	}
}
