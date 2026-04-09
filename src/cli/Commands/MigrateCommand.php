<?php

/**
 * MigrateCommand — migrate command
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class MigrateCommand implements CommandInterface {

	public function getName(): string {
		return 'migrate';
	}

	public function getDescription(): string {
		return 'Migrate — database migration from xc_vm_migrate';
	}

	public function execute(array $rArgs): int {
		// migrate requires admin bootstrap (CONTEXT_ADMIN) for $_INFO credentials
		// Expose $argc/$argv for migration_logic.php compatibility
		global $argc, $argv;

		require __DIR__ . '/../migration_logic.php';

		return 0;
	}
}
