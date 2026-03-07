<?php
/**
 * WatchController — Watch Folder listing.
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
