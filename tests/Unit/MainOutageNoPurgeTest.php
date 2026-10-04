<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ClusterHealth;
use XcVm\Core\Cluster\HlsReaping;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Domain\Cluster\ConnectionIngest;
use XcVm\Domain\Cluster\LivenessService;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * MAIN's own downtime never counts as a node's silence (plan, "Liveness";
 * Phase 6 acceptance: stopping MAIN for 5 minutes drops zero viewers).
 * Silence counts from max(last_seen_at, cluster_ready_at), and the fleet
 * silence guard suspends the orphan purge, so MAIN coming back never purges
 * the viewers of nodes that simply have not reconnected yet.
 *
 * Runs the real pieces MAIN's users cron and signals loop run: HlsReaping's
 * orphan watch, ConnectionIngest::purgeNode on what it reports, and
 * LivenessService, against the migrations' cluster tables and `lines_live`.
 */
final class MainOutageNoPurgeTest extends TestCase {
	/** MAIN's clock at the start of each scenario (seconds). */
	private const T = 1800000000;

	private const TTL = 120;

	private TestDb $rDb;

	private string $rDir;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec((string) (string) file_get_contents(MAIN_HOME . 'migrations/database/up/029_create_cluster_nodes.sql'));
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `features` varchar(255) DEFAULT NULL');
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		$this->rDb->exec('CREATE TABLE `lines_live` (`activity_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `uuid` text, `server_id` int, `user_id` int, `container` text, `hls_end` int DEFAULT 0)');
		DatabaseFactory::set($this->rDb);
		SettingsManager::set(['redis_handler' => 0]);

		$this->rDir = sys_get_temp_dir() . '/xcvm-outage-' . bin2hex(random_bytes(4));
		mkdir($this->rDir);
		HlsReaping::usePath($this->rDir . '/orphans.json');
		ClusterHealth::usePath($this->rDir . '/health.json');
		// No probe of a real cluster_ctl socket under MAIN_HOME: the pool cannot tell.
		LivenessService::useQueueReader(static fn(): ?int => null);

