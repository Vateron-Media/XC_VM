<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterBus;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\EventIngest;
use XcVm\Domain\Cluster\HeartbeatService;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\BusServer;

/**
 * The status a node reports as it updates (ADR 0004, "Three designs for
 * approval", design 2, option A): a 5 MAIN took from a node's node.state
 * holds against its heartbeats for HeartbeatService::UPDATING_HOLD_SEC, on
 * the direct path and on the cluster bus alike. 'Back' ends the hold at once,
 * and everything the hold does not cover (no note, an install state, a note
 * ahead of MAIN's clock, an enrolment that has ended) is as before.
 */
final class AuditCluster2UpdatingHoldTest extends TestCase {
	private int $rT0 = 1800000000000;

	private static ?BusServer $rBus = null;

	private TestDb $rDb;

	private int $rSeq = 0;

	private string $rDir;

	private ?string $rLockWas;

	public static function tearDownAfterClass(): void {
		self::$rBus?->stop();
		self::$rBus = null;
	}

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec((string) file_get_contents(MAIN_HOME . 'migrations/database/up/029_create_cluster_nodes.sql'));
		foreach (glob(MAIN_HOME . 'migrations/database/up/*.sql') ?: [] as $rFile) {
			preg_match_all('/^ALTER TABLE `cluster_nodes` ADD COLUMN (?:IF NOT EXISTS )?(`\w+` [^;]*?)(?: AFTER `\w+`)?;$/m', (string) file_get_contents($rFile), $rAdd);
			foreach ($rAdd[1] as $rColumn) {
				$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN ' . preg_replace('/ unsigned| COLLATE \w+/', '', $rColumn));
			}
		}
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY, `status` int NOT NULL DEFAULT 0)');
		$this->rDb->exec('INSERT INTO `servers` (`id`, `status`) VALUES (5, 1)');
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		DatabaseFactory::set($this->rDb);
		SettingsManager::set(['redis_handler' => 0]);
		ClusterClock::fix($this->rT0);
		$this->rDir = sys_get_temp_dir() . '/xcvm-hold-' . bin2hex(random_bytes(4));
		mkdir($this->rDir);
		HeartbeatService::useDir($this->rDir . '/');
		$this->rLockWas = EventIngest::useLockDir($this->rDir . '/ingest/');
		ClusterBus::useSocket($this->rDir . '/no-bus.sock');
		NodeRegistry::startEnrolment(5, '00000000-0000-4000-a000-000000000005', str_repeat("\1", 32), str_repeat("\2", 32), 1);
		NodeRegistry::update(5, ['state' => 'active', 'flows' => NodeRegistry::FLOW_TELEMETRY]);
	}

	protected function tearDown(): void {
		EventIngest::useLockDir($this->rLockWas);
		HeartbeatService::useDir(null);
		ClusterBus::useSocket(null);
		ClusterClock::fix(null);
		DatabaseFactory::reset();
		SettingsManager::set([]);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** The node's node.state {status} at MAIN's time $rMs, as its update reports it. */
	private function report(int $rStatus, int $rMs): void {
		ClusterClock::fix($rMs);
		$rOut = EventIngest::ingest((array) NodeRegistry::byServer(5), 'p0', ++$this->rSeq, [['type' => 'node.state', 'd' => ['fields' => ['status' => $rStatus]]]]);
		$this->assertSame(1, $rOut['applied']);
	}

	/** A heartbeat MAIN hears at $rMs (its node's row as now, or $rNode). */
	private function beat(int $rMs, ?array $rNode = null): void {
		ClusterClock::fix($rMs);
		HeartbeatService::record($rNode ?? (array) NodeRegistry::byServer(5), ['root_ready' => false], $rMs);
	}

	private function serverStatus(): int {
		$this->rDb->query('SELECT `status` FROM `servers` WHERE `id` = 5');
		return (int) $this->rDb->get_row()['status'];
	}

	private function note(): ?array {
		$this->rDb->query('SELECT `updated_at` FROM `cluster_meta` WHERE `name` = ?', 'updating.5');
		return $this->rDb->get_row() ?: null;
	}

	private function bus(): void {
		self::$rBus ??= BusServer::start('hold-bus');
		if (self::$rBus === null) {
			$this->markTestSkipped('redis-server or phpredis not available');
		}
		self::$rBus->restart();
		ClusterBus::useSocket(self::$rBus->socket());
		$this->assertInstanceOf(\Redis::class, ClusterBus::client());
		HeartbeatService::flush(); // a flusher is running
	}

	public function testAHeartbeatLeavesAReportedUpdateAloneForTheHold(): void {
		$this->report(5, $this->rT0);
		$this->assertSame(5, $this->serverStatus());
		$this->beat($this->rT0 + 2000);
		$this->assertSame(5, $this->serverStatus(), 'the next heartbeat leaves the node out of routing');
		$this->assertSame(['updated_at' => intdiv($this->rT0, 1000)], $this->note(), 'by MAIN\'s note of when it took the 5');
		$this->beat($this->rT0 + (HeartbeatService::UPDATING_HOLD_SEC - 1) * 1000);
		$this->assertSame(5, $this->serverStatus(), 'for the whole hold');

		$this->beat($this->rT0 + HeartbeatService::UPDATING_HOLD_SEC * 1000);
		$this->assertSame(1, $this->serverStatus(), 'a node still sending heartbeats after the hold is back by itself');
	}

	public function testTheHoldHoldsOnTheClusterBusToo(): void {
		$this->bus();
		$this->rDb->exec('UPDATE `servers` SET `status` = 0 WHERE `id` = 5');
		$this->beat($this->rT0);
		HeartbeatService::flush();
		$this->assertSame(1, $this->serverStatus(), 'the first heartbeat marks the server up');

		$this->report(5, $this->rT0 + 1000);
		$this->beat($this->rT0 + 1000 + HeartbeatService::FLUSH_EVERY_MS);
		HeartbeatService::flush();
		$this->assertSame(5, $this->serverStatus(), 'the flush leaves a reported update alone');

		$this->beat($this->rT0 + 1000 + HeartbeatService::UPDATING_HOLD_SEC * 1000);
		HeartbeatService::flush();
		$this->assertSame(1, $this->serverStatus(), 'and sets 1 once the hold is over');
	}

	public function testBackSetsOneAtOnceAndDropsTheNote(): void {
		$this->report(5, $this->rT0);
		$this->assertNotNull($this->note());
		$this->report(1, $this->rT0 + 1000);
		$this->assertSame(1, $this->serverStatus());
		$this->assertNull($this->note(), 'back drops the note');

		// A 5 the row was given without a note (a node that writes its own row) is as before.
		$this->rDb->exec('UPDATE `servers` SET `status` = 5 WHERE `id` = 5');
		$this->beat($this->rT0 + 2000);
		$this->assertSame(1, $this->serverStatus());
	}

	public function testWhatTheHoldDoesNotCoverIsAsBefore(): void {
		// An install state MAIN set is never held, even with a fresh note.
		$this->report(5, $this->rT0);
		$this->rDb->exec('UPDATE `servers` SET `status` = 4 WHERE `id` = 5');
		$this->beat($this->rT0 + 2000);
		$this->assertSame(1, $this->serverStatus(), 'install states are marked up as before');

		// A note ahead of MAIN's clock (the clock stepped back): no hold.
		$this->report(5, $this->rT0 + 120000);
		$this->beat($this->rT0 + 4000);
		$this->assertSame(1, $this->serverStatus(), 'a note from MAIN\'s future holds nothing');
	}

	public function testAHeartbeatOfAnEnrolmentThatHasEndedStillMarksNothingUp(): void {
		$this->bus();
		$this->report(5, $this->rT0);
		$rOld = (array) NodeRegistry::byServer(5);
		NodeRegistry::startEnrolment(5, '00000000-0000-4000-a000-000000000055', str_repeat("\3", 32), str_repeat("\4", 32), 1);
		$this->rDb->exec('UPDATE `servers` SET `status` = 4 WHERE `id` = 5');
		// Past the hold, a heartbeat of the old enrolment still in flight.
		$this->beat($this->rT0 + (HeartbeatService::UPDATING_HOLD_SEC + 5) * 1000, $rOld);
		HeartbeatService::flush();
		$this->assertSame(4, $this->serverStatus());
	}
}
