<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\ClusterCli;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Domain\Server\InstallCredentials;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * ServerEnrolCommand — enrol an existing (legacy) LB in the cluster API over
 * SSH (MAIN ↔ LB plan, section 6, path (a)). It reruns the install flow's
 * enrolment (LbInstallFlow::provisionCluster) without reinstalling.
 *
 * Trust on first use is refused: the node's SSH host key must match the
 * `--expect-hostkey` given (the SHA-1 of `ssh-keygen -l -E sha1 -f
 * /etc/ssh/ssh_host_ed25519_key.pub`, as the admin reads it on the node's
 * console) or the one stored at its install. The LB must already run this
 * panel release (it ships bin/xc_agent/run.sh); update it with the legacy
 * `update` signal first. A failure never marks the live node as failed: it
 * keeps serving the legacy way.
 *
 * Usage: `console.php server:enrol <serverID> <sshPort> --cred-file=<path> [--expect-hostkey=<sha1>]`
 * (the credentials as for server:install: a 0600 file, read and deleted).
 *
 * @package XC_VM_CLI_Commands
 */
class ServerEnrolCommand implements CommandInterface {
	use DatabaseAware;

	public function getName(): string {
		return 'server:enrol';
	}

	public function getDescription(): string {
		return 'Enrol an existing LB in the cluster API over SSH';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] != 'xc_vm') {
			echo "Please run as XC_VM!\n";
			return 1;
		}
		[$rArgs, $rOptions] = InstallCredentials::splitOptions($rArgs);
		$rServerID = intval($rArgs[0] ?? 0);
		$rPort = intval($rArgs[1] ?? 22);
		$rCred = isset($rOptions['cred-file']) ? InstallCredentials::consume($rOptions['cred-file']) : null;
		if ($rServerID <= 0 || $rCred === null) {
			echo "Usage: server:enrol <serverID> <sshPort> --cred-file=<path> [--expect-hostkey=<sha1>]\n";
			return 1;
		}
		if (empty(SettingsManager::get('cluster_api_enabled'))) {
			echo "The cluster API is disabled (Settings → Cluster). Exiting\n";
			return 1;
		}
		$rCrypto = ClusterCli::crypto();
		if ($rCrypto === null) {
			return 1;
		}

		set_time_limit(0);
		$rExpected = InstallCredentials::normalizeHostKey($rOptions['expect-hostkey'] ?? '');
		return self::enrol(ServerRepository::getAll(true), $rServerID, $rPort, $rCred, $rExpected, $rCrypto) === null ? 0 : 1;
	}

	/**
	 * Enrol (or re-enrol) one LB over SSH; `cluster:reenrol` runs it node by
	 * node. The host key must match $rExpected, else the one stored at the
	 * node's install; with neither, nothing is contacted (no trust on first
	 * use). Then LbInstallFlow::provisionCluster runs on the live node without
	 * marking it failed. Only one enrolment of a node runs at a time.
	 *
	 * The node counts as enrolled once it has a new identity: a flow that ends
	 * without one (the API off, no xc_agent for its arch) is a failure, and the
	 * node keeps the identity it had, if any.
	 *
	 * Everything is printed as it happens.
	 *
	 * @param array<int, array<string, mixed>>           $rServers     ServerRepository::getAll(true)
	 * @param array{username: string, password: string} $rCred
	 * @return string|null Null once the node is enrolled; otherwise why not.
	 */
	public static function enrol(array $rServers, int $rServerID, int $rPort, array $rCred, ?string $rExpected, ClusterCrypto $rCrypto, ?SshSession $rSsh = null): ?string {
		$rFail = static function (string $rWhy): string {
			echo $rWhy . ". Exiting\n";
			return $rWhy;
		};
		$rServer = $rServers[$rServerID] ?? null;
		if (!ClusterAdmin::isLoadBalancer($rServer)) {
			return $rFail("Server {$rServerID} is not a load balancer");
		}
		$rStored = $rServer['ssh_hostkey_sha1'] ?? null;
		if (($rExpected === null || $rExpected === '') && ($rStored === null || $rStored === '')) {
			return $rFail("No SSH host key to check against: pass --expect-hostkey with the node's SHA-1 fingerprint\n"
				. '(on the node: ssh-keygen -l -E sha1 -f /etc/ssh/ssh_host_ed25519_key.pub). Trust on first use is refused for enrolment');
		}

		// A second enrolment of the same node (server:enrol, cluster:reenrol)
		// would interleave its keygen and startEnrolment with this one.
		$rLock = @fopen(self::lockPath($rServerID), 'c');
		if ($rLock === false || !flock($rLock, LOCK_EX | LOCK_NB)) {
			return $rFail("Another enrolment of server {$rServerID} is running");
		}

		$rSsh ??= new SshSession();
		$rHost = (string) $rServer['server_ip'];
		echo 'Connecting to ' . $rHost . ':' . $rPort . "\n";
		try {
			if (!$rSsh->connect($rHost, $rPort)) {
				return $rFail('Failed to connect to server' . ($rSsh->error() !== '' ? ': ' . $rSsh->error() : ''));
			}
			$rPresented = $rSsh->hostKey();
			$rHostKeyError = InstallCredentials::checkHostKey($rPresented, $rExpected, $rStored);
			if ($rHostKeyError !== null) {
				return $rFail($rHostKeyError);
			}
			if (!$rSsh->login($rCred['username'], $rCred['password'])) {
				return $rFail('Failed to authenticate over SSH');
			}
			$rHostKey = InstallCredentials::normalizeHostKey($rPresented);
			if ($rHostKey !== $rStored) {
				self::db()->query('UPDATE `servers` SET `ssh_hostkey_sha1` = ? WHERE `id` = ?;', $rHostKey, $rServerID);
			}

			// As root, like the flow that follows: config/ is closed to the SSH user, and bin/ after an update.
			$rReady = $rSsh->run('sudo test -f /home/xc_vm/bin/xc_agent/run.sh && sudo test -f /home/xc_vm/config/config.enc && echo READY');
			if (trim($rReady['output']) !== 'READY') {
				return $rFail('The node does not run this panel release yet (no bin/xc_agent/run.sh). Update it first, then retry');
			}

			$rBefore = NodeRegistry::byServer($rServerID);
			$rLog = '';
			ob_start(static function (string $rChunk) use (&$rLog): string {
				$rLog .= $rChunk;
				return $rChunk;
			}, 1);
			try {
				$rOk = LbInstallFlow::provisionCluster(
					$rSsh,
					static fn(SshSession $rC, string $rCmd): array => $rC->run($rCmd),
					static fn(SshSession $rC, string $rFrom, string $rTo, bool $rWarn = false): bool => $rC->send($rFrom, $rTo, $rWarn),
					$rServers,
					$rServerID,
					self::db(),
					$rCrypto,
					false
				);
			} finally {
				ob_end_flush();
			}
			if (!$rOk) {
				return self::lastWords($rLog);
			}
			$rNode = NodeRegistry::byServer($rServerID);
			if ($rNode !== null && ($rBefore === null || $rNode['node_uuid'] !== $rBefore['node_uuid'])) {
				return null;
			}
			// The flow left the node as it was, and said why unless the API is off.
			$rCause = trim($rLog) === '' ? 'The cluster API is disabled' : (string) preg_replace('/;\s*the node stays legacy.*$/s', '', self::lastWords($rLog));
			return $rFail($rCause . ($rBefore === null ? '; the node was not enrolled and stays legacy' : '; the node was not re-enrolled and keeps its previous identity'));
		} finally {
			$rSsh->close();
			flock($rLock, LOCK_UN);
			fclose($rLock);
		}
	}

	/** The lock one enrolment of $rServerID holds. */
	public static function lockPath(int $rServerID): string {
		return (defined('TMP_PATH') ? TMP_PATH : sys_get_temp_dir() . '/') . 'cluster_enrol_' . $rServerID . '.lock';
	}

	/** Why provisionCluster stopped: what it printed after its first line, without "Exiting". */
	private static function lastWords(string $rLog): string {
		$rLines = array_values(array_filter(array_map('trim', explode("\n", $rLog)), static fn(string $rL): bool => $rL !== ''));
		if (count($rLines) > 1 && str_starts_with($rLines[0], 'Enrolling the node')) {
			array_shift($rLines);
		}
		$rWhy = (string) preg_replace('/[.!]?\s*Exiting$/', '', implode(' ', $rLines));
		return $rWhy === '' ? 'The enrolment failed on the node' : $rWhy;
	}
}
