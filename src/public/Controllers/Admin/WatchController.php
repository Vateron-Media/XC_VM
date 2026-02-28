<?php
/**
 * WatchController — Watch Folder listing (Phase 6.3 — Group L).
 */
class WatchController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Watch Folder');
        $this->render('watch');
    }
}
