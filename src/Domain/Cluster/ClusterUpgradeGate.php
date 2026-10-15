<?php

namespace XcVm\Domain\Cluster;

use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Refuses the update that removes the legacy MAIN-LB API while any load
 * balancer still runs below cluster mode 2: it could not follow the update.
 * A load balancer is a `servers` row that is neither MAIN nor a proxy; one
 * with no enrolled `cluster_nodes` row (never enrolled) counts as below mode 2.
 */
final class ClusterUpgradeGate {
	use DatabaseAware;

	/** First release without the legacy API (placeholder until the tag is chosen). */
	public const MAJOR = '2.7.0';

	/** Null: the update may proceed. Else a reason naming the load balancers to fix. */
	public static function blocksUpgradeTo(string $targetVersion, string $majorBoundary = self::MAJOR): ?string {
		// Base triple only: version_compare ranks 2.7.0-dev.1 below 2.7.0, which would let a pre-release of the major through.
		if (version_compare((string) preg_replace('/[-+].*$/', '', $targetVersion), $majorBoundary, '<')) {
			return null;
		}
		$rNodes = NodeRegistry::enrolled();
		self::db()->query('SELECT `id`, `server_name` FROM `servers` WHERE `is_main` = 0 AND `server_type` = 0 ORDER BY `id`;');
		$rBelow = [];
		foreach (self::db()->get_rows() ?: [] as $rServer) {
			if ((int) ($rNodes[(int) $rServer['id']]['mode'] ?? 0) < 2) {
				$rBelow[] = ($rServer['server_name'] ?? '') . ' (#' . $rServer['id'] . ')';
			}
		}
		if (!$rBelow) {
			return null;
		}
		return "Update to {$targetVersion} refused: load balancer(s) below cluster mode 2: " . implode(', ', $rBelow)
			. ". Migrate each to mode 2 first — Servers -> the node -> Guided cutover (to mode 2), or"
			. " `console.php cluster:cutover <id> --to-mode-2` — or remove it, then update again.";
	}
}