		// MAIN's API has been serving for an hour.
		$this->at(self::T - 3600);
		ClusterMeta::markReady();
		$this->node(2, ['a', 'b', 'c']);
		$this->node(3, ['a', 'b', 'c']);
	}

	/**
	 * An active node with every flow the purge and liveness read, and its
	 * viewers in MAIN's store.
	 *
	 * @param list<string> $rViewers
	 */
	private function node(int $rID, array $rViewers): void {
		NodeRegistry::startEnrolment($rID, sprintf('00000000-0000-4000-a000-%012d', $rID), str_repeat("\1", 32), str_repeat("\2", 32), 1);
		NodeRegistry::update($rID, ['state' => 'active', 'mode' => 1, 'flows' => NodeRegistry::FLOW_TELEMETRY | NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_STREAMS | NodeRegistry::FLOW_CONNECTIONS, 'features' => 'hls_reaper']);
		foreach ($rViewers as $rUUID) {
			$this->rDb->query('INSERT INTO `lines_live` (`uuid`, `server_id`, `user_id`, `container`) VALUES (?, ?, ?, ?)', $rUUID . $rID, $rID, 7, 'hls');
		}
	}

	protected function tearDown(): void {
		LivenessService::useQueueReader(null);
		ClusterClock::fix(null);
		HlsReaping::usePath(null);
		ClusterHealth::usePath(null);
		SettingsManager::set([]);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function at(int $rSec): void {
		ClusterClock::fix($rSec * 1000);
	}

	/** The node last spoke at $rSec (a verified request). */
	private function heard(int $rID, int $rSec): void {
		NodeRegistry::update($rID, ['last_seen_at' => $rSec * 1000]);
	}

	/**
	 * One pass of MAIN's users cron at $rSec, as UsersCronJob runs it: the
	 * orphan watch, then the purge of what it reports.
	 *
	 * @return array<int, int> server id => connections purged
	 */
	private function usersCron(int $rSec): array {
		$this->at($rSec);
		HlsReaping::begin($rSec, self::TTL);
		$rPurged = [];
		foreach (HlsReaping::orphaned() as $rID) {
			$rPurged[$rID] = ConnectionIngest::purgeNode($rID);
		}
		return $rPurged;
	}

	private function stored(int $rID): int {
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `lines_live` WHERE `server_id` = ?', $rID);
		return (int) $this->rDb->get_row()['n'];
	}

	public function testMainComingBackPurgesNobodyThatHasNotReconnectedYet(): void {
		$this->heard(2, self::T);
		$this->heard(3, self::T);
		$this->assertSame([], $this->usersCron(self::T));

		// MAIN's nginx stops first (an update, a reboot): the nodes are cut
		// off, while MAIN's cron still runs one more pass and starts watching.
		$this->assertSame([], $this->usersCron(self::T + 60));

		// MAIN is down for two minutes, then its API serves again.
		$this->at(self::T + 190);
		ClusterMeta::markReady();

		// The first pass after the restart comes before the nodes reconnect
		// (they spread their reconnects over max(5 s, N/10 s)). Measured from
		// last_seen_at they have been silent for over three minutes, and the
		// pass gap (150 s) keeps the watch that started before the restart.
		$this->assertSame([], $this->usersCron(self::T + 210), 'MAIN\'s downtime is not the nodes\' silence');
		$this->assertSame([3, 3], [$this->stored(2), $this->stored(3)]);
		$this->assertTrue(HlsReaping::nodeReaps(2), 'the node still ends its own idle HLS viewers');

		// Node 2 reconnects; node 3 really is gone. Its silence counts from
		// the moment MAIN could hear it again (the watch begun at T+60 stands:
		// the pass gap was under three minutes).
		$this->heard(2, self::T + 215);
		$this->assertSame([], $this->usersCron(self::T + 270));
		$this->heard(2, self::T + 300);
		$this->assertSame([], $this->usersCron(self::T + 305), 'silent 115 s since MAIN came back');
		$this->heard(2, self::T + 315);
		$this->assertSame([3 => 3], $this->usersCron(self::T + 315), 'a node that stays silent is still purged, TTL after MAIN is back');
		$this->assertSame([3, 0], [$this->stored(2), $this->stored(3)]);
	}

	public function testLivenessRightAfterARestartMarksNobodyOffline(): void {
		$this->heard(2, self::T);
		$this->heard(3, self::T);
		$this->at(self::T);
		LivenessService::tick(30);
		$this->at(self::T + 190);
		ClusterMeta::markReady();
		$this->at(self::T + 195);
		LivenessService::tick(30);
		$this->assertSame(['ok', 'ok'], [ClusterHealth::state(2), ClusterHealth::state(3)], 'silence counts from cluster_ready_at');
		$this->heard(2, self::T + 224);
		$this->at(self::T + 225);
		LivenessService::tick(30);
		$this->assertSame(['ok', 'offline'], [ClusterHealth::state(2), ClusterHealth::state(3)], 'still silent 35 s after MAIN came back');
	}

	public function testTheFleetSilenceGuardHoldsThePurge(): void {
		$this->heard(2, self::T);
		$this->heard(3, self::T);
		$this->at(self::T);
		LivenessService::tick(30);
		$this->assertSame([], $this->usersCron(self::T));

		// MAIN's own network is cut: every node falls silent at once, and
		// MAIN keeps running its crons. The liveness loop suspects MAIN itself.
		foreach ([self::T + 60, self::T + 120, self::T + 180, self::T + 240] as $rSec) {
			$this->at($rSec);
			LivenessService::tick(30);
			$this->assertTrue(ClusterHealth::read()['guard'], 'the fleet silence guard is up');
			$this->assertSame([], $this->usersCron($rSec), 'no purge while MAIN suspects itself');
		}
		$this->assertSame([3, 3], [$this->stored(2), $this->stored(3)]);

		// The network is back and node 2 speaks; node 3 does not. The guard
		// clears, and node 3's silence is watched afresh from here.
		$this->heard(2, self::T + 250);
		$this->at(self::T + 250);
		LivenessService::tick(30);
		$this->assertFalse(ClusterHealth::read()['guard'], 'the guard clears once most nodes speak again');
		$this->assertSame([], $this->usersCron(self::T + 300), 'the time under the guard does not count');
		$this->heard(2, self::T + 360);
		$this->assertSame([], $this->usersCron(self::T + 360));
		$this->heard(2, self::T + 420);
		$this->assertSame([3 => 3], $this->usersCron(self::T + 420), 'watched silent for the TTL once the guard is down');
	}

	public function testStoppingMainForFiveMinutesPurgesNobody(): void {
		$this->heard(2, self::T);
		$this->heard(3, self::T);
		$this->assertSame([], $this->usersCron(self::T));
		$this->assertSame([], $this->usersCron(self::T + 60), 'MAIN\'s nginx is already down: the watch starts');

		// Five minutes later MAIN serves again. Its first pass comes before the
		// nodes reconnect, and then they speak as always.
		$this->at(self::T + 390);
		ClusterMeta::markReady();
		$this->assertSame([], $this->usersCron(self::T + 400));
		foreach ([self::T + 460, self::T + 520, self::T + 580] as $rSec) {
			$this->heard(2, $rSec - 5);
			$this->heard(3, $rSec - 5);
			$this->assertSame([], $this->usersCron($rSec));
		}
		$this->assertSame([3, 3], [$this->stored(2), $this->stored(3)]);
	}

	public function testANodeNeverHeardIsSilentFromWhenMainServes(): void {
		// Node 2 was enrolled and has never spoken; MAIN's API starts at T.
		$this->heard(3, self::T);
		$this->at(self::T);
		ClusterMeta::markReady();
		$this->assertSame([], $this->usersCron(self::T + 5), 'silent 5 s since MAIN serves: not watched yet');
		$this->heard(3, self::T + 60);
		$this->assertSame([], $this->usersCron(self::T + 65), 'the watch starts');
		$this->heard(3, self::T + 120);
		$this->assertSame([], $this->usersCron(self::T + 125), 'silent for the TTL, but watched for 60 s only');
		$this->heard(3, self::T + 180);
		$this->assertSame([2 => 3], $this->usersCron(self::T + 185));
	}

	public function testANodeOfflineBeforeTheGuardCameUpIsStillPurged(): void {
		$this->node(4, []);
		foreach ([2, 3, 4] as $rID) {
			$this->heard($rID, self::T);
		}
		$this->at(self::T);
		LivenessService::tick(30);

		// Node 2 is gone at T, and the liveness loop marks it offline.
		$this->heard(3, self::T + 40);
		$this->heard(4, self::T + 40);
		$this->at(self::T + 40);
		LivenessService::tick(30);
		$this->assertSame(['offline', false], [ClusterHealth::state(2), ClusterHealth::read()['guard']]);
		$this->heard(3, self::T + 60);
		$this->heard(4, self::T + 60);
		$this->assertSame([], $this->usersCron(self::T + 60), 'the watch starts');

		// MAIN's own network is cut: the others fall silent too, and the guard
		// comes up. It leaves node 2 offline, and does not hold its watch.
		$this->at(self::T + 90);
		LivenessService::tick(30);
		$this->assertSame([true, 'offline'], [ClusterHealth::read()['guard'], ClusterHealth::state(2)]);
		$this->assertSame([], $this->usersCron(self::T + 120));
		$this->at(self::T + 150);
		LivenessService::tick(30);
		$this->assertSame([true, 'offline'], [ClusterHealth::read()['guard'], ClusterHealth::state(2)]);
		$this->assertSame([2 => 3], $this->usersCron(self::T + 180), 'watched silent for the TTL, from before the guard');
		$this->assertSame([0, 3], [$this->stored(2), $this->stored(3)], 'the nodes the guard holds keep their viewers');
	}

	public function testALastingLossOfMostOfTheFleetIsPurgedOnceTheGuardHasHeldLongEnough(): void {
		// Two nodes of three are gone for good at T, while MAIN and node 4 go
		// on talking. The liveness loop takes it for MAIN's own fault, and
		// nothing ever clears its guard.
		$this->node(4, []);
		foreach ([2, 3, 4] as $rID) {
			$this->heard($rID, self::T);
		}
		$this->at(self::T);
		LivenessService::tick(30);
		$this->assertSame([], $this->usersCron(self::T));
		for ($rSec = self::T + 60; $rSec <= self::T + 600; $rSec += 60) {
			$this->heard(4, $rSec);
			$this->at($rSec);
			LivenessService::tick(30);
			$this->assertTrue(ClusterHealth::read()['guard'], 'the guard is up');
			$this->assertSame([], $this->usersCron($rSec), 'held for four TTLs from T+60, then watched for one');
		}
		$this->heard(4, self::T + 660);
		$this->at(self::T + 660);
		LivenessService::tick(30);
		$this->assertTrue(ClusterHealth::read()['guard'], 'still up');
		$this->assertSame([2 => 3, 3 => 3], $this->usersCron(self::T + 660), 'their viewers stop counting against their lines');
		$this->assertSame([0, 0], [$this->stored(2), $this->stored(3)]);
	}
}
