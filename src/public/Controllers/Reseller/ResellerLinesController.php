<?php
/**
 * ResellerLinesController — Lines listing (Phase 6.4 — Reseller).
 */
class ResellerLinesController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('lines');
    }
}
