<?php

/**
 * SettingsWatchController — Watch Settings (admin/settings_watch.php).
 *
 * GET /settings_watch
 * Multi-tab form with watch scanner settings + movie/TV category mapping.
 * View content comes from modules/watch/views/.
 */
class SettingsWatchController extends BaseAdminController
{
    public function index(): void
    {
        $this->requirePermission();

        $rBouquets = BouquetService::getAllSimple();
        if (!is_array($rBouquets)) {
            $rBouquets = [];
        }

        $this->setTitle('Watch Settings');
        $this->render('settings_watch', compact('rBouquets'));
    }
}
