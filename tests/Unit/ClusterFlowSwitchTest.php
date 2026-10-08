<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\NodeRegistry;

/**
 * The flows the Cluster Nodes page switches. CONFIG (the authoritative replica,
 * Phase 7) was a bit every reader honoured but no operator could turn on, so the
 * whole replica path was unreachable. The page renders one cell per FLOW_BITS
 * entry against a fixed header row, so the two must stay the same length.
 */
final class ClusterFlowSwitchTest extends TestCase {

	public function testEveryFlowBitIsSwitchableAndDistinct(): void {
		$rBits = ClusterAdmin::FLOW_BITS;

		$this->assertSame(NodeRegistry::FLOW_CONFIG, $rBits['config'] ?? null);
		$this->assertSame(array_values($rBits), array_values(array_unique(array_values($rBits))));
		foreach ($rBits as $rName => $rBit) {
			$this->assertSame(0, $rBit & ($rBit - 1), $rName . ' is not a single bit');
		}
	}

	public function testTheNodesPageHasAToggleWithItsStringsForEverySwitchableFlow(): void {
		$rView = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/cluster_nodes.php');
		$rEn = (string) file_get_contents(MAIN_HOME . 'Core/Localization/lang/en.ini');

		$this->assertStringContainsString('foreach (ClusterAdmin::FLOW_BITS as $rFlowName => $rFlowBit)', $rView, 'one toggle per flow');
		foreach (array_keys(ClusterAdmin::FLOW_BITS) as $rName) {
			foreach (['cluster_' . $rName . '_flow', 'cluster_' . $rName . '_help'] as $rKey) {
				$this->assertMatchesRegularExpression('/^' . $rKey . ' = /m', $rEn, $rKey . ' is in en.ini');
			}
		}
	}

	public function testConfigNeedsNoOtherFlowButTheDependentOnesDo(): void {
		$this->assertTrue(NodeRegistry::validFlows(NodeRegistry::FLOW_CONFIG));

		// CONNECTIONS needs COMMANDS and STREAMS; DATAPLANE needs STREAMS and CONTENT.
		$this->assertFalse(NodeRegistry::validFlows(NodeRegistry::FLOW_CONNECTIONS));
		$this->assertTrue(NodeRegistry::validFlows(
			NodeRegistry::FLOW_CONNECTIONS | NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_STREAMS
		));
		$this->assertFalse(NodeRegistry::validFlows(NodeRegistry::FLOW_DATAPLANE | NodeRegistry::FLOW_STREAMS));
		$this->assertTrue(NodeRegistry::validFlows(
			NodeRegistry::FLOW_DATAPLANE | NodeRegistry::FLOW_STREAMS | NodeRegistry::FLOW_CONTENT
		));
	}

	/**
	 * The Servers list says which of its rows is a cluster node and how far it
	 * has moved: an operator reading that list had to correlate by server id
	 * against the Cluster Nodes page to know whether a node still holds MAIN's
	 * credentials. The badge is rendered from the same rows that page shows, and
	 * the list must survive the API being off (or its tables not existing yet).
	 */
	public function testTheServersListBadgesItsClusterNodes(): void {
		$rView = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/servers.php');
		$this->assertStringContainsString("\$rClusterNodes[(int) \$rServer['id']] ?? null", $rView);
		$this->assertStringContainsString('cluster_node_badge_help', $rView);

		$rController = (string) file_get_contents(MAIN_HOME . 'Public/Controllers/Admin/ServerListController.php');
		$this->assertStringContainsString("'rClusterNodes' => self::clusterNodes(\$rServers)", $rController);
		// The API off, or its tables not created yet, costs the list nothing.
		$this->assertStringContainsString("if (empty(SettingsManager::get('cluster_api_enabled'))) {", $rController);
		$this->assertStringContainsString('} catch (\Throwable) {', $rController);
		$this->assertSame(2, substr_count($rController, 'return [];'), 'both the off switch and the failure answer with no nodes');

		$rEn = (string) file_get_contents(MAIN_HOME . 'Core/Localization/lang/en.ini');
		$this->assertStringContainsString('cluster_node_badge_help = ', $rEn);
	}

	public function testEveryFlowHasItsStrings(): void {
		$rEn = (string) file_get_contents(MAIN_HOME . 'Core/Localization/lang/en.ini');

		foreach (array_keys(ClusterAdmin::FLOW_BITS) as $rName) {
			foreach (['_flow = ', '_help = ', '_on_done = ', '_off_done = '] as $rSuffix) {
				$this->assertStringContainsString("cluster_" . $rName . $rSuffix, $rEn, $rName . $rSuffix);
			}
		}
	}
}
