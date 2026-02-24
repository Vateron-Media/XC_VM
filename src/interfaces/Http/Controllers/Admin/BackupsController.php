<?php
/**
 * BackupsController — Backups listing (Phase 6.3 — Group M).
 */
class BackupsController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Backups');
        $this->render('backups');
    }
}
