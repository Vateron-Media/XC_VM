<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Module\ModuleManager;

/**
 * ModuleMigrateCommand — run a module's install or update steps (MAIN).
 *
 * ModuleManager starts it after replacing the files of a module its own
 * process had already loaded: PHP keeps the class it loaded first, so only a
 * new process runs the install() and getMigrations() of the version now on
 * disk (ModuleManager::migrateReplaced()). The files are in place when it
 * runs, and it leaves them as they are.
 *
 * Usage: `console.php module:migrate install <name> [version]`
 *        `console.php module:migrate update <name>`
 *
 * When the steps ran: exit status 0, and ModuleManager::STEPS_DONE as the
 * last line of stdout. Otherwise status 1, with the reason on stdout. What
 * the steps print is not passed on, but for the end of it after the reason.
 *
 * @package XC_VM_CLI_Commands
 * @author  obscuremind <https://github.com/obscuremind>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ModuleMigrateCommand implements CommandInterface {
	/** The most of what the steps printed that follows the reason one failed with. */
	private const PRINTED = 4096;

	public function getName(): string {
		return 'module:migrate';
	}

	public function getDescription(): string {
		return 'Run the install or update steps of a module whose files are in place';
	}

	public function execute(array $rArgs): int {
		$rAction = (string) ($rArgs[0] ?? '');
		$rName   = (string) ($rArgs[1] ?? '');

		if (!in_array($rAction, ['install', 'update'], true) || $rName === '') {
			echo "Usage: console.php module:migrate install <name> [version] | update <name>\n";
			return 1;
		}

		$rManager = new ModuleManager(container: ServiceContainer::getInstance());

		// What the steps print is held back: the caller reads the start of this
		// process's output only, and has to find the outcome in it.
		// ponytail: what a step flushes itself, writes straight to STDOUT or
		// leaves in a buffer that cannot be removed is not held; past the 64 KiB
		// the caller reads, the outcome is lost and the update is put back. Have
		// the caller read the end of the output if a module ever does that.
		$rLevel  = ob_get_level();
		$rReason = null;
		ob_start();
		try {
			if ($rAction === 'update') {
				$rManager->updateModule($rName);
			} else {
				$rManager->installModule($rName, isset($rArgs[2]) ? (string) $rArgs[2] : null);
			}
		} catch (\Throwable $e) {
			$rReason = $e->getMessage();
		}
		// A step may have left buffers of its own open. One pass per buffer, as
		// one that cannot be removed stays where it is.
		$rPrinted = '';
		for ($rOpen = ob_get_level(); $rOpen > $rLevel; $rOpen--) {
			$rPrinted = ob_get_clean() . $rPrinted;
		}

		if ($rReason !== null) {
			echo $rReason . "\n" . substr($rPrinted, -self::PRINTED);
			return 1;
		}

		echo ModuleManager::STEPS_DONE . "\n";
		return 0;
	}
}
