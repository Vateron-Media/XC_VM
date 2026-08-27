<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Proxy\ProxyArchiveUpdater;
use XcVm\Core\Updates\GitHubReleases;

/**
 * ProxyArchiveCronJob — keep the local proxy.tar.gz fresh from XC_VM_Proxy releases.
 *
 * Mirrors MaxMindCronJob: the archive is not shipped in the panel tree, it is
 * fetched at install time and refreshed here so every proxy-node install runs from
 * a current local cache (and keeps working offline). Dispatched from the `crontab`
 * table (filename 'proxy') via the xc_vm user crontab — runs as xc_vm, which owns
 * bin/install/, so no sudo/chown is needed.
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ProxyArchiveCronJob implements CommandInterface {
	use CronTrait;

	public function getName(): string {
		return 'cron:proxy';
	}

	public function getDescription(): string {
		return 'Cron: refresh the proxy node archive from XC_VM_Proxy releases';
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}

		echo "Proxy archive\n------------------------------\n";

		global $db;
		register_shutdown_function(function () use ($db) {
			if (is_object($db)) {
				$db->close_mysql();
			}
		});

		$rSettings   = SettingsManager::getAll();
		$rForceLocal = !empty($rSettings['proxy_force_local']);
		$rForce      = in_array('--force', $rArgs, true);

		$rRepo = new GitHubReleases(GIT_OWNER, GIT_REPO_PROXY, $rSettings['update_channel']);
		if ($rForce) {
			// Drop the 30-min releases cache so a just-published release is seen now.
			$rRepo->clearCache();
		}
		$rResult = (new ProxyArchiveUpdater($rRepo))->ensure($rForce, $rForceLocal);

		switch ($rResult['action']) {
			case 'download':
				echo '[OK]    proxy.tar.gz: updated to ' . $rResult['version'] . "\n";
				return 0;
			case 'skip':
				echo '[SKIP]  proxy.tar.gz: already up to date (' . $rResult['version'] . ")\n";
				return 0;
			case 'local':
				echo '[INFO]  proxy.tar.gz: ' . ($rResult['error'] ?? 'using existing archive') . "\n";
				return 0;
			case 'stale-fallback':
				echo '[WARN]  proxy.tar.gz: ' . ($rResult['error'] ?? 'using local copy') . "\n";
				return 0;
			default:
				echo '[ERROR] proxy.tar.gz: ' . ($rResult['error'] ?? 'update failed') . "\n";
				return 1;
		}
	}
}
