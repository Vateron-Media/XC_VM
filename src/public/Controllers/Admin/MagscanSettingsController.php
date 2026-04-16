<?php

/**
 * MagscanSettingsController — MAGSCAN Settings (admin/magscan_settings.php).
 *
 * GET /magscan_settings
 * 3-tab form for MAC whitelist/blacklist and IP whitelist.
 *
 * @renders Views/admin/magscan_settings.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class MagscanSettingsController extends BaseAdminController {
    public function index(): void {
        $this->requirePermission();

        $this->setTitle('MAGSCAN Settings');
        $this->render('magscan_settings');
    }
}
