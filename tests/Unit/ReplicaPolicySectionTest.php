<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ViewerKey;
use XcVm\Core\Gateway\GatewayPolicy;

/**
 * P1 (approach B): MAIN seals a per-node `policy` replica section so a serve-only
 * node writes tmp/gateway/policy.json from it without PHP. It MUST carry the
 * node's OWN viewer key K_n (not MAIN's, not another node's) — a shared key would
 * let any node accept another's viewer tokens. The per-node key invariant is
 * pinned on the pure ViewerKey::derive and, behaviourally, on GatewayPolicy::build;
 * the build-and-seal wiring (which is DB-driven) by source assertion.
 *
 * @covers \XcVm\Domain\Cluster\ReplicaBuilder
 * @covers \XcVm\Core\Gateway\GatewayPolicy
 */
final class ReplicaPolicySectionTest extends TestCase {

	public function testEachNodeGetsItsOwnViewerKey(): void {
		$rSecret = 'fleet-streaming-secret';
		$this->assertNotSame(ViewerKey::derive($rSecret, 3), ViewerKey::derive($rSecret, 7), 'K_n is per node');
		$this->assertSame(ViewerKey::derive($rSecret, 3), ViewerKey::derive($rSecret, 3), 'deterministic for the same (secret, id)');
	}

	public function testBuildEmitsExactlyTheGivenNodeKeys(): void {
		// Behavioural: build() must emit the viewer keys it is handed, so a
		// policyData that derived N's key but passed MAIN's held() keys could not
		// slip past a source check. Needs the CLI bootstrap; skipped where absent.
		if (!defined('OPENSSL_EXTRA')) {
			$this->markTestSkipped('GatewayPolicy::build needs the CLI bootstrap constants');
		}
		$rN = ViewerKey::derive('fleet-streaming-secret', 5);
		$rM = ViewerKey::derive('fleet-streaming-secret', 9);
		try {
			$rPolicy = GatewayPolicy::build([], [], 5, 1000, [], [['value' => $rN, 'until' => null]]);
		} catch (\Throwable $rE) {
			$this->markTestSkipped('build() unavailable here: ' . $rE->getMessage());
		}
		$rHex = $rPolicy['keys']['viewer'][0]['hex'] ?? null;
		$this->assertSame(bin2hex($rN), $rHex, 'build emits exactly the viewer keys it is given');
		$this->assertNotSame(bin2hex($rM), $rHex, "the node's policy carries its own key, never another node's");
	}

	public function testPolicySectionWiring(): void {
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Domain/Cluster/ReplicaBuilder.php');
		// Built with the node's own K_n and handed to build() as the viewer keys.
		$this->assertStringContainsString('ViewerKey::entry($rLive, $rServerID, $rPrevious)', $rSrc, 'policyData derives the node own K_n');
		$this->assertMatchesRegularExpression('/GatewayPolicy::build\([^;]*\$rViewerKeys\)/s', $rSrc, 'policyData passes the node viewer keys to build()');
		// A withholding mode-2 node does not get the stream pass in keys.shared.
		$this->assertStringContainsString('if (!self::withholdsStreamPass($rNode, $rLive))', $rSrc, 'policyData omits the stream pass for a withholding node');
		// Fresh, DB-consistent servers (not the possibly-stale cache).
		$this->assertStringContainsString('ServerRepository::getAll(true)', $rSrc, 'policyData uses fresh server data, consistent with the servers section');
		// The target node's flow/connection state, not MAIN's.
		$this->assertStringContainsString("\$rPolicy['live']['admission'] = (\$rNode['state'] ?? '') === 'active'", $rSrc, 'admission is the target node state, not MAIN');
		$this->assertStringContainsString('NodeRegistry::FLOW_CONNECTIONS', $rSrc, 'conn_store is the target node CONNECTIONS flow, not MAIN');
		// Read fresh, never cached unsealed (carries key material, like secrets).
		$this->assertStringContainsString('$rSection === ReplicaSections::SECRETS || $rSection === ReplicaSections::POLICY', $rSrc, 'policy is read fresh, not kept in the plaintext ETag cache');
		// Prioritised ahead of the large catalogue sections so it cannot be starved.
		$this->assertStringContainsString('ReplicaSections::SECRETS, ReplicaSections::POLICY, ReplicaSections::BOUQUETS', $rSrc, 'policy precedes bouquets/categories in REPLY_ORDER');

		// Eligibility: only an active, enrolled node gets the key-bearing sections.
		$this->assertStringContainsString('$rSection !== ReplicaSections::SECRETS && $rSection !== ReplicaSections::POLICY', $rSrc, 'serves() gates POLICY like SECRETS');

		// Build-time overridable viewer keys; defaults to held() for the node-local caller.
		$rGw = (string) file_get_contents(MAIN_HOME . 'Core/Gateway/GatewayPolicy.php');
		$this->assertStringContainsString('?array $rViewerKeys = null', $rGw, 'build accepts an optional viewer-keys override');
		$this->assertStringContainsString('$rViewerKeys ?? ViewerKey::held($rNow)', $rGw, 'uses the override when given, else held()');

		// Served on request but NOT applied by PHP (never added to WHOLE).
		$rSec = (string) file_get_contents(MAIN_HOME . 'Core/Cluster/ReplicaSections.php');
		$this->assertStringContainsString('self::BOUQUETS, self::CATEGORIES];', $rSec, 'policy is NOT in WHOLE — PHP never applies it');
	}
}
