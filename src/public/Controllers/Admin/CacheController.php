<?php
/**
 * CacheController — Cache & Redis Settings (Phase 6.3 — Group M).
 */
class CacheController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        CoreUtilities::$rSettings = CoreUtilities::getSettings(true);
        $GLOBALS['rSettings'] = CoreUtilities::$rSettings;

        $this->setTitle('Cache & Redis Settings');
        $this->render('cache');
    }
}
