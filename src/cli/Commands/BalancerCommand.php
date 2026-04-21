<?php

/**
 * Установка/настройка LB-сервера через SSH.
 *
 * Запуск: php console.php balancer <type> <server_id> <port> <username> <password> [http_port] [https_port] [sysctl] [private_ip] [parent_ids_json]
 *
 * Вызывается из ServerService::installServer().
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class BalancerCommand implements CommandInterface {

	public function getName(): string {
		return 'balancer';
	}

	public function getDescription(): string {
		return 'Install/configure load balancer server via SSH';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] != 'xc_vm') {
			echo "Please run as XC_VM!\n";
			return 1;
		}

		// balancer <type> <serverID> <port> <username> <password> [http] [https] [sysctl] [privateIP] [parentIDs]
		if (count($rArgs) < 4) {
			return 0;
		}

		global $db;

		$gitRelease = new GitHubReleases(GIT_OWNER, GIT_REPO_MAIN, SettingsManager::getAll()['update_channel']);

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
		$rParentIDs = (empty($rArgs[9]) ? array() : json_decode($rArgs[9], true));
		$rSysCtl = '# XC_VM' . PHP_EOL . PHP_EOL . 'net.ipv4.tcp_congestion_control = bbr' . PHP_EOL . 'net.core.default_qdisc = fq' . PHP_EOL . 'net.ipv4.tcp_rmem = 8192 87380 134217728' . PHP_EOL . 'net.ipv4.udp_rmem_min = 16384' . PHP_EOL . 'net.core.rmem_default = 262144' . PHP_EOL . 'net.core.rmem_max = 268435456' . PHP_EOL . 'net.ipv4.tcp_wmem = 8192 65536 134217728' . PHP_EOL . 'net.ipv4.udp_wmem_min = 16384' . PHP_EOL . 'net.core.wmem_default = 262144' . PHP_EOL . 'net.core.wmem_max = 268435456' . PHP_EOL . 'net.core.somaxconn = 1000000' . PHP_EOL . 'net.core.netdev_max_backlog = 250000' . PHP_EOL . 'net.core.optmem_max = 65535' . PHP_EOL . 'net.ipv4.tcp_max_tw_buckets = 1440000' . PHP_EOL . 'net.ipv4.tcp_max_orphans = 16384' . PHP_EOL . 'net.ipv4.ip_local_port_range = 2000 65000' . PHP_EOL . 'net.ipv4.tcp_no_metrics_save = 1' . PHP_EOL . 'net.ipv4.tcp_slow_start_after_idle = 0' . PHP_EOL . 'net.ipv4.tcp_fin_timeout = 15' . PHP_EOL . 'net.ipv4.tcp_keepalive_time = 300' . PHP_EOL . 'net.ipv4.tcp_keepalive_probes = 5' . PHP_EOL . 'net.ipv4.tcp_keepalive_intvl = 15' . PHP_EOL . 'fs.file-max=20970800' . PHP_EOL . 'fs.nr_open=20970800' . PHP_EOL . 'fs.aio-max-nr=20970800' . PHP_EOL . 'net.ipv4.tcp_timestamps = 1' . PHP_EOL . 'net.ipv4.tcp_window_scaling = 1' . PHP_EOL . 'net.ipv4.tcp_mtu_probing = 1' . PHP_EOL . 'net.ipv4.route.flush = 1' . PHP_EOL . 'net.ipv6.route.flush = 1';
		$rInstallDir = BIN_PATH . 'install/';

		if ($rType == 1) {
			$rPackages = array('iproute2', 'net-tools', 'libcurl4', 'libcurl3-gnutls', 'libxslt1-dev', 'libonig-dev', 'e2fsprogs', 'wget', 'sysstat', 'mcrypt', 'python3', 'certbot', 'iptables-persistent', 'libjpeg-dev', 'libpng-dev', 'libssh2-1', 'xz-utils', 'zip', 'unzip', 'cron');
			$rInstallFiles = 'proxy.tar.gz';
		} elseif ($rType == 2) {
			$rPackages = array('cpufrequtils', 'iproute2', 'python', 'net-tools', 'dirmngr', 'gpg-agent', 'software-properties-common', 'libmaxminddb0', 'libmaxminddb-dev', 'mmdb-bin', 'libcurl4', 'libgeoip-dev', 'libxslt1-dev', 'libonig-dev', 'e2fsprogs', 'wget', 'sysstat', 'alsa-utils', 'v4l-utils', 'mcrypt', 'python3', 'certbot', 'iptables-persistent', 'libjpeg-dev', 'libpng-dev', 'libssh2-1', 'xz-utils', 'zip', 'unzip', 'cron', 'libfribidi-dev', 'libharfbuzz-dev', 'libogg0');
			$UpdateData = $gitRelease->getUpdateFile("lb", XC_VM_VERSION);
			$rInstallFiles = $UpdateData['url'];
			$hash = $UpdateData['md5'];
		} else {
			$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
			echo "Invalid type specified!\n";
			return 1;
		}

		if ($rType == 1) {
			file_put_contents($rInstallDir . $rServerID . '.json', json_encode(array('root_username' => $rUsername, 'root_password' => $rPassword, 'ssh_port' => $rPort, 'http_broadcast_port' => $rHTTPPort, 'https_broadcast_port' => $rHTTPSPort, 'parent_id' => $rParentIDs)));
		} else {
			file_put_contents($rInstallDir . $rServerID . '.json', json_encode(array('root_username' => $rUsername, 'root_password' => $rPassword, 'ssh_port' => $rPort)));
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

		// 1. Detect remote OS version and distribution
		echo "Detecting remote OS version...\n";
		$rOS = $this->runSSH($rConn, 'lsb_release -rs');
		$rVersion = trim($rOS['output']);
		$rDistID = strtolower(trim($this->runSSH($rConn, 'lsb_release -is 2>/dev/null || (. /etc/os-release && echo $ID)')['output']));
		echo "\nRemote OS: {$rDistID} {$rVersion}\n";

		echo "\nStopping any previous version of XC_VM\n";
		$this->runSSH($rConn, 'sudo systemctl stop xc_vm');
		$this->runSSH($rConn, 'sudo killall -9 -u xc_vm');
		echo "\nUpdating system\n";
		$this->runSSH($rConn, 'sudo rm /var/lib/dpkg/lock-frontend && sudo rm /var/cache/apt/archives/lock && sudo rm /var/lib/dpkg/lock');
		if ($rType == 2) {
			$this->runSSH($rConn, 'sudo add-apt-repository -y ppa:maxmind/ppa');
		}
		$this->runSSH($rConn, 'sudo apt-get update');
		foreach ($rPackages as $rPackage) {
			echo 'Installing package: ' . $rPackage . "\n";
			$this->runSSH($rConn, 'sudo DEBIAN_FRONTEND=noninteractive apt-get -yq install ' . $rPackage);
		}

		// 2. Ubuntu 20.x — install libssl3
		if (preg_match('/^20\./', $rVersion)) {
			echo "Ubuntu 20.x detected — installing libssl3 for PHP compatibility...\n";
			$this->runSSH($rConn, "wget -O /tmp/libssl3_3.0.2-0ubuntu1_amd64.deb http://security.ubuntu.com/ubuntu/pool/main/o/openssl/libssl3_3.0.2-0ubuntu1_amd64.deb");
			$this->runSSH($rConn, "sudo dpkg -i /tmp/libssl3_3.0.2-0ubuntu1_amd64.deb || true");
			$this->runSSH($rConn, "rm -f /tmp/libssl3_3.0.2-0ubuntu1_amd64.deb");
			echo "libssl3 installed successfully.\n";
		}

		if (in_array($rType, array(1, 2))) {
			echo "Creating XC_VM system user\n";
			$this->runSSH($rConn, 'sudo adduser --system --shell /bin/false --group --disabled-login xc_vm');
			$this->runSSH($rConn, 'sudo mkdir ' . MAIN_HOME);
			$this->runSSH($rConn, 'sudo rm -rf ' . BIN_PATH);
		}

		if ($rType == 1) {
			if ($this->sendFileSSH($rConn, $rInstallDir . $rInstallFiles, '/tmp/' . $rInstallFiles, true)) {
				echo "Extracting to directory\n";
				$this->runSSH($rConn, 'sudo rm -rf ' . MAIN_HOME . 'service');
				$this->runSSH($rConn, 'sudo tar -zxvf "/tmp/' . $rInstallFiles . '" -C "' . MAIN_HOME . '"');
				$rRemoteCheck = trim($this->runSSH($rConn, 'test -f ' . MAIN_HOME . 'service && echo OK')['output']);
				if ($rRemoteCheck !== 'OK') {
					$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
					echo "Failed to extract files! Exiting\n";
					return 1;
				}
			} else {
				$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
				echo "Invalid MD5 checksum! Exiting\n";
				return 1;
			}
		} else {
			echo "Download archive\n";
			$this->runSSH($rConn, 'wget --timeout=2 -O /tmp/XC_VM.tar.gz -o /dev/null "' . $rInstallFiles . '"');
			$fileHash = $this->runSSH($rConn, 'md5=($(md5sum /tmp/XC_VM.tar.gz)); echo $md5;');
			if (!empty($fileHash['output']) && $hash == trim($fileHash['output'])) {
				echo "Extracting to directory\n";
				$this->runSSH($rConn, 'sudo rm -rf ' . MAIN_HOME . 'console.php');
				$this->runSSH($rConn, 'sudo tar -zxvf "/tmp/XC_VM.tar.gz" -C "' . MAIN_HOME . '"');
				$rRemoteCheck = trim($this->runSSH($rConn, 'test -f ' . MAIN_HOME . 'console.php && echo OK')['output']);
				if ($rRemoteCheck === 'OK') {
					$this->runSSH($rConn, 'sudo rm -f "/tmp/XC_VM.tar.gz"');
				} else {
					$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
					echo "Failed to extract files! Exiting\n";
					return 1;
				}
			} else {
				$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
				echo "Invalid MD5 checksum! Exiting\n";
				return 1;
			}
		}

		if ($rType == 2) {
			echo "Installing distribution-specific binaries\n";
			if (!$this->installDistributionBinaries($rConn, $rDistID, $rVersion)) {
				echo "Warning: Failed to install distribution binaries, using defaults\n";
			}

			if (stripos($this->runSSH($rConn, 'sudo cat /etc/fstab')['output'], STREAMS_PATH) === false) {
				echo "Adding ramdisk mounts\n";
				$this->runSSH($rConn, 'sudo echo "tmpfs ' . STREAMS_PATH . ' tmpfs defaults,noatime,nosuid,nodev,noexec,mode=1777,size=90% 0 0" >> /etc/fstab');
				$this->runSSH($rConn, 'sudo echo "tmpfs ' . TMP_PATH . ' tmpfs defaults,noatime,nosuid,nodev,noexec,mode=1777,size=2G 0 0" >> /etc/fstab');
			}
			if (stripos($this->runSSH($rConn, 'sudo cat /etc/sysctl.conf')['output'], 'XC_VM') === false) {
				if ($rUpdateSysctl) {
					echo "Adding sysctl.conf\n";
					$this->runSSH($rConn, 'sudo modprobe ip_conntrack');
					file_put_contents(TMP_PATH . 'sysctl_' . $rServerID, $rSysCtl);
					$this->sendFileSSH($rConn, TMP_PATH . 'sysctl_' . $rServerID, '/etc/sysctl.conf');
					$this->runSSH($rConn, 'sudo sysctl -p');
					$this->runSSH($rConn, 'sudo touch ' . CONFIG_PATH . 'sysctl.on');
				} else {
					$this->runSSH($rConn, 'sudo rm ' . CONFIG_PATH . 'sysctl.on');
				}
			} else {
				if (!$rUpdateSysctl) {
					$this->runSSH($rConn, 'sudo rm ' . CONFIG_PATH . 'sysctl.on');
				} else {
					$this->runSSH($rConn, 'sudo touch ' . CONFIG_PATH . 'sysctl.on');
				}
			}
		}

		echo "Generating configuration file\n";
		$rMasterConfig = parse_ini_file(CONFIG_PATH . 'config.ini');
		if ($rType == 1) {
			if ($rPrivateIP) {
				$rNewConfig = '; XC_VM Configuration' . "\n" . '; -----------------' . "\n\n" . '[XC_VM]' . "\n" . 'hostname    =   "' . $rServers[SERVER_ID]['private_ip'] . '"' . "\n" . 'port        =   ' . intval($rServers[SERVER_ID]['http_broadcast_port']) . "\n" . 'server_id   =   ' . $rServerID;
			} else {
				$rNewConfig = '; XC_VM Configuration' . "\n" . '; -----------------' . "\n\n" . '[XC_VM]' . "\n" . 'hostname    =   "' . $rServers[SERVER_ID]['server_ip'] . '"' . "\n" . 'port        =   ' . intval($rServers[SERVER_ID]['http_broadcast_port']) . "\n" . 'server_id   =   ' . $rServerID;
			}
		} else {
			$rNewConfig = '; XC_VM Configuration' . "\n" . '; -----------------' . "\n\n" . '[XC_VM]' . "\n" . 'hostname    =   "' . $rServers[SERVER_ID]['server_ip'] . '"' . "\n" . 'database    =   "xc_vm"' . "\n" . 'port        =   ' . intval(ConfigReader::get('port')) . "\n" . 'server_id   =   ' . $rServerID . "\n" . 'is_lb       =   1' . "\n\n" . '[Encrypted]' . "\n" . 'username    =   "' . ConfigReader::get('username') . '"' . "\n" . 'password    =   "' . ConfigReader::get('password') . '"';
		}
		file_put_contents(TMP_PATH . 'config_' . $rServerID, $rNewConfig);
		$this->sendFileSSH($rConn, TMP_PATH . 'config_' . $rServerID, CONFIG_PATH . 'config.ini');

		echo "Installing service\n";
		$this->runSSH($rConn, 'sudo rm /etc/systemd/system/xc_vm.service');
		$rSystemd = '[Unit]' . "\n" . 'SourcePath=/home/xc_vm/service' . "\n" . 'Description=XC_VM Service' . "\n" . 'After=network.target' . "\n" . 'StartLimitIntervalSec=0' . "\n\n" . '[Service]' . "\n" . 'Type=simple' . "\n" . 'User=root' . "\n" . 'Restart=always' . "\n" . 'RestartSec=1' . "\n" . 'ExecStart=/bin/bash /home/xc_vm/service start' . "\n" . 'ExecRestart=/bin/bash /home/xc_vm/service restart' . "\n" . 'ExecStop=/bin/bash /home/xc_vm/service stop' . "\n\n" . '[Install]' . "\n" . 'WantedBy=multi-user.target';
		file_put_contents(TMP_PATH . 'systemd_' . $rServerID, $rSystemd);
		$this->sendFileSSH($rConn, TMP_PATH . 'systemd_' . $rServerID, '/etc/systemd/system/xc_vm.service');
		$this->runSSH($rConn, 'sudo chmod +x /etc/systemd/system/xc_vm.service');
		$this->runSSH($rConn, 'sudo rm /etc/init.d/xc_vm');
		$this->runSSH($rConn, 'sudo systemctl daemon-reload');
		$this->runSSH($rConn, 'sudo systemctl enable xc_vm');

		if ($rType == 1) {
			$this->runSSH($rConn, 'sudo rm /home/xc_vm/bin/nginx/conf/servers/*.conf');
			$rServices = 1;
			foreach ($rParentIDs as $rParentID) {
				if ($rPrivateIP) {
					$rIP = $rServers[$rParentID]['private_ip'] . ':' . $rServers[$rParentID]['http_broadcast_port'];
				} else {
					$rIP = $rServers[$rParentID]['server_ip'] . ':' . $rServers[$rParentID]['http_broadcast_port'];
				}
				$rKey = '';
				if ($rServers[$rParentID]['is_main']) {
					$rConfigText = 'location / {' . "\n" . '    include options.conf;' . "\n" . '    proxy_pass http://' . $rIP . '$1;' . "\n" . '}';
				} else {
					$rKey = md5($rServerID . '_' . $rParentID . '_' . OPENSSL_EXTRA);
					$rConfigText = 'location ~/' . $rKey . '(.*)$ {' . "\n" . '    include options.conf;' . "\n" . '    proxy_pass http://' . $rIP . '$1;' . "\n" . '    proxy_set_header X-Token "' . $rKey . '";' . "\n" . '}';
				}
				$rTmpPath = TMP_PATH . md5(time() . $rKey . '.conf');
				file_put_contents($rTmpPath, $rConfigText);
				$this->sendFileSSH($rConn, $rTmpPath, '/home/xc_vm/bin/nginx/conf/servers/' . intval($rParentID) . '.conf');
			}
			$this->runSSH($rConn, 'sudo echo "listen ' . $rHTTPPort . ';" > "/home/xc_vm/bin/nginx/conf/ports/http.conf"');
			$this->runSSH($rConn, 'sudo echo "listen ' . $rHTTPSPort . ' ssl;" > "/home/xc_vm/bin/nginx/conf/ports/https.conf"');
			$this->runSSH($rConn, 'sudo chmod 0777 /home/xc_vm/bin');
		} else {
			$this->sendFileSSH($rConn, MAIN_HOME . 'bin/nginx/conf/custom.conf', MAIN_HOME . 'bin/nginx/conf/custom.conf');
			$this->sendFileSSH($rConn, MAIN_HOME . 'bin/nginx/conf/realip_cdn.conf', MAIN_HOME . 'bin/nginx/conf/realip_cdn.conf');
			$this->sendFileSSH($rConn, MAIN_HOME . 'bin/nginx/conf/realip_cloudflare.conf', MAIN_HOME . 'bin/nginx/conf/realip_cloudflare.conf');
			$this->sendFileSSH($rConn, MAIN_HOME . 'bin/nginx/conf/realip_xc_vm.conf', MAIN_HOME . 'bin/nginx/conf/realip_xc_vm.conf');
			$this->runSSH($rConn, 'sudo echo "" > "/home/xc_vm/bin/nginx/conf/limit.conf"');
			$this->runSSH($rConn, 'sudo echo "" > "/home/xc_vm/bin/nginx/conf/limit_queue.conf"');
			$rIP = '127.0.0.1:' . $rServers[$rServerID]['http_broadcast_port'];
			$this->runSSH($rConn, 'sudo echo "on_play http://' . $rIP . '/stream/rtmp; on_publish http://' . $rIP . '/stream/rtmp; on_play_done http://' . $rIP . '/stream/rtmp;" > "/home/xc_vm/bin/nginx_rtmp/conf/live.conf"');
			$rServices = (intval($this->runSSH($rConn, 'sudo cat /proc/cpuinfo | grep "^processor" | wc -l')['output']) ?: 4);
			$this->runSSH($rConn, 'sudo rm ' . MAIN_HOME . 'bin/php/etc/*.conf');
			$rNewScript = '#! /bin/bash' . "\n";
			$rNewBalance = 'upstream php {' . "\n" . '    least_conn;' . "\n";
			$rTemplate = file_get_contents(MAIN_HOME . 'bin/php/etc/template');
			foreach (range(1, $rServices) as $i) {
				$rNewScript .= 'start-stop-daemon --start --quiet --pidfile ' . MAIN_HOME . 'bin/php/sockets/' . $i . '.pid --exec ' . MAIN_HOME . 'bin/php/sbin/php-fpm -- --daemonize --fpm-config ' . MAIN_HOME . 'bin/php/etc/' . $i . '.conf' . "\n";
				$rNewBalance .= '    server unix:' . MAIN_HOME . 'bin/php/sockets/' . $i . '.sock;' . "\n";
				$rTmpPath = TMP_PATH . md5(time() . $i . '.conf');
				file_put_contents($rTmpPath, str_replace('#PATH#', MAIN_HOME, str_replace('#ID#', $i, $rTemplate)));
				$this->sendFileSSH($rConn, $rTmpPath, MAIN_HOME . 'bin/php/etc/' . $i . '.conf');
			}
			$rNewBalance .= '}';
			$rTmpPath = TMP_PATH . md5(time() . 'daemons.sh');
			file_put_contents($rTmpPath, $rNewScript);
			$this->sendFileSSH($rConn, $rTmpPath, MAIN_HOME . 'bin/daemons.sh');
			$rTmpPath = TMP_PATH . md5(time() . 'balance.conf');
			file_put_contents($rTmpPath, $rNewBalance);
			$this->sendFileSSH($rConn, $rTmpPath, MAIN_HOME . 'bin/nginx/conf/balance.conf');
			$this->runSSH($rConn, 'sudo chmod +x ' . MAIN_HOME . 'bin/daemons.sh');
		}

		$rSystemConf = $this->runSSH($rConn, 'sudo cat "/etc/systemd/system.conf"')['output'];
		if (strpos($rSystemConf, 'DefaultLimitNOFILE=1048576') === false) {
			$this->runSSH($rConn, 'sudo echo "' . "\n" . 'DefaultLimitNOFILE=1048576" >> "/etc/systemd/system.conf"');
			$this->runSSH($rConn, 'sudo echo "' . "\n" . 'DefaultLimitNOFILE=1048576" >> "/etc/systemd/user.conf"');
		}
		if (strpos($rSystemConf, 'DefaultLimitNOFILESoft=1048576') === false) {
			$this->runSSH($rConn, 'sudo echo "' . "\n" . 'DefaultLimitNOFILESoft=1048576" >> "/etc/systemd/system.conf"');
			$this->runSSH($rConn, 'sudo echo "' . "\n" . 'DefaultLimitNOFILESoft=1048576" >> "/etc/systemd/user.conf"');
		}

		$this->runSSH($rConn, 'sudo systemctl stop apparmor');
		$this->runSSH($rConn, 'sudo systemctl disable apparmor');
		$this->runSSH($rConn, 'sudo mount -a');
		$this->runSSH($rConn, "sudo echo 'net.ipv4.ip_unprivileged_port_start=0' > /etc/sysctl.d/50-allports-nonroot.conf && sudo sysctl --system");
		sleep(3);
		$this->runSSH($rConn, 'sudo chown -R xc_vm:xc_vm ' . MAIN_HOME . 'tmp');
		$this->runSSH($rConn, 'sudo chown -R xc_vm:xc_vm ' . MAIN_HOME . 'content/streams');
		$this->runSSH($rConn, 'sudo chown -R xc_vm:xc_vm ' . MAIN_HOME);
		BackupService::grantPrivileges($rHost, DatabaseFactory::get(), ConfigReader::getAll());
		echo "Installation complete! Starting XC_VM\n";
		$this->runSSH($rConn, 'sudo service xc_vm restart');

		if ($rType == 2) {
			$this->runSSH($rConn, 'sudo ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php status 1');
			$this->runSSH($rConn, 'sudo -u xc_vm ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php startup');
			$this->runSSH($rConn, 'sudo -u xc_vm ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:servers');
		} else {
			$this->runSSH($rConn, 'sudo -u xc_vm ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php startup');
		}

		if (in_array($rType, array(1, 2))) {
			$db->query('UPDATE `servers` SET `status` = 1, `http_broadcast_port` = ?, `https_broadcast_port` = ?, `total_services` = ? WHERE `id` = ?;', $rHTTPPort, $rHTTPSPort, $rServices, $rServerID);
		} else {
			$db->query('UPDATE `servers` SET `status` = 1 WHERE `id` = ?;', $rServerID);
		}
		unlink($rInstallDir . $rServerID . '.json');

		return 0;
	}

	private function getDistributionBinaryName(string $rDistID, string $rVersion): ?string {
		$rMajor = explode('.', $rVersion)[0];
		switch ($rDistID) {
			case 'ubuntu':
				if (in_array($rMajor, ['18', '20', '22', '24'])) {
					return 'ubuntu_' . $rMajor . '.tar.gz';
				}
				break;
			case 'debian':
				if (in_array($rMajor, ['11', '12', '13'])) {
					return 'debian_' . $rMajor . '.tar.gz';
				}
				break;
			case 'rocky':
			case 'almalinux':
			case 'rhel':
			case 'centos':
				if (in_array($rMajor, ['8', '9'])) {
					return 'rhel_' . $rMajor . '.tar.gz';
				}
				break;
		}
		return null;
	}

	private function installDistributionBinaries($rConn, string $rDistID, string $rVersion): bool {
		$rBinaryName = $this->getDistributionBinaryName($rDistID, $rVersion);
		if ($rBinaryName === null) {
			echo "Unsupported distribution for binaries: {$rDistID} {$rVersion}\n";
			return false;
		}

		$rTagCmd = 'curl -s https://api.github.com/repos/' . GIT_OWNER . '/' . GIT_REPO_BIN . '/releases/latest';
		$rTag = trim($this->runSSH($rConn, $rTagCmd . ' | grep \'"tag_name"\' | sed -E \'s/.*"([^"]+)".*/\\1/\'')['output']);
		if (empty($rTag)) {
			echo "Failed to get latest binaries release tag\n";
			return false;
		}

		$rURL = 'https://github.com/' . GIT_OWNER . '/' . GIT_REPO_BIN . '/releases/download/' . $rTag . '/' . $rBinaryName;
		echo "Downloading {$rBinaryName} from release {$rTag}\n";
		$this->runSSH($rConn, 'wget -q --timeout=30 -O /tmp/xc_vm_bin.tar.gz "' . $rURL . '"');

		$rCheck = trim($this->runSSH($rConn, 'test -s /tmp/xc_vm_bin.tar.gz && echo OK')['output']);
		if ($rCheck !== 'OK') {
			echo "Failed to download distribution binaries\n";
			return false;
		}

		// MD5 verification from hashes.md5
		$rHashURL = 'https://github.com/' . GIT_OWNER . '/' . GIT_REPO_BIN . '/releases/download/' . $rTag . '/hashes.md5';
		$rHashContent = trim($this->runSSH($rConn, 'curl -sL --timeout=15 "' . $rHashURL . '"')['output']);
		$rExpectedHash = null;
		if (!empty($rHashContent)) {
			foreach (explode("\n", $rHashContent) as $rLine) {
				$rLine = trim($rLine);
				if (empty($rLine)) continue;
				$rParts = preg_split('/\s+/', $rLine, 2);
				if (count($rParts) === 2 && $rParts[1] === $rBinaryName) {
					$rExpectedHash = $rParts[0];
					break;
				}
			}
		}

		if ($rExpectedHash !== null) {
			$rActualHash = trim(explode(' ', $this->runSSH($rConn, 'md5sum /tmp/xc_vm_bin.tar.gz')['output'])[0]);
			if ($rActualHash !== $rExpectedHash) {
				echo "MD5 verification failed for {$rBinaryName}: expected {$rExpectedHash}, got {$rActualHash}\n";
				$this->runSSH($rConn, 'rm -f /tmp/xc_vm_bin.tar.gz');
				return false;
			}
			echo "MD5 verification passed for {$rBinaryName}\n";
		} else {
			echo "Warning: Could not retrieve MD5 hash for {$rBinaryName}, skipping verification\n";
		}

		echo "Extracting distribution binaries\n";
		$this->runSSH($rConn, 'sudo rm -rf /tmp/xc_vm_bin && mkdir -p /tmp/xc_vm_bin');
		$this->runSSH($rConn, 'sudo tar -xzf /tmp/xc_vm_bin.tar.gz -C /tmp/xc_vm_bin');

		$rSourceDir = trim($this->runSSH($rConn, 'find /tmp/xc_vm_bin -maxdepth 3 -type d -name php -print -quit 2>/dev/null | xargs dirname 2>/dev/null')['output']);
		if (empty($rSourceDir) || $rSourceDir === '.') {
			echo "Could not find binary structure in archive\n";
			$this->runSSH($rConn, 'sudo rm -rf /tmp/xc_vm_bin.tar.gz /tmp/xc_vm_bin');
			return false;
		}

		echo "Installing binaries from {$rSourceDir}\n";
		$this->runSSH($rConn, 'sudo cp -rf ' . $rSourceDir . '/* ' . BIN_PATH);

		$this->runSSH($rConn, 'sudo chmod 0551 ' . MAIN_HOME . 'bin/php/bin/php');
		$this->runSSH($rConn, 'sudo chmod 0551 ' . MAIN_HOME . 'bin/php/sbin/php-fpm');
		$this->runSSH($rConn, 'sudo chmod 0550 ' . MAIN_HOME . 'bin/nginx/sbin/nginx');
		$this->runSSH($rConn, 'sudo chmod 0750 ' . MAIN_HOME . 'bin/nginx_rtmp/sbin/nginx_rtmp');

		$this->runSSH($rConn, 'sudo rm -rf /tmp/xc_vm_bin.tar.gz /tmp/xc_vm_bin');
		echo "Distribution binaries installed successfully\n";
		return true;
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
		} catch (Exception $e) {
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
