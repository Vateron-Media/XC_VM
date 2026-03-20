<?php

/**
 * ServerViewController — просмотр сервера (admin/server_view.php).
 *
 * GET /server_view?id=N
 * 3 server-side DataTables (streams, connections, live).
 * ApexCharts (CPU/Memory/IO, Network).
 * Progress bars + GPU info.
 */
class ServerViewController extends BaseAdminController
{
    public function index(): void
    {
        $this->requirePermission();

        global $allServers, $rProxyServers, $db;

        $id = $this->input('id');
        if (!$id) {
            exit();
        }

        if (isset($allServers[$id])) {
            $rServer = $allServers[$id];
        } elseif (isset($rProxyServers[$id])) {
            $rServer = $rProxyServers[$id];
        } else {
            exit();
        }

        // Watchdog data
        $rWatchdog = json_decode($rServer['watchdog_data'], true);
        $rServer['gpu_info'] = json_decode($rServer['gpu_info'], true);

        // Stats for charts
        $rStats = [
            'cpu'    => [],
            'memory' => [],
            'io'     => [],
            'input'  => [],
            'output' => [],
            'dates'  => [null, null],
        ];

        foreach (ProcessChecker::getWatchdog($rServer['id']) as $rData) {
            if (!$rStats['dates'][0] || $rData['time'] * 1000 <= $rStats['dates'][0]) {
                $rStats['dates'][0] = $rData['time'] * 1000;
            }
            if (!$rStats['dates'][1] || $rData['time'] * 1000 >= $rStats['dates'][1]) {
                $rStats['dates'][1] = $rData['time'] * 1000;
            }

            $rStats['cpu'][]    = [$rData['time'] * 1000, floatval(rtrim($rData['cpu'], '%'))];
            $rStats['memory'][] = [$rData['time'] * 1000, floatval(rtrim($rData['total_mem_used_percent'], '%'))];
            $rStats['io'][]     = [$rData['time'] * 1000, floatval(json_decode($rData['iostat_info'], true)['avg-cpu']['iowait'] ?? 0)];
            $rStats['input'][]  = [$rData['time'] * 1000, round($rData['bytes_received'] / 125000, 0)];
            $rStats['output'][] = [$rData['time'] * 1000, round($rData['bytes_sent'] / 125000, 0)];
        }

        // Certificate
        $rCertificate = json_decode($rServer['certbot_ssl'], true);
        $rCertValid = false;
        if (!empty($rCertificate['expiration'])) {
            $rHasCert = true;
            if (time() < $rCertificate['expiration']) {
                $rCertValid = true;
            }
            $rExpiration = date(\SettingsManager::getAll()['datetime_format'], $rCertificate['expiration']);
        } else {
            $rHasCert = false;
            $rExpiration = 'No Certificate Installed';
        }

        $title = ($rServer['server_type'] == 0) ? 'View Server' : 'View Proxy';
        $this->setTitle($title);

        $this->render('server_view', compact(
            'rServer',
            'rWatchdog',
            'rStats',
            'rCertificate',
            'rCertValid',
            'rHasCert',
            'rExpiration'
        ));
    }
}
