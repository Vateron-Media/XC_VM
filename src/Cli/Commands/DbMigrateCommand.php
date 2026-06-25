<?php

namespace XcVm\Cli\Commands;
use XcVm\Cli\CommandInterface;

use XcVm\Core\Database\MigrationRunner;
/**
 * DbMigrateCommand — apply pending database migrations
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/\XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class DbMigrateCommand implements CommandInterface {

	public function getName(): string {
		return 'db:migrate';
	}

	public function getDescription(): string {
		return 'Apply pending database migrations from the migrations/ directory';
	}

	public function execute(array $rArgs): int {
		global $db;

		MigrationRunner::run($db);

		return 0;
	}
}
