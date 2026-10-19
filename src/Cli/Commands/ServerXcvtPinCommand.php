<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Cluster\NodeCorePin;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\ClusterAudit;
use XcVm\Domain\Cluster\CorePins;
use XcVm\Domain\Cluster\XcvtTargets;
use XcVm\Domain\Server\InstallCredentials;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * ServerXcvtPinCommand — make an installed load balancer an XCVT v2 target
 * over SSH (xcvm_core's ADR-005, Decision 10: the backfill of a node
 * installed before release N, and the re-pin after MAIN's root changed,
 * Decision 16), without reinstalling it and with the cluster API on or off.
 *
 * Over a host-key-verified session, as root on the node: read its install_id
 * (only one that exists), show its panel key (`panel_pk()`, creating panel.sk
 * when there is none), check MAIN's root (XcvtTargets::ensureRoot), pin the
 * v2 pin with replace (LbInstallFlow::pinCore), read the pin back, and
 * record it all (XcvtTargets). The key and the pin are audit-logged.
 *
 * Trust on first use is refused: the host key must match `--expect-hostkey`,
 * or be the stored one when that was itself confirmed by an admin (an
 * install or this command given `--expect-hostkey`). A key stored on first
 * use is not enough: a re-pin over it would hand the node's trust to anyone
 * who could intercept SSH once.
 *
 * Usage: `console.php server:xcvt-pin <serverID> [sshPort] --cred-file=<path> [--expect-hostkey=<sha1>]`
 * (the credentials as for server:install: a 0600 file, read and deleted).
 * MAIN only.
 *
 * @package XC_VM_CLI_Commands
 */
class ServerXcvtPinCommand implements CommandInterface {
	use DatabaseAware;

	public function getName(): string {
		return 'server:xcvt-pin';
	}

	public function getDescription(): string {
		return 'Pin an installed LB for XCVT v2 configs over SSH (ADR-005 backfill)';
	}

	public function execute(array $rArgs): int {
		if ((posix_getpwuid(posix_geteuid())['name'] ?? null) !== 'xc_vm') {
			echo "Please run as XC_VM!\n";
			return 1;
		}
		[$rArgs, $rOptions] = InstallCredentials::splitOptions($rArgs);
		$rServerID = intval($rArgs[0] ?? 0);
		$rPort = intval($rArgs[1] ?? 22) ?: 22;
		$rCred = isset($rOptions['cred-file']) ? InstallCredentials::consume($rOptions['cred-file']) : null;
		if ($rServerID <= 0 || $rCred === null) {
			echo "Usage: server:xcvt-pin <serverID> [sshPort] --cred-file=<path> [--expect-hostkey=<sha1>]\n";
			return 1;
		}
		try {
			$rCrypto = ClusterCryptoFactory::create();
		} catch (\Throwable $rE) {
			echo $rE->getMessage() . ". Exiting\n";
			return 1;
		}
		set_time_limit(0);
		$rExpected = InstallCredentials::normalizeHostKey($rOptions['expect-hostkey'] ?? '');
		return self::pin(ServerRepository::getAll(true), $rServerID, $rPort, $rCred, $rExpected, $rCrypto) === null ? 0 : 1;
	}

