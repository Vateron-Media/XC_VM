<?php

namespace XcVm\Cli\Commands;

use XcVm\Core\Config\ConfigReader;
use XcVm\Core\Updates\GitHubReleases;

class LbInstallFlow {

	// Per-distribution package lists, mirrored from the MAIN installer (install -> PACKAGES),
	// with the mariadb-server/client/common packages removed (LB nodes use the main server's DB).
	public static function getPackages(string $rDistID = 'debian', string $rVersion = ''): array {
		$rLists = array(
			'debian' => array('iproute2', 'net-tools', 'dirmngr', 'gpg-agent', 'software-properties-common', 'libcurl4', 'libgeoip-dev', 'libxslt1-dev', 'libonig-dev', 'e2fsprogs', 'wget', 'sysstat', 'alsa-utils', 'v4l-utils', 'certbot', 'iptables-persistent', 'libjpeg-dev', 'libpng-dev', 'libharfbuzz-dev', 'libfribidi-dev', 'libogg0', 'libnuma1', 'xz-utils', 'zip', 'unzip', 'libssh2-1', 'libsodium23', 'cpufrequtils', 'mcrypt', 'cron', 'git', 'curl'),
			'debian11' => array('iproute2', 'net-tools', 'dirmngr', 'gpg-agent', 'software-properties-common', 'libcurl4', 'libgeoip-dev', 'libxslt1-dev', 'libonig-dev', 'e2fsprogs', 'wget', 'curl', 'unzip', 'zip', 'xz-utils', 'cron', 'git', 'sysstat', 'alsa-utils', 'v4l-utils', 'certbot', 'iptables-persistent', 'libjpeg-dev', 'libpng-dev', 'libharfbuzz-dev', 'libfribidi-dev', 'libogg0', 'libnuma1', 'libssh2-1', 'libssh2-1-dev', 'libsodium23', 'cpufrequtils', 'mcrypt'),
			'debian13' => array('iproute2', 'net-tools', 'dirmngr', 'gpg-agent', 'software-properties-common', 'libcurl4', 'wget', 'unzip', 'zip', 'xz-utils', 'cron', 'git', 'sysstat', 'perl', 'gawk', 'socat', 'libxml2-dev', 'libxslt1-dev', 'libonig5', 'libonig-dev', 'zlib1g-dev', 'libssl-dev', 'pkg-config', 'autoconf', 'automake', 'alsa-utils', 'v4l-utils', 'e2fsprogs', 'certbot', 'iptables-persistent', 'libssh2-1', 'libssh2-1-dev', 'libjpeg-dev', 'libpng-dev', 'libharfbuzz-dev', 'libfribidi-dev', 'libgeoip1', 'geoip-bin', 'libsodium23', 'cpufrequtils', 'mcrypt', 'libogg0', 'libnuma1'),
			'ubuntu20' => array('iproute2', 'net-tools', 'dirmngr', 'gpg-agent', 'software-properties-common', 'wget', 'curl', 'unzip', 'zip', 'xz-utils', 'cron', 'git', 'sysstat', 'ca-certificates', 'libcurl3-gnutls', 'libcurl4-gnutls-dev', 'libxml2-dev', 'libxslt1-dev', 'libonig5', 'libonig-dev', 'libjpeg-dev', 'libpng-dev', 'zlib1g-dev', 'alsa-utils', 'v4l-utils', 'e2fsprogs', 'iptables-persistent', 'certbot', 'python3-certbot', 'libssh2-1', 'libssh2-1-dev', 'libsodium23', 'cpufrequtils', 'mcrypt', 'libogg0', 'libnuma1'),
			'ubuntu22' => array('iproute2', 'net-tools', 'dirmngr', 'gpg-agent', 'software-properties-common', 'libcurl4', 'libcurl3-gnutls', 'libgeoip-dev', 'libxslt1-dev', 'libonig-dev', 'e2fsprogs', 'wget', 'curl', 'unzip', 'zip', 'xz-utils', 'cron', 'git', 'sysstat', 'ca-certificates', 'libxml2-dev', 'libonig5', 'zlib1g-dev', 'alsa-utils', 'v4l-utils', 'certbot', 'python3-certbot', 'iptables-persistent', 'libjpeg-dev', 'libpng-dev', 'libharfbuzz-dev', 'libfribidi-dev', 'libogg0', 'libnuma1', 'libssh2-1', 'libssh2-1-dev', 'libsodium23', 'cpufrequtils', 'mcrypt'),
			'ubuntu24' => array('iproute2', 'net-tools', 'dirmngr', 'gpg-agent', 'software-properties-common', 'libcurl4t64', 'wget', 'unzip', 'zip', 'xz-utils', 'cron', 'git', 'sysstat', 'perl', 'gawk', 'socat', 'libxml2-dev', 'libxslt1-dev', 'libonig5', 'libonig-dev', 'zlib1g-dev', 'libssl-dev', 'pkg-config', 'autoconf', 'automake', 'alsa-utils', 'v4l-utils', 'e2fsprogs', 'certbot', 'python3-certbot', 'ufw', 'libssh2-1t64', 'libssh2-1-dev', 'libjpeg-dev', 'libpng-dev', 'libharfbuzz-dev', 'libfribidi-dev', 'libgeoip1t64', 'geoip-bin', 'libsodium23', 'cpufrequtils', 'mcrypt', 'libogg0', 'libnuma1'),
			'redhat' => array('epel-release', 'wget', 'sysstat', 'alsa-utils', 'v4l-utils', 'libcurl-devel', 'geoip-devel', 'libxslt-devel', 'oniguruma-devel', 'e2fsprogs', 'libjpeg-turbo-devel', 'libpng-devel', 'harfbuzz-devel', 'fribidi-devel', 'libogg', 'xz', 'zip', 'unzip', 'libssh2-devel', 'cronie', 'certbot', 'iptables-services', 'GeoIP-update', 'git', 'curl', 'libsodium', 'numactl', 'kernel-tools'),
		);

		$rKey = self::resolvePackageKey(strtolower(trim($rDistID)), explode('.', $rVersion)[0]);
		return $rLists[$rKey] ?? $rLists['debian'];
	}

