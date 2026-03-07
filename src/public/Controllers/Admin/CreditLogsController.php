<?php
/**
 * CreditLogsController — Credit Logs listing.
 */
class CreditLogsController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Credit Logs');
        $this->render('credit_logs');
    }
}
