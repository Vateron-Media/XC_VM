<?php

/**
 * SettingsPlexController — Plex Settings (admin/settings_plex.php).
 *
 * GET /settings_plex
 * Multi-tab form with plex scanner settings + movie/TV category mapping.
 * View content comes from modules/plex/views/.
 */
class SettingsPlexController extends BaseAdminController
{
    public function index(): void
    {
        $this->requirePermission();

        $rBouquets = BouquetService::getAllSimple();
        if (!is_array($rBouquets)) {
            $rBouquets = [];
        }

        $this->setTitle('Plex Settings');
        $this->render('settings_plex', compact('rBouquets'));
    }
}
