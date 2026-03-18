<?php

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

		$rCommand = $rArgs[0];

		switch ($rCommand) {
			case 'update':
				if (ServerRepository::getAll()[SERVER_ID]['is_main']) {
					$UpdateData = $gitRelease->getUpdateFile("main", XC_VM_VERSION);
				} else {
					$UpdateData = $gitRelease->getUpdateFile("lb_update", ServerRepository::getAll()[SERVER_ID]['xc_vm_version']);
				}

				if ($UpdateData && 0 < strlen($UpdateData['url'])) {
					$rOutputDir = TMP_PATH . '.update.tar.gz';
					if ($this->downloadFile($UpdateData['url'], $rOutputDir) && md5_file($rOutputDir) === $UpdateData['md5']) {
						$db->query('UPDATE `servers` SET `status` = 5 WHERE `id` = ?;', SERVER_ID);

						$rCmd = 'sudo /usr/bin/python3 ' . MAIN_HOME . 'update "' . $rOutputDir . '" "' . $UpdateData['md5'] . '" > /dev/null 2>&1 &';
						shell_exec($rCmd);
						exit(1);
					} else {
						exit(-1);
					}
				}

				return 0;

			case 'post-update':
				if (ServerRepository::getAll()[SERVER_ID]['is_main']) {
					MigrationRunner::run($db);
				}
				MigrationRunner::runFileCleanup();

				if (ServerRepository::getAll()[SERVER_ID]['is_main'] && SettingsManager::getAll()['auto_update_lbs']) {
					foreach (ServerRepository::getAll() as $rServer) {
						if (($rServer['enabled'] && $rServer['status'] == 1 && time() - $rServer['last_check_ago'] <= 180) || !$rServer['is_main']) {
							$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServer['id'], time(), json_encode(array('action' => 'update')));
						}
					}
				}

				$db->query('UPDATE `servers` SET `status` = 1, `xc_vm_version` = ? WHERE `id` = ?;', XC_VM_VERSION, SERVER_ID);
				$db->query('UPDATE `settings` SET `update_data` = NULL;');

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
