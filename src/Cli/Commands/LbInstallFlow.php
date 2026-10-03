<?php

namespace XcVm\Cli\Commands;

use XcVm\Core\Cluster\AgentPaths;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\CredentialFreeConfig;
use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterRefusedException;
use XcVm\Core\Cluster\RootPin;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Process\PhpFpmPools;
use XcVm\Core\Updates\GitHubReleases;
use XcVm\Core\Updates\ReleaseAsset;
use XcVm\Core\Updates\UpdateChannels;
use XcVm\Domain\Cluster\ClusterCli;
use XcVm\Domain\Cluster\ClusterPolicy;
use XcVm\Domain\Cluster\CorePins;
use XcVm\Domain\Cluster\DbCredentials;
use XcVm\Domain\Cluster\EnrolmentService;
use XcVm\Domain\Cluster\LeaseService;

class LbInstallFlow {
	// Per-distribution package lists, mirrored from the MAIN installer (install -> PACKAGES),
	// with the mariadb-server/client/common packages removed (LB nodes use the main server's DB).
	public static function getPackages(string $rDistID = 'debian', string $rVersion = ''): array {
		$rLists = [
			'debian' => ['iproute2', 'net-tools', 'dirmngr', 'gpg-agent', 'software-properties-common', 'libcurl4', 'libgeoip-dev', 'libxslt1-dev', 'libonig-dev', 'e2fsprogs', 'wget', 'sysstat', 'alsa-utils', 'v4l-utils', 'certbot', 'iptables-persistent', 'libjpeg-dev', 'libpng-dev', 'libharfbuzz-dev', 'libfribidi-dev', 'libogg0', 'libnuma1', 'xz-utils', 'zip', 'unzip', 'libssh2-1', 'libsodium23', 'cpufrequtils', 'mcrypt', 'cron', 'git', 'curl'],
			'debian13' => ['iproute2', 'net-tools', 'dirmngr', 'gpg-agent', 'software-properties-common', 'libcurl4', 'wget', 'curl', 'unzip', 'zip', 'xz-utils', 'cron', 'git', 'sysstat', 'perl', 'gawk', 'socat', 'libxml2-dev', 'libxslt1-dev', 'libonig5', 'libonig-dev', 'zlib1g-dev', 'libssl-dev', 'pkg-config', 'autoconf', 'automake', 'alsa-utils', 'v4l-utils', 'e2fsprogs', 'certbot', 'iptables-persistent', 'libssh2-1', 'libssh2-1-dev', 'libjpeg-dev', 'libpng-dev', 'libharfbuzz-dev', 'libfribidi-dev', 'libgeoip1', 'geoip-bin', 'libsodium23', 'cpufrequtils', 'mcrypt', 'libogg0', 'libnuma1'],
			'ubuntu20' => ['iproute2', 'net-tools', 'dirmngr', 'gpg-agent', 'software-properties-common', 'wget', 'curl', 'unzip', 'zip', 'xz-utils', 'cron', 'git', 'sysstat', 'ca-certificates', 'libcurl3-gnutls', 'libcurl4-gnutls-dev', 'libxml2-dev', 'libxslt1-dev', 'libonig5', 'libonig-dev', 'libjpeg-dev', 'libpng-dev', 'zlib1g-dev', 'alsa-utils', 'v4l-utils', 'e2fsprogs', 'iptables-persistent', 'certbot', 'python3-certbot', 'libssh2-1', 'libssh2-1-dev', 'libsodium23', 'cpufrequtils', 'mcrypt', 'libogg0', 'libnuma1'],
			'ubuntu22' => ['iproute2', 'net-tools', 'dirmngr', 'gpg-agent', 'software-properties-common', 'libcurl4', 'libcurl3-gnutls', 'libgeoip-dev', 'libxslt1-dev', 'libonig-dev', 'e2fsprogs', 'wget', 'curl', 'unzip', 'zip', 'xz-utils', 'cron', 'git', 'sysstat', 'ca-certificates', 'libxml2-dev', 'libonig5', 'zlib1g-dev', 'alsa-utils', 'v4l-utils', 'certbot', 'python3-certbot', 'iptables-persistent', 'libjpeg-dev', 'libpng-dev', 'libharfbuzz-dev', 'libfribidi-dev', 'libogg0', 'libnuma1', 'libssh2-1', 'libssh2-1-dev', 'libsodium23', 'cpufrequtils', 'mcrypt'],
			'ubuntu24' => ['iproute2', 'net-tools', 'dirmngr', 'gpg-agent', 'software-properties-common', 'libcurl4t64', 'wget', 'curl', 'unzip', 'zip', 'xz-utils', 'cron', 'git', 'sysstat', 'perl', 'gawk', 'socat', 'libxml2-dev', 'libxslt1-dev', 'libonig5', 'libonig-dev', 'zlib1g-dev', 'libssl-dev', 'pkg-config', 'autoconf', 'automake', 'alsa-utils', 'v4l-utils', 'e2fsprogs', 'certbot', 'python3-certbot', 'ufw', 'libssh2-1t64', 'libssh2-1-dev', 'libjpeg-dev', 'libpng-dev', 'libharfbuzz-dev', 'libfribidi-dev', 'libgeoip1t64', 'geoip-bin', 'libsodium23', 'cpufrequtils', 'mcrypt', 'libogg0', 'libnuma1'],
			'redhat' => ['epel-release', 'wget', 'sysstat', 'alsa-utils', 'v4l-utils', 'libcurl-devel', 'geoip-devel', 'libxslt-devel', 'oniguruma-devel', 'e2fsprogs', 'libjpeg-turbo-devel', 'libpng-devel', 'harfbuzz-devel', 'fribidi-devel', 'libogg', 'xz', 'zip', 'unzip', 'libssh2-devel', 'cronie', 'certbot', 'iptables-services', 'GeoIP-update', 'git', 'curl', 'libsodium', 'numactl', 'kernel-tools'],
		];

		$rKey = self::resolvePackageKey(strtolower(trim($rDistID)), explode('.', $rVersion)[0]);
		return $rLists[$rKey] ?? $rLists['debian'];
	}

