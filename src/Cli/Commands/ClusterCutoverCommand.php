<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Cluster\NodeActions;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\ClusterCutover;
use XcVm\Domain\Cluster\ClusterOverview;
use XcVm\Domain\Server\ServerRepository;

/**
 * ClusterCutoverCommand — switch one load balancer's flows on in the
 * guide's order, each watched before the next, and stop at mode 1
 * (Domain\Cluster\ClusterCutover). Started by Cluster Nodes → Guided
 * cutover; one per node at a time. MAIN only: the LB build strips it.
 *
 * Usage: `console.php cluster:cutover <server id> [admin user id]`
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterCutoverCommand implements CommandInterface {
	/** Seconds the node has to load its viewers (its root actions come every minute without the cluster API). */
	public const SEED_TIMEOUT = 300;

	public function getName(): string {
		return 'cluster:cutover';
	}

	public function getDescription(): string {
		return "Switch a load balancer's cluster flows on one at a time, watched, up to mode 1";
	}

	public function execute(array $rArgs): int {
		$rServerID = (int) ($rArgs[0] ?? 0);
		$rUserID = (int) ($rArgs[1] ?? 0) ?: null;
		if ($rServerID <= 0) {
			echo "Usage: console.php cluster:cutover <server id> [admin user id]\n";
			return 1;
		}
		$rLock = fopen(CACHE_TMP_PATH . 'cutover_' . $rServerID . '.lock', 'c');
		if ($rLock === false || !flock($rLock, LOCK_EX | LOCK_NB)) {
			echo "A cutover of this node is already running\n";
			return 1;
		}
		try {
			$rCrypto = ClusterCryptoFactory::create();
		} catch (\Throwable $rE) {
			echo 'The cluster API is not available: ' . $rE->getMessage() . "\n";
			return 1;
		}
		global $db;
		$rState = ClusterCutover::run(
			$rServerID,
			static function () use ($rServerID): ?array {
				$rSettings = SettingsManager::getAll();
				foreach (ClusterAdmin::nodes(ServerRepository::getAll(true), ClusterSettings::int('cluster_offline_after_sec', $rSettings['cluster_offline_after_sec'] ?? null)) as $rNode) {
					if ((int) $rNode['server_id'] === $rServerID) {
						return $rNode;
					}
				}
				return null;
			},
			static fn(string $rAction, array $rExtra): array => ClusterAdmin::act($rCrypto, ['cluster_action' => $rAction, 'server_id' => $rServerID] + $rExtra, ServerRepository::getAll(true), (int) SERVER_ID, SettingsManager::getAll(), $rUserID),
			static fn(): ?string => self::seed($rServerID, $db),
			static fn(array $rNode): array => ClusterOverview::nodeBadges($rNode),
			static function (int $rSeconds): void {
				sleep($rSeconds);
			},
			time(...)
		);
		foreach ($rState['steps'] as $rStep) {
			echo $rStep['flow'] . ': ' . $rStep['state'] . ($rStep['note'] !== '' ? ' (' . $rStep['note'] . ')' : '') . "\n";
		}
		echo 'Cutover ' . $rState['status'] . ($rState['note'] !== '' ? ': ' . $rState['note'] : '') . "\n";
		flock($rLock, LOCK_UN);
		return $rState['status'] === 'failed' ? 1 : 0;
	}

	/** Ask the node to load its viewers into its agent and wait for its system log line: null when done, else why not. */
	private static function seed(int $rServerID, object $db): ?string {
		$rSent = time();
		if (!NodeActions::seedConnections($rServerID, $db)) {
			return 'the request could not be sent';
		}
		for ($rUntil = $rSent + self::SEED_TIMEOUT; time() < $rUntil; sleep(5)) {
			$db->query("SELECT `error` FROM `mysql_syslog` WHERE `server_id` = ? AND `type` = 'CLUSTER' AND `date` >= ? AND (`error` LIKE ? OR `error` LIKE ?) ORDER BY `id` DESC LIMIT 1;", $rServerID, $rSent, NodeActions::SEEDED . '%', NodeActions::NOT_SEEDED . '%');
			if ($db->num_rows() > 0) {
				$rLine = (string) $db->get_row()['error'];
				return str_starts_with($rLine, NodeActions::SEEDED) ? null : substr($rLine, strlen(NodeActions::NOT_SEEDED));
			}
		}
		return 'it did not answer within ' . intdiv(self::SEED_TIMEOUT, 60) . ' minutes';
	}
}
