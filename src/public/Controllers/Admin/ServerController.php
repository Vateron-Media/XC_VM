<?php

/**
 * ServerController — редактирование сервера (admin/server.php).
 *
 * GET /server?id=N
 * Форма: 5 табов (Details, Domains & IPs, Advanced, Performance, SSL Certificate).
 */
class ServerController extends BaseAdminController
{
    public function index(): void
    {
        $this->requirePermission();

        global $allServers;

        $id = $this->input('id');
        if (!$id || !isset($allServers[$id])) {
            $this->redirect('servers');
            return;
        }

        $rServerArr = $allServers[$id];

        // Watchdog / CPU cores
        $rWatchdog = !empty($rServerArr['watchdog_data']) ? json_decode($rServerArr['watchdog_data'], true) : [];
        $rServiceMax = max(4, intval($rWatchdog['cpu_cores'] ?? 0) ?: 16);

        // Interfaces
        $rInterfaces = !empty($rServerArr['interfaces']) ? json_decode($rServerArr['interfaces'], true) : [];
        if (count($rInterfaces) === 0) {
            $rInterfaces = ['eth0'];
        }

        // Certificate
        $rCertificate = !empty($rServerArr['certbot_ssl']) ? json_decode($rServerArr['certbot_ssl'], true) : [];
        $rCertValid = false;
        if (isset($rCertificate['expiration'])) {
            $rHasCert = true;
            if (time() < $rCertificate['expiration']) {
                $rCertValid = true;
            }
            $rExpiration = date(\CoreUtilities::$rSettings['datetime_format'], $rCertificate['expiration']);
        } else {
            $rHasCert = false;
            $rExpiration = 'No Certificate Installed';
        }

        // Free space
        $rFS = ServerRepository::getFreeSpace('systemapirequest', $rServerArr['id']);
        $rMounted = false;
        foreach ($rFS as $rMount) {
            if ($rMount['mount'] === rtrim(STREAMS_PATH, '/')) {
                $rMounted = true;
                break;
            }
        }

        // SSL Log
        $rSSLLog = ServerRepository::getSSLLog(CoreUtilities::$rServers, $rServerArr['id']);

        $this->setTitle('Edit Server');
        $this->render('server', compact(
            'rServerArr',
            'rWatchdog',
            'rServiceMax',
            'rInterfaces',
            'rCertificate',
            'rCertValid',
            'rHasCert',
            'rExpiration',
            'rFS',
            'rMounted',
            'rSSLLog'
        ));
    }
}
