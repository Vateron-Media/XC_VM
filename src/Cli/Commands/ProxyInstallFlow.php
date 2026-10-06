<?php

namespace XcVm\Cli\Commands;

use XcVm\Domain\Server\ProxyKey;
use XcVm\Domain\Server\ProxyRoute;

class ProxyInstallFlow {
	public static function getPackages(): array {
		return ['iproute2', 'net-tools', 'libcurl4', 'libcurl3-gnutls', 'libxslt1-dev', 'libonig-dev', 'e2fsprogs', 'wget', 'sysstat', 'mcrypt', 'python3', 'certbot', 'iptables-persistent', 'libjpeg-dev', 'libpng-dev', 'libssh2-1', 'xz-utils', 'zip', 'unzip', 'cron'];
	}

	public static function getInstallFile(): string {
		return 'proxy.tar.gz';
	}

	/** Non-secret install parameters for reinstalls; the password is never stored. */
	public static function writeInstallMetadata(string $rInstallDir, int $rServerID, string $rUsername, int $rPort, int $rHTTPPort, int $rHTTPSPort, array $rParentIDs): void {
		file_put_contents($rInstallDir . $rServerID . '.json', json_encode(['root_username' => $rUsername, 'ssh_port' => $rPort, 'http_broadcast_port' => $rHTTPPort, 'https_broadcast_port' => $rHTTPSPort, 'parent_id' => $rParentIDs]));
	}

	public static function installArchive($rConn, callable $rSendFileSSH, callable $rRunSSH, string $rInstallDir, string $rInstallFile, int $rServerID, $db): bool {
		// Root unpacks it: it is sent where no other user of the node can reach it (LbInstallFlow::privateDir).
		$rDir = LbInstallFlow::privateDir($rConn, $rRunSSH);
		if ($rDir === null) {
			$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
			echo "Could not create a private directory on the node! Exiting\n";
			return false;
		}
		$rArchive = $rDir . '/' . $rInstallFile;
		if (call_user_func($rSendFileSSH, $rConn, $rInstallDir . $rInstallFile, $rArchive, true)) {
			echo "Extracting to directory\n";
			call_user_func($rRunSSH, $rConn, 'sudo rm -rf ' . MAIN_HOME . 'service');
			call_user_func($rRunSSH, $rConn, 'sudo tar -zxvf "' . $rArchive . '" -C "' . MAIN_HOME . '"');
			call_user_func($rRunSSH, $rConn, 'sudo rm -rf ' . $rDir);
			$rRemoteCheck = trim(call_user_func($rRunSSH, $rConn, 'test -f ' . MAIN_HOME . 'service && echo OK')['output']);
			if ($rRemoteCheck !== 'OK') {
				$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
				echo "Failed to extract files! Exiting\n";
				return false;
			}
		} else {
			call_user_func($rRunSSH, $rConn, 'sudo rm -rf ' . $rDir);
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
				// The proxy's route to this parent (ProxyRoute): installed with a key
				// generation (provisionKey, before this), so every server routes by it.
				$rKey = ProxyRoute::current($rServerID, (int) $rParentID);
				$rConfigText = 'location ~/' . $rKey . '(.*)$ {' . "\n" . '    include options.conf;' . "\n" . '    proxy_pass http://' . $rIP . '$1;' . "\n" . '}';
			}

			$rTmpPath = TMP_PATH . md5(time() . $rKey . '.conf');
			file_put_contents($rTmpPath, $rConfigText);
			call_user_func($rSendFileSSH, $rConn, $rTmpPath, '/home/xc_vm/bin/nginx/conf/servers/' . intval($rParentID) . '.conf', false);
		}

		call_user_func($rRunSSH, $rConn, LbInstallFlow::sudoWrite('listen ' . $rHTTPPort . ';', '/home/xc_vm/bin/nginx/conf/ports/http.conf'));
		call_user_func($rRunSSH, $rConn, LbInstallFlow::sudoWrite('listen ' . $rHTTPSPort . ' ssl;', '/home/xc_vm/bin/nginx/conf/ports/https.conf'));
		// Only its owner writes to bin/.
		call_user_func($rRunSSH, $rConn, 'sudo chmod 0755 /home/xc_vm/bin');

		return $rServices;
	}

	/**
	 * The proxy's control-channel key (ProxyKey, D8): a new generation, so the
	 * key it had before opens nothing, written to the proxy as hex in
	 * config/proxy.key (0600, root's: its callback cron runs as root). The proxy
	 * is heard unsigned again until it signs (an archive from before this).
	 */
	public static function provisionKey($rConn, callable $rSendFileSSH, callable $rRunSSH, int $rServerID, $db): bool {
		$db->query('UPDATE `servers` SET `proxy_key_gen` = `proxy_key_gen` + 1, `proxy_signed` = 0 WHERE `id` = ?;', $rServerID);
		$db->query('SELECT `proxy_key_gen` FROM `servers` WHERE `id` = ?;', $rServerID);
		$rKey = ProxyKey::forServer($rServerID, (int) (($db->get_row() ?: [])['proxy_key_gen'] ?? 0));
		if ($rKey === null) {
			echo "No proxy secret on MAIN (config/proxy_secret): the proxy keeps the unsigned channel.\n";
			return false;
		}
		$rTmp = tempnam(sys_get_temp_dir(), 'pk'); // 0600 from the start
		if ($rTmp === false) {
			return false;
		}
		try {
			chmod($rTmp, 0600);
			file_put_contents($rTmp, bin2hex($rKey) . "\n");
			if (!call_user_func($rSendFileSSH, $rConn, $rTmp, MAIN_HOME . 'config/proxy.key', false)) {
				return false;
			}
		} finally {
			@unlink($rTmp);
		}
		call_user_func($rRunSSH, $rConn, 'sudo chown root:root ' . MAIN_HOME . 'config/proxy.key && sudo chmod 0600 ' . MAIN_HOME . 'config/proxy.key');
		return true;
	}

	public static function runStartup($rConn, callable $rRunSSH): void {
		call_user_func($rRunSSH, $rConn, 'sudo -u xc_vm ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php startup');
	}
}
