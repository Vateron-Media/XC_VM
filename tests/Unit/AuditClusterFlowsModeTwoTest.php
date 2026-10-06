<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\EventSpool;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\StreamRuntime;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Domain\Cluster\NodeAudit;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Domain\Stream\ContentSink;
use XcVm\Domain\Stream\StreamStateWriter;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\AgentUser;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * A node in mode 2 runs on every flow (ClusterAdmin::MODE2_FLOWS): the move
 * up asks for them, so the page keeps them on until the node is moved down.
 * On the node, a write that MAIN's database refused (mode 2) is no reason to
 * drop its own store of its streams' state: nothing landed on MAIN's row, and
 * a node in mode 2 cannot seed the store again.
 */
final class AuditClusterFlowsModeTwoTest extends TestCase {
	private TestDb $rDb;

	private string $rDir;

	private string $rRuntimeDir;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		$this->rDb->exec('CREATE TABLE `cluster_meta` (`name` varchar(64) PRIMARY KEY, `value` text, `updated_at` int)');
		$this->rDb->exec("CREATE TABLE `cluster_nodes` (`server_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `node_uuid` char(36), `state` varchar(16) NOT NULL DEFAULT 'active', `mode` int NOT NULL DEFAULT 1, `flows` int NOT NULL DEFAULT 0, `features` varchar(255) NOT NULL DEFAULT 'relay', `root_ready` int NOT NULL DEFAULT 1, `gen` int NOT NULL DEFAULT 1, `audit` text DEFAULT NULL, `last_seen_at` bigint DEFAULT NULL, `updated_at` int NOT NULL DEFAULT 0)");
		$this->rDb->query('INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`, `mode`, `flows`) VALUES (7, ?, 2, ?)', '0f8fad5b-d9cb-469f-a165-70867728950e', ClusterAdmin::MODE2_FLOWS);
		DatabaseFactory::set($this->rDb);
		foreach ([NodeAudit::class, NodeRegistry::class, ClusterMeta::class] as $rClass) {
			(new \ReflectionProperty($rClass, 'db'))->setValue(null, null);
		}

		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 5);
		}
		$this->rDir = sys_get_temp_dir() . '/xcvm-audit-flows-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'cluster/spool', 0777, true);
		AgentUser::own($this->rDir);
		EventSpool::useDir($this->rDir . 'cluster/spool/');
		$this->rRuntimeDir = StreamRuntime::dir();
		StreamRuntime::useDir($this->rDir . 'cluster/runtime/');
		NodeRole::useMainBuild(false);
	}

	protected function tearDown(): void {
		StreamStateWriter::useSink(null);
		StreamRuntime::useDir($this->rRuntimeDir);
		EventSpool::useDir(null);
		NodeFlows::usePath(null);
		NodeRole::useMainBuild(null);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function act(string $rAction): string {
		$rServers = [1 => ['is_main' => 1, 'server_type' => 0], 7 => ['is_main' => 0, 'server_type' => 0, 'server_name' => 'lb-7']];
		return ClusterAdmin::act(new FakeClusterCrypto(), ['cluster_action' => $rAction, 'server_id' => 7], $rServers, 1, [], 3)['message'];
	}

	private function flows(): int {
		return (int) $this->rDb->pdo->query('SELECT `flows` FROM `cluster_nodes` WHERE `server_id` = 7')->fetchColumn();
	}

	/** The agent's flows.json on the node, touched now. */
	private function nodeFlows(int $rFlows, int $rMode): void {
		$rFile = $this->rDir . 'cluster/flows.json';
		file_put_contents($rFile, json_encode(['mode' => $rMode, 'flows' => $rFlows, 'state' => 'active']));
		NodeFlows::usePath($rFile);
		clearstatcache();
	}

	/** The store as a seed leaves it (as the node's user). */
	private function markSeeded(): void {
		@mkdir($this->rDir . 'cluster/runtime', 0700, true);
		file_put_contents($this->rDir . 'cluster/runtime/seeded', json_encode(['at' => time(), 'server_id' => (int) SERVER_ID, 'streams' => 0]));
		AgentUser::own($this->rDir . 'cluster/runtime');
	}

	/** A database handle that turns every statement away, as a node in mode 2 is. */
	private function refusing(): object {
		return new class () {
			public function query(string $rSql, mixed ...$rParams): bool {
				throw new \RuntimeException('refused');
			}
		};
	}

	public function testNoFlowIsSwitchedOffWhileTheNodeIsInModeTwo(): void {
		foreach (array_keys(ClusterAdmin::FLOW_BITS) as $rName) {
			$this->assertSame('cluster_flow_mode_two', $this->act($rName . '_off'), $rName);
		}
		$this->assertSame(ClusterAdmin::MODE2_FLOWS, $this->flows());
		$this->assertSame(0, (int) $this->rDb->pdo->query("SELECT COUNT(*) FROM `cluster_audit` WHERE `event` = 'node.flows'")->fetchColumn(), 'a refused switch leaves no entry');

		// Quarantined, it is still in mode 2.
		$this->rDb->exec("UPDATE `cluster_nodes` SET `state` = 'quarantined'");
		$this->assertSame('cluster_flow_mode_two', $this->act('config_off'));
		$this->assertSame(ClusterAdmin::MODE2_FLOWS, $this->flows());
	}

	public function testAFlowIsStillSwitchedOnInModeTwoAndOffBelowIt(): void {
		// A node that reached mode 2 short of a flow gets it back from the page.
		$this->rDb->query('UPDATE `cluster_nodes` SET `flows` = ?', ClusterAdmin::MODE2_FLOWS & ~NodeRegistry::FLOW_LOGS);
		$this->assertSame('cluster_logs_on_done', $this->act('logs_on'));
		$this->assertSame(ClusterAdmin::MODE2_FLOWS, $this->flows());

		$this->rDb->exec('UPDATE `cluster_nodes` SET `mode` = 1');
		$this->assertSame('cluster_logs_off_done', $this->act('logs_off'));
		$this->assertSame(ClusterAdmin::MODE2_FLOWS & ~NodeRegistry::FLOW_LOGS, $this->flows());
	}

	public function testAWriteRefusedInModeTwoLeavesTheNodesStoreSeeded(): void {
		// Mode 2 with STREAMS off: the writer has only MAIN's database, which refuses it.
		$this->nodeFlows(NodeFlows::CONTENT, 2);
		$this->markSeeded();
		try {
			StreamStateWriter::update(10, (int) SERVER_ID, ['pid' => 2], $this->refusing());
			$this->fail('the write was refused');
		} catch (\RuntimeException) {
		}
		$this->assertTrue(StreamRuntime::seeded(), 'nothing landed on MAIN\'s row, and mode 2 cannot seed again');
		try {
			ContentSink::workerPid(10, 'vframes', 31, $this->refusing());
			$this->fail('the write was refused');
		} catch (\RuntimeException) {
		}
		$this->assertTrue(StreamRuntime::seeded());
	}

	public function testBelowModeTwoAWriteToMainsRowStillLapsesTheStore(): void {
		$this->nodeFlows(NodeFlows::CONTENT, 1);
		$this->markSeeded();
		$rDb = new class () {
			public function query(string $rSql, mixed ...$rParams): bool {
				return true;
			}
		};
		$this->assertTrue(StreamStateWriter::update(10, (int) SERVER_ID, ['pid' => 2], $rDb));
		$this->assertFalse(StreamRuntime::seeded());
		$this->markSeeded();
		$this->assertTrue(ContentSink::workerPid(10, 'vframes', 31, $rDb));
		$this->assertFalse(StreamRuntime::seeded());
	}
}
