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
		$rView = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/partials/cluster_section.php');
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

		// The servers list gets its nodes keyed by server id from the folded-in
		// cluster controller; Manage Servers is now the only cluster page.
		$rList = (string) file_get_contents(MAIN_HOME . 'Public/Controllers/Admin/ServerListController.php');
		$this->assertStringContainsString('ClusterNodesController::viewData(', $rList);
		$this->assertStringContainsString("'rClusterNodes' => \$rNodesByServer", $rList);
		// The API off, or its tables not created yet, costs the list nothing:
		// viewData() answers with the empty shape instead of throwing.
		$rCluster = (string) file_get_contents(MAIN_HOME . 'Public/Controllers/Admin/ClusterNodesController.php');
		$this->assertStringContainsString("empty(\$rSettings['cluster_api_enabled'])", $rCluster);
		$this->assertStringContainsString('ClusterAdmin::tablesReady()', $rCluster);
		$this->assertStringContainsString('} catch (\Throwable $rErr) {', $rCluster);
		$this->assertStringContainsString('error_log(', $rCluster);
		$this->assertSame(3, substr_count($rCluster, 'return $rEmpty;'), 'the off switch, the pre-init tables guard and the logged failure each answer with no nodes');

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

	/**
	 * A cluster action's result must reach the operator. SessionManager closed
	 * the session for writing during bootstrap, so index() reopens it and queues
	 * the flash (two overlapping actions each surface once); viewData reopens to
	 * persist the clear so a result shows once, not forever; and servers.php
	 * renders the flash before — and independently of — the clusterEnabled
	 * section, so a result (including an issued enrolment code) shows even when
	 * the cluster API or its tables are unavailable. Node links are made
	 * DataTables-page-aware so they reach a row on another page.
	 */
	public function testClusterActionFlashSurvivesSessionCloseAndShowsWhenDisabled(): void {
		$rCtrl = (string) file_get_contents(MAIN_HOME . 'Public/Controllers/Admin/ClusterNodesController.php');
		$this->assertStringContainsString('session_start()', $rCtrl, 'the flash write reopens the closed session');
		$this->assertStringContainsString('session_write_close()', $rCtrl);
		$this->assertStringContainsString('self::pushFlash(', $rCtrl);
		$this->assertStringContainsString('self::consumeFlashes()', $rCtrl);

		$rServers = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/servers.php');
		$rView = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/partials/cluster_section.php');
		$this->assertStringContainsString('$clusterFlashes', $rServers, 'servers.php renders the flash queue');
		$rFlashAt = strpos($rServers, '$clusterFlashes');
		$rSectionAt = strpos($rServers, "include __DIR__ . '/partials/cluster_section.php'");
		$this->assertIsInt($rFlashAt);
		$this->assertIsInt($rSectionAt);
		$this->assertLessThan($rSectionAt, $rFlashAt, 'the flash renders before the clusterEnabled-gated section');
		$this->assertStringNotContainsString('$clusterFlash', $rView, 'the partial no longer renders the flash (it is skipped when the cluster is off)');

		$rScript = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/partials/cluster_section_script.php');
		$this->assertStringContainsString('rNodesTable.page(', $rScript, 'node links move the DataTable to the target row page');
		$this->assertStringContainsString('a[href^="#node-"]', $rScript);
	}
}
