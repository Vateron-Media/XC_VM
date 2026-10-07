<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\NodeActions;
use XcVm\Domain\Cluster\RollingUpdate;
use XcVm\Domain\Server\ServerRepository;

/**
 * ClusterRollingUpdateCommand — update the load balancers to MAIN's release
 * one at a time (Domain\Cluster\RollingUpdate), each back online on it before
 * the next. Started by Servers → Rolling Update; one run at a time. MAIN
 * only: the LB build strips it.
 *
 * Usage: `console.php cluster:rolling-update`
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterRollingUpdateCommand implements CommandInterface {
	public function getName(): string {
		return 'cluster:rolling-update';
	}

	public function getDescription(): string {
		return "Update the load balancers to MAIN's release one at a time";
	}

	public function execute(array $rArgs): int {
		$rLock = fopen(CACHE_TMP_PATH . 'rolling_update.lock', 'c');
		if ($rLock === false || !flock($rLock, LOCK_EX | LOCK_NB)) {
			echo "A rolling update is already running\n";
			return 1;
		}
		global $db;
		$rState = RollingUpdate::run(
			XC_VM_VERSION,
			static fn(): array => ServerRepository::getAll(true),
			static fn(int $rID): bool => NodeActions::update($rID, $db),
			static function (int $rSeconds): void {
				sleep($rSeconds);
			},
			time(...)
		);
		foreach ($rState['nodes'] as $rNode) {
			echo $rNode['name'] . ': ' . $rNode['state'] . ($rNode['error'] !== '' ? ' (' . $rNode['error'] . ')' : '') . "\n";
		}
		echo 'Rolling update ' . $rState['status'] . "\n";
		flock($rLock, LOCK_UN);
		return $rState['status'] === 'failed' ? 1 : 0;
	}
}
