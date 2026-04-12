<?php

/**
 * UpdateCommand — update command
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class UpdateCommand implements CommandInterface {

	public function getName(): string {
		return 'update';
	}

	public function getDescription(): string {
		return 'System update (update / post-update)';
	}

	public function execute(array $rArgs): int {
		set_time_limit(0);

		if (empty($rArgs[0])) {
			return 0;
		}

		register_shutdown_function(function () {
			global $db;
			if (is_object($db)) {
				$db->close_mysql();
			}
		});

		global $db;
		$gitRelease = new GitHubReleases(GIT_OWNER, GIT_REPO_MAIN, SettingsManager::getAll()['update_channel']);
		$gitRelease->setTimeout(30);

		$rCommand = $rArgs[0];

		switch ($rCommand) {
			case 'update':
				UpdateLogger::reset();
				$rIsMain = ServerRepository::getAll()[SERVER_ID]['is_main'];
				$rServerType = $rIsMain ? 'MAIN' : 'LB';
				echo "Checking for updates (server={$rServerType}, version=" . XC_VM_VERSION . ")...\n";
				UpdateLogger::info('Update started; server=' . $rServerType . ', current version=' . XC_VM_VERSION);

				$rLatest = $gitRelease->getLatestVersion(
					$rIsMain ? XC_VM_VERSION : ServerRepository::getAll()[SERVER_ID]['xc_vm_version']
				);

				if ($rLatest === null) {
					echo "Already up to date.\n";
					UpdateLogger::info('Already up to date, no action needed');
					return 0;
				}

				echo "New version available: {$rLatest}\n";
				UpdateLogger::info('New version found: ' . $rLatest);

				if ($rIsMain) {
					$UpdateData = $gitRelease->getUpdateFile("main", XC_VM_VERSION);
				} else {
					$UpdateData = $gitRelease->getUpdateFile("lb_update", ServerRepository::getAll()[SERVER_ID]['xc_vm_version']);
				}

				if (!$UpdateData || empty($UpdateData['url'])) {
					echo "ERROR: Failed to get update file URL.\n";
					UpdateLogger::error('Failed to get update file URL');
					return 1;
				}

				if (empty($UpdateData['md5'])) {
					$rAssetName = $rIsMain ? 'xc_vm.tar.gz' : 'loadbalancer.tar.gz';
					$rHashUrl = "https://github.com/" . GIT_OWNER . "/" . GIT_REPO_MAIN . "/releases/download/{$rLatest}/hashes.md5";
					echo "WARNING: Could not fetch MD5 hash. Retrying...\n";
					echo "  Hash URL: {$rHashUrl}\n";
					UpdateLogger::info('MD5 hash fetch failed for ' . $rAssetName . ', retrying...');
					$UpdateData['md5'] = $gitRelease->getAssetHash($rLatest, $rAssetName);
					if (empty($UpdateData['md5'])) {
						echo "ERROR: Failed to get MD5 hash after retry.\n";
						echo "  Check if hashes.md5 exists in release {$rLatest} on GitHub.\n";
						UpdateLogger::error('Failed to get MD5 hash for version ' . $rLatest);
						return 1;
					}
				}

				echo "Downloading update...\n";
				UpdateLogger::info('Downloading update file...');
				$rOutputDir = TMP_PATH . '.update.tar.gz';
				$rDownloaded = $this->downloadFile($UpdateData['url'], $rOutputDir);

				if (!$rDownloaded) {
					echo "ERROR: Download failed.\n";
					UpdateLogger::error('Download failed from: ' . $UpdateData['url']);
					return 1;
				}

				$rFileMd5 = md5_file($rOutputDir);
				if ($rFileMd5 !== $UpdateData['md5']) {
					echo "ERROR: MD5 checksum mismatch.\n";
					UpdateLogger::error('MD5 mismatch: expected=' . $UpdateData['md5'] . ', got=' . $rFileMd5);
					@unlink($rOutputDir);
					return 1;
				}

				echo "Download OK, MD5 verified (" . filesize($rOutputDir) . " bytes).\n";
				UpdateLogger::info('Download OK, MD5 verified, size=' . filesize($rOutputDir) . ' bytes');

				$db->query('UPDATE `servers` SET `status` = 5 WHERE `id` = ?;', SERVER_ID);
				UpdateLogger::info('Server status set to 5 (updating), launching system update...');

				echo "Launching system update...\n";
				$rLogFile = UpdateLogger::getLogFile();
				$rCmd = 'sudo /usr/bin/python3 ' . MAIN_HOME . 'update '
					. escapeshellarg($rOutputDir) . ' '
					. escapeshellarg($UpdateData['md5'])
					. ' >> ' . escapeshellarg($rLogFile) . ' 2>&1 &';
				shell_exec($rCmd);
				exit(1);

			case 'post-update':
				UpdateLogger::info('Post-update started');

				if (ServerRepository::getAll()[SERVER_ID]['is_main']) {
					UpdateLogger::info('Running database migrations...');
					MigrationRunner::run($db);
				}
				UpdateLogger::info('Running file cleanup...');
				MigrationRunner::runFileCleanup();

				if (ServerRepository::getAll()[SERVER_ID]['is_main'] && SettingsManager::getAll()['auto_update_lbs']) {
					UpdateLogger::info('Broadcasting update signal to LB servers');
					foreach (ServerRepository::getAll() as $rServer) {
						if (($rServer['enabled'] && $rServer['status'] == 1 && time() - $rServer['last_check_ago'] <= 180) || !$rServer['is_main']) {
							$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServer['id'], time(), json_encode(array('action' => 'update')));
						}
					}
				}

				$db->query('UPDATE `servers` SET `status` = 1, `xc_vm_version` = ? WHERE `id` = ?;', XC_VM_VERSION, SERVER_ID);
				$db->query('UPDATE `settings` SET `update_data` = NULL;');
				UpdateLogger::info('Server status set to 1 (online), version=' . XC_VM_VERSION);

				foreach (array('http', 'https') as $rType) {
					$rPortConfig = file_get_contents(MAIN_HOME . 'bin/nginx/conf/ports/' . $rType . '.conf');
					if (stripos($rPortConfig, ' reuseport') !== false) {
						file_put_contents(MAIN_HOME . 'bin/nginx/conf/ports/' . $rType . '.conf', str_replace(' reuseport', '', $rPortConfig));
					}
				}

				exec('sudo chown -R xc_vm:xc_vm ' . MAIN_HOME);
				exec('sudo systemctl daemon-reload');
				exec("sudo echo 'net.ipv4.ip_unprivileged_port_start=0' > /etc/sysctl.d/50-allports-nonroot.conf && sudo sysctl --system");
				exec('sudo ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php status');
				UpdateLogger::info('Post-update completed successfully');
				break;
		}

		return 0;
	}

	private function downloadFile($url, $targetPath): bool {
		$rData = @fopen($url, 'rb');
		if (!$rData) return false;
		$rOutput = fopen($targetPath, 'wb');
		stream_copy_to_stream($rData, $rOutput);
		fclose($rData);
		fclose($rOutput);
		return true;
	}
}
