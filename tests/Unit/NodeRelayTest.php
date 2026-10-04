<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\NodeRelay;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * A node's heartbeat says whether its agent holds the relay proxy's port
 * (`relay`; ADR 0004, Phase 9's eighth increment). MAIN keeps the
 * transitions in the node's row and audits them; an older agent, or a report
 * that is not one, changes nothing.
 */
final class NodeRelayTest extends TestCase {
	private const SID = 5;

	private const NOW_MS = 1_800_000_000_000;

	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		$this->rDb->exec('CREATE TABLE `cluster_nodes` (`server_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `relay_down_since` int DEFAULT NULL, `relay_error` varchar(255) DEFAULT NULL, `updated_at` int NOT NULL DEFAULT 0)');
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

	public function testTheReportIsReadStrictly(): void {
		foreach ([null, 'bound', [], ['bound' => 1], ['bound' => 'false']] as $rNot) {
			$this->assertNull(NodeRelay::normalise($rNot, self::NOW_MS), json_encode($rNot));
		}
		$this->assertSame([null, null], NodeRelay::normalise(['bound' => true, 'error' => 'ignored'], self::NOW_MS));
		$this->assertSame([1_799_999_000, 'bind: address in use'], NodeRelay::normalise(['bound' => false, 'since_ms' => 1_799_999_000_500, 'failures' => 3, 'error' => "bind:\x00 address in use\n"], self::NOW_MS));
		$this->assertSame(1_799_998_999, NodeRelay::normalise(['bound' => false, 'since_ms' => 1_799_999_000_000], self::NOW_MS, 1000)[0], 'moved onto MAIN\'s clock by the node\'s offset');
		$this->assertSame(intdiv(self::NOW_MS, 1000), NodeRelay::normalise(['bound' => false, 'since_ms' => self::NOW_MS + 60000], self::NOW_MS)[0], 'never later than now');
		$this->assertSame([intdiv(self::NOW_MS, 1000), ''], NodeRelay::normalise(['bound' => false], self::NOW_MS), 'no since: now');
		$this->assertSame(NodeRelay::MAX_ERROR, strlen((string) NodeRelay::normalise(['bound' => false, 'error' => str_repeat('e', 400)], self::NOW_MS)[1]));
	}

	/** Down, the same again (no write), a new error, then bound: each transition once in the audit. */
	public function testTheTransitionsAreKeptAndAudited(): void {
		$rDown = ['bound' => false, 'since_ms' => self::NOW_MS - 30000, 'failures' => 2, 'error' => 'bind: address already in use'];
		NodeRelay::record($this->row(), $rDown, self::NOW_MS);
		$this->assertSame([1_799_999_970, 'bind: address already in use'], [(int) $this->row()['relay_down_since'], $this->row()['relay_error']]);

		$this->rDb->exec('UPDATE `cluster_nodes` SET `updated_at` = 1');
		NodeRelay::record($this->row(), ['failures' => 3] + $rDown, self::NOW_MS + 2000);
		$this->assertSame(1, (int) $this->row()['updated_at'], 'the same state is not written again');

		NodeRelay::record($this->row(), ['error' => 'bind: permission denied'] + $rDown, self::NOW_MS + 4000);
		$this->assertSame([1_799_999_970, 'bind: permission denied'], [(int) $this->row()['relay_down_since'], $this->row()['relay_error']], 'a new error, the same since');

		NodeRelay::record($this->row(), ['bound' => true], self::NOW_MS + 6000);
		$this->assertSame([null, null], [$this->row()['relay_down_since'], $this->row()['relay_error']]);
		NodeRelay::record($this->row(), ['bound' => true], self::NOW_MS + 8000);
		NodeRelay::record($this->row(), null, self::NOW_MS + 8000);

		$this->assertSame([
			'node.relay_unbound {"error":"bind: address already in use"}',
			'node.relay_bound {"down_since":1799999970}',
		], $this->events());
	}

	public function testATableFromBeforeTheMigrationChangesNothing(): void {
		NodeRelay::record(['server_id' => self::SID], ['bound' => false], self::NOW_MS);
		$this->assertNull($this->row()['relay_down_since']);
		$this->assertSame([], $this->events());
	}

	#[\PHPUnit\Framework\Attributes\Group('skip-on-panel')]
	public function testTheHeartbeatKeepsItAndTheSchemaHasIt(): void {
		$rRoot = dirname(__DIR__, 2);
		$this->assertStringContainsString("NodeRelay::record(\$rNode, \$rP['relay'] ?? null, \$rNowMs, HeartbeatService::nodeClockMs(\$rP, (int) \$rH['ts_ms']) - \$rNowMs);", (string) file_get_contents($rRoot . '/src/Domain/Cluster/ClusterApi.php'), 'since_ms is on the node\'s own clock');
		$rSchema = (string) file_get_contents($rRoot . '/src/bin/install/database.sql');
		$this->assertStringContainsString('`relay_down_since` int(11) DEFAULT NULL,', $rSchema);
		$this->assertStringContainsString('`relay_error` varchar(255)', $rSchema);
		$this->assertFileExists($rRoot . '/src/migrations/database/down/054_add_cluster_node_relay_down.sql');
		$this->assertStringContainsString('cluster_relay_unbound = ', (string) file_get_contents($rRoot . '/src/Core/Localization/lang/en.ini'));
	}
}
