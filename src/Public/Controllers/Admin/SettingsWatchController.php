<?php

/**
 * SettingsWatchController — Watch Settings (admin/settings_watch.php).
 *
 * GET /settings_watch
 * Multi-tab form with watch scanner settings + movie/TV category mapping.
 * View content comes from modules/watch/views/.
 *
 * @renders Views/admin/settings_watch.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class SettingsWatchController extends BaseAdminController {
    public function index(): void {
        $this->requirePermission();

        $rBouquets = BouquetService::getAllSimple();
        if (!is_array($rBouquets)) {
            $rBouquets = [];
        }

        $this->setTitle('Watch Settings');
        $this->render('settings_watch', compact('rBouquets'));
    }
}
