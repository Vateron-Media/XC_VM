<?php

declare(strict_types=1);

namespace XcVm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterBus;
use XcVm\Domain\Cluster\ClusterRoute;
use XcVm\Domain\Cluster\ConnectionRetirement;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;
use XcVm\Tests\Support\InstallSchema;

final class ConnectionRetirementTest extends TestCase {
	private \TestDb $db;

	protected function setUp(): void {
		$this->db = new \TestDb();
		$this->db->exec(InstallSchema::table('connection_retirements'));
		foreach (['029_create_cluster_nodes', '030_create_cluster_commands'] as $migration) {
			$this->db->exec(InstallSchema::migration($migration));
		}
		$this->db->exec('ALTER TABLE cluster_nodes ADD COLUMN features varchar(255);');
		$this->db->exec("INSERT INTO cluster_nodes (server_id, node_uuid, state, mode, flows, created_at, updated_at, features) VALUES (5, '0f8fad5b-d9cb-469f-a165-70867728950e', 'active', 2, 255, 0, 0, 'retire_fence');");
		DatabaseFactory::set($this->db);
		SettingsManager::set(['cluster_api_enabled' => 1]);
		ClusterRoute::useCrypto(static fn() => new FakeClusterCrypto());
		ClusterBus::useSocket(sys_get_temp_dir() . '/missing-retire-bus-' . bin2hex(random_bytes(4)));
	}

	protected function tearDown(): void {
		ClusterRoute::useCrypto(null);
		ClusterBus::useSocket(null);
		SettingsManager::set([]);
		DatabaseFactory::reset();
	}

	private function pending(): void {
		$this->db->query('INSERT INTO connection_retirements (server_id, uuid, generation, date_start, retired_at, retire_token) VALUES (5, ?, ?, 100, ?, ?);', 'viewer', str_repeat('a', 32), time(), str_repeat('a', 32));
	}

	public function testDeliveryUsesExistingSignedSchemaAndRetriesUntilAcknowledged(): void {
		$this->pending();
		$this->assertSame(1, ConnectionRetirement::dispatch());
		$this->db->query('SELECT payload, cmd_id FROM cluster_commands;');
		$command = $this->db->get_raw_row();
		$this->assertSame(['uuid' => 'retire_' . str_repeat('a', 32), 'remove' => true], json_decode($command['payload'], true)['args']);
		$this->db->query('SELECT notify_done, command_id FROM connection_retirements;');
		$this->assertSame(['notify_done' => 0, 'command_id' => $command['cmd_id']], $this->db->get_raw_row());
		$this->db->query("UPDATE cluster_commands SET state = 'failed';");
		$this->db->query('UPDATE connection_retirements SET retry_at = 0;');
		$this->assertSame(1, ConnectionRetirement::dispatch(), 'failed commands are queued again');
		$this->db->query("UPDATE cluster_commands SET state = 'acked';");
		$this->db->query('UPDATE connection_retirements SET retry_at = 0;');
		$this->assertSame(0, ConnectionRetirement::dispatch());
		$this->db->query('SELECT notify_done FROM connection_retirements;');
		$this->assertSame(1, (int) $this->db->get_col());
	}

	public function testAnOldAgentDoesNotConsumeTheDurableNotification(): void {
		$this->pending();
		$this->db->query("UPDATE cluster_nodes SET features = 'hls_reaper';");
		$this->assertSame(0, ConnectionRetirement::dispatch());
		$this->db->query('SELECT notify_done, command_id FROM connection_retirements;');
		$this->assertSame(['notify_done' => 0, 'command_id' => null], $this->db->get_raw_row());
		$this->db->query('SELECT COUNT(*) FROM cluster_commands;');
		$this->assertSame(0, (int) $this->db->get_col());
	}
}
