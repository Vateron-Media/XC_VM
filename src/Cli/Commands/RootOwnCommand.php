<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Config\TreeOwnership;
use XcVm\Core\Process\ProcessRunner;

/**
 * RootOwnCommand — make what root runs from the panel's tree root's.
 *
 * Root's crons, the service unit and the updater run the panel's PHP, its
 * classes and its scripts. An install and an update hand the whole tree to
 * xc_vm, which could then replace any of them; this takes those back
 * (TreeOwnership::ROOT_OWNED), leaving xc_vm's group to read them. `status`
 * does the same at every start on MAIN and load balancers.
 *
 * `root.php root:paths` says what xc_vm can still replace.
 *
 * Usage: `root.php root:own` (root)
 *
 * @package XC_VM_CLI_Commands
 */
class RootOwnCommand implements CommandInterface {
	public function getName(): string {
		return 'root:own';
	}

	public function getDescription(): string {
		return 'Make what root runs from the tree root-owned (root)';
	}

	public function execute(array $rArgs): int {
		if (posix_geteuid() !== 0) {
			echo "Please run as root!\n";
			return 1;
		}
		$rFailed = 0;
		foreach (TreeOwnership::rootOwn(MAIN_HOME) as $rArgv) {
			$rFailed += ProcessRunner::run($rArgv) === 0 ? 0 : 1;
		}
		echo $rFailed === 0 ? "What root runs from the tree is root's.\n" : "Some paths could not be changed (see above).\n";
		return $rFailed === 0 ? 0 : 1;
	}
}
