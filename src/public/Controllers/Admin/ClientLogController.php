<?php
/**
 * ClientLogController — клиентские логи (Phase 6.3 — Group C).
 */
class ClientLogController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('client_logs');
    }
}
