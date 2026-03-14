<?php

require_once __DIR__ . '/../CronTrait.php';

class RootSignalsCronJob implements CommandInterface {
    use CronTrait;

    private $rSaveIPTables = false;
    private $AutoUpdateServerIP = true;

    public function getName(): string {
        return 'cron:root_signals';
    }

    public function getDescription(): string {
        return 'Cron: process signals, iptables, nginx, service management (root)';
    }

    public function execute(array $rArgs): int {
        if (!$this->assertRunAsRoot()) {
            return 1;
        }

        set_time_limit(0);
        register_shutdown_function([$this, 'shutdown']);

        $this->rIdentifier = CRONS_TMP_PATH . md5(Encryption::generateUniqueCode(SettingsManager::getAll()['live_streaming_pass']) . static::class);
        ProcessManager::acquireCronLock($this->rIdentifier);

        $pids = shell_exec("pgrep -f 'XC_VM\[Signals\]'");
        if (!empty($pids)) {
            shell_exec("sudo kill -9 $pids");
        }
        cli_set_process_title('XC_VM[Signals]');
        file_put_contents(CONFIG_PATH . 'signals.last', time());

        $this->loadCron();

        return 0;
    }

    private function blockip($rIP): bool {
        $isPrivate = false;

        if (filter_var($rIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $isPrivate = filter_var($rIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            $isPrivate = !$isPrivate;

            if (!$isPrivate) {
                $isPrivate = (strpos($rIP, '127.') === 0) || ($rIP === '0.0.0.0');
            }

            if (!$isPrivate) {
                exec('sudo iptables -I INPUT -s ' . escapeshellcmd($rIP) . ' -j DROP');
            }
        } elseif (filter_var($rIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $isPrivate = (
                strpos($rIP, 'fc') === 0 ||
                strpos($rIP, 'fd') === 0 ||
                strpos($rIP, 'fe80') === 0 ||
                $rIP === '::1' ||
                strpos($rIP, '2001:db8') === 0
            );

            if (!$isPrivate) {
                exec('sudo ip6tables -I INPUT -s ' . escapeshellcmd($rIP) . ' -j DROP');
            }
        }

        if (!$isPrivate && $rIP) {
            touch(FLOOD_TMP_PATH . 'block_' . $rIP);
            return true;
        } elseif ($isPrivate) {
            error_log("Block attempt denied for private IP: " . $rIP);
            return false;
        }

        return false;
    }

    private function unblockip($rIP): void {
        if (filter_var($rIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            exec('sudo iptables -D INPUT -s ' . escapeshellcmd($rIP) . ' -j DROP');
        } elseif (filter_var($rIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            exec('sudo ip6tables -D INPUT -s ' . escapeshellcmd($rIP) . ' -j DROP');
        }
        if (file_exists(FLOOD_TMP_PATH . 'block_' . $rIP)) {
            unlink(FLOOD_TMP_PATH . 'block_' . $rIP);
        }
    }

    private function flushIPs(): void {
        exec('sudo iptables -F && sudo ip6tables -F');
        shell_exec('sudo rm ' . FLOOD_TMP_PATH . 'block_*');
    }

    private function saveiptables(): void {
        exec('sudo iptables-save && sudo ip6tables-save');
    }

    private function getBlockedIPs(): array {
        $rReturn = [];
        exec('sudo iptables -nL --line-numbers -t filter', $rLines);
        foreach ($rLines as $rLine) {
            $rLine = explode(' ', preg_replace('!\\s+!', ' ', $rLine));
            if (isset($rLine[1], $rLine[4]) && $rLine[1] == 'DROP') {
                $rReturn[] = $rLine[4];
            }
        }
        $rLines = '';
        exec('sudo ip6tables -nL --line-numbers -t filter', $rLines);
        foreach ($rLines as $rLine) {
            $rLine = explode(' ', preg_replace('!\\s+!', ' ', $rLine));
            if (isset($rLine[1], $rLine[3]) && $rLine[1] == 'DROP') {
                $rReturn[] = $rLine[3];
            }
        }
        return $rReturn;
    }

    private function getServerIP(?string $interface = null): ?string {
        if ($interface === null) {
            $route = shell_exec('ip route show default 2>/dev/null');
            if ($route && preg_match('/dev\s+([^\s]+)/', $route, $m)) {
                $interface = $m[1];
            } else {
                return null;
            }
        }

        $output = shell_exec(
            'ip -j addr show ' . escapeshellarg($interface) . ' 2>/dev/null'
        );

        if (!$output) {
            return null;
        }

        $data = json_decode($output, true);
        if (empty($data[0]['addr_info'])) {
            return null;
        }

        foreach ($data[0]['addr_info'] as $addr) {
            if (($addr['family'] ?? null) === 'inet') {
                return $addr['local'] ?? null;
            }
        }

        return null;
    }

    private function loadCron(): void {
        global $db;
        $rServers = ServerRepository::getAll(true);
        $db->query("SELECT `signal_id` FROM `signals` WHERE `server_id` = ? AND `custom_data` = '{\"action\":\"flush\"}' AND `cache` = 0;", SERVER_ID);
        if ($db->num_rows() > 0) {
            echo "Flushing IP's...";
            $this->flushIPs();
            $this->saveiptables();
            $db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'FLUSH', 'Flushed blocked IP\\'s from iptables.', 'root', 'localhost', NULL, ?);", SERVER_ID, time());
            $db->query("DELETE FROM `signals` WHERE `server_id` = ? AND `custom_data` = '{\"action\":\"flush\"}' AND `cache` = 0;", SERVER_ID);
        } else {
            $rActualBlocked = $this->getBlockedIPs();
            $rActualBlockedFlip = array_flip($rActualBlocked);
            $db->query('SELECT `ip` FROM `blocked_ips`;');
            $rBlocked = array_keys($db->get_rows(true, 'ip'));
            $rBlockedFlip = array_flip($rBlocked);
            $rAdd = $rDel = [];
            foreach (array_count_values($rActualBlocked) as $rIP => $rCount) {
                if ($rCount > 1) {
                    echo $rCount . "\n";
                    foreach (range(1, $rCount - 1) as $i) {
                        $rDel[] = $rIP;
                    }
                }
            }
            foreach ($rBlocked as $rIP) {
                if (!isset($rActualBlockedFlip[$rIP])) {
                    $rAdd[] = $rIP;
                }
            }
            foreach ($rActualBlocked as $rIP) {
                if (!isset($rBlockedFlip[$rIP])) {
                    $rDel[] = $rIP;
                }
            }
            if (count($rDel) > 0) {
                $this->rSaveIPTables = true;
                foreach ($rDel as $rIP) {
                    echo 'Unblock IP: ' . $rIP . "\n";
                    $this->unblockip($rIP);
                }
            }
            if (count($rAdd) > 0) {
                $this->rSaveIPTables = true;
                foreach ($rAdd as $rIP) {
                    echo 'Block IP: ' . $rIP . "\n";
                    $this->blockip($rIP);
                }
            }
            if ($this->rSaveIPTables) {
                $this->saveiptables();
                $this->rSaveIPTables = false;
            }
        }
        $rReload = false;
        $rAllowedIPs = ServerRepository::getAllowedIPs();
        $rXC_VMList = [];
        foreach ($rAllowedIPs as $rIP) {
            if (!empty($rIP) && filter_var($rIP, FILTER_VALIDATE_IP)) {
                $newEntry = 'set_real_ip_from ' . $rIP . ';';
                if (!in_array($newEntry, $rXC_VMList)) {
                    $rXC_VMList[] = $newEntry;
                }
            }
        }
        $rXC_VMList = trim(implode("\n", array_unique($rXC_VMList)));
        $rCurrentList = (trim(file_get_contents(BIN_PATH . 'nginx/conf/realip_xc_vm.conf')) ?: '');
        if ($rXC_VMList != $rCurrentList) {
            echo 'Updating XC_VM IP List...' . "\n";
            file_put_contents(BIN_PATH . 'nginx/conf/realip_xc_vm.conf', $rXC_VMList);
            $rReload = true;
        }
        $rCurrentList = (trim(file_get_contents(BIN_PATH . 'nginx/conf/realip_cloudflare.conf')) ?: '');
        if (SettingsManager::getAll()['cloudflare']) {
            if (empty($rCurrentList)) {
                echo 'Enabling Cloudflare...' . "\n";
                file_put_contents(BIN_PATH . 'nginx/conf/realip_cloudflare.conf', 'set_real_ip_from 103.21.244.0/22;' . "\n" . 'set_real_ip_from 103.22.200.0/22;' . "\n" . 'set_real_ip_from 103.31.4.0/22;' . "\n" . 'set_real_ip_from 104.16.0.0/13;' . "\n" . 'set_real_ip_from 104.24.0.0/14;' . "\n" . 'set_real_ip_from 108.162.192.0/18;' . "\n" . 'set_real_ip_from 131.0.72.0/22;' . "\n" . 'set_real_ip_from 141.101.64.0/18;' . "\n" . 'set_real_ip_from 162.158.0.0/15;' . "\n" . 'set_real_ip_from 172.64.0.0/13;' . "\n" . 'set_real_ip_from 173.245.48.0/20;' . "\n" . 'set_real_ip_from 188.114.96.0/20;' . "\n" . 'set_real_ip_from 190.93.240.0/20;' . "\n" . 'set_real_ip_from 197.234.240.0/22;' . "\n" . 'set_real_ip_from 198.41.128.0/17;' . "\n" . 'set_real_ip_from 2400:cb00::/32;' . "\n" . 'set_real_ip_from 2606:4700::/32;' . "\n" . 'set_real_ip_from 2803:f800::/32;' . "\n" . 'set_real_ip_from 2405:b500::/32;' . "\n" . 'set_real_ip_from 2405:8100::/32;' . "\n" . 'set_real_ip_from 2c0f:f248::/32;' . "\n" . 'set_real_ip_from 2a06:98c0::/29;');
                $rReload = true;
            }
        } else {
            if (!empty($rCurrentList)) {
                echo 'Disabling Cloudflare...' . "\n";
                file_put_contents(BIN_PATH . 'nginx/conf/realip_cloudflare.conf', '');
                $rReload = true;
            }
        }
        if ($rServers[SERVER_ID]['is_main']) {
            $rCurrentStatus = stripos((trim(file_get_contents(BIN_PATH . 'nginx/conf/gzip.conf')) ?: 'gzip off'), 'gzip on') !== false;
            if ($rServers[SERVER_ID]['enable_gzip']) {
                if (!$rCurrentStatus) {
                    echo 'Enabling GZIP...' . "\n";
                    file_put_contents(BIN_PATH . 'nginx/conf/gzip.conf', 'gzip on;' . "\n" . 'gzip_min_length 1000;' . "\n" . 'gzip_buffers 4 32k;' . "\n" . 'gzip_proxied any;' . "\n" . 'gzip_types application/json application/xml;' . "\n" . 'gzip_vary on;' . "\n" . 'gzip_disable "MSIE [1-6].(?!.*SV1)";');
                    $rReload = true;
                }
            } else {
                if ($rCurrentStatus) {
                    echo 'Disabling GZIP...' . "\n";
                    file_put_contents(BIN_PATH . 'nginx/conf/gzip.conf', 'gzip off;');
                    $rReload = true;
                }
            }

            $rServerIP = $this->getServerIP(($rServers[SERVER_ID]['network_interface'] == 'auto' ? null : $rServers[SERVER_ID]['network_interface']));
            if ($rServerIP && $rServerIP != $rServers[SERVER_ID]['server_ip'] && $this->AutoUpdateServerIP) {
                echo 'Updating server IP from ' . $rServers[SERVER_ID]['server_ip'] . ' to ' . $rServerIP . '...' . "\n";
                $db->query('UPDATE `servers` SET `server_ip` = ? WHERE `id` = ?;', $rServerIP, SERVER_ID);
                $rServers[SERVER_ID]['server_ip'] = $rServerIP;
            }

            if (empty(SettingsManager::getAll()['live_streaming_pass']) || SettingsManager::getAll()['live_streaming_pass'] === null) {
                $db->query('UPDATE `settings` SET `live_streaming_pass` = ?', Encryption::randomString(40));
            }
        }
        if ($rServers[SERVER_ID]['limit_requests'] > 0) {
            $rLimitConf = 'limit_req_zone global zone=two:10m rate=' . intval($rServers[SERVER_ID]['limit_requests']) . 'r/s;';
        } else {
            $rLimitConf = '';
        }
        $rCurrentConf = (trim(file_get_contents(BIN_PATH . 'nginx/conf/limit.conf')) ?: '');
        if ($rLimitConf != $rCurrentConf) {
            echo 'Updating rate limit...' . "\n";
            file_put_contents(BIN_PATH . 'nginx/conf/limit.conf', $rLimitConf);
            $rReload = true;
        }
        if ($rServers[SERVER_ID]['limit_requests'] > 0) {
            $rLimitConf = 'limit_req zone=two burst=' . intval($rServers[SERVER_ID]['limit_burst']) . ';';
        } else {
            $rLimitConf = '';
        }
        $rCurrentConf = (trim(file_get_contents(BIN_PATH . 'nginx/conf/limit_queue.conf')) ?: '');
        if ($rLimitConf != $rCurrentConf) {
            echo 'Updating rate limit queue...' . "\n";
            file_put_contents(BIN_PATH . 'nginx/conf/limit_queue.conf', $rLimitConf);
            $rReload = true;
        }
        if ($rReload) {
            shell_exec('sudo ' . BIN_PATH . 'nginx/sbin/nginx -s reload');
        }
        if (SettingsManager::getAll()['restart_php_fpm']) {
            $rPHP = $rNginx = 0;
            exec('ps -fp ' . trim(shell_exec('pgrep -u xc_vm | tr "\n" "," | sed "s/,$//"')), $rOutput, $rReturnVar);
            foreach ($rOutput as $rProcess) {
                $rSplit = explode(' ', preg_replace('!\\s+!', ' ', trim($rProcess)));
                if (isset($rSplit[8], $rSplit[9]) && $rSplit[8] == 'php-fpm:' && $rSplit[9] == 'master') {
                    $rPHP++;
                }
                if (isset($rSplit[8], $rSplit[9]) && $rSplit[8] == 'nginx:' && $rSplit[9] == 'master') {
                    $rNginx++;
                }
            }
            if ($rNginx > 0) {
                if ($rPHP == 0) {
                    echo 'PHP-FPM ERROR - Restarting...';
                    $db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'PHP-FPM', 'Restarted PHP-FPM instances due to a suspected crash.', 'root', 'localhost', NULL, ?);", SERVER_ID, time());
                    shell_exec('sudo systemctl stop xc_vm');
                    shell_exec('sudo systemctl start xc_vm');
                    exit();
                }
            }
            $rHandle = curl_init('http://127.0.0.1:' . $rServers[SERVER_ID]['http_broadcast_port'] . '/init');
            curl_setopt($rHandle, CURLOPT_RETURNTRANSFER, true);
            $rResponse = curl_exec($rHandle);
            $rCode = curl_getinfo($rHandle, CURLINFO_HTTP_CODE);
            if (!in_array($rCode, [500, 502])) {
                curl_close($rHandle);
            } else {
                echo $rCode . ' ERROR - Restarting...';
                $db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'PHP-FPM', 'Restarted services due to " . $rCode . " error.', 'root', 'localhost', NULL, ?);", SERVER_ID, time());
                shell_exec('sudo systemctl stop xc_vm');
                shell_exec('sudo systemctl start xc_vm');
                exit();
            }
        }
        if ($db->query("SELECT `signal_id`, `custom_data` FROM `signals` WHERE `server_id` = ? AND `custom_data` <> '' AND `cache` = 0 ORDER BY signal_id ASC;", SERVER_ID)) {
            $rRows = $db->get_rows();
            $rCheck = ['mag' => false, 'php' => false, 'services' => false, 'ports' => false, 'ramdisk' => false];
            foreach ($rRows as $rRow) {
                $rData = json_decode($rRow['custom_data'], true);
                switch ($rData['action']) {
                    case 'disable_ramdisk':
                    case 'enable_ramdisk':
                        $rCheck['ramdisk'] = true;
                        break;
                    case 'enable_ministra':
                    case 'disable_ministra':
                        $rCheck['mag'] = true;
                        break;
                    case 'set_services':
                        $rCheck['services'] = true;
                        break;
                    case 'set_port':
                        $rCheck['ports'] = true;
                        break;
                }
            }
            if ($rCheck['mag']) {
                if (SettingsManager::getAll()['mag_legacy_redirect']) {
                    if (!file_exists(MAIN_HOME . 'www/c')) {
                        array_unshift($rRows, ['custom_data' => json_encode(['action' => 'enable_ministra'])]);
                    }
                } else {
                    if (file_exists(MAIN_HOME . 'www/c')) {
                        array_unshift($rRows, ['custom_data' => json_encode(['action' => 'disable_ministra'])]);
                    }
                }
            }
            if ($rCheck['services']) {
                $rCurServices = 0;
                $rStartScript = explode("\n", file_get_contents(MAIN_HOME . 'bin/daemons.sh'));
                foreach ($rStartScript as $rLine) {
                    if (explode(' ', $rLine)[0] == 'start-stop-daemon') {
                        $rCurServices++;
                    }
                }
                if ($rServers[SERVER_ID]['total_services'] != $rCurServices) {
                    array_unshift($rRows, ['custom_data' => json_encode(['action' => 'set_services', 'count' => $rServers[SERVER_ID]['total_services'], 'reload' => true])]);
                }
            }
            if ($rCheck['ports']) {
                $rListen = $rPorts = ['http' => [], 'https' => []];
                foreach (array_merge([intval($rServers[SERVER_ID]['http_broadcast_port'])], explode(',', $rServers[SERVER_ID]['http_ports_add'])) as $rPort) {
                    if (is_numeric($rPort) && $rPort > 0 && $rPort <= 65535) {
                        $rListen['http'][] = 'listen ' . intval($rPort) . ';';
                        $rPorts['http'][] = intval($rPort);
                    }
                }
                foreach (array_merge([intval($rServers[SERVER_ID]['https_broadcast_port'])], explode(',', $rServers[SERVER_ID]['https_ports_add'])) as $rPort) {
                    if (is_numeric($rPort) && $rPort > 0 && $rPort <= 65535) {
                        $rListen['https'][] = 'listen ' . intval($rPort) . ' ssl;';
                        $rPorts['https'][] = intval($rPort);
                    }
                }
                if (trim(implode(' ', $rListen['http'])) != trim(file_get_contents(MAIN_HOME . 'bin/nginx/conf/ports/http.conf'))) {
                    array_unshift($rRows, ['custom_data' => json_encode(['action' => 'set_port', 'type' => 0, 'ports' => $rPorts['http'], 'reload' => true])]);
                }
                if (trim(implode(' ', $rListen['https'])) != trim(file_get_contents(MAIN_HOME . 'bin/nginx/conf/ports/https.conf'))) {
                    array_unshift($rRows, ['custom_data' => json_encode(['action' => 'set_port', 'type' => 1, 'ports' => $rPorts['https'], 'reload' => true])]);
                }
                if ('listen ' . intval($rServers[SERVER_ID]['rtmp_port']) . ';' != trim(file_get_contents(MAIN_HOME . 'bin/nginx_rtmp/conf/port.conf'))) {
                    array_unshift($rRows, ['custom_data' => json_encode(['action' => 'set_port', 'type' => 2, 'ports' => [intval($rServers[SERVER_ID]['rtmp_port'])], 'reload' => true])]);
                }
            }
            if ($rCheck['ramdisk']) {
                $rMounted = false;
                exec('df -h', $rLines);
                array_shift($rLines);
                foreach ($rLines as $rLine) {
                    $rSplit = explode(' ', preg_replace('!\\s+!', ' ', trim($rLine)));
                    if (implode(' ', array_slice($rSplit, 5, count($rSplit) - 5)) == rtrim(STREAMS_PATH, '/')) {
                        $rMounted = true;
                        break;
                    }
                }
                if ($rServers[SERVER_ID]['use_disk']) {
                    if ($rMounted) {
                        array_unshift($rRows, ['custom_data' => json_encode(['action' => 'disable_ramdisk'])]);
                    }
                } else {
                    if (!$rMounted) {
                        array_unshift($rRows, ['custom_data' => json_encode(['action' => 'enable_ramdisk'])]);
                    }
                }
            }
            if (file_exists(TMP_PATH . 'crontab')) {
                echo 'Checking crontab...' . "\n";
                exec('crontab -u xc_vm -l', $rCrons);
                $rCurrentCron = trim(implode("\n", $rCrons));
                $rJobs = [];
                $db->query('SELECT * FROM `crontab` WHERE `enabled` = 1;');
                foreach ($db->get_rows() as $rRow) {
                    $rJobs[] = $rRow['time'] . ' ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:' . $rRow['filename'] . ' # XC_VM';
                }
                $rActualCron = trim(implode("\n", $rJobs));
                if ($rCurrentCron != $rActualCron) {
                    echo 'Updating Crons...' . "\n";
                    unlink(TMP_PATH . 'crontab');
                } else {
                    echo "Crons valid.\n";
                }
            }
            if (file_exists(CONFIG_PATH . 'sysctl.on')) {
                if (strtoupper(substr(explode("\n", file_get_contents('/etc/sysctl.conf'))[0], 0, 9)) != '# XC_VM') {
                    echo 'Sysctl missing! Writing it.' . "\n";
                    exec('sudo modprobe ip_conntrack');
                    file_put_contents('/etc/sysctl.conf', implode(PHP_EOL, ['# XC_VM', '', 'net.core.somaxconn = 655350', 'net.ipv4.route.flush=1', 'net.ipv4.tcp_no_metrics_save=1', 'net.ipv4.tcp_moderate_rcvbuf = 1', 'fs.file-max = 6815744', 'fs.aio-max-nr = 6815744', 'fs.nr_open = 6815744', 'net.ipv4.ip_local_port_range = 1024 65000', 'net.ipv4.tcp_sack = 1', 'net.ipv4.tcp_rmem = 10000000 10000000 10000000', 'net.ipv4.tcp_wmem = 10000000 10000000 10000000', 'net.ipv4.tcp_mem = 10000000 10000000 10000000', 'net.core.rmem_max = 524287', 'net.core.wmem_max = 524287', 'net.core.rmem_default = 524287', 'net.core.wmem_default = 524287', 'net.core.optmem_max = 524287', 'net.core.netdev_max_backlog = 300000', 'net.ipv4.tcp_max_syn_backlog = 300000', 'net.netfilter.nf_conntrack_max=1215196608', 'net.ipv4.tcp_window_scaling = 1', 'vm.max_map_count = 655300', 'net.ipv4.tcp_max_tw_buckets = 50000', 'net.ipv6.conf.all.disable_ipv6 = 1', 'net.ipv6.conf.default.disable_ipv6 = 1', 'net.ipv6.conf.lo.disable_ipv6 = 1', 'kernel.shmmax=134217728', 'kernel.shmall=134217728', 'vm.overcommit_memory = 1', 'net.ipv4.tcp_tw_reuse=1']));
                    exec('sudo sysctl -p > /dev/null');
                }
            }
            if (count($rRows) > 0) {
                foreach ($rRows as $rRow) {
                    $rData = json_decode($rRow['custom_data'], true);
                    if (!empty($rRow['signal_id'])) {
                        $db->query('DELETE FROM `signals` WHERE `signal_id` = ?;', $rRow['signal_id']);
                    }
                    switch ($rData['action']) {
                        case 'reboot':
                            echo 'Rebooting system...' . "\n";
                            $db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'REBOOT', 'System rebooted on request.', 'root', 'localhost', NULL, ?);", SERVER_ID, time());
                            $db->close_mysql();
                            shell_exec('sudo reboot');
                            break;
                        case 'restart_services':
                            echo 'Restarting services...' . "\n";
                            $db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'RESTART', 'XC_VM services restarted on request.', 'root', 'localhost', NULL, ?);", SERVER_ID, time());
                            shell_exec('sudo systemctl stop xc_vm');
                            shell_exec('sudo systemctl start xc_vm');
                            break;
                        case 'stop_services':
                            echo 'Stopping services...' . "\n";
                            $db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'STOP', 'XC_VM services stopped on request.', 'root', 'localhost', NULL, ?);", SERVER_ID, time());
                            shell_exec('sudo systemctl stop xc_vm');
                            break;
                        case 'reload_nginx':
                            echo 'Reloading nginx...' . "\n";
                            $db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'RELOAD', 'NGINX services reloaded on request.', 'root', 'localhost', NULL, ?);", SERVER_ID, time());
                            shell_exec('sudo ' . BIN_PATH . 'nginx_rtmp/sbin/nginx_rtmp -s reload');
                            shell_exec('sudo ' . BIN_PATH . 'nginx/sbin/nginx -s reload');
                            break;
                        case 'disable_ramdisk':
                            echo 'Disabling ramdisk...' . "\n";
                            $rFstab = file_get_contents('/etc/fstab');
                            $rOutput = [];
                            foreach (explode("\n", $rFstab) as $rLine) {
                                if (substr($rLine, 0, 31) == 'tmpfs /home/xc_vm/content/streams') {
                                    $rLine = '#' . $rLine;
                                }
                                $rOutput[] = $rLine;
                            }
                            file_put_contents('/etc/fstab', implode("\n", $rOutput));
                            shell_exec('sudo umount -l ' . STREAMS_PATH);
                            shell_exec('sudo chown -R xc_vm:xc_vm ' . STREAMS_PATH);
                            break;
                        case 'enable_ramdisk':
                            echo 'Enabling ramdisk...' . "\n";
                            $rFstab = file_get_contents('/etc/fstab');
                            $rOutput = [];
                            foreach (explode("\n", $rFstab) as $rLine) {
                                if (substr($rLine, 0, 32) == '#tmpfs /home/xc_vm/content/streams') {
                                    $rLine = ltrim($rLine, '#');
                                }
                                $rOutput[] = $rLine;
                            }
                            file_put_contents('/etc/fstab', implode("\n", $rOutput));
                            shell_exec('sudo mount ' . STREAMS_PATH);
                            shell_exec('sudo chown -R xc_vm:xc_vm ' . STREAMS_PATH);
                            break;
                        case 'certbot_generate':
                            echo 'Generating certbot certificate.' . "\n";
                            $db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'CERTBOT', 'Attempting to generate certbot certificate on request.', 'root', 'localhost', NULL, ?);", SERVER_ID, time());
                            shell_exec('sudo ' . PHP_BIN . ' ' . INCLUDES_PATH . 'cli/certbot.php "' . base64_encode(json_encode($rData)) . '" 2>&1 &');
                            break;
                        case 'update_binaries':
                            echo 'Updating binaries...' . "\n";
                            $db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'BINARIES', 'Updating XC_VM binaries from XC_VM server...', 'root', 'localhost', NULL, ?);", SERVER_ID, time());
                            shell_exec('sudo ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php binaries 2>&1 &');
                            break;
                        case 'update':
                            echo 'Updating...' . "\n";
                            $db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'UPDATE', 'Updating XC_VM...', 'root', 'localhost', NULL, ?);", SERVER_ID, time());
                            shell_exec('sudo ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php update update 2>&1 &');
                            break;
                        case 'enable_ministra':
                            echo 'Enabling ministra /c...';
                            shell_exec('sudo ln -sfn ' . MAIN_HOME . 'ministra ' . MAIN_HOME . 'www/c');
                            shell_exec('sudo ln -sfn ' . MAIN_HOME . 'ministra/portal.php ' . MAIN_HOME . 'www/portal.php');
                            break;
                        case 'disable_ministra':
                            echo 'Disabling ministra /c...';
                            shell_exec('sudo rm ' . MAIN_HOME . 'www/c');
                            shell_exec('sudo rm ' . MAIN_HOME . 'www/portal.php');
                            break;
                        case 'set_services':
                            echo 'Setting PHP Services' . "\n";
                            $rServices = intval($rData['count']);
                            if ($rData['reload']) {
                                shell_exec('sudo systemctl stop xc_vm');
                            }
                            shell_exec('sudo rm ' . MAIN_HOME . 'bin/php/etc/*.conf');
                            $rNewScript = '#! /bin/bash' . "\n";
                            $rNewBalance = 'upstream php {' . "\n" . '    least_conn;' . "\n";
                            $rTemplate = file_get_contents(MAIN_HOME . 'bin/php/etc/template');
                            foreach (range(1, $rServices) as $i) {
                                $rNewScript .= 'start-stop-daemon --start --quiet --pidfile ' . MAIN_HOME . 'bin/php/sockets/' . $i . '.pid --exec ' . MAIN_HOME . 'bin/php/sbin/php-fpm -- --daemonize --fpm-config ' . MAIN_HOME . 'bin/php/etc/' . $i . '.conf' . "\n";
                                $rNewBalance .= '    server unix:' . MAIN_HOME . 'bin/php/sockets/' . $i . '.sock;' . "\n";
                                file_put_contents(MAIN_HOME . 'bin/php/etc/' . $i . '.conf', str_replace('#PATH#', MAIN_HOME, str_replace('#ID#', $i, $rTemplate)));
                            }
                            file_put_contents(MAIN_HOME . 'bin/daemons.sh', $rNewScript);
                            file_put_contents(MAIN_HOME . 'bin/nginx/conf/balance.conf', $rNewBalance . '}');
                            shell_exec('sudo chown xc_vm:xc_vm ' . MAIN_HOME . 'bin/php/etc/*');
                            if ($rData['reload']) {
                                shell_exec('sudo systemctl start xc_vm');
                            }
                            break;
                        case 'set_governor':
                            $rNewGovernor = $rData['data'];
                            if (!empty($rNewGovernor) && shell_exec('which cpufreq-info')) {
                                $rGovernors = array_filter(explode(' ', trim(shell_exec('cpufreq-info -g'))));
                                $rGovernor = explode(' ', trim(shell_exec('cpufreq-info -p')));
                                if ($rGovernor[2] != $rNewGovernor && in_array($rNewGovernor, $rGovernors)) {
                                    shell_exec("sudo bash -c 'for ((i=0;i<\$(nproc);i++)); do cpufreq-set -c " . $i . ' -g ' . $rNewGovernor . "; done'");
                                    sleep(2);
                                    $rGovernor = explode(' ', trim(shell_exec('cpufreq-info -p')));
                                    $db->query('UPDATE `servers` SET `governor` = ? WHERE `id` = ?;', json_encode($rGovernor), SERVER_ID);
                                }
                            }
                            break;
                        case 'set_sysctl':
                            $rNewConfig = $rData['data'];
                            if (!empty($rNewConfig)) {
                                $rSysCtl = file_get_contents('/etc/sysctl.conf');
                                if ($rSysCtl != $rNewConfig) {
                                    shell_exec('sudo modprobe ip_conntrack > /dev/null');
                                    file_put_contents('/etc/sysctl.conf', $rNewConfig);
                                    shell_exec('sudo sysctl -p > /dev/null');
                                    $db->query('UPDATE `servers` SET `sysctl` = ? WHERE `id` = ?;', $rNewConfig, SERVER_ID);
                                }
                            }
                            break;
                        case 'set_port':
                            echo 'Setting NGINX Port' . "\n";
                            if (intval($rData['type']) == 0) {
                                $rListen = [];
                                foreach ($rData['ports'] as $rPort) {
                                    if (is_numeric($rPort) && $rPort >= 80 && $rPort <= 65535) {
                                        $rListen[] = 'listen ' . intval($rPort) . ';';
                                    }
                                }
                                file_put_contents(MAIN_HOME . 'bin/nginx/conf/ports/http.conf', implode(' ', $rListen));
                                file_put_contents(MAIN_HOME . 'bin/nginx_rtmp/conf/live.conf', 'on_play http://127.0.0.1:' . intval($rData['ports'][0]) . '/stream/rtmp; on_publish http://127.0.0.1:' . intval($rData['ports'][0]) . '/stream/rtmp; on_play_done http://127.0.0.1:' . intval($rData['ports'][0]) . '/stream/rtmp;');
                                if ($rData['reload']) {
                                    shell_exec('sudo ' . BIN_PATH . 'nginx/sbin/nginx -s reload');
                                }
                            } elseif (intval($rData['type']) == 1) {
                                $rListen = [];
                                foreach ($rData['ports'] as $rPort) {
                                    if (is_numeric($rPort) && $rPort >= 80 && $rPort <= 65535) {
                                        $rListen[] = 'listen ' . intval($rPort) . ' ssl;';
                                    }
                                }
                                file_put_contents(MAIN_HOME . 'bin/nginx/conf/ports/https.conf', implode(' ', $rListen));
                                if ($rData['reload']) {
                                    shell_exec('sudo ' . BIN_PATH . 'nginx/sbin/nginx -s reload');
                                }
                            } elseif (intval($rData['type']) == 2) {
                                file_put_contents(MAIN_HOME . 'bin/nginx_rtmp/conf/port.conf', 'listen ' . intval($rData['ports'][0]) . ';');
                                if ($rData['reload']) {
                                    shell_exec('sudo ' . BIN_PATH . 'nginx_rtmp/sbin/nginx_rtmp -s reload');
                                }
                            }
                            // no break
                        default:
                            break;
                    }
                }
            }
            $db->query('DELETE FROM `signals` WHERE LENGTH(`custom_data`) > 0 AND UNIX_TIMESTAMP() - `time` >= 86400;');
            $db->close_mysql();
        } else {
            exit();
        }
    }

    public function shutdown(): void {
        global $db;
        if ($this->rSaveIPTables) {
            $this->saveiptables();
        }
        if (is_object($db)) {
            $db->close_mysql();
        }
        @unlink($this->rIdentifier);
    }
}
