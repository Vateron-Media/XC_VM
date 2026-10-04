<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\UsersCronJob;
use XcVm\Core\Cluster\ClusterHealth;
use XcVm\Core\Cluster\HlsReaping;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * MAIN leaves idle HLS viewers to a node whose agent reaps them, until that
 * node has been silent for the orphan TTL while MAIN itself was watching.
 */
final class HlsReapingTest extends TestCase {
	private const T = 1800000000;

	private TestDb $rDb;

	private string $rPath;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec("CREATE TABLE `cluster_nodes` (`server_id` int, `state` varchar(16), `mode` int, `flows` int, `features` varchar(255), `last_seen_at` bigint)");
		DatabaseFactory::set($this->rDb);
		$this->rPath = sys_get_temp_dir() . '/orphans_' . bin2hex(random_bytes(4)) . '.json';
		HlsReaping::usePath($this->rPath);
		ClusterHealth::usePath($this->rPath . '.health'); // no fleet silence guard
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		@unlink($this->rPath);
		@unlink($this->rPath . '.flows');
		HlsReaping::usePath(null);
		NodeFlows::usePath(null);
		ClusterHealth::usePath(null);
	}

	private function node(int $rID, string $rState, int $rMode, int $rFlows, ?string $rFeatures, ?int $rSeenSec): void {
		$this->rDb->query('INSERT INTO `cluster_nodes` VALUES (?, ?, ?, ?, ?, ?)', $rID, $rState, $rMode, $rFlows, $rFeatures, $rSeenSec === null ? null : $rSeenSec * 1000);
	}

	public function testOnlyActiveNodesWithTheFeatureAndConnectionsReapForThemselves(): void {
		$this->node(2, 'active', 1, 64 | 8 | 2, 'fanout_events,hls_reaper', self::T - 1);
		$this->node(3, 'active', 1, 64 | 8 | 2, null, self::T - 1);           // an older agent
		$this->node(4, 'active', 1, 8 | 2, 'hls_reaper', self::T - 1);         // CONNECTIONS off
		$this->node(5, 'quarantined', 1, 64 | 8 | 2, 'hls_reaper', self::T - 1);
		$this->node(6, 'active', 0, 64 | 8 | 2, 'hls_reaper', self::T - 1);    // mode 0
		HlsReaping::begin(self::T, 120);
		$this->assertTrue(HlsReaping::nodeReaps(2));
		foreach ([3, 4, 5, 6, 99] as $rID) {
			$this->assertFalse(HlsReaping::nodeReaps($rID), (string) $rID);
		}
	}

	/**
	 * With `lines_live` as the store each server sweeps its own rows, and a
	 * node in mode 2 has no database to sweep them in: MAIN closes what it ended.
	 */
	public function testMainSweepsForANodeInModeTwoOnly(): void {
		$this->node(2, 'active', 2, 255, 'hls_reaper', self::T - 1);
		$this->node(3, 'active', 1, 255, 'hls_reaper', self::T - 1);           // sweeps its own rows
		$this->node(4, 'active', 2, 255 & ~64, 'hls_reaper', self::T - 1);     // CONNECTIONS off: its viewers are not the agent's
		$this->node(5, 'quarantined', 2, 255, 'hls_reaper', self::T - 1);
		HlsReaping::begin(self::T, 120);
		$this->assertTrue(HlsReaping::sweptByMain(2));
		foreach ([3, 4, 5, 99] as $rID) {
			$this->assertFalse(HlsReaping::sweptByMain($rID), (string) $rID);
		}
		// The nodes cannot be read: nobody is taken for a node in mode 2.
		$this->rDb->exec('DROP TABLE `cluster_nodes`');
		HlsReaping::begin(self::T + 60, 120);
		$this->assertFalse(HlsReaping::sweptByMain(2));
	}

	public function testASilentNodeIsOrphanedOnlyAfterTheTtlWatchedByMain(): void {
		// Silent for 10 minutes already at the first pass: MAIN may just have come back.
		$this->node(2, 'active', 1, 74, 'hls_reaper', self::T - 600);
		HlsReaping::begin(self::T, 120);
		$this->assertTrue(HlsReaping::nodeReaps(2), 'first pass: the watch only starts');
		HlsReaping::begin(self::T + 60, 120);
		$this->assertTrue(HlsReaping::nodeReaps(2));
		HlsReaping::begin(self::T + 120, 120);
		$this->assertFalse(HlsReaping::nodeReaps(2), 'watched silent for the TTL: orphaned');

		// Heard again: back to reaping for itself, and the watch is forgotten.
		$this->rDb->query('UPDATE `cluster_nodes` SET `last_seen_at` = ?', (self::T + 179) * 1000);
		HlsReaping::begin(self::T + 180, 120);
		$this->assertTrue(HlsReaping::nodeReaps(2));
		$this->assertSame([], json_decode((string) file_get_contents($this->rPath), true)['since']);
	}

	public function testAGapInTheReaperRestartsTheWatch(): void {
		$this->node(2, 'active', 1, 74, 'hls_reaper', self::T - 600);
		HlsReaping::begin(self::T, 120);
		// MAIN (or its cron) was down for 10 minutes: its own downtime never counts.
		HlsReaping::begin(self::T + 600, 120);
		$this->assertTrue(HlsReaping::nodeReaps(2));
		HlsReaping::begin(self::T + 660, 120);
		$this->assertTrue(HlsReaping::nodeReaps(2));
		HlsReaping::begin(self::T + 720, 120);
		$this->assertFalse(HlsReaping::nodeReaps(2));
	}

	public function testANodeThatNeverSpokeIsWatchedToo(): void {
		$this->node(2, 'active', 1, 74, 'hls_reaper', null);
		HlsReaping::begin(self::T, 60);
		$this->assertTrue(HlsReaping::nodeReaps(2));
		HlsReaping::begin(self::T + 60, 60);
		$this->assertFalse(HlsReaping::nodeReaps(2));
	}

	public function testTheUsersCronLeavesAReapingNodesIdleViewersToIt(): void {
		$this->node(2, 'active', 1, 74, 'hls_reaper', self::T - 1);
		$this->node(3, 'active', 1, 74, null, self::T - 1);
		HlsReaping::begin(self::T, 120);
		$rEnded = new ReflectionMethod(UsersCronJob::class, 'hlsEnded');
		$rCron = new UsersCronJob();
		$rIdle = ['hls_end' => 0, 'hls_last_read' => self::T - 45];
		$this->assertFalse($rEnded->invoke($rCron, $rIdle + ['server_id' => 2], self::T), 'its agent decides');
		$this->assertTrue($rEnded->invoke($rCron, $rIdle + ['server_id' => 3], self::T), 'an older agent: the 30 s rule');
		$this->assertTrue($rEnded->invoke($rCron, ['hls_end' => 1, 'hls_last_read' => self::T, 'server_id' => 2], self::T), 'ended by the node');
		$this->assertFalse($rEnded->invoke($rCron, ['hls_end' => 0, 'hls_last_read' => self::T - 5, 'server_id' => 3], self::T));
	}

	public function testWhenTheNodesCannotBeReadTheLastKnownReapersStand(): void {
		$this->node(2, 'active', 1, 74, 'hls_reaper', self::T - 1);
		$this->node(3, 'active', 1, 74, null, self::T - 1);
		HlsReaping::begin(self::T, 120);
		$this->rDb->exec('DROP TABLE `cluster_nodes`');
		HlsReaping::begin(self::T + 60, 120);
		// A reaping node's touches no longer refresh MAIN's store (conn.touch
		// stays on the bus), so the 30 s rule must not come back on a bad read.
		$this->assertTrue(HlsReaping::nodeReaps(2));
		$this->assertFalse(HlsReaping::nodeReaps(3));
		$this->assertSame([], HlsReaping::orphaned(), 'nothing is purged on a read that failed');

		// Nothing ever read: every node gets the 30 s rule, as before.
		HlsReaping::usePath($this->rPath . '.fresh');
		HlsReaping::begin(self::T + 120, 120);
		$this->assertFalse(HlsReaping::nodeReaps(2));
		@unlink($this->rPath . '.fresh');
	}

	/**
	 * A reaping node's touches reach only the bus, so MAIN's store can hold a
	 * read minutes old for it: a node that stops reaping for itself keeps
	 * counting as reaping for the grace, which the node needs to hear of the
	 * change and have its viewers' next requests refresh the store.
	 *
	 * @return iterable<string, array{0: string}>
	 */
	public static function leaving(): iterable {
		yield 'CONNECTIONS off' => ['UPDATE `cluster_nodes` SET `flows` = 10 WHERE `server_id` = 2'];
		yield 'mode 0' => ['UPDATE `cluster_nodes` SET `mode` = 0 WHERE `server_id` = 2'];
		yield 'an agent without the reaper' => ['UPDATE `cluster_nodes` SET `features` = \'fanout_events\' WHERE `server_id` = 2'];
		yield 'revoked' => ['UPDATE `cluster_nodes` SET `state` = \'revoked\' WHERE `server_id` = 2'];
		yield 'deleted' => ['DELETE FROM `cluster_nodes` WHERE `server_id` = 2'];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('leaving')]
	public function testANodeThatStopsReapingKeepsItForTheGrace(string $rChange): void {
		$this->node(2, 'active', 1, 74, 'fanout_events,hls_reaper', self::T - 1);
		HlsReaping::begin(self::T, 120);
		$this->rDb->exec($rChange);
		$rEnded = new ReflectionMethod(UsersCronJob::class, 'hlsEnded');
		$rStale = ['hls_end' => 0, 'hls_last_read' => self::T - 600, 'server_id' => 2];

		HlsReaping::begin(self::T + 5, 120);
		$this->assertTrue(HlsReaping::nodeReaps(2), 'the first pass after the change');
		$this->assertFalse($rEnded->invoke(new UsersCronJob(), $rStale, self::T + 5), 'a live viewer whose read only the bus had is not ended');
		HlsReaping::begin(self::T + 65, 120);
		$this->assertTrue(HlsReaping::nodeReaps(2));
		HlsReaping::begin(self::T + 125, 120);
		$this->assertFalse(HlsReaping::nodeReaps(2), 'the grace counts from the pass that first saw it');
		$this->assertTrue($rEnded->invoke(new UsersCronJob(), $rStale, self::T + 125), 'then the 30 s rule, on what the store holds by now');
		$this->assertSame([], json_decode((string) file_get_contents($this->rPath), true)['leaving'], 'and it is forgotten');
		HlsReaping::begin(self::T + 185, 120);
		$this->assertFalse(HlsReaping::nodeReaps(2));
	}

	public function testANodeBackWithinTheGraceReapsAndLeavingAgainStartsANewOne(): void {
		$this->node(2, 'active', 1, 74, 'hls_reaper', self::T - 1);
		HlsReaping::begin(self::T, 120);
		$this->rDb->exec('UPDATE `cluster_nodes` SET `flows` = 10');
		HlsReaping::begin(self::T + 60, 120);
		$this->rDb->exec('UPDATE `cluster_nodes` SET `flows` = 74');
		HlsReaping::begin(self::T + 120, 120);
		$this->assertTrue(HlsReaping::nodeReaps(2));
		$this->rDb->exec('UPDATE `cluster_nodes` SET `flows` = 10');
		HlsReaping::begin(self::T + 180, 120);
		HlsReaping::begin(self::T + 240, 120);
		$this->assertTrue(HlsReaping::nodeReaps(2), 'a new grace, from the pass at +180');
		HlsReaping::begin(self::T + 300, 120);
		$this->assertFalse(HlsReaping::nodeReaps(2));
	}

	public function testAnOrphanedNodeGetsNoGraceAndAFailedReadKeepsOnlyARunningOne(): void {
		$this->node(2, 'active', 1, 74, 'hls_reaper', self::T - 600);
		$this->node(3, 'active', 1, 74, 'hls_reaper', self::T - 1);
		HlsReaping::begin(self::T, 120);
		HlsReaping::begin(self::T + 120, 120);
		$this->assertSame([2], HlsReaping::orphaned());
		$this->assertFalse(HlsReaping::nodeReaps(2), 'its rows are purged; no grace');

		$this->rDb->exec('UPDATE `cluster_nodes` SET `flows` = 10 WHERE `server_id` = 3');
		HlsReaping::begin(self::T + 180, 120);
		$this->rDb->exec('DROP TABLE `cluster_nodes`');
		HlsReaping::begin(self::T + 240, 120);
		$this->assertTrue(HlsReaping::nodeReaps(3), 'within its grace on a failed read');
		HlsReaping::begin(self::T + 300, 120);
		$this->assertFalse(HlsReaping::nodeReaps(3), 'and not past it');
		$this->assertFalse(HlsReaping::nodeReaps(2));
	}

	public function testAnLbKeepsItsOwnReaperForTheGraceToo(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1);
		}
		$rFlows = $this->rPath . '.flows';
		$rWrite = static function (int $rFlows_, array $rFeatures) use ($rFlows): void {
			file_put_contents($rFlows, json_encode(['mode' => 1, 'flows' => $rFlows_, 'state' => 'active', 'features' => $rFeatures]));
			NodeFlows::usePath($rFlows); // and forget what was read
		};
		$rWrite(NodeFlows::CONNECTIONS | NodeFlows::COMMANDS | NodeFlows::STREAMS, ['hls_reaper']);
		$this->assertTrue(HlsReaping::nodeReaps((int) SERVER_ID), 'no pass yet: its agent, now');
		HlsReaping::beginLocal(self::T);
		$this->assertTrue(HlsReaping::nodeReaps((int) SERVER_ID));

		// CONNECTIONS off: the node's PHP writes lines_live again from its
		// viewers' next requests; until then the rows hold what the bus had.
		$rWrite(NodeFlows::COMMANDS | NodeFlows::STREAMS, ['hls_reaper']);
		HlsReaping::beginLocal(self::T + 60);
		$this->assertTrue(HlsReaping::nodeReaps((int) SERVER_ID));
		HlsReaping::beginLocal(self::T + 120);
		$this->assertTrue(HlsReaping::nodeReaps((int) SERVER_ID));
		HlsReaping::beginLocal(self::T + 180);
		$this->assertFalse(HlsReaping::nodeReaps((int) SERVER_ID));

		// An agent that never reaped gets no grace.
		HlsReaping::usePath($this->rPath . '.fresh');
		HlsReaping::beginLocal(self::T + 240);
		$this->assertFalse(HlsReaping::nodeReaps((int) SERVER_ID));
		@unlink($this->rPath . '.fresh');
	}

	public function testEveryOrphanedConnectionsNodeIsReportedForThePurge(): void {
		$this->node(2, 'active', 1, 74, 'hls_reaper', self::T - 600);
		$this->node(3, 'active', 1, 74, null, self::T - 600);        // an older agent: purged too
		$this->node(4, 'active', 1, 10, 'hls_reaper', self::T - 600); // CONNECTIONS off: MAIN's store is the node's own
		$this->node(5, 'active', 1, 74, 'hls_reaper', self::T - 1);
		HlsReaping::begin(self::T, 120);
		$this->assertSame([], HlsReaping::orphaned(), 'the watch only starts');
		HlsReaping::begin(self::T + 120, 120);
		$this->assertSame([2, 3], HlsReaping::orphaned());
	}

	public function testThePurgeDropsOnlyThatNodesRowsFromTheStore(): void {
		\XcVm\Core\Config\SettingsManager::set(['redis_handler' => 0]);
		$this->rDb->exec('CREATE TABLE `lines_live` (`activity_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `uuid` text, `server_id` int, `container` text)');
		$this->rDb->exec("INSERT INTO `lines_live` (`uuid`, `server_id`, `container`) VALUES ('a', 2, 'ts'), ('b', 2, 'hls'), ('c', 3, 'ts')");
		$this->assertSame(2, \XcVm\Domain\Cluster\ConnectionIngest::purgeNode(2));
		$this->assertSame(0, \XcVm\Domain\Cluster\ConnectionIngest::purgeNode(2));
		$this->rDb->query('SELECT `uuid` FROM `lines_live`');
		$this->assertSame([['uuid' => 'c']], $this->rDb->get_rows());
		\XcVm\Core\Config\SettingsManager::set([]);
	}
}
