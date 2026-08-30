<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Process\ProcessManager;
use XcVm\Core\Util\Encryption;
use XcVm\Domain\Server\ServerRepository;

/**
 * RootSignalsCronJob — root signals cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

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

        $this->rIdentifier = CRONS_TMP_PATH . md5(Encryption::generateUniqueCode(SettingsManager::get('live_streaming_pass')) . static::class);
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
            // Auto-unban: on MAIN only, drop expired automatic IP bans (flood/
            // bruteforce) so the sync below removes them from iptables. Manual admin
            // bans (any other notes) are left permanent.
            $rUnbanSettings = \XcVm\Core\Config\SettingsManager::getAll();
            if (!empty($rServers[SERVER_ID]['is_main']) && !empty($rUnbanSettings['auto_unban_ip'])) {
                $rUnbanMul = array('minutes' => 60, 'hours' => 3600, 'days' => 86400);
                $rUnbanUnit = (string) ($rUnbanSettings['ban_duration_unit'] ?? 'hours');
                $rUnbanSecs = max(1, intval($rUnbanSettings['ban_duration_value'] ?? 24)) * ($rUnbanMul[$rUnbanUnit] ?? 3600);
                $db->query("DELETE FROM `blocked_ips` WHERE `date` < ? AND (UPPER(`notes`) LIKE '%ATTACK%' OR UPPER(`notes`) LIKE '%BRUTEFORCE%' OR UPPER(`notes`) LIKE '%FLOOD%');", time() - $rUnbanSecs);
            }

            $rSyncMarker = CRONS_TMP_PATH . 'blocked_ips_sync_marker';
            $rRunFullSync = true;
            $db->query('SELECT COUNT(*) AS `count` FROM `blocked_ips`;');
            $rCurrentIPCount = intval($db->get_row()['count']);

            if (file_exists($rSyncMarker)) {
                $rLastSyncData = json_decode(@file_get_contents($rSyncMarker), true);
                if (is_array($rLastSyncData) && isset($rLastSyncData['count'], $rLastSyncData['time'])) {
                    if (intval($rLastSyncData['count']) == $rCurrentIPCount && (time() - intval($rLastSyncData['time'])) < 300) {
                        $rRunFullSync = false;
                    }
                }
            }

            if ($rRunFullSync) {
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
                @file_put_contents($rSyncMarker, json_encode(['count' => $rCurrentIPCount, 'time' => time()]));
            }
        }
        $rReload = false;
        $rMinistraLegacyConf = 'set $ministra_legacy_redirect ' . (SettingsManager::get('mag_legacy_redirect') ? '1' : '0') . ';';
        $rCurrentMinistraLegacyConf = (trim(@file_get_contents(BIN_PATH . 'nginx/conf/ministra_legacy.conf')) ?: '');
        if ($rMinistraLegacyConf != $rCurrentMinistraLegacyConf) {
            echo 'Updating Ministra legacy /c toggle...' . "\n";
            file_put_contents(BIN_PATH . 'nginx/conf/ministra_legacy.conf', $rMinistraLegacyConf);
            $rReload = true;
        }
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
        if (SettingsManager::get('cloudflare')) {
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

            if (empty(SettingsManager::get('live_streaming_pass'))) {
                $db->query('UPDATE `settings` SET `live_streaming_pass` = ?', Encryption::randomString(40));
            }
        }

        // xc_fanout keepalive supervisor — ensure run.sh itself is alive. It is
        // the daemon's Restart=always loop (respawns the daemon within ~2s of any
        // exit, SIGKILL included), launched once by `service boot`. If IT dies
        // (OOM, a stray kill) the daemon is left unsupervised and never comes back
        // after its next exit — nothing else re-launches the loop (fanout_binary
        // only pkills the daemon and relies on it; StartupCommand only fixes its
        // mode). Re-launch it here every minute when absent — a cheap pgrep,
        // idempotent regardless (run.sh holds an flock single-instance guard, so
        // any racing duplicate supervisor exits at once), run as xc_vm to
        // match `service boot`. Closes the "supervisor died → fanout stays down"
        // gap. Runs on every node (main + LB), like the daemon self-heal below.
        $rRunSh = MAIN_HOME . 'bin/xc_fanout/run.sh';
        if (is_file($rRunSh) && trim((string) shell_exec('pgrep -u xc_vm -f ' . escapeshellarg($rRunSh) . ' 2>/dev/null')) === '') {
            shell_exec('sudo -u xc_vm bash ' . escapeshellarg($rRunSh) . ' >/dev/null 2>&1 &');
        }

        // xc_fanout daemon binary — keep it installed and current (ADR 0003,
        // Phase G). Nothing else pulls it: not the installer, not UpdateCommand,
        // so a fresh node/LB would never get the daemon and an updated panel would
        // keep an old one. fanout_binary is idempotent (downloads only on a
        // version mismatch, and only when GitHub is reachable), so it is safe to
        // poll. Throttle the check to ~hourly via a stamp, but the first pass
        // (stamp absent) runs immediately so a fresh install/LB gets the daemon
        // within a minute; the running daemon is respawned by fanout_binary on an
        // actual upgrade. Root context (this cron) is required — it installs into
        // bin/ and chowns. Runs on every node (main + LB) since LBs need it too.
        $rFanoutStamp = CRONS_TMP_PATH . 'fanout_binary_check';
        if (!file_exists($rFanoutStamp) || time() - intval(@file_get_contents($rFanoutStamp) ?: 0) > 3600) {
            file_put_contents($rFanoutStamp, time());
            shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php fanout_binary >/dev/null 2>&1 &');
        }

        // xcvm_core PHP extension — same self-heal rationale as the daemon above.
        // The extension is mirrored into the binaries repo tree decoupled from the
        // heavy runtime bundle, so nothing else keeps it current: a fresh LB (or a
        // node on an older extension) would never converge on its own. xcvm_core is
        // idempotent (version-compared, downloads only on a mismatch) and installs
        // with a load-test + rollback, so it is safe to poll ~hourly; the first
        // pass runs immediately. This is what delivers config_set_redis to LB nodes,
        // without which StatusCommand::configureRedisLb cannot point Redis at main.
        $rCoreStamp = CRONS_TMP_PATH . 'xcvm_core_check';
        if (!file_exists($rCoreStamp) || time() - intval(@file_get_contents($rCoreStamp) ?: 0) > 3600) {
            file_put_contents($rCoreStamp, time());
            shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php xcvm_core >/dev/null 2>&1 &');
        }

        // yt-dlp — same self-heal rationale. It is a static bundled binary that
        // resolves media URLs (StreamUtils) and nothing else keeps it current, so
        // it goes stale between panel releases and breaks extraction. The `ytdlp`
        // command is idempotent (version-compared against the upstream release,
        // downloads only on a mismatch, SHA-verified + run-tested before an atomic
        // swap), so it is safe to poll. Daily is enough (yt-dlp releases ~weekly);
        // the first pass (stamp absent) runs immediately. Runs on every node that
        // has the binary (main + LB).
        $rYtDlpStamp = CRONS_TMP_PATH . 'ytdlp_check';
        if (!file_exists($rYtDlpStamp) || time() - intval(@file_get_contents($rYtDlpStamp) ?: 0) > 86400) {
            file_put_contents($rYtDlpStamp, time());
            shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php ytdlp >/dev/null 2>&1 &');
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
        if (SettingsManager::get('restart_php_fpm')) {
            $rPHP = count(glob(BIN_PATH . 'php/sockets/*.pid') ?: []);
            $rNginx = 0;
            foreach (glob('/proc/*/cmdline') ?: [] as $rCmdFile) {
                $rRaw = @file_get_contents($rCmdFile);
                if ($rRaw && strpos(str_replace("\0", ' ', $rRaw), 'nginx: master') !== false) {
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
            $rCurlMarker = CRONS_TMP_PATH . 'fpm_curl_check';
            if (!file_exists($rCurlMarker) || (time() - filemtime($rCurlMarker)) >= 300) {
                @touch($rCurlMarker);
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
        }
        if ($db->query("SELECT `signal_id`, `custom_data` FROM `signals` WHERE `server_id` = ? AND `custom_data` <> '' AND `cache` = 0 ORDER BY signal_id ASC;", SERVER_ID)) {
            $rRows = $db->get_rows();
            $rCheck = ['php' => false, 'services' => false, 'ports' => false, 'ramdisk' => false];
            foreach ($rRows as $rRow) {
                $rData = json_decode($rRow['custom_data'], true);
                switch ($rData['action']) {
                    case 'disable_ramdisk':
                    case 'enable_ramdisk':
                        $rCheck['ramdisk'] = true;
                        break;
                    case 'set_services':
                        $rCheck['services'] = true;
                        break;
                    case 'set_port':
                        $rCheck['ports'] = true;
                        break;
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
                if (strtoupper(substr(explode("\n", file_get_contents('/etc/sysctl.conf'))[0], 0, 7)) != '# XC_VM') {
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
                            shell_exec('sudo ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php certbot "' . base64_encode(json_encode($rData)) . '" 2>&1 &');
                            break;
                        case 'update_binaries':
                            echo 'Updating binaries...' . "\n";
                            $db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'BINARIES', 'Updating XC_VM binaries from XC_VM server...', 'root', 'localhost', NULL, ?);", SERVER_ID, time());
                            shell_exec('sudo ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php binaries 2>&1 &');
                            break;
                        case 'install_module':
                            echo 'Installing module distributed from MAIN...' . "\n";
                            $db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'MODULE', 'Installing module distributed from MAIN...', 'root', 'localhost', NULL, ?);", SERVER_ID, time());
                            shell_exec('sudo ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php module:install "' . base64_encode(json_encode($rData)) . '" 2>&1 &');
                            break;
                        case 'delete_module':
                            echo 'Deleting module removed on MAIN...' . "\n";
                            $db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'MODULE', 'Deleting module removed on MAIN...', 'root', 'localhost', NULL, ?);", SERVER_ID, time());
                            shell_exec('sudo ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php module:delete "' . base64_encode(json_encode($rData)) . '" 2>&1 &');
                            break;
                        case 'update':
                            echo 'Updating...' . "\n";
                            $db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'UPDATE', 'Updating XC_VM...', 'root', 'localhost', NULL, ?);", SERVER_ID, time());
                            shell_exec('sudo ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php update update 2>&1 &');
                            break;
                        case 'rollback':
                            $rRbVersion = isset($rData['version']) ? trim((string) $rData['version']) : '';
                            if (preg_match('/^\d+\.\d+\.\d+$/', $rRbVersion)) {
                                echo 'Rolling back to ' . $rRbVersion . '...' . "\n";
                                $db->query("INSERT INTO `mysql_syslog`(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`) VALUES(?, 'UPDATE', ?, 'root', 'localhost', NULL, ?);", SERVER_ID, 'Rolling back XC_VM to ' . $rRbVersion . '...', time());
                                shell_exec('sudo ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php update rollback ' . escapeshellarg($rRbVersion) . ' 2>&1 &');
                            }
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
                                file_put_contents(MAIN_HOME . 'bin/php/etc/' . $i . '.conf', str_replace('#PATH#', MAIN_HOME, str_replace('#ID#', (string) $i, $rTemplate)));
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
                                    shell_exec("sudo bash -c 'for ((i=0;i<\$(nproc);i++)); do cpufreq-set -c \$i -g " . $rNewGovernor . "; done'");
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
