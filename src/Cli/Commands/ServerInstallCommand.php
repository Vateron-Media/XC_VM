<?php

namespace XcVm\Cli\Commands;
use XcVm\Cli\CommandInterface;

use XcVm\Domain\Server\ServerService;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Core\Updates\GitHubReleases;
use XcVm\Core\Backup\BackupService;
use XcVm\Core\Config\SettingsManager;
/**
 * Установка/настройка сервера (Proxy/LB) через SSH.
 *
 * Запуск: php console.php server:install <type> <server_id> <port> <username> <password> [http_port] [https_port] [sysctl] [private_ip] [parent_ids_json]
 *
 * Вызывается из ServerService::installServer().
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/\XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ServerInstallCommand implements CommandInterface {

	public function getName(): string {
		return 'server:install';
	}

	public function getDescription(): string {
		return 'Install/configure server (proxy or load balancer) via SSH';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] != 'xc_vm') {
			echo "Please run as \XC_VM!\n";
			return 1;
		}

		// server:install <type> <serverID> <port> <username> <password> [http] [https] [sysctl] [privateIP] [parentIDs]
		if (count($rArgs) < 4) {
			return 0;
		}

		global $db;

		$gitRelease = new GitHubReleases(GIT_OWNER, GIT_REPO_MAIN, SettingsManager::getAll()['update_channel']);

		$rServerID = intval($rArgs[1]);
		if ($rServerID == 0) {
			return 0;
		}

		shell_exec("kill -9 `ps -ef | grep 'XC_VM Install\\[" . $rServerID . "\\]' | grep -v grep | awk '{print \$2}'`;" );
		set_time_limit(0);
		cli_set_process_title('XC_VM Install[' . $rServerID . ']');
		register_shutdown_function(function () use ($db) {
			if (is_object($db)) {
				$db->close_mysql();
			}
		});

		unlink(CACHE_TMP_PATH . 'servers');
		$rServers = ServerRepository::getAll();
		$rType = intval($rArgs[0]);
		$rPort = intval($rArgs[2]);
		$rUsername = $rArgs[3];
		$rPassword = $rArgs[4];
		$rHTTPPort = (empty($rArgs[5]) ? 80 : intval($rArgs[5]));
		$rHTTPSPort = (empty($rArgs[6]) ? 443 : intval($rArgs[6]));
		$rUpdateSysctl = (empty($rArgs[7]) ? 0 : intval($rArgs[7]));
		$rPrivateIP = (empty($rArgs[8]) ? 0 : intval($rArgs[8]));
		$rParentIDs = (empty($rArgs[9]) ? array() : json_decode($rArgs[9], true));
		$rSysCtl = '# \XC_VM' . PHP_EOL . PHP_EOL . 'net.ipv4.tcp_congestion_control = bbr' . PHP_EOL . 'net.core.default_qdisc = fq' . PHP_EOL . 'net.ipv4.tcp_rmem = 8192 87380 134217728' . PHP_EOL . 'net.ipv4.udp_rmem_min = 16384' . PHP_EOL . 'net.core.rmem_default = 262144' . PHP_EOL . 'net.core.rmem_max = 268435456' . PHP_EOL . 'net.ipv4.tcp_wmem = 8192 65536 134217728' . PHP_EOL . 'net.ipv4.udp_wmem_min = 16384' . PHP_EOL . 'net.core.wmem_default = 262144' . PHP_EOL . 'net.core.wmem_max = 268435456' . PHP_EOL . 'net.core.somaxconn = 1000000' . PHP_EOL . 'net.core.netdev_max_backlog = 250000' . PHP_EOL . 'net.core.optmem_max = 65535' . PHP_EOL . 'net.ipv4.tcp_max_tw_buckets = 1440000' . PHP_EOL . 'net.ipv4.tcp_max_orphans = 16384' . PHP_EOL . 'net.ipv4.ip_local_port_range = 2000 65000' . PHP_EOL . 'net.ipv4.tcp_no_metrics_save = 1' . PHP_EOL . 'net.ipv4.tcp_slow_start_after_idle = 0' . PHP_EOL . 'net.ipv4.tcp_fin_timeout = 15' . PHP_EOL . 'net.ipv4.tcp_keepalive_time = 300' . PHP_EOL . 'net.ipv4.tcp_keepalive_probes = 5' . PHP_EOL . 'net.ipv4.tcp_keepalive_intvl = 15' . PHP_EOL . 'fs.file-max=20970800' . PHP_EOL . 'fs.nr_open=20970800' . PHP_EOL . 'fs.aio-max-nr=20970800' . PHP_EOL . 'net.ipv4.tcp_timestamps = 1' . PHP_EOL . 'net.ipv4.tcp_window_scaling = 1' . PHP_EOL . 'net.ipv4.tcp_mtu_probing = 1' . PHP_EOL . 'net.ipv4.route.flush = 1' . PHP_EOL . 'net.ipv6.route.flush = 1';
		$rInstallDir = BIN_PATH . 'install/';

		if ($rType == 1) {
			$rPackages = ProxyInstallFlow::getPackages();
			$rInstallFiles = ProxyInstallFlow::getInstallFile();
			ProxyInstallFlow::writeInstallMetadata($rInstallDir, $rServerID, $rUsername, $rPassword, $rPort, $rHTTPPort, $rHTTPSPort, $rParentIDs);
		} elseif ($rType == 2) {
			$rUpdateData = LbInstallFlow::resolveUpdateData($gitRelease);
			$rInstallFiles = $rUpdateData['url'];
			$rHash = $rUpdateData['md5'];
			LbInstallFlow::writeInstallMetadata($rInstallDir, $rServerID, $rUsername, $rPassword, $rPort);
		} else {
			$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
			echo "Invalid type specified!\n";
			return 1;
		}

		$rHost = $rServers[$rServerID]['server_ip'];
		echo 'Connecting to ' . $rHost . ':' . $rPort . "\n";
		if (!($rConn = ssh2_connect($rHost, $rPort))) {
			$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
			echo "Failed to connect to server. Exiting\n";
			return 1;
		}

		if ($rUsername == 'root') {
			echo "Connected! Authenticating as root user...\n";
		} else {
			echo "Connected! Authenticating as non-root user...\n";
		}
		$rResult = @ssh2_auth_password($rConn, $rUsername, $rPassword);
		if (!$rResult) {
			$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
			echo "Failed to authenticate using config.ini. Exiting\n";
			return 1;
		}

		$rRunSSH = function ($rConnection, string $rCommand): array {
			return $this->runSSH($rConnection, $rCommand);
		};
		$rSendFileSSH = function ($rConnection, string $rPath, string $rOutput, bool $rWarn = false): bool {
			return $this->sendFileSSH($rConnection, $rPath, $rOutput, $rWarn);
		};

		// 1. Detect remote OS version and distribution
		echo "Detecting remote OS version...\n";
		$rOS = $this->runSSH($rConn, 'lsb_release -rs');
		$rVersion = trim($rOS['output']);
		$rDistID = strtolower(trim($this->runSSH($rConn, 'lsb_release -is 2>/dev/null || (. /etc/os-release && echo $ID)')['output']));
		echo "\nRemote OS: {$rDistID} {$rVersion}\n";

		// LB package list is distribution/version-specific, so resolve it after OS detection.
		if ($rType == 2) {
			$rPackages = LbInstallFlow::getPackages($rDistID, $rVersion);
		}

		$this->updateSystemAndInstallPackages($rConn, $rRunSSH, $rPackages, $rType);
		$this->installUbuntu20Compatibility($rConn, $rRunSSH, $rVersion);
		$this->prepareInstallRoot($rConn, $rRunSSH, $rType);

		if ($rType == 1) {
			if (!ProxyInstallFlow::installArchive($rConn, $rSendFileSSH, $rRunSSH, $rInstallDir, $rInstallFiles, $rServerID, $db)) {
				return 1;
			}
		} else {
			if (!LbInstallFlow::installArchive($rConn, $rRunSSH, $rInstallFiles, $rHash, $rServerID, $db)) {
				return 1;
			}
		}

		if ($rType == 2) {
			LbInstallFlow::runPostExtractSteps($rConn, $rRunSSH, $rSendFileSSH, $rDistID, $rVersion, $rUpdateSysctl, $rSysCtl, $rServerID);
		}

		echo "Generating configuration file\n";
		if ($rType == 1) {
			$rNewConfig = ProxyInstallFlow::buildConfig($rServers, $rServerID, $rPrivateIP);
		} else {
			$rNewConfig = LbInstallFlow::buildConfig($rServers, $rServerID);
		}
		file_put_contents(TMP_PATH . 'config_' . $rServerID, $rNewConfig);
		$this->sendFileSSH($rConn, TMP_PATH . 'config_' . $rServerID, CONFIG_PATH . 'config.ini');

		$this->installSystemdService($rConn, $rRunSSH, $rSendFileSSH, $rServerID);

		if ($rType == 1) {
			$rServices = ProxyInstallFlow::configureRuntime($rConn, $rSendFileSSH, $rRunSSH, $rServers, $rParentIDs, $rPrivateIP, $rHTTPPort, $rHTTPSPort, $rServerID);
		} else {
			$rServices = LbInstallFlow::configureRuntime($rConn, $rSendFileSSH, $rRunSSH, $rServers, $rServerID);
		}

		$this->finalizeHostAfterRuntime($rConn, $rRunSSH, $rHost);

		if ($rType == 2) {
			LbInstallFlow::runStartup($rConn, $rRunSSH);
		} else {
			ProxyInstallFlow::runStartup($rConn, $rRunSSH);
		}

		if (in_array($rType, array(1, 2))) {
			$db->query('UPDATE `servers` SET `status` = 1, `http_broadcast_port` = ?, `https_broadcast_port` = ?, `total_services` = ? WHERE `id` = ?;', $rHTTPPort, $rHTTPSPort, $rServices, $rServerID);
		} else {
			$db->query('UPDATE `servers` SET `status` = 1 WHERE `id` = ?;', $rServerID);
		}
		unlink($rInstallDir . $rServerID . '.json');

		return 0;
	}

	private function updateSystemAndInstallPackages($rConn, callable $rRunSSH, array $rPackages, int $rType): void {
		echo "\nStopping any previous version of \XC_VM\n";
		call_user_func($rRunSSH, $rConn, 'sudo systemctl stop xc_vm');
		call_user_func($rRunSSH, $rConn, 'sudo killall -9 -u xc_vm');

		echo "\nUpdating system\n";
		call_user_func($rRunSSH, $rConn, 'sudo rm /var/lib/dpkg/lock-frontend && sudo rm /var/cache/apt/archives/lock && sudo rm /var/lib/dpkg/lock');
		if ($rType == 2) {
			call_user_func($rRunSSH, $rConn, 'sudo add-apt-repository -y ppa:maxmind/ppa');
		}
		call_user_func($rRunSSH, $rConn, 'sudo apt-get update');
		foreach ($rPackages as $rPackage) {
			echo 'Installing package: ' . $rPackage . "\n";
			call_user_func($rRunSSH, $rConn, 'sudo DEBIAN_FRONTEND=noninteractive apt-get -yq install ' . $rPackage);
		}
	}

	private function installUbuntu20Compatibility($rConn, callable $rRunSSH, string $rVersion): void {
		if (!preg_match('/^20\./', $rVersion)) {
			return;
		}

		echo "Ubuntu 20.x detected — installing libssl3 for PHP compatibility...\n";
		call_user_func($rRunSSH, $rConn, 'wget -O /tmp/libssl3_3.0.2-0ubuntu1_amd64.deb http://security.ubuntu.com/ubuntu/pool/main/o/openssl/libssl3_3.0.2-0ubuntu1_amd64.deb');
		call_user_func($rRunSSH, $rConn, 'sudo dpkg -i /tmp/libssl3_3.0.2-0ubuntu1_amd64.deb || true');
		call_user_func($rRunSSH, $rConn, 'rm -f /tmp/libssl3_3.0.2-0ubuntu1_amd64.deb');
		echo "libssl3 installed successfully.\n";
	}

	private function prepareInstallRoot($rConn, callable $rRunSSH, int $rType): void {
		if (!in_array($rType, array(1, 2))) {
			return;
		}

		echo "Creating \XC_VM system user\n";
		call_user_func($rRunSSH, $rConn, 'sudo adduser --system --shell /bin/false --group --disabled-login xc_vm');
		call_user_func($rRunSSH, $rConn, 'sudo mkdir ' . MAIN_HOME);
		call_user_func($rRunSSH, $rConn, 'sudo rm -rf ' . BIN_PATH);
	}

	private function installSystemdService($rConn, callable $rRunSSH, callable $rSendFileSSH, int $rServerID): void {
		echo "Installing service\n";
		call_user_func($rRunSSH, $rConn, 'sudo rm /etc/systemd/system/xc_vm.service');
		$rSystemd = '[Unit]' . "\n" . 'SourcePath=/home/xc_vm/service' . "\n" . 'Description=\XC_VM Service' . "\n" . 'After=network.target' . "\n" . 'StartLimitIntervalSec=0' . "\n\n" . '[Service]' . "\n" . 'Type=simple' . "\n" . 'User=root' . "\n" . 'Restart=always' . "\n" . 'RestartSec=1' . "\n" . 'ExecStart=/bin/bash /home/xc_vm/service start' . "\n" . 'ExecRestart=/bin/bash /home/xc_vm/service restart' . "\n" . 'ExecStop=/bin/bash /home/xc_vm/service stop' . "\n\n" . '[Install]' . "\n" . 'WantedBy=multi-user.target';
		file_put_contents(TMP_PATH . 'systemd_' . $rServerID, $rSystemd);
		call_user_func($rSendFileSSH, $rConn, TMP_PATH . 'systemd_' . $rServerID, '/etc/systemd/system/xc_vm.service', false);
		call_user_func($rRunSSH, $rConn, 'sudo chmod +x /etc/systemd/system/xc_vm.service');
		call_user_func($rRunSSH, $rConn, 'sudo rm /etc/init.d/xc_vm');
		call_user_func($rRunSSH, $rConn, 'sudo systemctl daemon-reload');
		call_user_func($rRunSSH, $rConn, 'sudo systemctl enable xc_vm');
	}

	private function finalizeHostAfterRuntime($rConn, callable $rRunSSH, string $rHost): void {
		$rSystemConf = call_user_func($rRunSSH, $rConn, 'sudo cat "/etc/systemd/system.conf"')['output'];
		if (strpos($rSystemConf, 'DefaultLimitNOFILE=1048576') === false) {
			call_user_func($rRunSSH, $rConn, 'sudo echo "' . "\n" . 'DefaultLimitNOFILE=1048576" >> "/etc/systemd/system.conf"');
			call_user_func($rRunSSH, $rConn, 'sudo echo "' . "\n" . 'DefaultLimitNOFILE=1048576" >> "/etc/systemd/user.conf"');
		}
		if (strpos($rSystemConf, 'DefaultLimitNOFILESoft=1048576') === false) {
			call_user_func($rRunSSH, $rConn, 'sudo echo "' . "\n" . 'DefaultLimitNOFILESoft=1048576" >> "/etc/systemd/system.conf"');
			call_user_func($rRunSSH, $rConn, 'sudo echo "' . "\n" . 'DefaultLimitNOFILESoft=1048576" >> "/etc/systemd/user.conf"');
		}

		call_user_func($rRunSSH, $rConn, 'sudo systemctl stop apparmor');
		call_user_func($rRunSSH, $rConn, 'sudo systemctl disable apparmor');
		call_user_func($rRunSSH, $rConn, 'sudo mount -a');
		call_user_func($rRunSSH, $rConn, "sudo echo 'net.ipv4.ip_unprivileged_port_start=0' > /etc/sysctl.d/50-allports-nonroot.conf && sudo sysctl --system");
		sleep(3);
		call_user_func($rRunSSH, $rConn, 'sudo chown -R xc_vm:xc_vm ' . MAIN_HOME . 'tmp');
		call_user_func($rRunSSH, $rConn, 'sudo chown -R xc_vm:xc_vm ' . MAIN_HOME . 'content/streams');
		call_user_func($rRunSSH, $rConn, 'sudo chown -R xc_vm:xc_vm ' . MAIN_HOME);
		BackupService::grantPrivileges($rHost);
		echo "Installation complete! Starting \XC_VM\n";
		call_user_func($rRunSSH, $rConn, 'sudo service xc_vm restart');
	}

	private function sendFileSSH($rConn, string $rPath, string $rOutput, bool $rWarn = false): bool {
		$rMD5 = md5_file($rPath);
		ssh2_scp_send($rConn, $rPath, $rOutput);
		$rOutMD5 = trim(explode(' ', $this->runSSH($rConn, 'md5sum "' . $rOutput . '"')['output'])[0]);
		if ($rMD5 == $rOutMD5) {
			return true;
		}
		if ($rWarn) {
			echo "Failed to write using SCP, reverting to SFTP transfer... This will be take significantly longer!\n";
		}
		$rSFTP = ssh2_sftp($rConn);
		if (!$rSFTP) {
			return false;
		}
		$rSuccess = true;
		$rStream = @fopen('ssh2.sftp://' . $rSFTP . $rOutput, 'wb');
		if (!$rStream) {
			return false;
		}
		try {
			$rData = @file_get_contents($rPath);
			if ($rData === false || @fwrite($rStream, $rData) === false) {
				$rSuccess = false;
			}
			if (is_resource($rStream)) {
				fclose($rStream);
			}
		} catch (\Exception $e) {
			$rSuccess = false;
			if (is_resource($rStream)) {
				fclose($rStream);
			}
		}
		return $rSuccess;
	}

	private function runSSH($rConn, string $rCommand): array {
		$rStream = ssh2_exec($rConn, $rCommand);
		$rError = ssh2_fetch_stream($rStream, SSH2_STREAM_STDERR);
		stream_set_blocking($rError, true);
		stream_set_blocking($rStream, true);
		return array('output' => stream_get_contents($rStream), 'error' => stream_get_contents($rError));
	}
}