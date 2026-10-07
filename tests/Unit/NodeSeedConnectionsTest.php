<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\RootSignalsCronJob;
use XcVm\Core\Cluster\NodeActions;

/**
 * The `seed_connections` root action, the guided cutover's step before
 * CONNECTIONS (ClusterCutover): the node runs cluster:seed-connections as
 * xc_vm and tells MAIN how it went in its system log, the line MAIN waits for.
 */
final class NodeSeedConnectionsTest extends TestCase {
	/** @var list<list<string>> */
	private array $rRan = [];

	/** @var array{0: int, 1: string} */
	private array $rAnswer = [0, ''];

	protected function setUp(): void {
		defined('SERVER_ID') || define('SERVER_ID', 1);
		RootSignalsCronJob::useRunner(function (array $rArgv): array {
			$this->rRan[] = $rArgv;
			return $this->rAnswer;
		});
	}

	protected function tearDown(): void {
		RootSignalsCronJob::useRunner(null);
	}

	/** @return list<list<mixed>> the system log rows the action wrote */
	private function seed(): array {
		$rDb = new class {
			/** @var list<list<mixed>> */
			public array $rRows = [];

			public function query(string $rSql, mixed ...$rParams): bool {
				if (str_contains($rSql, 'mysql_syslog')) {
					$this->rRows[] = $rParams;
				}
				return true;
			}
		};
		ob_start();
		(new RootSignalsCronJob())->executeAction(['action' => NodeActions::SEED_CONNECTIONS], [], $rDb);
		ob_end_clean();
		return $rDb->rRows;
	}

	public function testTheNodeLoadsItsViewersAsXcVmAndSaysSo(): void {
		$this->assertContains(NodeActions::SEED_CONNECTIONS, NodeActions::ROOT_ACTIONS, 'MAIN may send it');
		$this->rAnswer = [0, "OK: 12 of 12 connections loaded into the agent's registry\n"];
		$rRows = $this->seed();
		$this->assertSame([['sudo', '-u', 'xc_vm', PHP_BIN, MAIN_HOME . 'console.php', 'cluster:seed-connections']], $this->rRan);
		$this->assertCount(1, $rRows);
		$this->assertSame(SERVER_ID, $rRows[0][0]);
		$this->assertSame(NodeActions::SEEDED . "OK: 12 of 12 connections loaded into the agent's registry", $rRows[0][1]);
	}

	public function testAFailedSeedIsSaidSoMainLeavesConnectionsOff(): void {
		$this->rAnswer = [1, "Cannot read MAIN's store: refused\nThe agent did not answer (config/cluster/agent.sock). Nothing seeded\n"];
		$this->assertSame(NodeActions::NOT_SEEDED . 'The agent did not answer (config/cluster/agent.sock). Nothing seeded', $this->seed()[0][1]);
	}
}
