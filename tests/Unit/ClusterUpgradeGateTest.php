<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Cluster\ClusterUpgradeGate;
use XcVm\Infrastructure\Database\DatabaseFactory;

final class ClusterUpgradeGateTest extends TestCase {
	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY, `server_name` varchar(64), `is_main` int DEFAULT 0, `server_type` int DEFAULT 0)');
		$this->rDb->exec('CREATE TABLE `cluster_nodes` (`server_id` int PRIMARY KEY, `state` varchar(16), `mode` tinyint)');
		$this->rDb->exec("INSERT INTO `servers` VALUES (1, 'main', 1, 0), (2, 'lb', 0, 0), (3, 'proxy', 0, 1)");
		DatabaseFactory::set($this->rDb);
	}

	private function node(int $rMode): void {
		$this->rDb->exec("INSERT INTO `cluster_nodes` VALUES (2, 'active', {$rMode})");
	}

	public function testBelowBoundaryAllowedEvenAtMode1(): void {
		$this->node(1);
		$this->assertNull(ClusterUpgradeGate::blocksUpgradeTo('2.6.9'));
	}

	public function testAllMode2Allowed(): void {
		$this->node(2);
		$this->assertNull(ClusterUpgradeGate::blocksUpgradeTo('2.7.0'));
	}

	public function testMode1Blocked(): void {
		$this->node(1);
		$this->assertStringContainsString('lb (#2)', (string) ClusterUpgradeGate::blocksUpgradeTo('2.7.0'));
	}

	public function testNeverEnrolledBlocked(): void {
		$this->assertStringContainsString('lb (#2)', (string) ClusterUpgradeGate::blocksUpgradeTo('2.7.0'));
	}
}
