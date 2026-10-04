<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ClusterHealth;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\LivenessService;
use XcVm\Domain\Cluster\NodeHealth;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * Node liveness hysteresis: a published state gets worse at once and better
 * only after NodeHealth::RECOVER_MS of steady health, so a node whose
 * heartbeats straddle the 10 s threshold does not flap.
 */
final class NodeHealthHysteresisTest extends TestCase {
	private int $rT0 = 1800000000000;

	private TestDb $rDb;

	private string $rHealth;

	public function testWorseAtOnceBetterOnlyWhenSteady(): void {
		$this->assertSame(['suspect', null], NodeHealth::settle('ok', 'suspect', 5, 100));
		$this->assertSame(['offline', null], NodeHealth::settle('suspect', 'offline', null, 100));
		$this->assertSame(['offline', null], NodeHealth::settle('ok', 'offline', 5, 100));

		// suspect → ok needs RECOVER_MS of ok judgements without a break.
		$this->assertSame(['suspect', 1000], NodeHealth::settle('suspect', 'ok', null, 1000));
		$this->assertSame(['suspect', 1000], NodeHealth::settle('suspect', 'ok', 1000, 1000 + NodeHealth::RECOVER_MS - 1));
		$this->assertSame(['ok', 1000], NodeHealth::settle('suspect', 'ok', 1000, 1000 + NodeHealth::RECOVER_MS));

		// An offline node heard again is suspect at once, whatever it is judged.
		$this->assertSame(['suspect', 2000], NodeHealth::settle('offline', 'ok', null, 2000));
		$this->assertSame(['suspect', null], NodeHealth::settle('offline', 'suspect', null, 2000));
		$this->assertSame(['ok', 2000], NodeHealth::settle('offline', 'ok', 2000, 2000 + NodeHealth::RECOVER_MS));
	}

	public function testFirstPublicationAndSteadyNodesAreUnchanged(): void {
		$this->assertSame(['ok', 7], NodeHealth::settle(null, 'ok', null, 7));
		$this->assertSame(['offline', null], NodeHealth::settle(null, 'offline', null, 7));
		$this->assertSame(['ok', 3], NodeHealth::settle('ok', 'ok', 3, 99999), 'an ok node keeps its streak start');
		$this->assertSame(['suspect', null], NodeHealth::settle('suspect', 'suspect', null, 5));
	}

	/**
	 * A node heard every 11.5 s: each gap passes the 10 s threshold. Without
	 * hysteresis it flips twice per gap (suspect, then ok when heard).
	 */
	public function testANodeNearTheThresholdDoesNotFlap(): void {
		$this->setUpLoop();
		$this->tickAt(0);
		$rFlips = 0;
		for ($rMs = 1000; $rMs <= 60000; $rMs += 1000) {
			$rLast = intdiv($rMs, 11500) * 11500;
			$this->rDb->query('UPDATE `cluster_nodes` SET `last_seen_at` = ? WHERE `server_id` = 5', $this->rT0 + $rLast);
			$rFlips += count($this->tickAt($rMs));
		}
		$this->assertSame(1, $rFlips, 'one ok→suspect, then held: every gap past 10 s restarts the steady period');
		$this->assertSame('suspect', ClusterHealth::state(5));

		// Steady heartbeats bring it back once, after RECOVER_MS.
		for ($rMs = 61000; $rMs <= 61000 + NodeHealth::RECOVER_MS; $rMs += 1000) {
			$this->rDb->query('UPDATE `cluster_nodes` SET `last_seen_at` = ? WHERE `server_id` = 5', $this->rT0 + $rMs);
			$rFlips += count($this->tickAt($rMs));
		}
		$this->assertSame(2, $rFlips);
		$this->assertSame('ok', ClusterHealth::state(5));
	}

	public function testTheStreakSurvivesTicksWithoutATransition(): void {
		$this->setUpLoop();
		$this->tickAt(0);
		$this->tickAt(11000);
		$this->assertSame('suspect', ClusterHealth::state(5));
		// Heard at 12 s, then every second: ok_since is kept on disk between ticks
		// that publish nothing, or the node would never recover.
		for ($rMs = 12000; $rMs <= 12000 + NodeHealth::RECOVER_MS; $rMs += 1000) {
			$this->rDb->query('UPDATE `cluster_nodes` SET `last_seen_at` = ? WHERE `server_id` = 5', $this->rT0 + $rMs);
			$this->tickAt($rMs);
			ClusterHealth::usePath($this->rHealth); // forget the in-process cache: read it back from the file
		}
		$this->assertSame('ok', ClusterHealth::state(5));
		$this->assertSame($this->rT0 + 12000, ClusterHealth::read()['ok_since'][5]);
	}

	private function setUpLoop(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec((string) (string) file_get_contents(MAIN_HOME . 'migrations/database/up/029_create_cluster_nodes.sql'));
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix($this->rT0);
		\XcVm\Domain\Cluster\ClusterMeta::set('ready_at', (string) ($this->rT0 - 600000));
		NodeRegistry::startEnrolment(5, '00000000-0000-4000-a000-000000000005', str_repeat("\1", 32), str_repeat("\2", 32), 1);
		NodeRegistry::update(5, ['state' => 'active', 'flows' => NodeRegistry::FLOW_TELEMETRY, 'last_seen_at' => $this->rT0]);
		$this->rHealth = sys_get_temp_dir() . '/health_' . bin2hex(random_bytes(4)) . '.json';
		ClusterHealth::usePath($this->rHealth);
		// No probe of a real cluster_ctl socket under MAIN_HOME: the pool cannot tell.
		LivenessService::useQueueReader(static fn(): ?int => null);
	}

	private function tickAt(int $rMs): array {
		ClusterClock::fix($this->rT0 + $rMs);
		return LivenessService::tick(30);
	}

	protected function tearDown(): void {
		LivenessService::useQueueReader(null);
		ClusterClock::fix(null);
		if (isset($this->rDb)) {
			DatabaseFactory::reset();
			@unlink($this->rHealth);
			ClusterHealth::usePath(null);
		}
	}
}