	// Mirrors the MAIN installer's _PACKAGE_KEY_MAP: (dist_id, major_version) -> package list key.
	private static function resolvePackageKey(string $rDistID, string $rMajor): string {
		if (in_array($rDistID, array('rocky', 'almalinux', 'rhel', 'centos', 'redhat', 'fedora'), true)) {
			return 'redhat';
		}
		$rMap = array(
			'ubuntu' => array('18' => 'ubuntu20', '20' => 'ubuntu20', '22' => 'ubuntu22', '24' => 'ubuntu24'),
			'debian' => array('11' => 'debian11', '12' => 'debian', '13' => 'debian13'),
		);
		return $rMap[$rDistID][$rMajor] ?? 'debian';
	}

	public static function resolveUpdateData(GitHubReleases $gitRelease): array {
		$rUpdateData = $gitRelease->getUpdateFile("lb", XC_VM_VERSION);
		return array(
			'url' => $rUpdateData['url'],
			'md5' => $rUpdateData['md5'],
		);
	}

	public static function writeInstallMetadata(string $rInstallDir, int $rServerID, string $rUsername, string $rPassword, int $rPort): void {
		file_put_contents($rInstallDir . $rServerID . '.json', json_encode(array('root_username' => $rUsername, 'root_password' => $rPassword, 'ssh_port' => $rPort)));
	}

	public static function installArchive($rConn, callable $rRunSSH, string $rInstallFiles, string $rHash, int $rServerID, $db): bool {
		echo "Download archive\n";
		call_user_func($rRunSSH, $rConn, 'wget --timeout=2 -O /tmp/XC_VM.tar.gz -o /dev/null "' . $rInstallFiles . '"');
		$rFileHash = call_user_func($rRunSSH, $rConn, 'md5=($(md5sum /tmp/XC_VM.tar.gz)); echo $md5;');
		if (empty($rFileHash['output']) || $rHash != trim($rFileHash['output'])) {
			$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
			echo "Invalid MD5 checksum! Exiting\n";
			return false;
		}

		echo "Extracting to directory\n";
		call_user_func($rRunSSH, $rConn, 'sudo rm -rf ' . MAIN_HOME . 'console.php');
		call_user_func($rRunSSH, $rConn, 'sudo tar -zxvf /tmp/XC_VM.tar.gz -C "' . MAIN_HOME . '"');
		$rRemoteCheck = trim(call_user_func($rRunSSH, $rConn, 'test -f ' . MAIN_HOME . 'console.php && echo OK')['output']);
		if ($rRemoteCheck !== 'OK') {
			$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
			echo "Failed to extract files! Exiting\n";
			return false;
		}

		call_user_func($rRunSSH, $rConn, 'sudo rm -f "/tmp/XC_VM.tar.gz"');

		return true;
	}

