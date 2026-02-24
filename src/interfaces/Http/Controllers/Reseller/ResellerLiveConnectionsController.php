<?php
/**
 * ResellerLiveConnectionsController — Live connections (Phase 6.4 — Reseller).
 */
class ResellerLiveConnectionsController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('live_connections');
    }
}
