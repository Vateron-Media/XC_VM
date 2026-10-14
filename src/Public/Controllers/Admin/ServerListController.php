<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Server\ServerRepository;

/**
 * ServerListController — the server list (admin/servers.php), now the single
 * place that also shows and drives the cluster: each enrolled load balancer's
 * node detail hangs off its row, and the cluster-wide cards (pending enrolments,
 * the MAIN data plane, metrics and audit) sit below the table. The cluster data
 * comes from ClusterNodesController::viewData(); its action POSTs still go to
 * cluster_nodes, which performs them and redirects back here.
 *
 * GET /servers · permission: servers
 *
 * @renders Views/admin/servers.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ServerListController extends BaseAdminController {
	public function index(): void {
		$this->requirePermission();
		$this->setTitle('Servers');

		$rServers = ServerRepository::getAll(true);
		$rCluster = ClusterNodesController::viewData($rServers, SettingsManager::getAll());

		// Nodes keyed by server id, for each row's badge and its expandable cluster panel.
		$rNodesByServer = [];
		foreach ($rCluster['clusterNodes'] as $rNode) {
			$rNodesByServer[(int) ($rNode['server_id'] ?? 0)] = $rNode;
		}

		$this->render('servers', [
			'rServers' => $rServers,
			'rClusterNodes' => $rNodesByServer,
			'rClusterEnabled' => $rCluster['clusterEnabled'],
		] + $rCluster);
	}
}
