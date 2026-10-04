<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Cluster\CommandBus;

/**
 * "Rotate token" on the Cluster Nodes page. `token.rotate_now` was listed among
 * the restrictive command types and had no producer and no executor: an operator
 * who no longer trusted a node's token could only revoke the node (which stops
 * it) or wait out the refresh window. The command is the agent's own, because
 * the token lives there and the node's PHP would refuse the type.
 */
final class ClusterRotateNowTest extends TestCase {

	private function src(string $rPath): string {
		return (string) file_get_contents(MAIN_HOME . $rPath);
	}

	public function testTheTypeIsOneMainMaySendAndIsRestrictive(): void {
		$this->assertContains('token.rotate_now', CommandBus::TYPES, 'MAIN refuses to enqueue a type it does not list');
		// Restrictive: the extension signs it even while MAIN's licence is
		// refused, which is exactly when an operator wants to rotate.
		$this->assertContains('token.rotate_now', CommandBus::RESTRICTIVE);
	}

	public function testMainHasAProducerAndTheOperatorAButton(): void {
		$rRoute = $this->src('Domain/Cluster/ClusterRoute.php');
		$this->assertMatchesRegularExpression('/function rotateNow\(int \$rServerID\): array/', $rRoute);
		$this->assertStringContainsString("'token.rotate_now', [], 'token.rotate_now'", $rRoute, 'deduped, so a double click queues one command');

		$rAdmin = $this->src('Domain/Cluster/ClusterAdmin.php');
		$this->assertMatchesRegularExpression("/case 'rotate_now':\s*\n(.*\n)*?\s*\[\\\$rRouted, \\\$rQueued\] = ClusterRoute::rotateNow/", $rAdmin);
		$this->assertStringContainsString("ClusterAudit::log('node.token_rotate'", $rAdmin, 'every decision is audited');

		$rView = $this->src('Public/Views/admin/cluster_nodes.php');
		$this->assertStringContainsString('value="rotate_now"', $rView);
		$this->assertStringNotContainsString('js-cluster-rotate', $rView, 'no confirmation: rotating costs a node nothing');
	}

	public function testEveryOutcomeHasItsString(): void {
		$rEn = (string) file_get_contents(MAIN_HOME . 'Core/Localization/lang/en.ini');

		foreach (['cluster_rotate_now', 'cluster_rotate_now_help', 'cluster_rotate_done', 'cluster_rotate_failed', 'cluster_rotate_no_commands'] as $rKey) {
			$this->assertStringContainsString($rKey . ' = ', $rEn, $rKey);
		}
	}
}
