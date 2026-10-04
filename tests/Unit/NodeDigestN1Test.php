<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ClusterDiagnosis;
use XcVm\Domain\Cluster\ClusterOverview;
use XcVm\Domain\Cluster\NodeDigestN1;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * A node's heartbeat names the owners whose chunk digest named no request
 * (`digest_n1`; ADR 0004, "The N−1 digest report"). MAIN keeps the list with
 * the node, writes it only when it changes, and the Cluster Nodes page and
 * `server:diagnose` show it; an older agent leaves the column NULL.
 */
final class NodeDigestN1Test extends TestCase {
	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `cluster_nodes` (`server_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `digest_n1` varchar(255) DEFAULT NULL, `updated_at` int NOT NULL DEFAULT 0)');
		$this->rDb->exec('INSERT INTO `cluster_nodes` (`server_id`) VALUES (5)');
		DatabaseFactory::set($this->rDb);
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
	}

	/** @return array<string, mixed> */
	private function row(): array {
		$this->rDb->query('SELECT * FROM `cluster_nodes` WHERE `server_id` = 5');
		return $this->rDb->get_row();
	}

	public function testTheReportIsReadStrictly(): void {
		foreach ([null, 'x', ['a' => 3], 7] as $rNot) {
			$this->assertNull(NodeDigestN1::normalise($rNot), json_encode($rNot));
		}
		$this->assertSame([], NodeDigestN1::normalise([]));
		$this->assertSame([3, 7], NodeDigestN1::normalise([7, 3, 7, 0, -1, '4', 1.5, 1000000]));
		$this->assertCount(NodeDigestN1::MAX_OWNERS, NodeDigestN1::normalise(range(1, 100)));
		$this->assertLessThanOrEqual(255, strlen((string) json_encode(NodeDigestN1::normalise(range(999000, 999999)))));
	}

	public function testTheListIsWrittenOnlyWhenItChanges(): void {
		NodeDigestN1::record($this->row(), null);
		$this->assertNull($this->row()['digest_n1'], 'an agent from before the report');
		NodeDigestN1::record($this->row(), []);
		$this->assertSame('[]', $this->row()['digest_n1']);
		NodeDigestN1::record($this->row(), [9, 4]);
		$this->assertSame('[4,9]', $this->row()['digest_n1']);
		$this->rDb->exec('UPDATE `cluster_nodes` SET `updated_at` = 1');
		NodeDigestN1::record($this->row(), [4, 9]);
		$this->assertSame(1, (int) $this->row()['updated_at'], 'the same list is not written again');
		NodeDigestN1::record(['server_id' => 5], [1]);
		$this->assertSame('[4,9]', $this->row()['digest_n1'], 'a table from before migration 055');
	}

	public function testThePageNamesTheOwnersAndTheNodesThatDoNotSay(): void {
		$this->assertSame(['owners' => [4, 9], 'silent' => 1], ClusterOverview::digestN1([
			['state' => 'active', 'digest_n1' => '[9,4]'],
			['state' => 'active', 'digest_n1' => '[4]'],
			['state' => 'active', 'digest_n1' => '[]'],
			['state' => 'active', 'digest_n1' => null],
			['state' => 'revoked', 'digest_n1' => '[12]'],
		]));
		$this->assertSame(['owners' => [], 'silent' => 0], ClusterOverview::digestN1([['state' => 'active', 'digest_n1' => '[]']]));
	}

	public function testServerDiagnoseSaysWhatTheNodeTakes(): void {
		$rLine = static function (array $rChecks): ?string {
			foreach ($rChecks as $rCheck) {
				if ($rCheck['label'] === 'Chunk digests') {
					return $rCheck['value'];
				}
			}
			return null;
		};
		$rNode = static fn(array $rRow, array $rSettings): ?string => $rLine(ClusterDiagnosis::node($rRow + ['state' => 'active', 'health' => 'ok'], ['count' => 0, 'oldest' => null], 1_800_000_000_000, $rSettings));
		$this->assertNull($rNode([], []), 'nothing reported, the setting off');
		$this->assertStringContainsString('every owner', (string) $rNode(['digest_n1' => '[]'], []));
		$this->assertStringContainsString('server 4, 9', (string) $rNode(['digest_n1' => '[4,9]'], []));
		$this->assertStringContainsString('refused', (string) $rNode(['digest_n1' => '[4]'], ['lb_digest_nonce_required' => '1']));
		// On the node itself: only whether its agent refuses them.
		$this->assertNull($rLine(ClusterDiagnosis::agent(['version' => 't'], 1_800_000_000_000, [])));
		$this->assertStringContainsString('refused', (string) $rLine(ClusterDiagnosis::agent(['version' => 't'], 1_800_000_000_000, ['lb_digest_nonce_required' => '1'])));
	}
}
