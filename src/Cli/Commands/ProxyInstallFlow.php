<?php

namespace XcVm\Cli\Commands;

class ProxyInstallFlow {

	public static function getPackages(): array {
		return array('iproute2', 'net-tools', 'libcurl4', 'libcurl3-gnutls', 'libxslt1-dev', 'libonig-dev', 'e2fsprogs', 'wget', 'sysstat', 'mcrypt', 'python3', 'certbot', 'iptables-persistent', 'libjpeg-dev', 'libpng-dev', 'libssh2-1', 'xz-utils', 'zip', 'unzip', 'cron');
	}

	public static function getInstallFile(): string {
		return 'proxy.tar.gz';
	}

	public static function writeInstallMetadata(string $rInstallDir, int $rServerID, string $rUsername, string $rPassword, int $rPort, int $rHTTPPort, int $rHTTPSPort, array $rParentIDs): void {
		file_put_contents($rInstallDir . $rServerID . '.json', json_encode(array('root_username' => $rUsername, 'root_password' => $rPassword, 'ssh_port' => $rPort, 'http_broadcast_port' => $rHTTPPort, 'https_broadcast_port' => $rHTTPSPort, 'parent_id' => $rParentIDs)));
	}

	public static function installArchive($rConn, callable $rSendFileSSH, callable $rRunSSH, string $rInstallDir, string $rInstallFile, int $rServerID, $db): bool {
		if (call_user_func($rSendFileSSH, $rConn, $rInstallDir . $rInstallFile, '/tmp/' . $rInstallFile, true)) {
			echo "Extracting to directory\n";
			call_user_func($rRunSSH, $rConn, 'sudo rm -rf ' . MAIN_HOME . 'service');
			call_user_func($rRunSSH, $rConn, 'sudo tar -zxvf "/tmp/' . $rInstallFile . '" -C "' . MAIN_HOME . '"');
			$rRemoteCheck = trim(call_user_func($rRunSSH, $rConn, 'test -f ' . MAIN_HOME . 'service && echo OK')['output']);
			if ($rRemoteCheck !== 'OK') {
				$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
				echo "Failed to extract files! Exiting\n";
				return false;
			}
		} else {
			$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
			echo "Invalid MD5 checksum! Exiting\n";
			return false;
		}

		return true;
	}

	public static function buildConfig(array $rServers, int $rServerID, int $rPrivateIP): string {
		if ($rPrivateIP) {
			return '; XC_VM Configuration' . "\n" . '; -----------------' . "\n\n" . '[XC_VM]' . "\n" . 'hostname    =   "' . $rServers[SERVER_ID]['private_ip'] . '"' . "\n" . 'port        =   ' . intval($rServers[SERVER_ID]['http_broadcast_port']) . "\n" . 'server_id   =   ' . $rServerID;
		}

		return '; XC_VM Configuration' . "\n" . '; -----------------' . "\n\n" . '[XC_VM]' . "\n" . 'hostname    =   "' . $rServers[SERVER_ID]['server_ip'] . '"' . "\n" . 'port        =   ' . intval($rServers[SERVER_ID]['http_broadcast_port']) . "\n" . 'server_id   =   ' . $rServerID;
	}

	public static function configureRuntime($rConn, callable $rSendFileSSH, callable $rRunSSH, array $rServers, array $rParentIDs, int $rPrivateIP, int $rHTTPPort, int $rHTTPSPort, int $rServerID): int {
		call_user_func($rRunSSH, $rConn, 'sudo rm /home/xc_vm/bin/nginx/conf/servers/*.conf');
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
			call_user_func($rSendFileSSH, $rConn, $rTmpPath, '/home/xc_vm/bin/nginx/conf/servers/' . intval($rParentID) . '.conf', false);
		}

		call_user_func($rRunSSH, $rConn, 'sudo echo "listen ' . $rHTTPPort . ';" > "/home/xc_vm/bin/nginx/conf/ports/http.conf"');
		call_user_func($rRunSSH, $rConn, 'sudo echo "listen ' . $rHTTPSPort . ' ssl;" > "/home/xc_vm/bin/nginx/conf/ports/https.conf"');
		call_user_func($rRunSSH, $rConn, 'sudo chmod 0777 /home/xc_vm/bin');

		return $rServices;
	}

	public static function runStartup($rConn, callable $rRunSSH): void {
		call_user_func($rRunSSH, $rConn, 'sudo -u xc_vm ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php startup');
	}
}