	// Mirrors the MAIN installer's _PACKAGE_KEY_MAP: (dist_id, major_version) -> package list key.
	private static function resolvePackageKey(string $rDistID, string $rMajor): string {
		if (in_array($rDistID, ['rocky', 'almalinux', 'rhel', 'centos', 'redhat', 'fedora'], true)) {
			return 'redhat';
		}
		$rMap = [
			'ubuntu' => ['18' => 'ubuntu20', '20' => 'ubuntu20', '22' => 'ubuntu22', '24' => 'ubuntu24'],
			'debian' => ['12' => 'debian', '13' => 'debian13'],
		];
		return $rMap[$rDistID][$rMajor] ?? 'debian';
	}

	/**
	 * The load-balancer archive of MAIN's own release, from GitHub: a node runs
	 * MAIN's release, never a newer one, as UpdateCommand pins its updates. The
	 * md5 is null when the release lists no such asset or cannot be read.
	 *
	 * @return array{url: string, md5: ?string}
	 */
	public static function resolveUpdateData(GitHubReleases $gitRelease): array {
		$rUpdateData = $gitRelease->getVersionFile('lb', XC_VM_VERSION);
		return [
			'url' => $rUpdateData['url'],
			'md5' => $rUpdateData['md5'],
		];
	}

	/** Non-secret install parameters for reinstalls; the password is never stored. */
	public static function writeInstallMetadata(string $rInstallDir, int $rServerID, string $rUsername, int $rPort): void {
		file_put_contents($rInstallDir . $rServerID . '.json', json_encode(['root_username' => $rUsername, 'ssh_port' => $rPort]));
	}

