<?php
/**
 * ResellerUserLogsController — Sub-reseller login logs (Phase 6.4 — Reseller).
 */
class ResellerUserLogsController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('user_logs');
    }
}
