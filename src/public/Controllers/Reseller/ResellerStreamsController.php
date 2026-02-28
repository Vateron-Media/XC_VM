<?php
/**
 * ResellerStreamsController — Streams listing (read-only) (Phase 6.4 — Reseller).
 */
class ResellerStreamsController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('streams');
    }
}
