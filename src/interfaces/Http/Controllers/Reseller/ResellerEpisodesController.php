<?php
/**
 * ResellerEpisodesController — Episodes listing (read-only) (Phase 6.4 — Reseller).
 */
class ResellerEpisodesController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('episodes');
    }
}
