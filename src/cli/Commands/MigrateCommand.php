<?php

class MigrateCommand implements CommandInterface {

	public function getName(): string {
		return 'migrate';
	}

	public function getDescription(): string {
		return 'Migrate — database migration from xc_vm_migrate';
	}

	public function execute(array $rArgs): int {
		// migrate requires admin bootstrap (CONTEXT_ADMIN) for $_INFO credentials
		require INCLUDES_PATH . 'admin.php';

		// Expose $argc/$argv for migration_logic.php compatibility
		global $argc, $argv;

		require __DIR__ . '/../migration_logic.php';

		return 0;
	}
}
