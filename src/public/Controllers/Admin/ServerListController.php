<?php

/**
 * ServerListController — список серверов (admin/servers.php).
 *
 * GET /servers
 * Client-side DataTable — PHP рендерит <tbody>.
 * Данные: ServerRepository::getAll(true).
 */
class ServerListController extends BaseAdminController
{
    public function index(): void
    {
        $this->requirePermission();
        $this->setTitle('Servers');

        \ServerRepository::getAll(true);

        $this->render('servers');
    }
}
