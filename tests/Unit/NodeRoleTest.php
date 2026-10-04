<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;

/**
 * NodeRole — "is this node MAIN?". The crontab is copied verbatim to every
 * load balancer, so cluster-wide jobs (table rotation, the TMDb crawl, the
 * signals purge, the update and module-update checks) gate on this and do
 * nothing on an LB. An unknown answer must read as "not MAIN".
 */
final class NodeRoleTest extends TestCase {
	protected function setUp(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1);
		}
	}

	protected function tearDown(): void {
		NodeRole::useServers(null);
		NodeRole::useMainBuild(null);
		NodeFlows::usePath(null);
	}

	/**
	 * A load balancer's lightweight paths (segment.php) have no database. Its
	 * build is known from its files, so reading its flows never reads the
	 * servers there: that read went to MAIN's database once the servers cache
	 * was 10 s old, and threw (no DatabaseFactory) into the segment request.
	 */
	public function testALoadBalancersFlowsAreReadWithoutTheServers(): void {
		$rFile = tempnam(sys_get_temp_dir(), 'flows');
		file_put_contents($rFile, json_encode(['v' => 1, 'mode' => 1, 'flows' => NodeFlows::TELEMETRY, 'state' => 'active', 'features' => []]));
		NodeRole::useServers(static function (): array {
			throw new \RuntimeException('no database connection available');
		});
		try {
			NodeRole::useMainBuild(false);
			NodeFlows::usePath($rFile, true);
			$this->assertTrue(NodeFlows::on(NodeFlows::TELEMETRY));

			NodeRole::useServers(fn () => [SERVER_ID => ['is_main' => 1]]);
			NodeRole::useMainBuild(true);
			NodeFlows::usePath($rFile, true);
			$this->assertFalse(NodeFlows::on(NodeFlows::TELEMETRY), "MAIN's build still rules out MAIN by its servers");
		} finally {
			unlink($rFile);
		}
	}

	public function testMainNodeIsMain(): void {
		NodeRole::useServers(fn () => [SERVER_ID => ['is_main' => 1]]);
		$this->assertTrue(NodeRole::isMain());
	}

	public function testLoadBalancerIsNotMain(): void {
		NodeRole::useServers(fn () => [SERVER_ID => ['is_main' => 0], SERVER_ID + 1 => ['is_main' => 1]]);
		$this->assertFalse(NodeRole::isMain());
	}

	public function testUnknownServersReadAsNotMain(): void {
		NodeRole::useServers(fn () => []);
		$this->assertFalse(NodeRole::isMain(), 'no servers row: skip cluster-wide work');
		NodeRole::useServers(fn () => [SERVER_ID + 7 => ['is_main' => 1]]);
		$this->assertFalse(NodeRole::isMain(), 'this node missing from the list');
	}

	/**
	 * Every cron that changes cluster-wide state asks NodeRole before doing it.
	 *
	 * @dataProvider mainOnlyCrons
	 */
	public function testClusterWideCronsAreGated(string $rFile, string $rGuarded): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/' . $rFile);
		$rGate = strpos($rSource, 'NodeRole::isMain()');
		$this->assertNotFalse($rGate, "$rFile must ask NodeRole::isMain()");
		$this->assertLessThan(strpos($rSource, $rGuarded), $rGate, "$rFile must gate before: $rGuarded");
	}

	/**
	 * cron:cleanup's node-side part runs on every node, before its MAIN-only
	 * part: it is the only path that drops old days out of the settings-miss
	 * report (a node in mode 0 counts nothing, so never merges one) and
	 * removes it in mode 0. It also runs before the cron's first query, which
	 * a node in mode 2 refuses: the connect audit stays bounded there too.
	 */
	public function testCleanupKeepsEachNodesAuditsBeforeItsMainGate(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/CleanupCronJob.php');
		$rGate = strpos($rSource, 'if (!NodeRole::isMain())');
		$rQuery = strpos($rSource, '$db->query(');
		$this->assertNotFalse($rGate);
		$this->assertNotFalse($rQuery);
		foreach (['ConnectAudit::prune(8);', 'SettingsAudit::prune(8);', 'SettingsAudit::publish();'] as $rCall) {
			$rAt = strpos($rSource, $rCall);
			$this->assertNotFalse($rAt, "CleanupCronJob.php must call $rCall");
			$this->assertLessThan($rGate, $rAt, "$rCall runs on every node, before the MAIN-only part");
			$this->assertLessThan($rQuery, $rAt, "$rCall runs before the first query, which mode 2 refuses");
		}
	}

	/** @return array<string, array{string, string}> */
	public static function mainOnlyCrons(): array {
		return [
			'table rotation'  => ['CleanupCronJob.php', "\$rTables = ['lines_activity'"],
			'tmdb'            => ['TmdbCronJob.php', 'TmdbCron::run()'],
			'tmdb popular'    => ['TmdbPopularCronJob.php', 'TmdbPopularCron::run()'],
			'signals purge'   => ['RootSignalsCronJob.php', 'UNIX_TIMESTAMP() - `time` >= 86400'],
			'update check'    => ['UpdateCronJob.php', 'getUpdate(XC_VM_VERSION)'],
			'module updates'  => ['ModuleUpdatesCronJob.php', 'new ModuleUpdateChecker()'],
		];
	}
}
