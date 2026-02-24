<?php
/**
 * IpEditController — add/edit blocked IP (Phase 6.3 — Group H).
 *
 * Route: GET /admin/ip → index()
 */
class IpEditController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Blocked IP');
        $this->render('ip');
    }
}
