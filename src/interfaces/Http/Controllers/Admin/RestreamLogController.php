<?php

/**
 * RestreamLogController — Restream Detection Logs (admin/restream_logs.php).
 *
 * Server-side DataTable с логами обнаружения рестриминга. API block IP.
 *
 * Legacy: admin/restream_logs.php (197 строк)
 * Route:  GET /admin/restream_logs → index()
 */
class RestreamLogController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Restream Detection Logs');
        $this->render('restream_logs');
    }
}
