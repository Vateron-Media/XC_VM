<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Module\ModuleManager;

/**
 * ModuleDeleteCommand — delete a module on a load balancer (files only).
 *
 * Dispatched from MAIN via the `delete_module` signal (RootSignalsCronJob):
 *   {"action":"delete_module","name":"…"}
 *
 * LB nodes share MAIN's database, so deletion here is FILES-ONLY — it removes the
 * module directory + its config override and NEVER drops tables (that would delete
 * MAIN's data). The DB teardown happens once, on MAIN, in ModuleManager::deleteModule().
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ModuleDeleteCommand implements CommandInterface {

    public function getName(): string {
        return 'module:delete';
    }

    public function getDescription(): string {
        return 'Delete a module on a load balancer (files only)';
    }

    public function execute(array $rArgs): int {
        register_shutdown_function(function () {
            global $db;
            if (is_object($db)) {
                $db->close_mysql();
            }
        });

        $rArg  = trim((string) ($rArgs[0] ?? ''), "'\" ");
        $rJson = $rArg !== '' ? base64_decode($rArg, true) : false;
        $rData = $rJson !== false ? json_decode($rJson, true) : null;
        $rName = is_array($rData) ? (string) ($rData['name'] ?? '') : '';

        if ($rName === '') {
            echo "module:delete: missing module name.\n";
            return 1;
        }

        try {
            (new ModuleManager())->deleteModuleFilesOnly($rName);
            echo "module:delete: '{$rName}' removed.\n";
            return 0;
        } catch (\Throwable $e) {
            echo "module:delete failed for '{$rName}': " . $e->getMessage() . "\n";
            return 1;
        }
    }
}
