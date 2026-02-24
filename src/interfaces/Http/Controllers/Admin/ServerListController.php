<?php

/**
 * ServerListController — список серверов (admin/servers.php).
 *
 * GET /servers
 * Client-side DataTable — PHP рендерит <tbody>.
 * Данные: CoreUtilities::getServers(true).
 */
class ServerListController extends BaseAdminController
{
    public function index(): void
    {
        $this->requirePermission();
        $this->setTitle('Servers');

        \CoreUtilities::$rServers = \CoreUtilities::getServers(true);

        $this->render('servers');
    }
}
