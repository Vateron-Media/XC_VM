<?php
/**
 * CacheController — Cache & Redis Settings.
 */
class CacheController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        SettingsManager::set(SettingsRepository::getAll(true));
        $GLOBALS['rSettings'] = SettingsManager::getAll();

        $this->setTitle('Cache & Redis Settings');
        $this->render('cache');
    }
}