	/**
	 * The shell line that writes $rText (and a newline) to $rPath as root
	 * over SSH, appending when $rAppend. A `sudo echo` redirected to it runs
	 * only the echo as root: the redirect is opened by the SSH user's shell,
	 * so it fails unless that user is root. `tee` is what runs under sudo.
	 */
	public static function sudoWrite(string $rText, string $rPath, bool $rAppend = false): string {
		return 'echo ' . escapeshellarg($rText) . ' | sudo tee ' . ($rAppend ? '-a ' : '') . escapeshellarg($rPath) . ' > /dev/null';
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

	/** False when the node could not get its PHP and nginx: the archive carries neither. */
	public static function runPostExtractSteps($rConn, callable $rRunSSH, callable $rSendFileSSH, string $rDistID, string $rVersion, int $rUpdateSysctl, string $rSysCtl, int $rServerID): bool {
		echo "Installing distribution-specific binaries\n";
		if (!self::installDistributionBinaries($rConn, $rRunSSH, $rDistID, $rVersion)) {
			echo "Failed to install the distribution binaries (PHP, nginx) from GitHub. Exiting\n";
			return false;
		}
		self::installExtension($rConn, $rRunSSH, $rSendFileSSH);

		if (stripos(call_user_func($rRunSSH, $rConn, 'sudo cat /etc/fstab')['output'], STREAMS_PATH) === false) {
			echo "Adding ramdisk mounts\n";
			call_user_func($rRunSSH, $rConn, self::sudoWrite('tmpfs ' . STREAMS_PATH . ' tmpfs defaults,noatime,nosuid,nodev,noexec,mode=1777,size=90% 0 0', '/etc/fstab', true));
			call_user_func($rRunSSH, $rConn, self::sudoWrite('tmpfs ' . TMP_PATH . ' tmpfs defaults,noatime,nosuid,nodev,noexec,mode=1777,size=2G 0 0', '/etc/fstab', true));
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
		return true;
	}

	/**
	 * Install xcvm_core on the node. The binaries bundle does not carry it, the
	 * LB archive has no bin/install, and the node's console.php cannot boot
	 * without it, so MAIN sends its own installer script over.
	 */
	private static function installExtension($rConn, callable $rRunSSH, callable $rSendFileSSH): void {
		echo "Installing the xcvm_core PHP extension\n";
		$rScript = '/tmp/install_xcvm_core.sh';
		if (!call_user_func($rSendFileSSH, $rConn, MAIN_HOME . 'bin/install/install_xcvm_core.sh', $rScript, true)) {
			// Not fatal: the binaries bundle carries an xcvm_core, and the node's hourly check brings it current.
			echo 'Could not send ' . MAIN_HOME . "bin/install/install_xcvm_core.sh: the node keeps the xcvm_core of its binaries bundle for now\n";
			return;
		}
		$rArgs = implode(' ', array_map('escapeshellarg', [GIT_OWNER, GIT_REPO_BIN, BIN_PATH]));
		echo call_user_func($rRunSSH, $rConn, 'sudo bash ' . $rScript . ' ' . $rArgs . ' 2>&1; rm -f ' . $rScript)['output'] . "\n";
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
	public static function provisionConfig($rConn, callable $rRunSSH, callable $rSendFileSSH, array $rServers, int $rServerID, $db, ?bool $rApiMode = null): bool {
		echo "Generating configuration file\n";
		$rApiMode ??= self::installsInApiMode(SettingsManager::getAll(), $rServerID);
		if ($rApiMode && !CredentialFreeConfig::supported()) {
			// Refused before anything is written: an older extension would pack
			// MAIN's credentials into a node meant never to hold them.
			$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
			echo "This node installs in API mode (lb_new_node_mode is api, or MAIN keeps it free of its credentials: cluster mode 2 or a revoked grant), but this panel's xcvm_core cannot pack a configuration without MAIN's credentials (it needs install_config). Update the extension. Exiting\n";
			return false;
		}

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
		// A freshly installed PHP may print warnings before the id, which
		// config_pack() would refuse as part of it.
		$rInstallId = self::lastLine($rIdResult);
		if (!CorePins::validInstallId($rInstallId)) {
			$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
			echo "Failed to read install_id from node! Exiting\n";
			foreach (['output', 'error'] as $rStream) {
				$rText = trim((string) ($rIdResult[$rStream] ?? ''));
				if ($rText !== '') {
					echo $rText . "\n";
				}
			}
			return false;
		}

		// Pack config.enc targeted at the node's install_id. Credentials are
		// read from MAIN's config.enc inside the extension, never exposed here;
		// in API mode there are none (CredentialFreeConfig).
		$rPackParams = self::configPackParams($rServers, $rServerID);
		$rBlob = $rApiMode ? CredentialFreeConfig::pack($rInstallId, $rPackParams) : \XC_VM::config_pack($rInstallId, $rPackParams);
		if (empty($rBlob)) {
			$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
			$rWhy = error_get_last()['message'] ?? 'no warning';
			echo 'Failed to pack node configuration (install_id ' . $rInstallId . ', hostname ' . var_export($rPackParams['hostname'], true) . ', api mode ' . ($rApiMode ? 'yes' : 'no') . ', ' . $rWhy . ")! Exiting\n";
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
		if (!self::provisionOpensslExtra($rConn, $rRunSSH, $rSendFileSSH, CONFIG_PATH . 'openssl_extra')) {
			$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
			echo "Failed to upload OPENSSL_EXTRA! Exiting\n";
			return false;
		}
		// install_id was created by root above and SCP writes config.enc as root;
		// hand the whole config/ dir to xc_vm so FPM can read install_id and
		// re-encrypt the transport blob to at-rest format on first read.
		call_user_func($rRunSSH, $rConn, 'sudo chown -R xc_vm:xc_vm ' . CONFIG_PATH);
		call_user_func($rRunSSH, $rConn, 'sudo chmod 600 ' . CONFIG_PATH . 'config.enc');

		return true;
	}

	/**
	 * Is this install in API mode? (ClusterSettings::newNodesInApiMode)
	 *
	 * @param array<string, mixed> $rSettings
	 */
	public static function apiMode(array $rSettings): bool {
		return ClusterSettings::newNodesInApiMode($rSettings);
	}

	/**
	 * Does this node install (or re-enrol) in API mode? New nodes do when
	 * `lb_new_node_mode` is api (apiMode()). A node MAIN already keeps
	 * credential-free — in cluster mode 2, or with its grant revoked
	 * (DbCredentials::credentialFree) — does too, whatever the setting: a
	 * reinstall over SSH must not hand it MAIN's credentials and grant back,
	 * nor drop it to mode 1. Ask before the enrolment, which replaces the
	 * node's row.
	 *
	 * @param array<string, mixed> $rSettings
	 */
	public static function installsInApiMode(array $rSettings, int $rServerID): bool {
		return self::apiMode($rSettings) || (!empty($rSettings['cluster_api_enabled']) && DbCredentials::credentialFree($rServerID));
	}

	/**
	 * The server parameters config_pack() builds the node's config.enc from:
	 * MAIN's address for the node to reach the DB at, and the node's own
	 * server_id and is_lb. There is no `port`: ConfigReader holds only the
	 * `server` section (server_id, is_lb), so the DB port was always 0 there,
	 * which config_pack() refuses. Without one, config_pack() packs its
	 * documented default, 3306 (the extension's ADR-001) — the port the
	 * installer runs MAIN's MariaDB on.
	 *
	 * @param array<int, array<string, mixed>> $rServers
	 * @return array<string, mixed>
	 */
	public static function configPackParams(array $rServers, int $rServerID): array {
		return [
			'hostname'  => $rServers[SERVER_ID]['server_ip'],
			'database'  => 'xc_vm',
			'server_id' => $rServerID,
			'is_lb'     => 1,
		];
	}

	/**
	 * Give the node the MAIN's OPENSSL_EXTRA, which keys the stream tokens MAIN
	 * mints for the redirects the node serves.
	 *
	 * The MAIN's config/openssl_extra ($rLocalFile) is shipped when it holds a
	 * value; without one the MAIN runs on the built-in default and so does the
	 * node. Whatever a previous MAIN left on a reused host is removed first.
	 * Runs before config/ is handed to xc_vm, which makes the file readable by FPM.
	 *
	 * @return bool False only when the upload failed.
	 */
	public static function provisionOpensslExtra($rConn, callable $rRunSSH, callable $rSendFileSSH, string $rLocalFile): bool {
		call_user_func($rRunSSH, $rConn, 'sudo rm -f ' . CONFIG_PATH . 'openssl_extra ' . CONFIG_PATH . 'openssl_extra.prev');
		if (!is_file($rLocalFile) || trim((string) @file_get_contents($rLocalFile)) === '') {
			return true;
		}
		if (!call_user_func($rSendFileSSH, $rConn, $rLocalFile, CONFIG_PATH . 'openssl_extra', false)) {
			return false;
		}
		call_user_func($rRunSSH, $rConn, 'sudo chmod 600 ' . CONFIG_PATH . 'openssl_extra');

		return true;
	}

	public static function configureRuntime($rConn, callable $rSendFileSSH, callable $rRunSSH, array $rServers, int $rServerID): int {
		call_user_func($rSendFileSSH, $rConn, MAIN_HOME . 'bin/nginx/conf/custom.conf', MAIN_HOME . 'bin/nginx/conf/custom.conf', false);
		call_user_func($rSendFileSSH, $rConn, MAIN_HOME . 'bin/nginx/conf/realip_cdn.conf', MAIN_HOME . 'bin/nginx/conf/realip_cdn.conf', false);
		call_user_func($rSendFileSSH, $rConn, MAIN_HOME . 'bin/nginx/conf/realip_cloudflare.conf', MAIN_HOME . 'bin/nginx/conf/realip_cloudflare.conf', false);
		call_user_func($rSendFileSSH, $rConn, MAIN_HOME . 'bin/nginx/conf/realip_xc_vm.conf', MAIN_HOME . 'bin/nginx/conf/realip_xc_vm.conf', false);
		call_user_func($rRunSSH, $rConn, self::sudoWrite('', '/home/xc_vm/bin/nginx/conf/limit.conf'));
		call_user_func($rRunSSH, $rConn, self::sudoWrite('', '/home/xc_vm/bin/nginx/conf/limit_queue.conf'));
		$rIP = '127.0.0.1:' . $rServers[$rServerID]['http_broadcast_port'];
		call_user_func($rRunSSH, $rConn, self::sudoWrite('on_play http://' . $rIP . '/stream/rtmp; on_publish http://' . $rIP . '/stream/rtmp; on_play_done http://' . $rIP . '/stream/rtmp;', '/home/xc_vm/bin/nginx_rtmp/conf/live.conf'));
		$rServices = (intval(call_user_func($rRunSSH, $rConn, 'sudo cat /proc/cpuinfo | grep "^processor" | wc -l')['output']) ?: 4);
		call_user_func($rRunSSH, $rConn, 'sudo rm ' . MAIN_HOME . 'bin/php/etc/*.conf');
		// The pool configs, daemons.sh and balance.conf, each through a
		// temporary file under an unpredictable name.
		foreach (PhpFpmPools::files($rServices, (string) file_get_contents(MAIN_HOME . 'bin/php/etc/template'), MAIN_HOME) as $rPath => $rBody) {
			$rTmpPath = TMP_PATH . bin2hex(random_bytes(16)) . '_' . basename($rPath);
			file_put_contents($rTmpPath, $rBody);
			call_user_func($rSendFileSSH, $rConn, $rTmpPath, $rPath, false);
		}
		call_user_func($rRunSSH, $rConn, 'sudo chmod +x ' . MAIN_HOME . 'bin/daemons.sh');
		call_user_func($rRunSSH, $rConn, 'sudo chmod 0777 /home/xc_vm/bin');

		return $rServices;
	}

	public static function runStartup($rConn, callable $rRunSSH): void {
		// Fix ownership BEFORE any PHP runs, so the extension never creates or reads
		// install_id / config.enc as root. A root-owned install_id is unreadable by
		// FPM (xc_vm) and makes config.enc decryption fall back to a default config.
		call_user_func($rRunSSH, $rConn, 'sudo chown xc_vm:xc_vm -R /home/xc_vm >/dev/null 2>&1');
		// No `status 1` here: it runs as root only, and the service restart before
		// this ran it (`startup`, as root).
		call_user_func($rRunSSH, $rConn, 'sudo -u xc_vm ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php startup');
		call_user_func($rRunSSH, $rConn, 'sudo -u xc_vm ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:servers');
		// The node's ffmpeg builds, which no release archive carries: before its
		// first stream (cron:root_signals keeps them current daily).
		call_user_func($rRunSSH, $rConn, 'sudo -u xc_vm ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php ffmpeg');
	}

	/**
	 * Resolve a repo's latest release tag from the github.com `/releases/latest`
	 * redirect (302 → …/releases/tag/<TAG>) using cURL on MAIN. Unlike the REST
	 * API this is not rate-limited, so it is a reliable fallback during installs.
	 *
	 * @return string The tag, or '' when it cannot be resolved.
	 */
	private static function latestReleaseTagViaRedirect(string $rOwner, string $rRepo): string {
		$rCurl = curl_init('https://github.com/' . $rOwner . '/' . $rRepo . '/releases/latest');
		curl_setopt_array($rCurl, [
			CURLOPT_NOBODY         => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => 20,
			CURLOPT_USERAGENT      => 'XC_VM',
		]);
		$rHeaders = (string) curl_exec($rCurl);
		$rLocation = (string) curl_getinfo($rCurl, CURLINFO_REDIRECT_URL);
		curl_close($rCurl);

		if ($rLocation === '' && preg_match('~^location:\s*(\S+)~im', $rHeaders, $rM)) {
			$rLocation = trim($rM[1]);
		}
		if ($rLocation !== '' && preg_match('~/releases/tag/([^/\s]+)~', $rLocation, $rM)) {
			return trim($rM[1]);
		}
		return '';
	}

	private static function installDistributionBinaries($rConn, callable $rRunSSH, string $rDistID, string $rVersion): bool {
		$rBinaryName = ReleaseAsset::bundleFor($rDistID, $rVersion);
		if ($rBinaryName === null) {
			echo "Unsupported distribution for binaries: {$rDistID} {$rVersion}\n";
			return false;
		}

		// Resolve the release tag on MAIN, honouring the per-repository BIN channel,
		// so a BIN channel of `beta` provisions LB nodes with the newest pre-release
		// binaries (GitHub's `/releases/latest` only ever returns stable). Fall back
		// to the node-side `releases/latest` lookup if the API yields nothing.
		$rTag = '';
		try {
			$rBinRepo = new GitHubReleases(GIT_OWNER, GIT_REPO_BIN, UpdateChannels::bin());
			$rBinRepo->setTimeout(20);
			$rBinReleases = $rBinRepo->getReleases();
			if (!empty($rBinReleases[0])) {
				$rTag = trim($rBinReleases[0]);
			}
		} catch (\Throwable) {
			$rTag = '';
		}
		// Non-API fallback resolved on MAIN: the github.com `/releases/latest`
		// redirect is not subject to the 60/hour unauthenticated API rate limit
		// that can throttle the channel-aware lookup above during a busy install.
		if ($rTag === '') {
			$rTag = self::latestReleaseTagViaRedirect(GIT_OWNER, GIT_REPO_BIN);
		}
		// Last resort: resolve on the node itself (needs curl, in the package list).
		if ($rTag === '') {
			$rTagCmd = 'curl -s https://api.github.com/repos/' . GIT_OWNER . '/' . GIT_REPO_BIN . '/releases/latest';
			$rTag = trim(call_user_func($rRunSSH, $rConn, $rTagCmd . ' | grep ' . "'\"tag_name\"'" . ' | sed -E ' . "'s/.*\"([^\"]+)\".*/\\1/'")['output']);
		}
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
		$rVersionData = [
			'owner' => GIT_OWNER,
			'repository' => GIT_REPO_BIN,
			'release' => $rTag,
			'asset' => $rBinaryName,
			'distribution' => $rDistID,
			'distribution_version' => $rVersion,
			'updated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
		];
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

	/** Where the agent and its state live on the node. */
	public const AGENT_BIN = MAIN_HOME . 'bin/xc_agent/xc_agent';
	public const AGENT_STATE = CONFIG_PATH . AgentPaths::STATE;

	/**
	 * Enrol the new LB in the cluster API (MAIN ↔ LB plan, section 6), over the
	 * install's verified SSH session. Runs after runStartup(), when /home/xc_vm
	 * belongs to xc_vm.
	 *
	 * With the API disabled, no extension, or no agent binary on the node (it
	 * could not take one from the agent's GitHub release), the node stays
	 * legacy (mode 0) and the install goes on: it can be enrolled later.
	 * Otherwise:
	 *
	 * 1. the node installs the SHA-256-verified agent from its GitHub release
	 *    itself (`console.php fanout_binary agent`), as it keeps it current;
	 * 2. `xc_agent keygen` on the node — its private keys never leave it;
	 * 3. `xc_agent probe` — the node checks MAIN's signed health with the panel
	 *    key it got over SSH, before any token exists;
	 * 4. MAIN mints epoch 1 (EnrolmentService::issueFirst);
	 * 5. `xc_agent install` opens and checks the token, then the agent starts
	 *    and completes enrolment itself (`enrol_complete`).
	 *
	 * Only a refusal by an available extension, or a node that cannot reach
	 * MAIN's API, stops the install (status 4).
	 *
	 * @param bool $rMarkFailed Set status 4 on failure (a fresh install); false for `server:enrol` on a live node.
	 */
	public static function provisionCluster($rConn, callable $rRunSSH, callable $rSendFileSSH, array $rServers, int $rServerID, $db, ?ClusterCrypto $rCrypto = null, bool $rMarkFailed = true, ?bool $rApiMode = null): bool {
		$rSettings = SettingsManager::getAll();
		if (empty($rSettings['cluster_api_enabled'])) {
			return true;
		}
		// A node MAIN keeps credential-free re-enrols in mode 2 whatever
		// lb_new_node_mode says (installsInApiMode, asked before the row goes).
		if ($rApiMode ?? self::installsInApiMode($rSettings, $rServerID)) {
			$rSettings['lb_new_node_mode'] = 'api';
		}
		$rFail = static function (string $rWhy) use ($db, $rServerID, $rMarkFailed): bool {
			if ($rMarkFailed) {
				$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
			}
			echo $rWhy . "\n";
			return false;
		};
		// An API-mode node holds no DB grant and no credentials: without its
		// enrolment it could reach nothing, so it never "stays legacy".
		$rApiMode = self::apiMode($rSettings);
		if (!$rCrypto instanceof \XcVm\Core\Cluster\Crypto\ClusterCrypto) {
			$rCrypto = ClusterCli::crypto($rApiMode ? "Cluster API unavailable (%s)\n" : "Cluster API unavailable (%s); the node stays legacy\n");
			if ($rCrypto === null) {
				return $rApiMode ? $rFail('An API-mode node cannot run without the cluster API. Exiting') : true;
			}
		}
		echo "Enrolling the node in the cluster API\n";

		// A re-enrolment replaces the node's identity: stop a running agent (the
		// supervisor first, so it cannot respawn it) before its state changes.
		call_user_func($rRunSSH, $rConn, 'sudo pkill -u xc_vm -f ' . escapeshellarg(dirname(self::AGENT_BIN) . '/run.sh') . '; sudo pkill -u xc_vm -x xc_agent; true');
		call_user_func($rRunSSH, $rConn, 'sudo mkdir -p ' . escapeshellarg(dirname(self::AGENT_BIN)) . ' ' . escapeshellarg(dirname(self::AGENT_STATE)) . ' && sudo rm -f ' . escapeshellarg(dirname(self::AGENT_BIN) . '/stopped') . ' && sudo chown -R xc_vm:xc_vm ' . escapeshellarg(dirname(self::AGENT_BIN)) . ' ' . escapeshellarg(dirname(self::AGENT_STATE)) . ' && sudo chmod 0700 ' . escapeshellarg(dirname(self::AGENT_STATE)));
		// The node takes the agent from its GitHub release itself, as it keeps it current.
		$rGot = trim((string) call_user_func($rRunSSH, $rConn, 'sudo ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php fanout_binary agent 2>&1; test -x ' . escapeshellarg(self::AGENT_BIN) . ' && echo AGENT_OK')['output']);
		if (!str_ends_with($rGot, 'AGENT_OK')) {
			$rWhy = trim((string) strrchr("\n" . $rGot, "\n")) ?: 'it could not install one';
			if ($rApiMode) {
				return $rFail('No xc_agent on the node (' . $rWhy . '): an API-mode node cannot run without it. Exiting');
			}
			echo 'No xc_agent on the node (' . $rWhy . "); the node stays legacy and can be enrolled later\n";
			return true;
		}
		$rAgent = 'sudo -u xc_vm ' . escapeshellarg(self::AGENT_BIN);

		$rUuid = self::uuid4();
		$rOut = (string) call_user_func($rRunSSH, $rConn, $rAgent . ' keygen -state ' . escapeshellarg(self::AGENT_STATE) . ' -uuid ' . $rUuid)['output'];
		$rKeys = json_decode(trim($rOut), true);
		$rHex = static fn($rV) => is_string($rV) && preg_match('/^[0-9a-f]{64}$/', $rV) ? (string) hex2bin($rV) : null;
		$rSign = $rHex($rKeys['sign_pub'] ?? null);
		$rBox = $rHex($rKeys['box_pub'] ?? null);
		$rEph = $rHex($rKeys['eph_pub'] ?? null);
		if (($rKeys['node_uuid'] ?? null) !== $rUuid || $rSign === null || $rBox === null || $rEph === null) {
			return $rFail('xc_agent keygen failed on the node! Exiting');
		}
		$rSas = EnrolmentService::sas($rUuid, $rSign, $rBox);
		if (($rKeys['sas'] ?? null) !== $rSas) {
			return $rFail('The node\'s keys do not match their SAS! Exiting');
		}

		$rMain = $rServers[SERVER_ID] ?? [];
		$rPolicy = ClusterPolicy::current($rSettings, $rMain);
		$rPanelPub = (string) ($rCrypto->info()['panel_sign_pub'] ?? '');
		$rProbe = $rAgent . ' probe -panel-pub ' . bin2hex($rPanelPub);
		foreach ($rPolicy['main_urls'] as $rUrl) {
			$rProbe .= ' -url ' . escapeshellarg($rUrl);
		}
		$rProbed = call_user_func($rRunSSH, $rConn, $rProbe . ' 2>&1');
		if (!str_starts_with(trim((string) $rProbed['output']), 'OK ')) {
			return $rFail("The node cannot reach MAIN's cluster API (" . implode(', ', $rPolicy['main_urls']) . '): ' . trim((string) $rProbed['output']) . "\nOpen the cluster API port from the LB to MAIN, then reinstall. Exiting");
		}

		try {
			$rFirst = EnrolmentService::issueFirst($rCrypto, $rServerID, $rUuid, $rSign, $rBox, $rEph, $rSettings, $rMain);
		} catch (ClusterRefusedException $rE) {
			return $rFail(($rE->reason() === 'LICENCE' ? 'CLUSTER_LICENCE_REQUIRED' : 'Cluster token refused: ' . $rE->reason()) . '! Exiting');
		}
		$rInstall = (string) json_encode([
			'server_id' => $rServerID,
			'panel_sign_pub' => base64_encode($rPanelPub),
			'panel_box_pub' => base64_encode((string) ($rCrypto->info()['panel_box_pub'] ?? '')),
			'main_urls' => $rPolicy['main_urls'],
			'policy_ver' => $rPolicy['policy_ver'],
			'epoch' => 1,
			'token_sealed' => base64_encode($rFirst['token_sealed']),
		] + LeaseService::wire($rFirst['lease']), JSON_UNESCAPED_SLASHES);
		$rTmp = TMP_PATH . 'agent_install_' . $rServerID . '.json';
		$rRemote = '/tmp/xc_agent_install_' . $rServerID . '.json';
		file_put_contents($rTmp, $rInstall);
		$rSent = call_user_func($rSendFileSSH, $rConn, $rTmp, $rRemote, false);
		@unlink($rTmp);
		if (!$rSent) {
			return $rFail('Failed to upload the first cluster token! Exiting');
		}
		$rDone = call_user_func($rRunSSH, $rConn, 'sudo chown xc_vm:xc_vm ' . $rRemote . ' && ' . $rAgent . ' install -state ' . escapeshellarg(self::AGENT_STATE) . ' < ' . $rRemote . ' 2>&1; sudo rm -f ' . $rRemote);
		if (trim((string) $rDone['output']) !== 'OK') {
			return $rFail('xc_agent install failed on the node: ' . trim((string) $rDone['output']) . ' Exiting');
		}
		// Root's own pin of the panel key, for root commands (cluster:root): written
		// over this verified SSH session, root-owned, so nothing the panel's user
		// can write becomes root's trust anchor. A new pin starts root's seq afresh.
		$rPinDir = RootPin::DIR;
		call_user_func($rRunSSH, $rConn, 'sudo mkdir -p ' . escapeshellarg($rPinDir) . ' && sudo chown root:root /etc/xc_vm ' . escapeshellarg($rPinDir) . ' && sudo chmod 0755 /etc/xc_vm ' . escapeshellarg($rPinDir)
			. ' && echo ' . escapeshellarg(bin2hex($rPanelPub)) . ' | sudo tee ' . escapeshellarg($rPinDir . 'main_sign.pub') . ' >/dev/null'
			. ' && echo ' . escapeshellarg($rUuid) . ' | sudo tee ' . escapeshellarg($rPinDir . 'node') . ' >/dev/null'
			. ' && sudo chmod 0644 ' . escapeshellarg($rPinDir . 'main_sign.pub') . ' ' . escapeshellarg($rPinDir . 'node')
			. ' && sudo rm -f ' . escapeshellarg($rPinDir . 'root.seq')
			. ' && sudo -u xc_vm mkdir -p ' . escapeshellarg(dirname(self::AGENT_STATE) . '/root-inbox') . ' && sudo chmod 0700 ' . escapeshellarg(dirname(self::AGENT_STATE) . '/root-inbox'));
		// The extension's own pin of the panel key (core.pin), which its compiled
		// lease verdict needs: packed for the node's install_id and pinned over this
		// same verified session. Not fatal: MAIN pins it over the cluster API later.
		$rWhyNot = self::pinCore($rConn, $rRunSSH, $rCrypto, $rServerID);
		echo $rWhyNot === null ? "Panel key pinned in the node's xcvm_core\n" : 'The node\'s xcvm_core is not pinned yet (' . $rWhyNot . "); MAIN pins it once the node takes root commands\n";
		// Started, and seen running: a release without run.sh, or an agent that
		// exits at once, would otherwise leave a node that never completes.
		$rRunSh = escapeshellarg(MAIN_HOME . 'bin/xc_agent/run.sh');
		$rStart = call_user_func($rRunSSH, $rConn, 'if [ ! -f ' . $rRunSh . ' ]; then echo NO_RUNSH; else sudo -u xc_vm bash ' . $rRunSh . ' </dev/null >/dev/null 2>&1 & sleep 3; pgrep -u xc_vm -x xc_agent >/dev/null && echo STARTED; fi');
		$rStarted = trim((string) ($rStart['output'] ?? ''));
		if ($rStarted === 'NO_RUNSH') {
			return $rFail('The node has no bin/xc_agent/run.sh: its release predates the cluster agent. Install a newer release, then retry. Exiting');
		}
		if ($rStarted !== 'STARTED') {
			return $rFail('xc_agent did not start on the node (see ' . MAIN_HOME . 'bin/xc_agent/xc_agent.log there). Exiting');
		}
		echo 'Node enrolled (uuid ' . $rUuid . ', SAS ' . $rSas . "); it finishes with enrol_complete within 30 minutes\n";
		return true;
	}

	/**
	 * Pin MAIN's panel key in the node's xcvm_core over an SSH session (plan,
	 * section 6; the extension's ADR-002 "Pin"): read the node's install_id,
	 * pack the pin for it (`cluster_pack`, an XCVT blob only that install
	 * opens, for an hour) and pin it there at once, as root. The session's host
	 * key was verified, which is what makes this the authorised re-pin path, so
	 * a pin of an earlier MAIN is replaced. The node must answer with this
	 * panel's key, and the pin is recorded (CorePins), as is the node's
	 * install_id (`cluster_nodes.install_id`). Returns null when
	 * pinned, else why not — never fatal, and an extension without the cluster
	 * API on either side is only a reason.
	 */
	public static function pinCore($rConn, callable $rRunSSH, ClusterCrypto $rCrypto, int $rServerID): ?string {
		// install_id() would create a root-owned file were there none: ask only
		// when it exists (provisionConfig created it).
		$rAsk = 'echo is_file(' . var_export(CONFIG_PATH . 'install_id', true) . ') && class_exists("XC_VM") && method_exists("XC_VM", "cluster_pin") ? XC_VM::install_id() : "";';
		$rID = self::lastLine((array) call_user_func($rRunSSH, $rConn, 'sudo ' . PHP_BIN . ' -r ' . escapeshellarg($rAsk)));
		if (!CorePins::validInstallId($rID)) {
			return 'no install_id, or an xcvm_core without the cluster API on the node';
		}
		// Kept: what MAIN packs this node's pin and config for later (CorePins).
		CorePins::rememberInstallId($rServerID, $rID);
		try {
			$rPub = (string) ($rCrypto->info()['panel_sign_pub'] ?? '');
			$rBlob = $rCrypto->pack($rID);
		} catch (ClusterRefusedException $rE) {
			return 'cluster_pack refused: ' . $rE->reason();
		} catch (\Throwable $rE) {
			return 'cluster_pack failed: ' . $rE->getMessage();
		}
		$rPin = '$r = XC_VM::cluster_pin(base64_decode(' . var_export(base64_encode($rBlob), true) . '), true);'
			. ' echo $r === false ? "ERR " . XC_VM::cluster_last_error() : "OK " . hash("sha256", $r["panel_sign_pub"]);';
		$rOut = self::lastLine((array) call_user_func($rRunSSH, $rConn, 'sudo ' . PHP_BIN . ' -r ' . escapeshellarg($rPin)));
		$rFp = hash('sha256', $rPub);
		if ($rOut !== 'OK ' . $rFp) {
			return 'cluster_pin: ' . ($rOut === '' ? 'no answer' : substr($rOut, 0, 120));
		}
		CorePins::recorded($rServerID, $rFp, 'install');
		return null;
	}

	/** The last stdout line of an SSH command: what it echoed after any PHP warnings. */
	private static function lastLine(array $rOut): string {
		$rLines = preg_split('/\R/', trim((string) ($rOut['output'] ?? ''))) ?: [];
		return trim((string) end($rLines));
	}

	private static function uuid4(): string {
		$rB = random_bytes(16);
		$rB[6] = chr((ord($rB[6]) & 0x0f) | 0x40);
		$rB[8] = chr((ord($rB[8]) & 0x3f) | 0x80);
		$rH = bin2hex($rB);
		return substr($rH, 0, 8) . '-' . substr($rH, 8, 4) . '-' . substr($rH, 12, 4) . '-' . substr($rH, 16, 4) . '-' . substr($rH, 20);
	}
}
