<?php
/**
 * ResellerUserLogsController — Sub-reseller login logs.
 */
class ResellerUserLogsController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('user_logs');
    }
}
