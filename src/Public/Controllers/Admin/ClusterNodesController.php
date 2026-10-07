<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Domain\Cluster\ClusterOverview;
use XcVm\Domain\Cluster\MainDataPlane;
use XcVm\Domain\Server\ServerRepository;

/**
 * ClusterNodesController — the cluster API's nodes (admin/cluster_nodes.php):
 * enrolled nodes with liveness, pending code enrolments approved by SAS,
 * enrolment codes, and revocation. Actions POST back to the page (session
 * cookies are SameSite=Strict); the page renders their result.
 *
 * GET|POST /cluster_nodes · permission: servers
 *
 * @renders Views/admin/cluster_nodes.php
 *
 * @package XC_VM_Public_Controllers_Admin
 */
class ClusterNodesController extends BaseAdminController {
	public function index(): void {
		$this->requirePermission();
		$this->setTitle('Cluster Nodes');

		$rSettings = SettingsManager::getAll();
		$rServers = ServerRepository::getAll(true);
		$rEnabled = !empty($rSettings['cluster_api_enabled']);
		$rAvailable = ClusterCryptoFactory::available();
		$rFlash = null;

		if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
			if (!$rEnabled || !$rAvailable) {
				$rFlash = ['type' => 'danger', 'message' => 'cluster_api_off'];
			} else {
				$rFlash = ClusterAdmin::act(ClusterCryptoFactory::create(), [
					'cluster_action' => $this->input('cluster_action', ''),
					'server_id' => $this->input('server_id', 0),
					'sas' => $this->input('sas', ''),
					'url' => $this->input('url', ''),
					'mode' => $this->input('mode', null),
				], $rServers, (int) SERVER_ID, $rSettings, isset($GLOBALS['rUserInfo']['id']) ? (int) $GLOBALS['rUserInfo']['id'] : null);
			}
		}

		$rNodes = ClusterAdmin::nodes($rServers, self::offlineAfter($rSettings));
		$rNow = time();
		$rMainDataPlane = $rEnabled && $rAvailable && !empty(MainDataPlane::identity()['on']);
		$this->render('cluster_nodes', [
			'clusterEnabled' => $rEnabled && $rAvailable,
			'clusterNodes' => $rNodes,
			'clusterFences' => ClusterOverview::fenceWindows($rNodes, $rSettings),
			'clusterDigestN1' => ClusterOverview::digestN1($rNodes),
			'clusterMainDataPlane' => $rMainDataPlane,
			'clusterLegacyApiOpenBy' => ClusterOverview::legacyApiOpenBy($rServers, $rNodes, (int) SERVER_ID, $rMainDataPlane),
			'clusterBinding' => $rEnabled && $rAvailable ? ClusterOverview::binding($rNodes, $rSettings) : null,
			'clusterBanners' => ClusterOverview::banners(ClusterOverview::licensed($rEnabled && $rAvailable), $rServers[(int) SERVER_ID] ?? [], $rSettings, $rNodes, $rNow),
			'clusterMetrics' => $rEnabled ? self::metrics($rSettings, $rNow) : null,
			'clusterNames' => array_map(static fn(array $rServer): string => (string) ($rServer['server_name'] ?? ''), $rServers),
			'clusterPending' => ClusterAdmin::pending($rServers),
			'clusterLbs' => ClusterAdmin::loadBalancers($rServers),
			'clusterFlash' => $rFlash,
			'clusterPanelFp' => $rEnabled && $rAvailable ? ClusterMeta::get('panel_fp') ?? '' : '',
		]);
	}

	/**
	 * The page's figures (ClusterOverview); null when the cluster tables are
	 * not there yet.
	 *
	 * @param array<string, mixed> $rSettings
	 * @return array{commands: array<string, mixed>, saturation: array<string, mixed>, audit: list<array<string, mixed>>}|null
	 */
	private static function metrics(array $rSettings, int $rNow): ?array {
		try {
			return [
				'commands' => ClusterOverview::commandMetrics($rNow),
				'saturation' => ClusterOverview::saturation($rSettings, $rNow * 1000),
				'audit' => ClusterOverview::audit(),
			];
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Seconds without a heartbeat before a node shows offline: the setting,
	 * 30 when it is unset or 0, kept within 10–300 — as the servers list, the
	 * dashboard, the liveness tick and the endpoint's port check read it.
	 *
	 * @param array<string, mixed> $rSettings
	 */
	public static function offlineAfter(array $rSettings): int {
		return ClusterSettings::int('cluster_offline_after_sec', $rSettings['cluster_offline_after_sec'] ?? null);
	}
}
