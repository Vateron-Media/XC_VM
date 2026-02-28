<?php
/**
 * ResellerEpgViewController — EPG preview (Phase 6.4 — Reseller).
 */
class ResellerEpgViewController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('epg_view');
    }
}
