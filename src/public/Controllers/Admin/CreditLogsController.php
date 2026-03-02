<?php
/**
 * CreditLogsController — Credit Logs listing (Phase 6.3 — Group N).
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
