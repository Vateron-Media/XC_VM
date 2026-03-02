<?php

/**
 * MagscanSettingsController — MAGSCAN Settings (admin/magscan_settings.php).
 *
 * GET /magscan_settings
 * 3-tab form for MAC whitelist/blacklist and IP whitelist.
 */
class MagscanSettingsController extends BaseAdminController
{
    public function index(): void
    {
        $this->requirePermission();

        $this->setTitle('MAGSCAN Settings');
        $this->render('magscan_settings');
    }
}
