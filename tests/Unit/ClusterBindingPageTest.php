<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Domain\Cluster\ClusterOverview;
use XcVm\Domain\Cluster\ConnectionAdmission;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * Viewer record proof on the Cluster Nodes page (cluster_conn_binding, ADR
 * 0004, "The line a node names"): which nodes prove MAIN's mint since their
 * enrolment, the day's counts, and whether enforce is safe for the nodes it
 * would hold.
 */
final class ClusterBindingPageTest extends TestCase {
	private const NOW = 1800000000;

	private string $rDir;

	protected function setUp(): void {
		$rDb = new TestDb();
		$rDb->exec(InstallSchema::table('cluster_meta'));
		DatabaseFactory::set($rDb);
		ClusterClock::fix(self::NOW * 1000);
		$this->rDir = sys_get_temp_dir() . '/xcvm-binding-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir);
		ConnectionAdmission::useBinding(null, $this->rDir);
		ClusterMeta::set('conn_proven.5', '3'); // node 5 proved at its current generation
		ClusterMeta::set('conn_proven.6', '1'); // node 6 proved before its re-enrolment
		file_put_contents($this->rDir . '5.json', json_encode(['day' => gmdate('Y-m-d', self::NOW), 'unproven' => 0, 'admit_unproven' => 0, 'proven' => 4, 'age_max' => 2]));
		file_put_contents($this->rDir . '6.json', json_encode(['day' => gmdate('Y-m-d', self::NOW), 'unproven' => 1, 'admit_unproven' => 2, 'proven' => 0, 'age_max' => 0]));
	}

	protected function tearDown(): void {
		ConnectionAdmission::useBinding(null, null);
		ClusterClock::fix(null);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** @return list<array<string, mixed>> */
	private function nodes(): array {
		return [
			['server_id' => 5, 'server_name' => 'lb-a', 'state' => 'active', 'mode' => 2, 'gen' => 3],
			['server_id' => 6, 'server_name' => 'lb-b', 'state' => 'active', 'mode' => 2, 'gen' => 2],
			['server_id' => 7, 'server_name' => 'lb-c', 'state' => 'revoked', 'mode' => 2, 'gen' => 1],
		];
	}

	public function testItSaysWhichNodesProveAndWhatTheyCountedToday(): void {
		$rOut = ClusterOverview::binding($this->nodes(), ['live_streaming_pass' => 'secret'], static fn(): bool => false);
		$this->assertSame('observe', $rOut['mode']);
		$this->assertSame([5, 6], array_column($rOut['nodes'], 'server_id'), 'active nodes only');
		$this->assertSame([true, false], array_column($rOut['nodes'], 'proves'), 'a proof from before a re-enrolment does not count');
		$this->assertSame(4, $rOut['nodes'][0]['counts']['proven']);
		$this->assertSame(2, $rOut['nodes'][1]['counts']['admit_unproven']);
		$this->assertFalse($rOut['held'], 'MAIN sends every node the secret: enforce holds none');
		$this->assertSame('enforce', ClusterOverview::binding([], [ConnectionAdmission::BINDING => 'enforce'])['mode']);
		$this->assertSame('observe', ClusterOverview::binding([], [ConnectionAdmission::BINDING => 'anything else'])['mode']);
	}

	public function testEnforceIsReadyOnlyWhenEveryNodeItHoldsProvesAndCountedNothingUnproven(): void {
		$rHeld = static fn(array $rNode): bool => in_array((int) $rNode['server_id'], [5, 6], true);
		$this->assertFalse(ClusterOverview::binding($this->nodes(), ['live_streaming_pass' => 'secret'], $rHeld)['ready'], 'node 6 has not proved, and counted unproven records');

		$rOnlyFive = static fn(array $rNode): bool => (int) $rNode['server_id'] === 5;
		$rOut = ClusterOverview::binding($this->nodes(), ['live_streaming_pass' => 'secret'], $rOnlyFive);
		$this->assertTrue($rOut['held']);
		$this->assertTrue($rOut['ready'], 'the node enforce holds proves and counted nothing unproven');

		file_put_contents($this->rDir . '5.json', json_encode(['day' => gmdate('Y-m-d', self::NOW), 'unproven' => 0, 'admit_unproven' => 1, 'proven' => 4, 'age_max' => 2]));
		$this->assertFalse(ClusterOverview::binding($this->nodes(), ['live_streaming_pass' => 'secret'], $rOnlyFive)['ready'], 'an admission without a proof today');
		$this->assertFalse(ClusterOverview::binding($this->nodes(), [], $rOnlyFive)['held'], 'without the secret, nothing is held');
	}
}
