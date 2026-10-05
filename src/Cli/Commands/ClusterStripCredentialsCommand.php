<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\DbCredentials;
use XcVm\Domain\Cluster\NodeRegistry;

/**
 * ClusterStripCredentialsCommand — take MAIN's database and Redis credentials
 * off a load balancer in mode 2 (plan, section 10, step 3; Phase 9): a signed
 * `node.root strip_db_credentials`, after which MAIN revokes the node's
 * database grant once the node reports its config holds none
 * (DbCredentials). The same operation as the Cluster Nodes page's "Drop DB
 * credentials", and audited the same way (`node.strip_credentials`, then
 * `node.db_revoked`).
 *
 * It is the point of no return for a node, so it asks: the operator types the
 * server id back, or passes `--yes` (a script). `--wait=<seconds>` waits for
 * the revoke to be recorded.
 *
 * Usage: `console.php cluster:strip-credentials <serverID> [--yes] [--wait=<seconds>]`. MAIN only.
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterStripCredentialsCommand implements CommandInterface {
	/** @var \Closure(string): string|null Tests: what the operator types at the prompt. */
	private static ?\Closure $rAsk = null;

	/** What each refusal says on the command line. */
	public const MESSAGES = [
		'cluster_not_enrolled' => 'That server has no enrolled node.',
		'cluster_strip_needs_mode2' => 'Only a node in mode 2 gives up MAIN\'s credentials: it must already run without MAIN\'s database.',
		'cluster_strip_not_active' => 'The node is not active; it gives up MAIN\'s credentials only while it is.',
		'cluster_strip_too_soon' => 'The node has not been in mode 2 for seven days yet. This step cannot be undone: until then mode down is its way back.',
		'cluster_strip_not_local' => 'The node says it does not read its streams on itself. Move it down a mode until it does (it needs these credentials for that), then up again.',
		'cluster_strip_not_queued' => 'The command could not be queued (the node takes no root commands, or the extension refused); see the cluster log.',
	];

	public function getName(): string {
		return 'cluster:strip-credentials';
	}

	public function getDescription(): string {
		return 'Take MAIN\'s DB/Redis credentials off a mode-2 load balancer and revoke its grant';
	}

	/** Tests: answer the confirmation prompt with $rAsk; null reads the terminal. */
	public static function useAsk(?\Closure $rAsk): void {
		self::$rAsk = $rAsk;
	}

	public function execute(array $rArgs): int {
		$rServerID = intval($rArgs[0] ?? 0);
		$rYes = in_array('--yes', $rArgs, true);
		$rWait = 0;
		foreach ($rArgs as $rArg) {
			if (preg_match('/^--wait=(\d{1,4})$/', (string) $rArg, $rM)) {
				$rWait = (int) $rM[1];
			}
		}
		if ($rServerID <= 0) {
			echo "Usage: cluster:strip-credentials <serverID> [--yes] [--wait=<seconds>]\n";
			return 1;
		}
		if (empty(SettingsManager::get('cluster_api_enabled'))) {
			echo "The cluster API is off: nothing to send.\n";
			return 1;
		}
		$rNode = NodeRegistry::byServer($rServerID);
		if ($rNode === null) {
			echo self::MESSAGES['cluster_not_enrolled'] . "\n";
			return 1;
		}
		$rRevoked = DbCredentials::revokedAt($rServerID);
		if ($rRevoked !== null) {
			echo "Server {$rServerID} already gave up MAIN's credentials; its grant was revoked at " . gmdate('Y-m-d H:i:s', $rRevoked) . " UTC.\n";
			return 0;
		}
		echo "Server {$rServerID}: node {$rNode['node_uuid']}, mode " . (int) $rNode['mode'] . ", {$rNode['state']}.\n";
		echo "This removes MAIN's database and Redis credentials from the node's config.enc and then revokes its database grant.\n";
		echo "The node keeps working over the cluster API; rolling back needs a new config and a new grant.\n";
		if (!$rYes && !self::confirmed($rServerID)) {
			echo "Not confirmed: nothing sent.\n";
			return 1;
		}
		$rWhy = DbCredentials::strip($rServerID, 'cli');
		if ($rWhy !== null) {
			echo (self::MESSAGES[$rWhy] ?? $rWhy) . "\n";
			return 1;
		}
		echo "Sent. MAIN revokes the node's database grant once the node reports its config holds no credentials.\n";
		for ($rUntil = time() + $rWait; $rWait > 0 && time() < $rUntil; sleep(2)) {
			$rAt = DbCredentials::revokedAt($rServerID);
			if ($rAt !== null) {
				echo 'Revoked at ' . gmdate('Y-m-d H:i:s', $rAt) . " UTC.\n";
				return 0;
			}
		}
		if ($rWait > 0) {
			echo "Not revoked yet; see the cluster log (node.db_revoked, node.db_revoke_failed).\n";
		}
		return 0;
	}

	/** The operator types the server id back; without a terminal (and no --yes) nothing is confirmed. */
	private static function confirmed(int $rServerID): bool {
		$rPrompt = "Type the server id ({$rServerID}) to confirm: ";
		if (self::$rAsk !== null) {
			$rAnswer = (self::$rAsk)($rPrompt);
		} else {
			if (!function_exists('posix_isatty') || !posix_isatty(STDIN)) {
				echo "No terminal to confirm on: pass --yes.\n";
				return false;
			}
			echo $rPrompt;
			$rAnswer = (string) fgets(STDIN);
		}
		return trim($rAnswer) === (string) $rServerID;
	}
}
