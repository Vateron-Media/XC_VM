<?php
/**
 * LineListController — список линий (Phase 6.3 — Group C).
 */
class LineListController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('lines');
    }
}
