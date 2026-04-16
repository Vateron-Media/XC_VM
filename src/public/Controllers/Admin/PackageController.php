<?php

/**
 * PackageController — Packages (admin/packages.php).
 *
 * Листинг пакетов (без addon'ов) с edit/delete.
 *
 * Legacy: admin/packages.php (239 строк)
 * Route:  GET /admin/packages → index()
 *
 * @renders Views/admin/packages.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class PackageController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $this->setTitle('Packages');

        $allPackages = PackageService::getAll();
        // Фильтруем addon-пакеты (показываем только обычные)
        $packages = array_filter($allPackages, function ($p) {
            return empty($p['is_addon']);
        });

        $this->render('packages', [
            'packages' => $packages,
        ]);
    }
}
