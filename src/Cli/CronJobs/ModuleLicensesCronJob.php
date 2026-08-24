<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Module\ModuleManager;

require_once __DIR__ . '/../CronTrait.php';

/**
 * ModuleLicensesCronJob — keep per-machine ionCube licenses for platform modules
 * fresh.
 *
 * For every platform-installed module that already carries a license file, it
 * re-mints a hardware-bound .lic with a new expiry (\XC_VM::module_license via
 * ModuleManager::renewModuleLicense), so a valid license never lapses while the
 * subscription is active. The SaaS refusing to mint (lapsed/revoked) lets the
 * license expire on its own — the effective kill-switch.
 *
 * Modules with NO .lic are unlicensed-encoded and skipped (no SaaS call), so on
 * setups where platform licensing is off this cron is a cheap no-op.
 *
 * @package XC_VM_CLI
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ModuleLicensesCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string {
        return 'cron:module_licenses';
    }

    public function getDescription(): string {
        return 'Cron: renew per-machine ionCube licenses for platform modules';
    }

    public function execute(array $rArgs): int {
        $this->setProcessTitle('xc_vm: module licenses');

        // Licensing only applies when the ionCube loader + extension are present.
        if (!function_exists('ioncube_server_data') || !class_exists('XC_VM')) {
            echo "ionCube loader/extension not present — nothing to do.\n";
            return 0;
        }

        $apiKey = (string) (SettingsManager::get('platform_api_key') ?? '');
        if ($apiKey === '') {
            echo "No platform API key configured — skipping.\n";
            return 0;
        }

        $manager = new ModuleManager(container: ServiceContainer::getInstance());

        $platform = array_filter(
            $manager->listModules(),
            static fn ($m) => ($m['source'] ?? '') === 'platform'
        );
        if (empty($platform)) {
            echo "No platform modules installed.\n";
            return 0;
        }

        $renewed = 0;
        $skipped = 0;
        foreach ($platform as $m) {
            $slug = (string) ($m['name'] ?? '');
            $dir  = (string) ($m['path'] ?? '');

            // Only renew modules that are actually licensed (carry a .lic).
            if ($slug === '' || $dir === '' || empty(glob($dir . '/*.lic'))) {
                continue;
            }

            if ($manager->renewModuleLicense($slug, $apiKey)) {
                echo "[OK]   {$slug}: license renewed\n";
                $renewed++;
            } else {
                echo "[WARN] {$slug}: not renewed (licensing off / not entitled / error)\n";
                $skipped++;
            }
        }

        echo "Done: {$renewed} renewed, {$skipped} skipped.\n";
        return 0;
    }
}
