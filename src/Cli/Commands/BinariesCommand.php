<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Updates\GitHubReleases;
use XcVm\Core\Updates\ReleaseAsset;
use XcVm\Core\Updates\UpdateChannels;

/**
 * BinariesCommand — binaries command
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class BinariesCommand implements CommandInterface {
	public function getName(): string {
		return 'binaries';
	}

	public function getDescription(): string {
		return 'Update binaries';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] != 'root') {
			echo "Please run as root!\n";
			return 1;
		}

		register_shutdown_function(function () {
			global $db;
			if (is_object($db)) {
				$db->close_mysql();
			}
		});

		// Check if apparmor_status command exists
		if (shell_exec('which apparmor_status')) {
			exec('sudo apparmor_status', $rAppArmor);

			if (strtolower(trim($rAppArmor[0])) == 'apparmor module is loaded.') {
				exec('sudo systemctl is-active apparmor', $rStatus);

				if (strtolower(trim($rStatus[0])) == 'active') {
					echo 'AppArmor is loaded! Disabling...' . "\n";
					shell_exec('sudo systemctl stop apparmor');
					shell_exec('sudo systemctl disable apparmor');
				}
			}
		}

		$rVersionFile = BIN_PATH . 'bin_version.json';
		$rCurrentVersion = $this->getInstalledBinaryVersion($rVersionFile);

		$rChannel = UpdateChannels::bin();

		try {
			$gitRelease = new GitHubReleases(GIT_OWNER, GIT_REPO_BIN, $rChannel);
			$gitRelease->setTimeout(20);
			$rReleases = $gitRelease->getReleases();
		} catch (\Exception $e) {
			echo 'Failed to check binaries releases: ' . $e->getMessage() . "\n";
			return 1;
		}

		if (empty($rReleases[0])) {
			echo "Failed to resolve latest binaries release.\n";
			return 1;
		}

		$rLatestVersion = trim($rReleases[0]);

		if (!empty($rCurrentVersion) && $rCurrentVersion === $rLatestVersion) {
			echo 'Binaries are up to date (' . $rCurrentVersion . '). Skipping update.' . "\n";
			return 0;
		}

		if (empty($rCurrentVersion)) {
			echo 'Current binaries version is unknown (missing/invalid ' . $rVersionFile . '). Update required.' . "\n";
		} else {
			echo 'New binaries release available: installed=' . $rCurrentVersion . ', latest=' . $rLatestVersion . ".\n";
		}

		// The release must have this distribution's bundle before anything
		// stops: the updater a panel already runs (bin/install is never
		// refreshed by a panel update) stops the service first, and a release
		// published without its assets then took the panel down every minute.
		$rOs = @parse_ini_file('/etc/os-release') ?: [];
		$rAsset = ReleaseAsset::bundleFor(strtolower((string) ($rOs['ID'] ?? '')), (string) ($rOs['VERSION_ID'] ?? ''));
		if ($rAsset === null || !ReleaseAsset::exists(ReleaseAsset::baseUrl(GIT_OWNER, GIT_REPO_BIN, $rLatestVersion) . $rAsset)) {
			echo 'Release ' . $rLatestVersion . ' has no ' . ($rAsset ?? 'bundle for this distribution') . ': the running binaries are kept.' . "\n";
			return 0;
		}

		$rScript = MAIN_HOME . 'bin/install/update_binaries.sh';
		if (!file_exists($rScript)) {
			echo 'Updater script not found: ' . $rScript . "\n";
			return 1;
		}

		// Then xcvm_core, which is kept current on its own: the bundle's PHP can
		// bring an older one (no cluster API), and a panel update never refreshes
		// bin/install, so the script's own guard is not on every panel.
		$rCommand = '/bin/bash ' . escapeshellarg($rScript) . ' ' . escapeshellarg(GIT_OWNER) . ' ' . escapeshellarg(GIT_REPO_BIN) . ' ' . escapeshellarg(BIN_PATH) . ' ' . escapeshellarg($rLatestVersion)
			. '; ' . escapeshellarg(PHP_BIN) . ' ' . escapeshellarg(MAIN_HOME . 'console.php') . ' xcvm_core'
			// The script hands the whole tree to xc_vm when it ends: what root runs is taken back.
			. '; ' . escapeshellarg(PHP_BIN) . ' ' . escapeshellarg(MAIN_HOME . 'console.php') . ' root:own';
		$rLogDir = MAIN_HOME . 'tmp/';
		if (!is_dir($rLogDir)) {
			@mkdir($rLogDir, 0755, true);
		}

		$rLogFile = $rLogDir . 'binaries_update_' . date('Ymd_His') . '.log';
		$rDetachedCommand = 'nohup sh -c ' . escapeshellarg($rCommand) . ' > ' . escapeshellarg($rLogFile) . ' 2>&1 < /dev/null & echo $!';
		echo "Starting binaries updater in background...\n";
		$rPid = trim((string) shell_exec($rDetachedCommand));

		if (empty($rPid)) {
			echo "Failed to start binaries updater in background.\n";
			return 1;
		}

		echo 'Binaries updater started (PID: ' . $rPid . ').' . "\n";
		echo 'Log file: ' . $rLogFile . "\n";

		return 0;
	}

	private function getInstalledBinaryVersion(string $rVersionFile): ?string {
		if (!file_exists($rVersionFile)) {
			return null;
		}

		$rContent = file_get_contents($rVersionFile);
		if ($rContent === false) {
			return null;
		}

		$rData = json_decode($rContent, true);
		if (!is_array($rData) || empty($rData['release']) || !is_string($rData['release'])) {
			return null;
		}

		$rVersion = trim($rData['release']);
		return strlen($rVersion) ? $rVersion : null;
	}
}
