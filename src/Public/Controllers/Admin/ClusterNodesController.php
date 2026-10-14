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
 * ClusterNodesController — the cluster API's node actions, now folded into the
 * Manage Servers page (admin/servers.php). A POST still carries out one cluster
 * action here (session cookies are SameSite=Strict, so no AJAX), stores its
 * result as a session flash, then sends the admin to the servers list; a GET is
 * a plain redirect there, so old links and bookmarks keep working. The page
 * content it used to render now lives on Manage Servers via self::viewData().
 *
 * GET|POST /cluster_nodes · permission: servers
 *
 * @package XC_VM_Public_Controllers_Admin
 */
class ClusterNodesController extends BaseAdminController {
	public function index(): void {
		$this->requirePermission();

		if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
			$rSettings = SettingsManager::getAll();
			$rEnabled = !empty($rSettings['cluster_api_enabled']);
			$rAvailable = ClusterCryptoFactory::available();
			$rFlash = null;

			if (!$rEnabled || !$rAvailable) {
				$rFlash = ['type' => 'danger', 'message' => 'cluster_api_off'];
			} else {
				$rServers = ServerRepository::getAll(true);
				$rFlash = ClusterAdmin::act(ClusterCryptoFactory::create(), [
					'cluster_action' => $this->input('cluster_action', ''),
					'server_id' => $this->input('server_id', 0),
					'sas' => $this->input('sas', ''),
					'url' => $this->input('url', ''),
					'mode' => $this->input('mode', null),
				], $rServers, (int) SERVER_ID, $rSettings, isset($GLOBALS['rUserInfo']['id']) ? (int) $GLOBALS['rUserInfo']['id'] : null);
			}

			if ($rFlash !== null) {
				$_SESSION['cluster_flash'] = $rFlash;
			}
		}

		$this->redirect('servers');
	}

	/**
	 * The cluster section of the Manage Servers page: enrolled nodes with
	 * liveness, pending code enrolments, the MAIN data plane, metrics and audit.
	 * Returns the same empty/safe shape as before when the cluster API is off or
	 * unavailable, so the page simply renders no cluster UI. Consumes any pending
	 * session flash left by a cluster action (index()).
	 *
	 * @param array<int, array<string, mixed>> $rServers
	 * @param array<string, mixed> $rSettings
	 * @return array<string, mixed>
	 */
	public static function viewData(array $rServers, array $rSettings): array {
		$rFlash = $_SESSION['cluster_flash'] ?? null;
		unset($_SESSION['cluster_flash']);

		// The servers list is a page an operator opens even before cluster:init, so
		// the cluster section must cost nothing when the API is off (or its tables
		// are not there yet): answer with the empty shape — no section — rather than
		// throw, exactly as the former per-row badge query did.
		$rNames = array_map(static fn(array $rServer): string => (string) ($rServer['server_name'] ?? ''), $rServers);
		$rEmpty = [
			'clusterEnabled' => false,
			'clusterNodes' => [],
			'clusterFences' => [],
			'clusterDigestN1' => null,
			'clusterMainDataPlane' => false,
			'clusterBinding' => null,
			'clusterBanners' => [],
			'clusterMetrics' => null,
			'clusterNames' => $rNames,
			'clusterPending' => [],
			'clusterLbs' => [],
			'clusterFlash' => $rFlash,
			'clusterPanelFp' => '',
		];

		if (empty($rSettings['cluster_api_enabled']) || !ClusterCryptoFactory::available()) {
			return $rEmpty;
		}

		try {
			$rNodes = ClusterAdmin::nodes($rServers, self::offlineAfter($rSettings));
			$rNow = time();

			return [
				'clusterEnabled' => true,
				'clusterNodes' => $rNodes,
				'clusterFences' => ClusterOverview::fenceWindows($rNodes, $rSettings),
				'clusterDigestN1' => ClusterOverview::digestN1($rNodes),
				'clusterMainDataPlane' => !empty(MainDataPlane::identity()['on']),
				'clusterBinding' => ClusterOverview::binding($rNodes, $rSettings),
				'clusterBanners' => ClusterOverview::banners(ClusterOverview::licensed(true), $rServers[(int) SERVER_ID] ?? [], $rSettings, $rNodes, $rNow),
				'clusterMetrics' => self::metrics($rSettings, $rNow),
				'clusterNames' => $rNames,
				'clusterPending' => ClusterAdmin::pending($rServers),
				'clusterLbs' => ClusterAdmin::loadBalancers($rServers),
				'clusterFlash' => $rFlash,
				'clusterPanelFp' => ClusterMeta::get('panel_fp') ?? '',
			];
		} catch (\Throwable) {
			return $rEmpty;
		}
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
