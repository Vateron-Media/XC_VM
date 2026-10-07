<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Process\ProcessRunner;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\ClusterOverview;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * MAIN's data plane from the Cluster Nodes page (ClusterAdmin::act runs the
 * CLI's `cluster:main-dataplane`), and the servers that still keep the load
 * balancers' legacy /api open (ClusterOverview::legacyApiOpenBy), as each
 * node judges it from its signed node list (DataPlane::legacyApiRetired).
 */
final class MainDataPlaneSwitchTest extends TestCase {
	private TestDb $rDb;

	/** @var list<list<string>> */
	private array $rRan = [];

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		DatabaseFactory::set($this->rDb);
	}

	protected function tearDown(): void {
		ProcessRunner::useCapturer(null);
		DatabaseFactory::reset();
	}

	/** @return array<string, mixed> act()'s answer, the command answering $rStatus and $rOut. */
	private function act(string $rAction, int $rStatus, string $rOut): array {
		ProcessRunner::useCapturer(function (array $rArgv) use ($rStatus, $rOut): array {
			$this->rRan[] = $rArgv;
			return [$rStatus, $rOut];
		});
		// No server id: it is MAIN's own, not a load balancer's.
		return ClusterAdmin::act(new FakeClusterCrypto(), ['cluster_action' => $rAction], [1 => ['server_name' => 'main']], 1, [], 3);
	}

	public function testThePageRunsTheCommandTheCliRuns(): void {
		$this->assertSame(['type' => 'success', 'message' => 'cluster_main_dataplane_on_done'], $this->act('main_dataplane_on', 0, "MAIN's data plane is on (key gen 1)\n"));
		$this->assertSame(['type' => 'success', 'message' => 'cluster_main_dataplane_off_done'], $this->act('main_dataplane_off', 0, ''));
		$this->assertSame([[PHP_BIN, MAIN_HOME . 'console.php', 'cluster:main-dataplane', 'on'], [PHP_BIN, MAIN_HOME . 'console.php', 'cluster:main-dataplane', 'off']], $this->rRan);
		$this->rDb->query("SELECT `actor`, `detail` FROM `cluster_audit` WHERE `event` = 'cluster.main_dataplane_asked' ORDER BY `id`");
		$rRows = $this->rDb->get_rows();
		$this->assertSame(['admin:3', ['on' => true, 'exit' => 0]], [$rRows[0]['actor'], json_decode($rRows[0]['detail'], true)], 'who asked, from the page');
	}

	public function testARefusalSaysTheCommandsReason(): void {
		$rOut = $this->act('main_dataplane_on', 1, "Refused: no xc_agent binary yet: run `console.php fanout_binary agent` as root\n");
		$this->assertSame(['type' => 'warning', 'message' => 'cluster_main_dataplane_refused', 'vars' => ['{WHY}' => 'no xc_agent binary yet: run `console.php fanout_binary agent` as root']], $rOut);
		$this->assertSame('exit 127', $this->act('main_dataplane_off', 127, '')['vars']['{WHY}'], 'a command that did not start');
	}

	public function testTheLegacyApiStaysOpenUntilEveryServerHasItsDataPlane(): void {
		$rServers = [1 => ['server_name' => 'main'], 7 => ['server_name' => 'lb-a'], 8 => ['server_name' => 'lb-b'], 9 => ['server_name' => 'proxy']];
		$rNode = static fn(int $rSid, array $rOver = []): array => $rOver + ['server_id' => $rSid, 'state' => 'active', 'mode' => 2, 'flows' => 255];
		$rNodes = [$rNode(7), $rNode(8, ['flows' => 255 & ~NodeRegistry::FLOW_DATAPLANE])];
		$this->assertSame([1, 8, 9], ClusterOverview::legacyApiOpenBy($rServers, $rNodes, 1, false), 'MAIN off, a node without the flow, a proxy (no node)');
		$this->assertSame([8, 9], ClusterOverview::legacyApiOpenBy($rServers, $rNodes, 1, true));

		unset($rServers[9]);
		$rNodes[1] = $rNode(8);
		$this->assertSame([], ClusterOverview::legacyApiOpenBy($rServers, $rNodes, 1, true), 'closed');
		$this->assertSame([8], ClusterOverview::legacyApiOpenBy($rServers, [$rNode(7), $rNode(8, ['mode' => 0])], 1, true), 'mode 0 is not in the node list with its data plane');
		$this->assertSame([8], ClusterOverview::legacyApiOpenBy($rServers, [$rNode(7), $rNode(8, ['state' => 'quarantined'])], 1, true), 'nor a quarantined node');
	}
}
