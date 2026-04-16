<?php

/**
 * SettingsPlexController — Plex Settings (admin/settings_plex.php).
 *
 * GET /settings_plex
 * Multi-tab form with plex scanner settings + movie/TV category mapping.
 * View content comes from modules/plex/views/.
 *
 * @renders Views/admin/settings_plex.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class SettingsPlexController extends BaseAdminController {
    public function index(): void {
        $this->requirePermission();

        $rBouquets = BouquetService::getAllSimple();
        if (!is_array($rBouquets)) {
            $rBouquets = [];
        }

        $this->setTitle('Plex Settings');
        $this->render('settings_plex', compact('rBouquets'));
    }
}