	public static function runPostExtractSteps($rConn, callable $rRunSSH, callable $rSendFileSSH, string $rDistID, string $rVersion, int $rUpdateSysctl, string $rSysCtl, int $rServerID): void {
		echo "Installing distribution-specific binaries\n";
		if (!self::installDistributionBinaries($rConn, $rRunSSH, $rDistID, $rVersion)) {
			echo "Warning: Failed to install distribution binaries, using defaults\n";
		}

		if (stripos(call_user_func($rRunSSH, $rConn, 'sudo cat /etc/fstab')['output'], STREAMS_PATH) === false) {
			echo "Adding ramdisk mounts\n";
			call_user_func($rRunSSH, $rConn, 'sudo echo "tmpfs ' . STREAMS_PATH . ' tmpfs defaults,noatime,nosuid,nodev,noexec,mode=1777,size=90% 0 0" >> /etc/fstab');
			call_user_func($rRunSSH, $rConn, 'sudo echo "tmpfs ' . TMP_PATH . ' tmpfs defaults,noatime,nosuid,nodev,noexec,mode=1777,size=2G 0 0" >> /etc/fstab');
		}

		if (stripos(call_user_func($rRunSSH, $rConn, 'sudo cat /etc/sysctl.conf')['output'], 'XC_VM') === false) {
			if ($rUpdateSysctl) {
				echo "Adding sysctl.conf\n";
				call_user_func($rRunSSH, $rConn, 'sudo modprobe ip_conntrack');
				file_put_contents(TMP_PATH . 'sysctl_' . $rServerID, $rSysCtl);
				call_user_func($rSendFileSSH, $rConn, TMP_PATH . 'sysctl_' . $rServerID, '/etc/sysctl.conf', false);
				call_user_func($rRunSSH, $rConn, 'sudo sysctl -p');
				call_user_func($rRunSSH, $rConn, 'sudo touch ' . CONFIG_PATH . 'sysctl.on');
			} else {
				call_user_func($rRunSSH, $rConn, 'sudo rm ' . CONFIG_PATH . 'sysctl.on');
			}
		} else {
			if (!$rUpdateSysctl) {
				call_user_func($rRunSSH, $rConn, 'sudo rm ' . CONFIG_PATH . 'sysctl.on');
			} else {
				call_user_func($rRunSSH, $rConn, 'sudo touch ' . CONFIG_PATH . 'sysctl.on');
			}
		}
	}

