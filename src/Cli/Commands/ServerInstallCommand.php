<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Backup\BackupService;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\License\LicenseGate;
use XcVm\Core\Proxy\ProxyArchiveUpdater;
use XcVm\Core\Updates\GitHubReleases;
use XcVm\Core\Updates\ReleaseAsset;
use XcVm\Core\Updates\UpdateChannels;
use XcVm\Domain\Server\InstallCredentials;
use XcVm\Domain\Server\ServerRepository;

/**
* Install/configure a server (Proxy/LB) via SSH.
*
* Run: php console.php server:install <type> <server_id> <port> <username> <password> [http_port] [https_port] [sysctl] [private_ip] [parent_ids_json]
*
* Called by ServerService::installServer().
*
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
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
			echo "Please run as XC_VM!\n";
			return 1;
		}

		// server:install <type> <serverID> <port> <username> <password> [http] [https] [sysctl] [privateIP] [parentIDs]
		//                [--cred-file=<path>] [--expect-hostkey=<sha1>] [--forget-hostkey=1]
		// The panel passes "-" for username and password and the credentials in
		// a 0600 --cred-file, read and deleted here, so they never sit in argv.
		[$rArgs, $rOptions] = InstallCredentials::splitOptions($rArgs);
		if (isset($rOptions['cred-file'])) {
			$rCred = InstallCredentials::consume($rOptions['cred-file']);
			if ($rCred === null) {
				echo "Credential file missing or unreadable. Exiting\n";
				return 1;
			}
			$rArgs[3] = $rCred['username'];
			$rArgs[4] = $rCred['password'];
		}
		$rExpectedHostKey = InstallCredentials::normalizeHostKey($rOptions['expect-hostkey'] ?? '');
		if (count($rArgs) < 4) {
			return 0;
		}

		global $db;

		$gitRelease = UpdateChannels::mainReleases();

		$rServerID = intval($rArgs[1]);
		if ($rServerID == 0) {
			return 0;
		}

		shell_exec("kill -9 `ps -ef | grep 'XC_VM Install\\[" . $rServerID . "\\]' | grep -v grep | awk '{print \$2}'`;");
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
		$rParentIDs = (empty($rArgs[9]) ? [] : json_decode($rArgs[9], true));
		$rSysCtl = '# XC_VM' . PHP_EOL . PHP_EOL . 'net.ipv4.tcp_congestion_control = bbr' . PHP_EOL . 'net.core.default_qdisc = fq' . PHP_EOL . 'net.ipv4.tcp_rmem = 8192 87380 134217728' . PHP_EOL . 'net.ipv4.udp_rmem_min = 16384' . PHP_EOL . 'net.core.rmem_default = 262144' . PHP_EOL . 'net.core.rmem_max = 268435456' . PHP_EOL . 'net.ipv4.tcp_wmem = 8192 65536 134217728' . PHP_EOL . 'net.ipv4.udp_wmem_min = 16384' . PHP_EOL . 'net.core.wmem_default = 262144' . PHP_EOL . 'net.core.wmem_max = 268435456' . PHP_EOL . 'net.core.somaxconn = 1000000' . PHP_EOL . 'net.core.netdev_max_backlog = 250000' . PHP_EOL . 'net.core.optmem_max = 65535' . PHP_EOL . 'net.ipv4.tcp_max_tw_buckets = 1440000' . PHP_EOL . 'net.ipv4.tcp_max_orphans = 16384' . PHP_EOL . 'net.ipv4.ip_local_port_range = 2000 65000' . PHP_EOL . 'net.ipv4.tcp_no_metrics_save = 1' . PHP_EOL . 'net.ipv4.tcp_slow_start_after_idle = 0' . PHP_EOL . 'net.ipv4.tcp_fin_timeout = 15' . PHP_EOL . 'net.ipv4.tcp_keepalive_time = 300' . PHP_EOL . 'net.ipv4.tcp_keepalive_probes = 5' . PHP_EOL . 'net.ipv4.tcp_keepalive_intvl = 15' . PHP_EOL . 'fs.file-max=20970800' . PHP_EOL . 'fs.nr_open=20970800' . PHP_EOL . 'fs.aio-max-nr=20970800' . PHP_EOL . 'net.ipv4.tcp_timestamps = 1' . PHP_EOL . 'net.ipv4.tcp_window_scaling = 1' . PHP_EOL . 'net.ipv4.tcp_mtu_probing = 1' . PHP_EOL . 'net.ipv4.route.flush = 1' . PHP_EOL . 'net.ipv6.route.flush = 1';
		$rInstallDir = BIN_PATH . 'install/';

		if ($rType == 1) {
			$rPackages = ProxyInstallFlow::getPackages();
			$rInstallFiles = ProxyInstallFlow::getInstallFile();
			ProxyInstallFlow::writeInstallMetadata($rInstallDir, $rServerID, $rUsername, $rPort, $rHTTPPort, $rHTTPSPort, $rParentIDs);

			// proxy.tar.gz is fetched from XC_VM_Proxy releases (not shipped in LFS) and
			// kept fresh by cron:proxy. Self-heal here so a brand-new panel that has not
			// run the cron yet still gets a valid local archive before shipping it to the node.
			$rProxyRepo = new GitHubReleases(GIT_OWNER, GIT_REPO_PROXY, UpdateChannels::forRepo(GIT_REPO_PROXY));
			$rProxyResult = (new ProxyArchiveUpdater($rProxyRepo))->ensure(false, !empty(SettingsManager::get('proxy_force_local')));
			if ($rProxyResult['action'] === 'error' && !is_file($rInstallDir . $rInstallFiles)) {
				$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
				echo 'Failed to prepare proxy archive: ' . $rProxyResult['error'] . "\n";
				return 1;
			}
		} elseif ($rType == 2) {
			// Load-balancer nodes are a licensed capability — an unlicensed
			// white-label panel stays usable locally but cannot add LB nodes.
			// This is a first-line stopgap (removable PHP); the tamper-proof gate
			// is XC_VM::db_grant() in the compiled core, which refuses the LB node
			// DB access unless the panel is licensed.
			if (!LicenseGate::licensed()) {
				$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
				echo "This panel is not verified — load-balancer nodes require a verified panel.\n";
				echo "Verify the panel (dashboard banner → Get a key), then retry.\n";
				return 1;
			}

			$rUpdateData = LbInstallFlow::resolveUpdateData($gitRelease);
			$rInstallFiles = $rUpdateData['url'];
			$rHash = $rUpdateData['md5'];
			if (empty($rInstallFiles) || empty($rHash)) {
				$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
				echo "MAIN's release " . XC_VM_VERSION . " has no loadbalancer.tar.gz listed in its hashes.md5 on GitHub, or GitHub could not be reached. Exiting\n";
				return 1;
			}
			// Before anything is removed on the node: the script that gives it
			// xcvm_core, which no archive or bundle carries (taken from GitHub
			// when MAIN has none).
			if (LbInstallFlow::extensionInstaller() === null) {
				$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
				echo 'MAIN has no ' . MAIN_HOME . "bin/install/install_xcvm_core.sh and could not take it from GitHub. Exiting\n";
				return 1;
			}
			LbInstallFlow::writeInstallMetadata($rInstallDir, $rServerID, $rUsername, $rPort);
		} else {
			$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
			echo "Invalid type specified!\n";
			return 1;
		}

		$rHost = $rServers[$rServerID]['server_ip'];
		echo 'Connecting to ' . $rHost . ':' . $rPort . "\n";
		if (!($rConn = SshSession::open($rHost, $rPort, $rConnectError))) {
			$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
			echo 'Failed to connect to server' . ($rConnectError !== '' ? ': ' . $rConnectError : '') . ". Exiting\n";
			return 1;
		}

		$rPresentedHostKey = (string) @ssh2_fingerprint($rConn, SSH2_FINGERPRINT_SHA1 | SSH2_FINGERPRINT_HEX);
		$rStoredHostKey = $rServers[$rServerID]['ssh_hostkey_sha1'] ?? null;
		if (!empty($rOptions['forget-hostkey']) && $rStoredHostKey !== null && $rStoredHostKey !== '') {
			// A rebuilt node has a new key. The stored one is not asked for, and the
			// one presented now is stored below, once the login has worked.
			echo 'The saved SSH host key (' . $rStoredHostKey . ") is forgotten, as the admin asked: the key this node presents is trusted and saved\n";
			$rStoredHostKey = null;
		}
		$rHostKeyError = InstallCredentials::checkHostKey($rPresentedHostKey, $rExpectedHostKey, $rStoredHostKey);
		if ($rHostKeyError !== null) {
			$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
			echo $rHostKeyError . ". Exiting\n";
			return 1;
		}
		echo 'SSH host key (SHA-1): ' . InstallCredentials::normalizeHostKey($rPresentedHostKey) . "\n";

		echo "Connected! Authenticating as user '" . $rUsername . "'...\n";
		$rResult = @ssh2_auth_password($rConn, $rUsername, $rPassword);
		if (!$rResult) {
			$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
			echo "Failed to authenticate over SSH — check the root username and password. Exiting\n";
			return 1;
		}
		// Remember the host key for reinstalls to match (trust on first use).
		$rHostKey = InstallCredentials::normalizeHostKey($rPresentedHostKey);
		if ($rHostKey !== $rStoredHostKey) {
			$db->query('UPDATE `servers` SET `ssh_hostkey_sha1` = ? WHERE `id` = ?;', $rHostKey, $rServerID);
		}

		// Every step below runs through sudo, and so does the node's own service
		// script. A Debian installed with a root password has no sudo: root
		// installs it. A sudo that asks for a password cannot be answered here.
		$rSudo = trim($this->runSSH($rConn, 'command -v sudo >/dev/null 2>&1 || { [ "$(id -u)" = 0 ] && apt-get update >/dev/null 2>&1 && DEBIAN_FRONTEND=noninteractive apt-get -yq install sudo >/dev/null 2>&1; }; sudo -n true >/dev/null 2>&1 && echo SUDO_OK')['output']);
		if ($rSudo !== 'SUDO_OK') {
			$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
			echo "The SSH user must be root, or a user whose sudo asks for no password, and the node needs sudo (apt-get install sudo). Exiting\n";
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
		// Minimal Debian images have no lsb_release: os-release has the version too.
		$rOS = $this->runSSH($rConn, 'lsb_release -rs 2>/dev/null || (. /etc/os-release && echo $VERSION_ID)');
		$rVersion = trim($rOS['output']);
		$rDistID = strtolower(trim($this->runSSH($rConn, 'lsb_release -is 2>/dev/null || (. /etc/os-release && echo $ID)')['output']));
		echo "\nRemote OS: {$rDistID} {$rVersion}\n";

		// The binaries release builds PHP and nginx for these only, and the LB
		// archive carries neither: anywhere else the node would get no PHP.
		if ($rType == 2 && ReleaseAsset::bundleFor($rDistID, $rVersion) === null) {
			$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
			echo "Unsupported system: {$rDistID} {$rVersion}. A load balancer runs on Ubuntu 20.04, 22.04 or 24.04, or Debian 12 or 13. Exiting\n";
			return 1;
		}

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

		// An LB in API mode — a new one under lb_new_node_mode=api, or one MAIN
		// keeps free of its credentials — gets no grant and a credential-free
		// config.enc. Decided once, before the enrolment replaces the node's row.
		$rApiMode = $rType == 2 && LbInstallFlow::installsInApiMode(SettingsManager::getAll(), $rServerID);

		if ($rType == 2 && !LbInstallFlow::runPostExtractSteps($rConn, $rRunSSH, $rSendFileSSH, $rDistID, $rVersion, $rUpdateSysctl, $rSysCtl, $rServerID)) {
			$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
			return 1;
		}

		if ($rType == 1) {
			echo "Generating configuration file\n";
			$rNewConfig = ProxyInstallFlow::buildConfig($rServers, $rServerID, $rPrivateIP);
			file_put_contents(TMP_PATH . 'config_' . $rServerID, $rNewConfig);
			$this->sendFileSSH($rConn, TMP_PATH . 'config_' . $rServerID, CONFIG_PATH . 'config.ini');
		} else {
			// LB: secure provisioning — DB password never enters PHP (see LbInstallFlow::provisionConfig).
			if (!LbInstallFlow::provisionConfig($rConn, $rRunSSH, $rSendFileSSH, $rServers, $rServerID, $db, $rApiMode)) {
				return 1;
			}
		}

		$this->installSystemdService($rConn, $rRunSSH, $rSendFileSSH, $rServerID);

		if ($rType == 1) {
			// The key generation first: the routes configureRuntime writes are that generation's (ProxyRoute).
			ProxyInstallFlow::provisionKey($rConn, $rSendFileSSH, $rRunSSH, $rServerID, $db);
			$rServices = ProxyInstallFlow::configureRuntime($rConn, $rSendFileSSH, $rRunSSH, $rServers, $rParentIDs, $rPrivateIP, $rHTTPPort, $rHTTPSPort, $rServerID);
		} else {
			$rServices = LbInstallFlow::configureRuntime($rConn, $rSendFileSSH, $rRunSSH, $rServers, $rServerID);
		}

		$this->finalizeHostAfterRuntime($rConn, $rRunSSH, $rHost, !$rApiMode);

		if ($rType == 2) {
			LbInstallFlow::runStartup($rConn, $rRunSSH);
			if (!LbInstallFlow::provisionCluster($rConn, $rRunSSH, $rSendFileSSH, $rServers, $rServerID, $db, null, true, $rApiMode)) {
				return 1;
			}
		} else {
			ProxyInstallFlow::runStartup($rConn, $rRunSSH);
		}

		if (in_array($rType, [1, 2])) {
			$db->query('UPDATE `servers` SET `status` = 1, `http_broadcast_port` = ?, `https_broadcast_port` = ?, `total_services` = ? WHERE `id` = ?;', $rHTTPPort, $rHTTPSPort, $rServices, $rServerID);
		} else {
			$db->query('UPDATE `servers` SET `status` = 1 WHERE `id` = ?;', $rServerID);
		}
		unlink($rInstallDir . $rServerID . '.json');

		return 0;
	}

	private function updateSystemAndInstallPackages($rConn, callable $rRunSSH, array $rPackages, int $rType): void {
		echo "\nStopping any previous version of XC_VM\n";
		call_user_func($rRunSSH, $rConn, 'sudo systemctl stop xc_vm');
		call_user_func($rRunSSH, $rConn, 'sudo killall -9 -u xc_vm');

		echo "\nUpdating system\n";
		call_user_func($rRunSSH, $rConn, 'sudo rm /var/lib/dpkg/lock-frontend && sudo rm /var/cache/apt/archives/lock && sudo rm /var/lib/dpkg/lock');
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
		if (!in_array($rType, [1, 2])) {
			return;
		}

		echo "Creating XC_VM system user\n";
		call_user_func($rRunSSH, $rConn, 'sudo adduser --system --shell /bin/false --group --disabled-login xc_vm');
		call_user_func($rRunSSH, $rConn, 'sudo mkdir ' . MAIN_HOME);
		call_user_func($rRunSSH, $rConn, 'sudo rm -rf ' . BIN_PATH);
	}

	private function installSystemdService($rConn, callable $rRunSSH, callable $rSendFileSSH, int $rServerID): void {
		echo "Installing service\n";
		call_user_func($rRunSSH, $rConn, 'sudo rm /etc/systemd/system/xc_vm.service');
		$rSystemd = '[Unit]' . "\n" . 'SourcePath=/home/xc_vm/service' . "\n" . 'Description=XC_VM Service' . "\n" . 'After=network.target' . "\n" . 'StartLimitIntervalSec=0' . "\n\n" . '[Service]' . "\n" . 'Type=simple' . "\n" . 'User=root' . "\n" . 'Restart=always' . "\n" . 'RestartSec=1' . "\n" . 'ExecStart=/bin/bash /home/xc_vm/service start' . "\n" . 'ExecRestart=/bin/bash /home/xc_vm/service restart' . "\n" . 'ExecStop=/bin/bash /home/xc_vm/service stop' . "\n\n" . '[Install]' . "\n" . 'WantedBy=multi-user.target';
		file_put_contents(TMP_PATH . 'systemd_' . $rServerID, $rSystemd);
		call_user_func($rSendFileSSH, $rConn, TMP_PATH . 'systemd_' . $rServerID, '/etc/systemd/system/xc_vm.service', false);
		call_user_func($rRunSSH, $rConn, 'sudo chmod +x /etc/systemd/system/xc_vm.service');
		call_user_func($rRunSSH, $rConn, 'sudo rm /etc/init.d/xc_vm');
		call_user_func($rRunSSH, $rConn, 'sudo systemctl daemon-reload');
		call_user_func($rRunSSH, $rConn, 'sudo systemctl enable xc_vm');
	}

	/** $rGrant false: a load balancer installed in API mode, which never gets MAIN's database. */
	private function finalizeHostAfterRuntime($rConn, callable $rRunSSH, string $rHost, bool $rGrant = true): void {
		// systemd 256+ (Debian 13) ships its defaults under /usr/lib/systemd and leaves these absent. systemd
		// ignores an assignment outside a section, so a file without its header (2.6.0 made one there) starts over.
		call_user_func($rRunSSH, $rConn, 'for f in system user; do sudo grep -qs "^\[Manager\]" /etc/systemd/$f.conf || echo "[Manager]" | sudo tee /etc/systemd/$f.conf > /dev/null; done');
		$rSystemConf = call_user_func($rRunSSH, $rConn, 'sudo cat "/etc/systemd/system.conf"')['output'];
		if (strpos($rSystemConf, 'DefaultLimitNOFILE=1048576') === false) {
			call_user_func($rRunSSH, $rConn, LbInstallFlow::sudoWrite("\n" . 'DefaultLimitNOFILE=1048576', '/etc/systemd/system.conf', true));
			call_user_func($rRunSSH, $rConn, LbInstallFlow::sudoWrite("\n" . 'DefaultLimitNOFILE=1048576', '/etc/systemd/user.conf', true));
		}
		if (strpos($rSystemConf, 'DefaultLimitNOFILESoft=1048576') === false) {
			call_user_func($rRunSSH, $rConn, LbInstallFlow::sudoWrite("\n" . 'DefaultLimitNOFILESoft=1048576', '/etc/systemd/system.conf', true));
			call_user_func($rRunSSH, $rConn, LbInstallFlow::sudoWrite("\n" . 'DefaultLimitNOFILESoft=1048576', '/etc/systemd/user.conf', true));
		}

		call_user_func($rRunSSH, $rConn, 'sudo systemctl stop apparmor');
		call_user_func($rRunSSH, $rConn, 'sudo systemctl disable apparmor');
		call_user_func($rRunSSH, $rConn, 'sudo mount -a');
		call_user_func($rRunSSH, $rConn, LbInstallFlow::sudoWrite('net.ipv4.ip_unprivileged_port_start=0', '/etc/sysctl.d/50-allports-nonroot.conf') . ' && sudo sysctl --system');
		sleep(3);
		call_user_func($rRunSSH, $rConn, 'sudo chown -R xc_vm:xc_vm ' . MAIN_HOME . 'tmp');
		call_user_func($rRunSSH, $rConn, 'sudo chown -R xc_vm:xc_vm ' . MAIN_HOME . 'content/streams');
		call_user_func($rRunSSH, $rConn, 'sudo chown -R xc_vm:xc_vm ' . MAIN_HOME);
		if ($rGrant) {
			BackupService::grantPrivileges($rHost);
		}
		echo "Installation complete! Starting XC_VM\n";
		// The node's root cron ran through the install, and after the stop at
		// its start may have brought the panel's daemons up again: a fanout
		// supervisor holding the lock file of the bin/ that was since replaced,
		// next to which the service then started a second one, each killing the
		// other's daemon every few seconds. systemd holds the unit as stopped,
		// so a restart alone would only start: what runs as xc_vm goes first.
		call_user_func($rRunSSH, $rConn, 'sudo systemctl stop xc_vm; sudo pkill -9 -u xc_vm; sudo service xc_vm restart');
	}

	private function sendFileSSH($rConn, string $rPath, string $rOutput, bool $rWarn = false): bool {
		return SshChannel::send($rConn, $rPath, $rOutput, $rWarn);
	}

	private function runSSH($rConn, string $rCommand): array {
		return SshChannel::run($rConn, $rCommand);
	}
}