	/**
	 * Pin one load balancer. Null once it is a v2 target; otherwise why not
	 * (printed as it happens, with what the probe found recorded for
	 * server:xcvt-report).
	 *
	 * @param array<int, array<string, mixed>>           $rServers ServerRepository::getAll(true)
	 * @param array{username: string, password: string} $rCred
	 */
	public static function pin(array $rServers, int $rServerID, int $rPort, array $rCred, ?string $rExpected, ClusterCrypto $rCrypto, ?SshSession $rSsh = null): ?string {
		$rFail = static function (string $rWhy) use ($rServerID): string {
			ClusterAudit::log('node.xcvt_pin_failed', $rServerID, ['why' => mb_substr($rWhy, 0, 300)], 'cli');
			echo $rWhy . ". Exiting\n";
			return $rWhy;
		};
		$rServer = $rServers[$rServerID] ?? null;
		if (!ClusterAdmin::isLoadBalancer($rServer)) {
			return $rFail("Server {$rServerID} is not a load balancer");
		}
		$rStored = InstallCredentials::normalizeHostKey((string) ($rServer['ssh_hostkey_sha1'] ?? ''));
		if (($rExpected === null || $rExpected === '') && ($rStored === null || !XcvtTargets::hostKeyVerified($rServerID, $rStored))) {
			return $rFail(($rStored === null ? 'No SSH host key is stored for this node' : 'The stored SSH host key (' . $rStored . ') was taken on first use, not confirmed')
				. ": pass --expect-hostkey with the node's SHA-1 fingerprint\n(on the node: ssh-keygen -l -E sha1 -f /etc/ssh/ssh_host_ed25519_key.pub). A pin is never written over a first-use host key");
		}

		$rSsh ??= new SshSession();
		$rHost = (string) $rServer['server_ip'];
		echo 'Connecting to ' . $rHost . ':' . $rPort . "\n";
		try {
			if (!$rSsh->connect($rHost, $rPort)) {
				XcvtTargets::noteProbe($rServerID, 'ssh_unreachable');
				return $rFail('Failed to connect to server' . ($rSsh->error() !== '' ? ': ' . $rSsh->error() : ''));
			}
			$rPresented = $rSsh->hostKey();
			$rHostKeyError = InstallCredentials::checkHostKey($rPresented, $rExpected, $rStored);
			if ($rHostKeyError !== null) {
				return $rFail($rHostKeyError);
			}
			if (!$rSsh->login($rCred['username'], $rCred['password'])) {
				XcvtTargets::noteProbe($rServerID, 'ssh_unreachable');
				return $rFail('Failed to authenticate over SSH');
			}
			$rHostKey = (string) InstallCredentials::normalizeHostKey($rPresented);
			if ($rHostKey !== $rStored) {
				self::db()->query('UPDATE `servers` SET `ssh_hostkey_sha1` = ? WHERE `id` = ?;', $rHostKey, $rServerID);
			}
			XcvtTargets::noteHostKey($rServerID, $rHostKey, $rExpected !== null && $rExpected !== '');

			$rRun = static fn(SshSession $rC, string $rCmd): array => $rC->run($rCmd);
			if (trim((string) $rSsh->run('sudo -n true >/dev/null 2>&1 && echo SUDO_OK')['output']) !== 'SUDO_OK') {
				return $rFail('The SSH user must be root, or a user whose sudo asks for no password');
			}
			// install_id() creates the file when there is none: ask only when it exists.
			$rAsk = 'echo class_exists("XC_VM") && ' . NodeCorePin::identityProbe(CONFIG_PATH) . ' ? XC_VM::install_id() : "";';
			$rLines = preg_split('/\R/', trim((string) $rSsh->run('sudo ' . PHP_BIN . ' -r ' . escapeshellarg($rAsk))['output'])) ?: [];
			$rInstallId = trim((string) end($rLines));
			if (!CorePins::validInstallId($rInstallId)) {
				return $rFail('The node has no install_id, or no xcvm_core: it is not an installed load balancer');
			}
			$rKey = LbInstallFlow::probePanelPk($rSsh, $rRun);
			if (!XcvtTargets::validKey($rKey)) {
				XcvtTargets::noteProbe($rServerID, $rKey);
				return $rFail($rKey === 'ext_old' ? "The node's xcvm_core predates XCVT v2 (no panel_pk): update it, then retry" : "The node's panel key does not open (panel.sk): it is never regenerated here");
			}
			echo 'The node\'s panel key: ' . $rKey . "\n";
			if (!XcvtTargets::record($rServerID, $rInstallId, $rKey, null, 0, 'cli', $rCrypto)) {
				return $rFail('MAIN could not record the node\'s key');
			}
			$rWhy = LbInstallFlow::pinCore($rSsh, $rRun, $rCrypto, $rInstallId);
			if ($rWhy !== null) {
				return $rFail('Not pinned: ' . $rWhy);
			}
			// Read back as the node keeps it, not as cluster_pin answered.
			$rFp = (string) CorePins::panelFp();
			$rPinned = LbInstallFlow::probePinned($rSsh, $rRun);
			if (!hash_equals('OK ' . $rFp, $rPinned)) {
				return $rFail('The node reads back another pin (' . ($rPinned === '' ? 'no answer' : $rPinned) . ') than this panel\'s key (' . $rFp . ')');
			}
			if (!XcvtTargets::record($rServerID, $rInstallId, $rKey, $rFp, 2, 'cli', $rCrypto) || !XcvtTargets::ready($rServerID, $rCrypto)) {
				return $rFail('Pinned, but MAIN could not record it');
			}
			// A cluster node's row learns it too, so cron:cluster does not offer it again.
			LbInstallFlow::recordCorePin($rServerID);
			ClusterAudit::log('node.xcvt_pinned', $rServerID, ['install_id' => $rInstallId, 'panel_pk' => $rKey, 'pin_fp' => $rFp], 'cli');
			echo 'Pinned for XCVT v2 (install_id ' . $rInstallId . ', panel key ' . $rKey . ', pin ' . $rFp . "): its configs are now signed by MAIN and sealed to it\n";
			return null;
		} finally {
			$rSsh->close();
		}
	}
}