	/**
	 * Securely provision config.enc onto a freshly-installed LB node.
	 *
	 * The DB password never enters PHP: we read the node's install_id over the
	 * SSH channel, then XC_VM::config_pack() pulls the credentials from MAIN's
	 * own config.enc and returns a transport blob (XCVT) encrypted for that
	 * install_id. The node re-encrypts it to its at-rest format on first read.
	 *
	 * Replaces the old config.ini flow, which wrote empty credentials because
	 * the extension never exposes them to PHP — producing an unreadable
	 * config.enc on the node ("failed to read config.enc").
	 *
	 * @return bool True on success; on failure marks the server as errored (status 4).
	 */
	public static function provisionConfig($rConn, callable $rRunSSH, callable $rSendFileSSH, array $rServers, int $rServerID, $db): bool {
		echo "Generating configuration file\n";

		// Generate the node's install_id AS root. At this point in the flow the
		// bundled PHP under bin/ is still root-owned (ownership is handed to
		// xc_vm later in the install, after provisionConfig), so running php as
		// xc_vm here cannot load the xcvm_core extension — XC_VM is undefined and
		// install_id() prints nothing ("Failed to read install_id"). Root can
		// always load the extension.
		//
		// install_id() creates config/install_id on its first call; the at-rest
		// config.enc key is derived from its VALUE, not from the creating user
		// (SHA-256("xcvm_cfg_v1" || install_id || machine_id)). So we create it as
		// root and hand the whole config/ dir to xc_vm at the end, letting FPM
		// (xc_vm) read both install_id and config.enc on boot. If install_id
		// stayed root-owned, config.enc would fail to decrypt and the extension
		// would silently fall back to a default config (server_id=1, is_lb=0).
		call_user_func($rRunSSH, $rConn, 'sudo mkdir -p ' . CONFIG_PATH);
		$rIdResult = call_user_func($rRunSSH, $rConn, 'sudo ' . PHP_BIN . ' -r ' . escapeshellarg('echo XC_VM::install_id();'));
		$rInstallId = trim($rIdResult['output'] ?? '');
		if ($rInstallId === '') {
			$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
			echo "Failed to read install_id from node! Exiting\n";
			$rIdErr = trim($rIdResult['error'] ?? '');
			if ($rIdErr !== '') {
				echo $rIdErr . "\n";
			}
			return false;
		}

		// Pack config.enc targeted at the node's install_id. Credentials are
		// read from MAIN's config.enc inside the extension, never exposed here.
		$rBlob = \XC_VM::config_pack($rInstallId, array(
			'hostname'  => $rServers[SERVER_ID]['server_ip'],
			'database'  => 'xc_vm',
			'port'      => intval(ConfigReader::get('port')),
			'server_id' => $rServerID,
			'is_lb'     => 1,
		));
		if (empty($rBlob)) {
			$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
			echo "Failed to pack node configuration! Exiting\n";
			return false;
		}

		// Ship the ready-to-use config.enc. Drop any stale config.ini first so the
		// extension does not migrate it over our blob.
		call_user_func($rRunSSH, $rConn, 'sudo rm -f ' . CONFIG_PATH . 'config.ini');
		$rTmp = TMP_PATH . 'config_' . $rServerID . '.enc';
		file_put_contents($rTmp, $rBlob);
		$rOk = call_user_func($rSendFileSSH, $rConn, $rTmp, CONFIG_PATH . 'config.enc', false);
		@unlink($rTmp);
		if (!$rOk) {
			$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
			echo "Failed to upload node configuration! Exiting\n";
			return false;
		}
		// install_id was created by root above and SCP writes config.enc as root;
		// hand the whole config/ dir to xc_vm so FPM can read install_id and
		// re-encrypt the transport blob to at-rest format on first read.
		call_user_func($rRunSSH, $rConn, 'sudo chown -R xc_vm:xc_vm ' . CONFIG_PATH);
		call_user_func($rRunSSH, $rConn, 'sudo chmod 600 ' . CONFIG_PATH . 'config.enc');

		return true;
	}

	public static function configureRuntime($rConn, callable $rSendFileSSH, callable $rRunSSH, array $rServers, int $rServerID): int {
		call_user_func($rSendFileSSH, $rConn, MAIN_HOME . 'bin/nginx/conf/custom.conf', MAIN_HOME . 'bin/nginx/conf/custom.conf', false);
		call_user_func($rSendFileSSH, $rConn, MAIN_HOME . 'bin/nginx/conf/realip_cdn.conf', MAIN_HOME . 'bin/nginx/conf/realip_cdn.conf', false);
		call_user_func($rSendFileSSH, $rConn, MAIN_HOME . 'bin/nginx/conf/realip_cloudflare.conf', MAIN_HOME . 'bin/nginx/conf/realip_cloudflare.conf', false);
		call_user_func($rSendFileSSH, $rConn, MAIN_HOME . 'bin/nginx/conf/realip_xc_vm.conf', MAIN_HOME . 'bin/nginx/conf/realip_xc_vm.conf', false);
		call_user_func($rRunSSH, $rConn, 'sudo echo "" > "/home/xc_vm/bin/nginx/conf/limit.conf"');
		call_user_func($rRunSSH, $rConn, 'sudo echo "" > "/home/xc_vm/bin/nginx/conf/limit_queue.conf"');
		$rIP = '127.0.0.1:' . $rServers[$rServerID]['http_broadcast_port'];
		call_user_func($rRunSSH, $rConn, 'sudo echo "on_play http://' . $rIP . '/stream/rtmp; on_publish http://' . $rIP . '/stream/rtmp; on_play_done http://' . $rIP . '/stream/rtmp;" > "/home/xc_vm/bin/nginx_rtmp/conf/live.conf"');
		$rServices = (intval(call_user_func($rRunSSH, $rConn, 'sudo cat /proc/cpuinfo | grep "^processor" | wc -l')['output']) ?: 4);
		call_user_func($rRunSSH, $rConn, 'sudo rm ' . MAIN_HOME . 'bin/php/etc/*.conf');
		$rNewScript = '#! /bin/bash' . "\n";
		$rNewBalance = 'upstream php {' . "\n" . '    least_conn;' . "\n";
		$rTemplate = file_get_contents(MAIN_HOME . 'bin/php/etc/template');
		foreach (range(1, $rServices) as $i) {
			$rNewScript .= 'start-stop-daemon --start --quiet --pidfile ' . MAIN_HOME . 'bin/php/sockets/' . $i . '.pid --exec ' . MAIN_HOME . 'bin/php/sbin/php-fpm -- --daemonize --fpm-config ' . MAIN_HOME . 'bin/php/etc/' . $i . '.conf' . "\n";
			$rNewBalance .= '    server unix:' . MAIN_HOME . 'bin/php/sockets/' . $i . '.sock;' . "\n";
			$rTmpPath = TMP_PATH . md5(time() . $i . '.conf');
			file_put_contents($rTmpPath, str_replace('#PATH#', MAIN_HOME, str_replace('#ID#', (string) $i, $rTemplate)));
			call_user_func($rSendFileSSH, $rConn, $rTmpPath, MAIN_HOME . 'bin/php/etc/' . $i . '.conf', false);
		}
		$rNewBalance .= '}';
		$rTmpPath = TMP_PATH . md5(time() . 'daemons.sh');
		file_put_contents($rTmpPath, $rNewScript);
		call_user_func($rSendFileSSH, $rConn, $rTmpPath, MAIN_HOME . 'bin/daemons.sh', false);
		$rTmpPath = TMP_PATH . md5(time() . 'balance.conf');
		file_put_contents($rTmpPath, $rNewBalance);
		call_user_func($rSendFileSSH, $rConn, $rTmpPath, MAIN_HOME . 'bin/nginx/conf/balance.conf', false);
		call_user_func($rRunSSH, $rConn, 'sudo chmod +x ' . MAIN_HOME . 'bin/daemons.sh');
		call_user_func($rRunSSH, $rConn, 'sudo chmod 0777 /home/xc_vm/bin');

		return $rServices;
	}

