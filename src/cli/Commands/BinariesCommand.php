<?php

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
		return 'Update binaries and GeoLite from GitHub';
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

		$rBaseDir = '/home/xc_vm/bin/';
		$geolitejsonFile = '/home/xc_vm/bin/maxmind/version.json';

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

		$rUpdated = false;
		$repo = new GitHubReleases(GIT_OWNER, GIT_REPO_UPDATE, SettingsManager::getAll()['update_channel']);

		$datageolite = $repo->getGeolite();
		if (is_array($datageolite)) {
			foreach ($datageolite['files'] as $rFile) {
				if (!file_exists($rFile['path']) || md5_file($rFile['path']) != $rFile['md5']) {
					$rFolderPath = pathinfo($rFile['path'])['dirname'] . '/';

					if (!file_exists($rFolderPath)) {
						shell_exec('sudo mkdir -p "' . $rFolderPath . '"');
					}

					$ch = curl_init();
					curl_setopt($ch, CURLOPT_URL, $rFile['fileurl']);
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
					curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
					curl_setopt($ch, CURLOPT_TIMEOUT, 300);
					curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
					curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
					$rData = curl_exec($ch);
					$rMD5 = md5($rData);

					if ($rFile['md5'] == $rMD5) {
						echo 'Updated binary: ' . $rFile['path'] . "\n";
						file_put_contents($rFile['path'], $rData);

						shell_exec('sudo chown xc_vm:xc_vm "' . $rFile['path'] . '"');
						shell_exec('sudo chmod ' . $rFile["permission"] . ' "' . $rFile['path'] . '"');
						$rUpdated = true;
					}
				}
			}

			$jsonData = file_get_contents($geolitejsonFile);
			$data = json_decode($jsonData, true);

			if (isset($data['geolite2_version'])) {
				$data['geolite2_version'] = $datageolite["version"];
				file_put_contents($geolitejsonFile, json_encode($data, JSON_PRETTY_PRINT));
			}
		}

		if ($rUpdated) {
			shell_exec('sudo chown -R xc_vm:xc_vm "' . $rBaseDir . '"');
		}

		return 0;
	}
}
