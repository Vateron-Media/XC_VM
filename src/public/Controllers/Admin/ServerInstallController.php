<?php

/**
 * ServerInstallController — установка/переустановка сервера (admin/server_install.php).
 *
 * GET /server_install?proxy (type=1) или GET /server_install (type=2)
 * Опционально: ?id=N (reinstall), ?update (update).
 * Форма: 1–2 таба (Details + Server Coverage для proxy).
 */
class ServerInstallController extends BaseAdminController
{
    public function index(): void
    {
        $this->requirePermission();

        global $allServers, $rProxyServers;

        $rType = isset(\CoreUtilities::$rRequest['proxy']) ? 1 : 2;
        $rServerArr = null;

        if ($this->input('id')) {
            $id = intval($this->input('id'));
            if ($rType === 1) {
                $rServerArr = $rProxyServers[$id] ?? null;
            } else {
                $rServerArr = $allServers[$id] ?? null;
            }

            if (!$rServerArr) {
                $this->redirect('servers');
                return;
            }
        }

        $title = ($rType === 1) ? 'Install Proxy' : 'Install Server';
        $this->setTitle($title);

        $this->render('server_install', compact('rType', 'rServerArr'));
    }
}
