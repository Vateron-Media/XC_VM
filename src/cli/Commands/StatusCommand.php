<?php

/**
 * Проверка статуса, DB-миграции, конфигурация системы.
 *
 * Команда: status [first-run]
 * Требует: root
 *
 * Заменяет src/status. При первом запуске (аргумент "first-run")
 * пропускает вывод статуса и сразу выполняет миграции/конфигурацию.
 */
class StatusCommand implements CommandInterface {

	public function getName(): string {
		return 'status';
	}

	public function getDescription(): string {
		return 'XC_VM status, DB migrations, system configuration';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] !== 'root') {
			echo "Please run as root!\n";
			return 1;
		}

		set_time_limit(0);

		$rFirstRun = isset($rArgs[0]);
		$rReload = false;

		require_once MAIN_HOME . 'www/stream/init.php';
		ini_set('display_startup_errors', 1);
		ini_set('display_errors', 1);
		error_reporting(E_ALL);

		if (!$rFirstRun) {
			echo "\nStatus\n------------------------------\n";
			if ($this->isRunning()) {
				echo "XC_VM is running.\n\n";
			} else {
				echo "XC_VM is not running.\n\n";
			}
		} else {
			echo "\n";
		}

		echo "Database\n------------------------------\n";

		global $db;
		DatabaseFactory::connect();

		if (!$db->connected) {
			echo "Couldn't connect to database. Please add them to config.ini.\n\n";
			return 1;
		}

		echo "Connected successfully.\n\n";
		$rServers = $this->getServers();

		if ($rServers[SERVER_ID]['is_main']) {
			MigrationRunner::run($db);
			shell_exec('sudo chmod 0775 ' . MAIN_HOME . 'bin/install');
		}

		$this->fixPermissions();
		$rReload = $this->fixNginxConfig($rReload);

		if ($rReload) {
			exec('sudo -u xc_vm ' . MAIN_HOME . 'bin/nginx/sbin/nginx -s reload');
			exec('sudo service xc_vm restart');
		}

		$this->installRootCrontab();
		$this->configureFileLimits();
		$this->removeInitScript();

		if ($rServers[SERVER_ID]['is_main']) {
			$this->broadcastUpdateBinaries($db, $rServers);
			$this->configureRedis($db, $rServers);
		}

		if (!$rFirstRun && $rServers[SERVER_ID]['is_main']) {
			$this->printStatusReport($db, $rServers);
		}

		$db->query('UPDATE `servers` SET `xc_vm_version` = ? WHERE `id` = ?;', XC_VM_VERSION, SERVER_ID);

		return 0;
	}

	private function isRunning(): bool {
		$rNginx = 0;
		exec('ps -fp $(pgrep -u xc_vm 2>/dev/null) 2>/dev/null', $rOutput);

		foreach ($rOutput as $rProcess) {
			$rSplit = explode(' ', preg_replace('!\\s+!', ' ', trim($rProcess)));
			if (isset($rSplit[8], $rSplit[9]) && $rSplit[8] === 'nginx:' && $rSplit[9] === 'master') {
				$rNginx++;
			}
		}

		return $rNginx > 0;
	}

	private function getServers(): array {
		global $db;
		$db->query('SELECT * FROM `servers`');
		$rServers = array();
		$rOnlineStatus = array(1);

		foreach ($db->get_rows() as $rRow) {
			if (empty($rRow['domain_name'])) {
				$rURL = escapeshellcmd($rRow['server_ip']);
			} else {
				$rURL = str_replace(array('http://', '/', 'https://'), '', escapeshellcmd(explode(',', $rRow['domain_name'])[0]));
			}

			$rProtocol = ($rRow['enable_https'] == 1) ? 'https' : 'http';
			$rPort = ($rProtocol === 'http') ? intval($rRow['http_broadcast_port']) : intval($rRow['https_broadcast_port']);
			$rRow['site_url'] = $rProtocol . '://' . $rURL . ':' . $rPort . '/';
			$rLastCheckTime = ($rRow['server_type'] == 1) ? 180 : 90;
			$rRow['server_online'] = ($rRow['enabled'] && in_array($rRow['status'], $rOnlineStatus) && time() - $rRow['last_check_ago'] <= $rLastCheckTime) || SERVER_ID == $rRow['id'];
			$rServers[intval($rRow['id'])] = $rRow;
		}

		return $rServers;
	}



	private function fixPermissions(): void {
		shell_exec('sudo chmod 0660 ' . MAIN_HOME . 'bin/php/sockets/*');
		shell_exec('sudo chmod 0771 ' . MAIN_HOME . 'bin/daemons.sh');
		shell_exec('sudo chmod 0775 ' . MAIN_HOME . 'bin/certbot');
		shell_exec('sudo chown -R xc_vm:xc_vm ' . MAIN_HOME . 'config');
		shell_exec("sudo echo 'net.ipv4.ip_unprivileged_port_start=0' > /etc/sysctl.d/50-allports-nonroot.conf && sudo sysctl --system 2> /dev/null");
	}

	private function fixNginxConfig(bool $rReload): bool {
		$rSSLConfig = file_get_contents(MAIN_HOME . 'bin/nginx/conf/ssl.conf');

		if (stripos($rSSLConfig, "\nssl_stapling on;\nssl_stapling_verify on;") !== false || stripos($rSSLConfig, "\r\nssl_stapling on;\r\nssl_stapling_verify on;") !== false) {
			file_put_contents(MAIN_HOME . 'bin/nginx/conf/ssl.conf', str_replace("\r\nssl_stapling on;\r\nssl_stapling_verify on;", '', str_replace("\nssl_stapling on;\nssl_stapling_verify on;", '', $rSSLConfig)));
			$rReload = true;
		}

		foreach (array('http', 'https') as $rType) {
			$rPortConfig = file_get_contents(MAIN_HOME . 'bin/nginx/conf/ports/' . $rType . '.conf');

			if (stripos($rPortConfig, ' reuseport') !== false) {
				file_put_contents(MAIN_HOME . 'bin/nginx/conf/ports/' . $rType . '.conf', str_replace(' reuseport', '', $rPortConfig));
				$rReload = true;
			}
		}

		return $rReload;
	}

	private function installRootCrontab(): void {
		$rCrons = array();

		$rCrons[] = '* * * * * ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:root_signals # XC_VM';
		if (file_exists(MAIN_HOME . 'cli/CronJobs/RootMysqlCronJob.php')) {
			$rCrons[] = '* * * * * ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:root_mysql # XC_VM';
		}

		$rWrite = false;
		$rOutput = array();
		exec('sudo crontab -l', $rOutput);

		foreach ($rCrons as $rCron) {
			if (!in_array($rCron, $rOutput)) {
				$rOutput[] = $rCron;
				$rWrite = true;
			}
		}

		if ($rWrite) {
			$rCronFile = tempnam(TMP_PATH, 'crontab');
			file_put_contents($rCronFile, implode("\n", $rOutput) . "\n");
			exec('sudo chattr -i /var/spool/cron/crontabs/root');
			exec('sudo crontab -r');
			exec('sudo crontab ' . $rCronFile);
			exec('sudo chattr +i /var/spool/cron/crontabs/root');
			unlink($rCronFile);
			echo "Root crontab installed.\n\n";
		}
	}

	private function configureFileLimits(): void {
		$rFile = file('/etc/systemd/system.conf');
		$rHasHard = false;
		$rHasSoft = false;

		for ($i = 0; $i < count($rFile); $i++) {
			if (substr($rFile[$i], 0, 19) === 'DefaultLimitNOFILE=') {
				$rHasHard = true;
			}
			if (substr($rFile[$i], 0, 23) === 'DefaultLimitNOFILESoft=') {
				$rHasSoft = true;
			}
		}

		if (!$rHasHard || !$rHasSoft) {
			echo "Configuration\n------------------------------\n";
			echo "Increasing file limit... You need to reboot your system!\n\n";

			if (!$rHasHard) {
				shell_exec('sudo echo "' . "\n" . 'DefaultLimitNOFILE=1048576" >> "/etc/systemd/system.conf"');
				shell_exec('sudo echo "' . "\n" . 'DefaultLimitNOFILE=1048576" >> "/etc/systemd/user.conf"');
			}

			if (!$rHasSoft) {
				shell_exec('sudo echo "' . "\n" . 'DefaultLimitNOFILESoft=1048576" >> "/etc/systemd/system.conf"');
				shell_exec('sudo echo "' . "\n" . 'DefaultLimitNOFILESoft=1048576" >> "/etc/systemd/user.conf"');
			}
		}
	}

	private function removeInitScript(): void {
		if (file_exists('/etc/init.d/xc_vm')) {
			unlink('/etc/init.d/xc_vm');
			shell_exec('sudo systemctl daemon-reload');
		}
	}

	private function broadcastUpdateBinaries($db, array $rServers): void {
		foreach ($rServers as $rServerID => $rServerArray) {
			$db->query('DELETE FROM `signals` WHERE `custom_data` = ?;', json_encode(array('action' => 'update_binaries')));
			$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServerID, time(), json_encode(array('action' => 'update_binaries')));
		}
	}

	private function configureRedis($db, array $rServers): void {
		$rConfig = file_get_contents(MAIN_HOME . 'bin/redis/redis.conf');
		$rWrite = false;

		if (stripos($rConfig, "\nsave 60 1000") === false) {
			$rWrite = true;
			$rConfig .= "\nsave 60 1000";
			$rConfig = str_replace('stop-writes-on-bgsave-error yes', 'stop-writes-on-bgsave-error no', $rConfig);
			echo "Turning Redis Snapshots On!\n\n";
		}

		if (stripos($rConfig, 'stop-writes-on-bgsave-error yes') !== false) {
			$rWrite = true;
			$rConfig = str_replace('stop-writes-on-bgsave-error yes', 'stop-writes-on-bgsave-error no', $rConfig);
			echo "Disabling failed write lock on Redis\n\n";
		}

		if (stripos($rConfig, "\nserver-threads") === false) {
			$rWrite = true;
			$rConfig .= "\nserver-threads 4\nserver-thread-affinity true";
			echo "Enabling multithreading on Redis\n\n";
		}

		$rPassword = trim(explode("\n", explode("\nrequirepass ", $rConfig)[1])[0]);

		if ($rPassword === '#PASSWORD#') {
			$rWrite = true;
			$rPassword = $this->generateString(512);
			$rConfig = str_replace('#PASSWORD#', $rPassword, $rConfig);
			echo "Generating a new Redis password\n\n";
		}

		if ($rWrite) {
			file_put_contents(MAIN_HOME . 'bin/redis/redis.conf', $rConfig);
		}

		$db->query('SELECT `redis_password` FROM `settings`;');
		if ($db->get_row()['redis_password'] !== $rPassword) {
			echo "Updating Redis password in database\n";
			$db->query('UPDATE `settings` SET `redis_password` = ?;', $rPassword);
		}
	}

	private function printStatusReport($db, array $rServers): void {
		global $rSettings;

		$db->query('UPDATE `servers` SET `is_main` = 0 WHERE `id` <> ?;', SERVER_ID);
		$db->query('UPDATE `servers` SET `is_main` = 1 WHERE `id` = ?;', SERVER_ID);
		$db->query('UPDATE `settings` SET `status_uuid` = ?;', md5(XC_VM_VERSION));

		if (stripos($rSettings['server_name'], 'xtream') !== false || stripos($rSettings['server_name'], 'zapx') !== false || stripos($rSettings['server_name'], 'streamcreed') !== false) {
			$db->query("UPDATE `settings` SET `server_name` = 'XC_VM';");
		}

		$db->query('SELECT * FROM `access_codes` WHERE `enabled` = 1 AND `type` = 0;');
		$rCodeCount = 0;
		echo "Access Codes\n------------------------------\n";

		foreach ($db->get_rows() as $rRow) {
			$rExists = file_exists(MAIN_HOME . 'bin/nginx/conf/codes/' . $rRow['code'] . '.conf');
			if ($rExists) {
				$rCodeCount++;
			}
			echo $rServers[SERVER_ID]['site_url'] . $rRow['code'] . "/\n";
		}

		if ($rCodeCount === 0) {
			echo "No access codes available!\nGenerate a rescue code using tools\n\n";
		} else {
			echo "\n";
		}

		$db->query('SELECT COUNT(`id`) AS `count` FROM `users` LEFT JOIN `users_groups` ON `users_groups`.`group_id` = `users`.`member_group_id` WHERE `status` = 1 AND `users_groups`.`is_admin` = 1;');
		if ($db->get_row()['count'] == 0) {
			echo "No administrator users are available on the system.\nGenerate a rescue user with tools\n\n";
		}

		echo "Servers\n------------------------------\n";
		$rOffline = 0;

		foreach ($rServers as $rServerID => $rServer) {
			if ($rServerID !== SERVER_ID) {
				if (!$rServer['server_online'] && $rServer['enabled'] && $rServer['status'] != 3 && $rServer['status'] != 5) {
					echo 'Server #' . $rServerID . ' - ' . $rServer['server_ip'] . ' - Down since: ' . ($rServer['last_check_ago'] ? date('Y-m-d H:i:s', $rServer['last_check_ago']) : 'Always') . "\n";
					$rOffline++;
				}
			}
		}

		if ($rOffline === 0) {
			echo "All servers are Online and reporting back to XC_VM!\n\n";
		} else {
			echo "\n";
		}

		echo 'Installed Version: ' . $rServers[SERVER_ID]['xc_vm_version'] . "\n------------------------------\n";
		$rMismatch = 0;

		foreach ($rServers as $rServerID => $rServer) {
			if ($rServerID !== SERVER_ID) {
				if ($rServer['server_type'] == 0 && $rServer['xc_vm_version'] && $rServer['xc_vm_version'] !== $rServers[SERVER_ID]['xc_vm_version']) {
					echo 'Server #' . $rServerID . ' - ' . $rServer['server_ip'] . ' - Running v' . $rServer['xc_vm_version'] . "\n";
					$rMismatch++;
				}
			}
		}

		if ($rMismatch === 0) {
			echo "All servers are up to date!\n\n";
		} else {
			echo "\n";
		}
	}

	private function generateString(int $rLength = 10): string {
		$rCharacters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789qwertyuiopasdfghjklzxcvbnm';
		$rString = '';
		$rMax = strlen($rCharacters) - 1;

		for ($i = 0; $i < $rLength; $i++) {
			$rString .= $rCharacters[random_int(0, $rMax)];
		}

		return $rString;
	}
}
