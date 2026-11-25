<?php

namespace XcVm\Core\Module\Contract;

/**
 * Optional contract for modules that need xc_vm-user crontab entries.
 *
 * Implement this interface to declare cron schedule entries your module
 * requires. ModuleLoader::collectCronEntries() validates them against the
 * module's registered commands before module:cron-sync writes them.
 *
 * This removes the need to edit core files (StartupCommand, StatusCommand)
 * when a module adds or changes its scheduling requirements.
 *
 * @package XC_VM_Core_Module_Contract
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
interface CronProviderInterface {
	/**
	 * Return the cron schedule entries this module requires.
	 *
	 * Keys are five-field numeric cron expressions; values are registered console
	 * command names with optional literal arguments. The caller resolves PHP_BIN, MAIN_HOME,
	 * and the comment suffix automatically.
	 *
	 * Example:
	 *   return ['* * * * *' => 'cron:watch'];
	 *   // Produces: "* * * * * /usr/bin/php /opt/xc_vm/console.php cron:watch # XC_VM_MODULE"
	 *
	 * @return array<string, string>  cron-expression => console-command-name
	 */
	public function getCronEntries(): array;
}
