<?php
/**
 * ResellerUserLogsController — Sub-reseller login logs.
 */
class ResellerUserLogsController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('User Logs');
        $this->render('user_logs');
    }
}
