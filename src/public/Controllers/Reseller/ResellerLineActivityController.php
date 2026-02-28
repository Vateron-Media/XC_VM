<?php
/**
 * ResellerLineActivityController — Line activity log (Phase 6.4 — Reseller).
 */
class ResellerLineActivityController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('line_activity');
    }
}
