<?php
/**
 * ResellerCreatedChannelsController — Created channels listing (read-only) (Phase 6.4 — Reseller).
 */
class ResellerCreatedChannelsController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('created_channels');
    }
}
