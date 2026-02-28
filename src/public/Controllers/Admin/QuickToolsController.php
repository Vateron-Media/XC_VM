<?php
/**
 * QuickToolsController — Quick Tools (Phase 6.3 — Group M).
 */
class QuickToolsController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Quick Tools');
        $this->render('quick_tools');
    }
}
