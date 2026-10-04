<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Cluster\ClusterBus;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\CommandBus;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * A command's `dedupe_key` names one desired state per node
 * (`UNIQUE(server_id, dedupe_key)`, migration 030): a newer command with
 * the key replaces one not yet acked. An acked command keeps its outcome
 * for a day (CommandBus::result), and must not keep its key: the next
 * command with that key (the next `config.changed`, a drop of the same
 * viewer) is queued, not refused by the unique key.
 */
final class CommandBusDedupeTest extends TestCase {
	private const SID = 5;

	private TestDb $rDb;

	private FakeClusterCrypto $rCrypto;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['029_create_cluster_nodes', '030_create_cluster_commands'] as $rName) {
			$rSql = (string) file_get_contents(MAIN_HOME . 'migrations/database/up/' . $rName . '.sql');
			$this->rDb->exec((string) $rSql);
		}
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix(1800000000000);
		ClusterBus::useSocket(sys_get_temp_dir() . '/no-such-bus-' . bin2hex(random_bytes(4)) . '/cluster.sock');
		$this->rCrypto = new FakeClusterCrypto();
		NodeRegistry::startEnrolment(self::SID, '0f8fad5b-d9cb-469f-a165-70867728950e', random_bytes(32), random_bytes(32), 1);
		NodeRegistry::update(self::SID, ['state' => 'active', 'mode' => 1, 'flows' => NodeRegistry::FLOW_COMMANDS]);
	}

	protected function tearDown(): void {
		ClusterBus::useSocket(null);
		ClusterClock::fix(null);
		DatabaseFactory::reset();
	}

	/** @return list<array{0: string, 1: string, 2: ?string}> [cmd_id, state, dedupe_key] by seq */
	private function rows(): array {
		$this->rDb->query('SELECT `cmd_id`, `state`, `dedupe_key` FROM `cluster_commands` ORDER BY `seq`');
		return array_map(static fn(array $rRow): array => [(string) $rRow['cmd_id'], (string) $rRow['state'], $rRow['dedupe_key']], $this->rDb->get_rows());
	}

	public function testAnAckedCommandGivesUpItsKey(): void {
		$rFirst = CommandBus::enqueue($this->rCrypto, self::SID, 'config.changed', ['sections' => ['servers']], 'config.changed');
		$this->assertTrue(CommandBus::ack(self::SID, $rFirst, true, 'done'));
		$rSecond = CommandBus::enqueue($this->rCrypto, self::SID, 'config.changed', ['sections' => ['servers']], 'config.changed');
		$this->assertSame([[$rFirst, 'acked', null], [$rSecond, 'queued', 'config.changed']], $this->rows());
		$this->assertSame([true, 'done'], CommandBus::result($rFirst), 'the acked one keeps its outcome');

		// A newer desired state still replaces one not acked yet.
		$rThird = CommandBus::enqueue($this->rCrypto, self::SID, 'config.changed', ['sections' => ['servers']], 'config.changed');
		$this->assertSame([[$rFirst, 'acked', null], [$rThird, 'queued', 'config.changed']], $this->rows());
		// And a failed one goes, as before.
		$this->assertTrue(CommandBus::ack(self::SID, $rThird, false, 'no'));
		$rFourth = CommandBus::enqueue($this->rCrypto, self::SID, 'conn.drop', ['uuid' => 'v1'], 'config.changed');
		$this->assertSame([[$rFirst, 'acked', null], [$rFourth, 'queued', 'config.changed']], $this->rows());
	}
}
