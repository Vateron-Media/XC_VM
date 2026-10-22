<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ViewerKey;

/**
 * P1 (approach B): MAIN seals a per-node `policy` replica section so a serve-only
 * node writes tmp/gateway/policy.json from it without PHP. The section MUST carry
 * the node's OWN viewer key K_n (not MAIN's, not another node's) — a shared key
 * would let any node accept another's viewer tokens. The section-build wiring is
 * checked by source assertion (DB-driven, like the other ReplicaBuilder paths);
 * the per-node key invariant is pinned on ViewerKey::derive, which is pure.
 *
 * @covers \XcVm\Domain\Cluster\ReplicaBuilder
 */
final class ReplicaPolicySectionTest extends TestCase {

	public function testEachNodeGetsItsOwnViewerKey(): void {
		$rSecret = 'fleet-streaming-secret';
		$this->assertNotSame(
			ViewerKey::derive($rSecret, 3),
			ViewerKey::derive($rSecret, 7),
			'K_n is per node: a different server id derives a different viewer key'
		);
		$this->assertSame(
			ViewerKey::derive($rSecret, 3),
			ViewerKey::derive($rSecret, 3),
			'deterministic for the same (secret, id): the node and MAIN agree on K_n'
		);
	}

	public function testPolicyDataBuildsWithTheNodesOwnViewerKey(): void {
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Domain/Cluster/ReplicaBuilder.php');
		$this->assertStringContainsString('ViewerKey::entry($rLive, $rServerID, $rPrevious)', $rSrc, 'policyData derives the node own K_n via ViewerKey::entry(serverID), as the secrets section does');
		$this->assertMatchesRegularExpression('/GatewayPolicy::build\([^;]*\$rViewerKeys\)/s', $rSrc, 'policyData passes the node viewer keys to GatewayPolicy::build');
		$this->assertStringContainsString("ReplicaSections::POLICY => self::policyData((int) \$rNode['server_id'])", $rSrc, 'the policy section data is built per node');
		$this->assertStringContainsString("ReplicaSections::NODE, ReplicaSections::POLICY => \$rSection . '.' . (int) \$rNode['server_id']", $rSrc, 'the policy section ETag is cached per node, never shared across nodes');

		// GatewayPolicy::build takes the viewer keys, defaulting to held() for the node-local PHP caller.
		$rGw = (string) file_get_contents(MAIN_HOME . 'Core/Gateway/GatewayPolicy.php');
		$this->assertStringContainsString('?array $rViewerKeys = null', $rGw, 'build accepts an optional viewer-keys override');
		$this->assertStringContainsString('$rViewerKeys ?? ViewerKey::held($rNow)', $rGw, 'it uses the override when given, else the node-local held() keys');

		// Served on request (REPLY_ORDER) but NOT applied by PHP (never added to WHOLE).
		$rBuilder = $rSrc;
		$this->assertStringContainsString('ReplicaSections::CATEGORIES, ReplicaSections::POLICY,', $rBuilder, 'policy is in REPLY_ORDER so the config op serves it on request');
		$rSec = (string) file_get_contents(MAIN_HOME . 'Core/Cluster/ReplicaSections.php');
		$this->assertStringContainsString('self::BOUQUETS, self::CATEGORIES];', $rSec, 'policy is NOT in WHOLE — PHP never applies it (the Go agent writes it)');
	}
}