	public static function runStartup($rConn, callable $rRunSSH): void {
		// Fix ownership BEFORE any PHP runs, so the extension never creates or reads
		// install_id / config.enc as root. A root-owned install_id is unreadable by
		// FPM (xc_vm) and makes config.enc decryption fall back to a default config.
		call_user_func($rRunSSH, $rConn, 'sudo chown xc_vm:xc_vm -R /home/xc_vm >/dev/null 2>&1');
		call_user_func($rRunSSH, $rConn, 'sudo -u xc_vm ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php status 1');
		call_user_func($rRunSSH, $rConn, 'sudo -u xc_vm ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php startup');
		call_user_func($rRunSSH, $rConn, 'sudo -u xc_vm ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:servers');
	}

	private static function getDistributionBinaryName(string $rDistID, string $rVersion): ?string {
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

	private static function installDistributionBinaries($rConn, callable $rRunSSH, string $rDistID, string $rVersion): bool {
		$rBinaryName = self::getDistributionBinaryName($rDistID, $rVersion);
		if ($rBinaryName === null) {
			echo "Unsupported distribution for binaries: {$rDistID} {$rVersion}\n";
			return false;
		}

		$rTagCmd = 'curl -s https://api.github.com/repos/' . GIT_OWNER . '/' . GIT_REPO_BIN . '/releases/latest';
		$rTag = trim(call_user_func($rRunSSH, $rConn, $rTagCmd . ' | grep ' . "'\"tag_name\"'" . ' | sed -E ' . "'s/.*\"([^\"]+)\".*/\\1/'")['output']);
		if (empty($rTag)) {
			echo "Failed to get latest binaries release tag\n";
			return false;
		}

		$rURL = 'https://github.com/' . GIT_OWNER . '/' . GIT_REPO_BIN . '/releases/download/' . $rTag . '/' . $rBinaryName;
		echo "Downloading {$rBinaryName} from release {$rTag}\n";
		call_user_func($rRunSSH, $rConn, 'wget -q --timeout=30 -O /tmp/xc_vm_bin.tar.gz "' . $rURL . '"');

		$rCheck = trim(call_user_func($rRunSSH, $rConn, 'test -s /tmp/xc_vm_bin.tar.gz && echo OK')['output']);
		if ($rCheck !== 'OK') {
			echo "Failed to download distribution binaries\n";
			return false;
		}

		$rHashURL = 'https://github.com/' . GIT_OWNER . '/' . GIT_REPO_BIN . '/releases/download/' . $rTag . '/hashes.md5';
		$rHashContent = trim(call_user_func($rRunSSH, $rConn, 'curl -sL --max-time 15 "' . $rHashURL . '"')['output']);
		$rExpectedHash = null;
		if (!empty($rHashContent)) {
			foreach (explode("\n", $rHashContent) as $rLine) {
				$rLine = trim($rLine);
				if (empty($rLine)) {
					continue;
				}
				$rParts = preg_split('/\s+/', $rLine, 2);
				if (count($rParts) !== 2) {
					continue;
				}

				$rAssetName = ltrim(trim($rParts[1]), '*');
				if (strpos($rAssetName, './') === 0) {
					$rAssetName = substr($rAssetName, 2);
				}

				if ($rAssetName === $rBinaryName) {
					$rExpectedHash = $rParts[0];
					break;
				}
			}
		}

		if ($rExpectedHash !== null) {
			$rActualHash = trim(explode(' ', call_user_func($rRunSSH, $rConn, 'md5sum /tmp/xc_vm_bin.tar.gz')['output'])[0]);
			if ($rActualHash !== $rExpectedHash) {
				echo "MD5 verification failed for {$rBinaryName}: expected {$rExpectedHash}, got {$rActualHash}\n";
				call_user_func($rRunSSH, $rConn, 'rm -f /tmp/xc_vm_bin.tar.gz');
				return false;
			}
			echo "MD5 verification passed for {$rBinaryName}\n";
		} else {
			echo "Warning: Could not retrieve MD5 hash for {$rBinaryName}, skipping verification\n";
		}

		echo "Extracting distribution binaries\n";
		call_user_func($rRunSSH, $rConn, 'sudo rm -rf /tmp/xc_vm_bin && mkdir -p /tmp/xc_vm_bin');
		call_user_func($rRunSSH, $rConn, 'sudo tar -xzf /tmp/xc_vm_bin.tar.gz -C /tmp/xc_vm_bin');

		$rSourceDir = trim(call_user_func($rRunSSH, $rConn, 'find /tmp/xc_vm_bin -maxdepth 3 -type d -name php -print -quit 2>/dev/null | xargs dirname 2>/dev/null')['output']);
		if (empty($rSourceDir) || $rSourceDir === '.') {
			echo "Could not find binary structure in archive\n";
			call_user_func($rRunSSH, $rConn, 'sudo rm -rf /tmp/xc_vm_bin.tar.gz /tmp/xc_vm_bin');
			return false;
		}

		echo "Installing binaries from {$rSourceDir}\n";
		call_user_func($rRunSSH, $rConn, 'sudo cp -rf ' . $rSourceDir . '/* ' . BIN_PATH);

		call_user_func($rRunSSH, $rConn, 'sudo chmod 0551 ' . MAIN_HOME . 'bin/php/bin/php');
		call_user_func($rRunSSH, $rConn, 'sudo chmod 0551 ' . MAIN_HOME . 'bin/php/sbin/php-fpm');
		call_user_func($rRunSSH, $rConn, 'sudo chmod 0550 ' . MAIN_HOME . 'bin/nginx/sbin/nginx');
		call_user_func($rRunSSH, $rConn, 'sudo chmod 0750 ' . MAIN_HOME . 'bin/nginx_rtmp/sbin/nginx_rtmp');

		$rVersionFile = BIN_PATH . 'bin_version.json';
		$rVersionData = array(
			'owner' => GIT_OWNER,
			'repository' => GIT_REPO_BIN,
			'release' => $rTag,
			'asset' => $rBinaryName,
			'distribution' => $rDistID,
			'distribution_version' => $rVersion,
			'updated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
		);
		$rVersionJson = json_encode($rVersionData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($rVersionJson !== false) {
			$rEncodedVersion = base64_encode($rVersionJson);
			call_user_func($rRunSSH, $rConn, 'echo ' . escapeshellarg($rEncodedVersion) . ' | base64 -d | sudo tee ' . escapeshellarg($rVersionFile) . ' > /dev/null');
			call_user_func($rRunSSH, $rConn, 'sudo chown xc_vm:xc_vm ' . escapeshellarg($rVersionFile));
		} else {
			echo "Warning: Failed to encode binaries version metadata\n";
		}

		call_user_func($rRunSSH, $rConn, 'sudo rm -rf /tmp/xc_vm_bin.tar.gz /tmp/xc_vm_bin');
		echo "Distribution-specific binaries installed successfully\n";
		return true;
	}
}
