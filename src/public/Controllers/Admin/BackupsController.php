<?php
/**
 * BackupsController — Backups listing.
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
