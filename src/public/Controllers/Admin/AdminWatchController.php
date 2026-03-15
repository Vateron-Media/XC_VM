<?php
/**
 * AdminWatchController — Watch Folder listing (admin wrapper).
 */
class AdminWatchController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Watch Folder');
        $this->render('watch');
    }
}
