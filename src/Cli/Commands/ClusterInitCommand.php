<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\CredentialFreeConfig;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterCli;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * ClusterInitCommand — create MAIN's cluster root in `xcvm_core` (idempotent)
 * and record the panel keys in `cluster_meta`.
 *
 * Enabling the cluster API in Settings does the same from php-fpm; this is the
 * CLI path (install, recovery). The extension hands files root writes to the
 * owner of its config directory. MAIN only: the LB build strips it.
 *
 * With `--enable` (the installer: a new panel starts on the cluster API) it
 * then switches the cluster API on, as Settings does once the root exists
 * (enable()).
 *
 * Usage: `console.php cluster:init [--enable]`
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterInitCommand implements CommandInterface {
	use DatabaseAware;

	public function getName(): string {
		return 'cluster:init';
	}

	public function getDescription(): string {
		return 'Create the cluster API root (xcvm_core) and record the panel keys; --enable also switches the cluster API on';
	}

	public function execute(array $rArgs): int {
		$rCrypto = ClusterCli::crypto(ClusterCli::UNAVAILABLE);
		if ($rCrypto === null) {
			return 1;
		}
		try {
			$rOut = ClusterMeta::init($rCrypto);
		} catch (\Throwable $rE) {
			echo 'cluster:init failed: ' . $rE->getMessage() . "\n";
			return 1;
		}
		echo ($rOut['created'] ? 'Created' : 'Already initialised') . '; panel fingerprint ' . $rOut['panel_fp'] . "\n";
		if (!in_array('--enable', $rArgs, true)) {
			return 0;
		}
		$rMode = self::enable();
		if ($rMode === null) {
			echo "Could not switch the cluster API on: the settings table did not answer.\n";
			return 1;
		}
		echo 'Cluster API on; new load balancers join in mode ' . $rMode . ".\n";
		return 0;
	}

	/**
	 * Switch the cluster API on, once its root exists: new load balancers
	 * then enrol at their install. They join in mode 2 (`lb_new_node_mode =
	 * api`) when the extension packs a credential-free config and the Redis
	 * connection handler is off, the conditions Settings asks
	 * (ClusterSettings::normalize); else in mode 1. A mode already set stays.
	 * Returns the mode new load balancers join in, or null when the settings
	 * table did not answer.
	 */
	public static function enable(): ?int {
		$db = self::db();
		if (!$db->query('SELECT `redis_handler`, `lb_new_node_mode` FROM `settings` LIMIT 1;')) {
			return null;
		}
		$rRow = $db->get_row() ?: [];
		$rApi = ($rRow['lb_new_node_mode'] ?? '') === 'api' || (empty($rRow['redis_handler']) && CredentialFreeConfig::supported());
		if (!$db->query('UPDATE `settings` SET `cluster_api_enabled` = 1, `lb_new_node_mode` = ?;', $rApi ? 'api' : ((string) ($rRow['lb_new_node_mode'] ?? '') ?: 'legacy'))) {
			return null;
		}
		if (defined('CACHE_TMP_PATH')) {
			SettingsManager::clearCache();
		}
		return $rApi ? 2 : 1;
	}
}
