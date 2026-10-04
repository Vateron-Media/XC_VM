<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterOverview;
use XcVm\Domain\Cluster\ConnectionDigest;
use XcVm\Domain\Cluster\NodeLag;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * A node's heartbeat reports its event lanes' lag and the MAIN URLs it cannot
 * reach (`lanes`, `unreachable`; plan section 11, "Servers list badges"). MAIN
 * keeps the transitions in the node's row, audits them, and badges them on the
 * Servers list; an older agent, or a report that is not one, changes nothing.
 */
final class NodeLagTest extends TestCase {
	private const SID = 5;

	private const NOW_MS = 1_800_000_000_000;

	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		$this->rDb->exec('CREATE TABLE `cluster_nodes` (`server_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `p0_lag_since` int DEFAULT NULL, `p1_lag_since` int DEFAULT NULL, `unreachable_urls` varchar(1024) DEFAULT NULL, `updated_at` int NOT NULL DEFAULT 0)');
		$this->rDb->exec('INSERT INTO `cluster_nodes` (`server_id`) VALUES (5)');
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix(self::NOW_MS);
	}

	protected function tearDown(): void {
		ClusterClock::fix(null);
		DatabaseFactory::reset();
	}

	/** @return array<string, mixed> */
	private function row(): array {
		$this->rDb->query('SELECT * FROM `cluster_nodes` WHERE `server_id` = ?', self::SID);
		return $this->rDb->get_row();
	}

	/** @return list<string> */
	private function events(): array {
		$this->rDb->query('SELECT `event`, `detail` FROM `cluster_audit` ORDER BY `id`');
		return array_map(static fn(array $rRow): string => $rRow['event'] . ' ' . $rRow['detail'], $this->rDb->get_rows());
	}

	public function testTheReportsAreReadStrictly(): void {
		$this->assertNull(NodeLag::lanes(null, self::NOW_MS));
		$this->assertSame(['p0' => null, 'p1' => null], NodeLag::lanes(['p0' => ['lag_ms' => 120000], 'p1' => ['lag_ms' => '999999'], 'p2' => ['lag_ms' => 999999]], self::NOW_MS), '120 s is not yet lag; a string is no age; P2 is not kept');
		$this->assertSame(['p0' => null, 'p1' => 1_799_999_700], NodeLag::lanes(['p1' => ['files' => 3, 'lag_ms' => 300000]], self::NOW_MS), 'since: now − the oldest event\'s age');

		foreach ([null, 'x', ['url' => 'a'], ['a' => ['url' => 'b']]] as $rNot) {
			$this->assertNull(NodeLag::urls($rNot), json_encode($rNot));
		}
		$this->assertSame('', NodeLag::urls([]));
		$this->assertSame('http://b:80 https://a:8443', NodeLag::urls([['url' => "https://a:8443\n", 'for_ms' => 1], ['url' => 'http://b:80'], ['url' => 'https://a:8443'], ['url' => 7], 'junk']), 'sorted, once each, printable');
		$this->assertSame(NodeLag::MAX_URLS, strlen((string) NodeLag::urls(array_map(static fn(int $i): array => ['url' => 'https://main' . $i . '.example.com:8443'], range(1, 100)))));
	}

	/** P1 lags, the same again (no write), catches up; a URL fails, then answers: each transition once in the audit. */
	public function testTheTransitionsAreKeptAndAudited(): void {
		$rLagging = ['lanes' => ['p0' => ['files' => 0, 'lag_ms' => 0], 'p1' => ['files' => 40, 'lag_ms' => 200000]], 'unreachable' => [['url' => 'https://main.example.com:8443', 'for_ms' => 60000]]];
		NodeLag::record($this->row(), $rLagging, self::NOW_MS);
		$this->assertSame([null, 1_799_999_800, 'https://main.example.com:8443'], [$this->row()['p0_lag_since'], (int) $this->row()['p1_lag_since'], $this->row()['unreachable_urls']]);

		$this->rDb->exec('UPDATE `cluster_nodes` SET `updated_at` = 1');
		NodeLag::record($this->row(), ['lanes' => ['p0' => ['lag_ms' => 0], 'p1' => ['lag_ms' => 202000]]] + $rLagging, self::NOW_MS + 2000);
		$this->assertSame(1, (int) $this->row()['updated_at'], 'the same state is not written again');
		$this->assertSame(1_799_999_800, (int) $this->row()['p1_lag_since'], 'still since it began');

		NodeLag::record($this->row(), ['lanes' => ['p0' => ['lag_ms' => 0], 'p1' => ['lag_ms' => 900]], 'unreachable' => []], self::NOW_MS + 4000);
		$this->assertSame([null, null, null], [$this->row()['p0_lag_since'], $this->row()['p1_lag_since'], $this->row()['unreachable_urls']]);
		NodeLag::record($this->row(), [], self::NOW_MS + 6000);

		$this->assertSame([
			'node.lane_lagging {"lane":"p1","lag_s":200}',
			'node.urls_unreachable {"urls":"https://main.example.com:8443"}',
			'node.lane_caught_up {"lane":"p1","since":1799999800}',
			'node.urls_reachable {"urls":""}',
		], $this->events());
	}

	public function testATableFromBeforeTheMigrationChangesNothing(): void {
		NodeLag::record(['server_id' => self::SID], ['lanes' => ['p0' => ['lag_ms' => 999999]], 'unreachable' => [['url' => 'https://x']]], self::NOW_MS);
		$this->assertNull($this->row()['p0_lag_since']);
		$this->assertSame([], $this->events());
	}

	public function testTheServersListBadgesThem(): void {
		$rNow = self::NOW_MS;
		ConnectionDigest::useState(sys_get_temp_dir() . '/xcvm-no-digest-' . bin2hex(random_bytes(4)) . '/');
		try {
			$rBadges = ClusterOverview::nodeBadges(['server_id' => self::SID, 'p0_lag_since' => intdiv($rNow, 1000) - 300, 'p1_lag_since' => null, 'unreachable_urls' => 'https://main.example.com:8443'], $rNow);
			$this->assertSame([
				['tone' => 'danger', 'key' => 'cluster_lane_lag', 'vars' => ['{LANE}' => 'P0', '{AGE}' => '5m'], 'help' => 'cluster_lane_lag_help'],
				['tone' => 'warning', 'key' => 'cluster_url_unreachable', 'vars' => ['{URLS}' => 'https://main.example.com:8443'], 'help' => 'cluster_url_unreachable_help'],
			], $rBadges);
			$this->assertSame('warning', ClusterOverview::nodeBadges(['server_id' => self::SID, 'p1_lag_since' => intdiv($rNow, 1000)], $rNow)[0]['tone'], 'P1 carries logs only');
		} finally {
			ConnectionDigest::useState(null);
		}
	}

	#[\PHPUnit\Framework\Attributes\Group('skip-on-panel')]
	public function testTheHeartbeatKeepsItAndTheSchemaHasIt(): void {
		$rRoot = dirname(__DIR__, 2);
		$this->assertStringContainsString('NodeLag::record($rNode, $rP, $rNowMs);', (string) file_get_contents($rRoot . '/src/Domain/Cluster/ClusterApi.php'));
		$rSchema = (string) file_get_contents($rRoot . '/src/bin/install/database.sql');
		foreach (['`p0_lag_since` int(11) DEFAULT NULL,', '`p1_lag_since` int(11) DEFAULT NULL,', '`unreachable_urls` varchar(1024)'] as $rColumn) {
			$this->assertStringContainsString($rColumn, $rSchema);
		}
		$this->assertFileExists($rRoot . '/src/migrations/database/down/056_add_cluster_node_lag.sql');
		$this->assertStringContainsString('cluster_lane_lag = ', (string) file_get_contents($rRoot . '/src/Core/Localization/lang/en.ini'));
	}
}
