<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Module\ModuleManager;
use XcVm\Core\Module\ModuleUpdateChecker;

/**
 * ModuleUpdatesCronJob — weekly check of module update availability.
 *
 * For every INSTALLED module it resolves the latest version from the module's
 * declared source (the `update` manifest block: git/url/platform; `bundled`
 * compares the on-disk version) via ModuleUpdateChecker, and records a
 * `available_version` in config/modules.php when it is strictly newer than the
 * installed version (otherwise clears it). listModules()/the admin panel read
 * that flag to show the "Update to X" button only when an update actually exists.
 *
 * Read-only w.r.t. the modules themselves — it never downloads or applies files
 * (that is ModuleManager::updateModuleFromSource, a later phase).
 *
 * Scheduled via the `crontab` table (migration 008_add_module_updates_cron.sql).
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ModuleUpdatesCronJob implements CommandInterface {

    public function getName(): string {
        return 'cron:module_updates';
    }

    public function getDescription(): string {
        return 'Cron: check module update availability from their declared sources';
    }

    public function execute(array $rArgs): int {
        register_shutdown_function(function () {
            global $db;
            if (is_object($db)) {
                $db->close_mysql();
            }
        });

        $rManager = new ModuleManager(container: ServiceContainer::getInstance());
        $rChecker = new ModuleUpdateChecker();

        foreach ($rManager->listModules() as $rModule) {
            // Only installed modules — the check compares against installed_version.
            if (($rModule['installed_version'] ?? '') === '') {
                continue;
            }

            $rInstalled = (string) $rModule['installed_version'];
            $rLatest    = $rChecker->latestAvailable($rModule);

            if ($rLatest !== null && version_compare($rLatest, $rInstalled, '>')) {
                $rManager->recordAvailableVersion($rModule['name'], $rLatest);
                echo '[UPDATE] ' . $rModule['name'] . ': ' . $rInstalled . ' -> ' . $rLatest . "\n";
            } else {
                // Nothing newer / not checkable — clear any stale flag.
                $rManager->recordAvailableVersion($rModule['name'], null);
            }
        }

        return 0;
    }
}
