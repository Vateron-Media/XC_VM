<?php
/**
 * ClientLogController — клиентские логи.
 */
class ClientLogController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('client_logs');
    }
}
