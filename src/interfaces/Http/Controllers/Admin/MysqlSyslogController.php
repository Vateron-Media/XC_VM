<?php

/**
 * MysqlSyslogController — System Logs (admin/mysql_syslog.php).
 *
 * Server-side DataTable с системными логами. API block IP.
 *
 * Legacy: admin/mysql_syslog.php (251 строк)
 * Route:  GET /admin/mysql_syslog → index()
 */
class MysqlSyslogController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('System Logs');
        $this->render('mysql_syslog');
    }
}
