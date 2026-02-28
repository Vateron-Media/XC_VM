<?php

/**
 * PackageController — Packages (admin/packages.php).
 *
 * Листинг пакетов (без addon'ов) с edit/delete.
 *
 * Legacy: admin/packages.php (239 строк)
 * Route:  GET /admin/packages → index()
 */
class PackageController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $this->setTitle('Packages');

        $allPackages = function_exists('getPackages') ? getPackages() : [];
        // Фильтруем addon-пакеты (показываем только обычные)
        $packages = array_filter($allPackages, function ($p) {
            return empty($p['is_addon']);
        });

        $this->render('packages', [
            'packages' => $packages,
        ]);
    }
}
