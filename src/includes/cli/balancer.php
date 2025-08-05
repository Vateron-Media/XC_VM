<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc && $argc >= 6) {
        $rServerID = intval($argv[2]);
        if ($rServerID != 0) {
            shell_exec("kill -9 `ps -ef | grep 'XC_VM Install\\[" . $rServerID . "\\]' | grep -v grep | awk '{print \$2}'`;");
            set_time_limit(0);
            cli_set_process_title('XC_VM Install[' . $rServerID . ']');
            register_shutdown_function('shutdown');
            require str_replace('\\', '/', dirname($argv[0])) . '/../../www/init.php';
            unlink(CACHE_TMP_PATH . 'servers');
            CoreUtilities::$rServers = CoreUtilities::getServers();
            $rType = intval($argv[1]);
            $rPort = intval($argv[3]);
            list(,,,, $rUsername, $rPassword) = $argv;
            $rHTTPPort = (empty($argv[6]) ? 80 : intval($argv[6]));
            $rHTTPSPort = (empty($argv[7]) ? 443 : intval($argv[7]));
            $rUpdateSysctl = (empty($argv[8]) ? 0 : intval($argv[8]));
            $rPrivateIP = (empty($argv[9]) ? 0 : intval($argv[9]));
            $rParentIDs = (empty($argv[10]) ? array() : json_decode($argv[10], true));
            $rSysCtl = '# XC_VM' . PHP_EOL . PHP_EOL . 'net.ipv4.tcp_congestion_control = bbr' . PHP_EOL . 'net.core.default_qdisc = fq' . PHP_EOL . 'net.ipv4.tcp_rmem = 8192 87380 134217728' . PHP_EOL . 'net.ipv4.udp_rmem_min = 16384' . PHP_EOL . 'net.core.rmem_default = 262144' . PHP_EOL . 'net.core.rmem_max = 268435456' . PHP_EOL . 'net.ipv4.tcp_wmem = 8192 65536 134217728' . PHP_EOL . 'net.ipv4.udp_wmem_min = 16384' . PHP_EOL . 'net.core.wmem_default = 262144' . PHP_EOL . 'net.core.wmem_max = 268435456' . PHP_EOL . 'net.core.somaxconn = 1000000' . PHP_EOL . 'net.core.netdev_max_backlog = 250000' . PHP_EOL . 'net.core.optmem_max = 65535' . PHP_EOL . 'net.ipv4.tcp_max_tw_buckets = 1440000' . PHP_EOL . 'net.ipv4.tcp_max_orphans = 16384' . PHP_EOL . 'net.ipv4.ip_local_port_range = 2000 65000' . PHP_EOL . 'net.ipv4.tcp_no_metrics_save = 1' . PHP_EOL . 'net.ipv4.tcp_slow_start_after_idle = 0' . PHP_EOL . 'net.ipv4.tcp_fin_timeout = 15' . PHP_EOL . 'net.ipv4.tcp_keepalive_time = 300' . PHP_EOL . 'net.ipv4.tcp_keepalive_probes = 5' . PHP_EOL . 'net.ipv4.tcp_keepalive_intvl = 15' . PHP_EOL . 'fs.file-max=20970800' . PHP_EOL . 'fs.nr_open=20970800' . PHP_EOL . 'fs.aio-max-nr=20970800' . PHP_EOL . 'net.ipv4.tcp_timestamps = 1' . PHP_EOL . 'net.ipv4.tcp_window_scaling = 1' . PHP_EOL . 'net.ipv4.tcp_mtu_probing = 1' . PHP_EOL . 'net.ipv4.route.flush = 1' . PHP_EOL . 'net.ipv6.route.flush = 1';
            $rInstallDir = BIN_PATH . 'install/';
            $rFiles = array('lb' => 'loadbalancer.tar.gz', 'lb_update' => 'loadbalancer_update.tar.gz', 'proxy' => 'proxy.tar.gz');
            if ($rType == 1) {
                $rPackages = array('iproute2', 'net-tools', 'libcurl4', 'libxslt1-dev', 'libonig-dev', 'e2fsprogs', 'wget', 'sysstat', 'mcrypt', 'python3', 'certbot', 'iptables-persistent', 'libjpeg-dev', 'libpng-dev', 'php-ssh2', 'xz-utils', 'zip', 'unzip');
                $rInstallFiles = array($rFiles['proxy']);
                exit(); // заглушка чтобы процесс не продолжался
            } elseif ($rType == 2) {
                $rPackages = array('cpufrequtils', 'iproute2', 'python', 'net-tools', 'dirmngr', 'gpg-agent', 'software-properties-common', 'libmaxminddb0', 'libmaxminddb-dev', 'mmdb-bin', 'libcurl4', 'libgeoip-dev', 'libxslt1-dev', 'libonig-dev', 'e2fsprogs', 'wget', 'sysstat', 'alsa-utils', 'v4l-utils', 'mcrypt', 'python3', 'certbot', 'iptables-persistent', 'libjpeg-dev', 'libpng-dev', 'php-ssh2', 'xz-utils', 'zip', 'unzip');
                $rInstallFiles = array($rFiles['lb']);
            } elseif ($rType == 3) {
                $rPackages = array('cpufrequtils');
                $rInstallFiles = array($rFiles['lb_update']);
            } else {
                $db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
                echo 'Invalid type specified!' . "\n";
                exit();
            }
            if ($rType == 1) {
                file_put_contents($rInstallDir . $rServerID . '.json', json_encode(array('root_username' => $rUsername, 'root_password' => $rPassword, 'ssh_port' => $rPort, 'http_broadcast_port' => $rHTTPPort, 'https_broadcast_port' => $rHTTPSPort, 'parent_id' => $rParentIDs)));
            } else {
                file_put_contents($rInstallDir . $rServerID . '.json', json_encode(array('root_username' => $rUsername, 'root_password' => $rPassword, 'ssh_port' => $rPort)));
            }
            $rHost = CoreUtilities::$rServers[$rServerID]['server_ip'];
            echo 'Connecting to ' . $rHost . ':' . $rPort . "\n";
            if ($rConn = ssh2_connect($rHost, $rPort)) {
                if ($rUsername == 'root') {
                    echo 'Connected! Authenticating as root user...' . "\n";
                } else {
                    echo 'Connected! Authenticating as non-root user...' . "\n";
                }
                $rResult = @ssh2_auth_password($rConn, $rUsername, $rPassword);
                if ($rResult) {
                    echo "\n" . 'Stopping any previous version of XC_VM' . "\n";
                    runCommand($rConn, 'sudo systemctl stop xc_vm');
                    runCommand($rConn, 'sudo killall -9 -u xc_vm');
                    echo "\n" . 'Updating system' . "\n";
                    runCommand($rConn, 'sudo rm /var/lib/dpkg/lock-frontend && sudo rm /var/cache/apt/archives/lock && sudo rm /var/lib/dpkg/lock');
                    if ($rType == 2) {
                        runCommand($rConn, 'sudo add-apt-repository -y ppa:maxmind/ppa');
                    }
                    runCommand($rConn, 'sudo apt-get update');
                    foreach ($rPackages as $rPackage) {
                        echo 'Installing package: ' . $rPackage . "\n";
                        runCommand($rConn, 'sudo DEBIAN_FRONTEND=noninteractive apt-get -yq install ' . $rPackage);
                    }
                    if (in_array($rType, array(1, 2))) {
                        echo 'Creating XC_VM system user' . "\n";
                        runCommand($rConn, 'sudo adduser --system --shell /bin/false --group --disabled-login xc_vm');
                        runCommand($rConn, 'sudo mkdir ' . MAIN_HOME);
                        runCommand($rConn, 'sudo rm -rf ' . BIN_PATH);
                    }
                    $i = 0;
                    foreach ($rInstallFiles as $rFile) {
                        $i++;
                        echo 'Transferring compressed system files (' . $i . ' of ' . count($rInstallFiles) . ')' . "\n";
                        if (sendfile($rConn, $rInstallDir . $rFile, '/tmp/' . $rFile, true)) {
                            echo 'Extracting to directory' . "\n";
                            $rRet = runCommand($rConn, 'sudo rm -rf ' . MAIN_HOME . 'status');
                            $rRet = runCommand($rConn, 'sudo tar -zxvf "/tmp/' . $rFile . '" -C "' . MAIN_HOME . '"');
                            if (file_exists(MAIN_HOME . 'status')) {
                                runCommand($rConn, 'sudo rm -f "/tmp/' . $rFile . '.tar.gz"');
                            } else {
                                $db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
                                echo 'Failed to extract files! Exiting' . "\n";
                                exit();
                            }
                        } else {
                            $db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
                            echo 'Invalid MD5 checksum! Exiting' . "\n";
                            exit();
                        }
                    }
                    if (in_array($rType, array(2, 3))) {
                        if (stripos(runCommand($rConn, 'sudo cat /etc/fstab')['output'], STREAMS_PATH) !== true) {
                            echo 'Adding ramdisk mounts' . "\n";
                            runCommand($rConn, 'sudo echo "tmpfs ' . STREAMS_PATH . ' tmpfs defaults,noatime,nosuid,nodev,noexec,mode=1777,size=90% 0 0" >> /etc/fstab');
                            runCommand($rConn, 'sudo echo "tmpfs ' . TMP_PATH . ' tmpfs defaults,noatime,nosuid,nodev,noexec,mode=1777,size=2G 0 0" >> /etc/fstab');
                        }
                        if (stripos(runCommand($rConn, 'sudo cat /etc/sysctl.conf')['output'], 'XC_VM') === false) {
                            if ($rUpdateSysctl) {
                                echo 'Adding sysctl.conf' . "\n";
                                runCommand($rConn, 'sudo modprobe ip_conntrack');
                                file_put_contents(TMP_PATH . 'sysctl_' . $rServerID, $rSysCtl);
                                sendfile($rConn, TMP_PATH . 'sysctl_' . $rServerID, '/etc/sysctl.conf');
                                runCommand($rConn, 'sudo sysctl -p');
                                runCommand($rConn, 'sudo touch ' . CONFIG_PATH . 'sysctl.on');
                            } else {
                                runCommand($rConn, 'sudo rm ' . CONFIG_PATH . 'sysctl.on');
                            }
                        } else {
                            if (!$rUpdateSysctl) {
                                runCommand($rConn, 'sudo rm ' . CONFIG_PATH . 'sysctl.on');
                            } else {
                                runCommand($rConn, 'sudo touch ' . CONFIG_PATH . 'sysctl.on');
                            }
                        }
                    }
                    echo 'Generating configuration file' . "\n";
                    $rMasterConfig = parse_ini_file(CONFIG_PATH . 'config.ini');
                    if ($rType == 1) {
                        if ($rPrivateIP) {
                            $rNewConfig = '; XC_VM Configuration' . "\n" . '; -----------------' . "\n\n" . '[XC_VM]' . "\n" . 'hostname    =   "' . CoreUtilities::$rServers[SERVER_ID]['private_ip'] . '"' . "\n" . 'port        =   ' . intval(CoreUtilities::$rServers[SERVER_ID]['http_broadcast_port']) . "\n" . 'server_id   =   ' . $rServerID;
                        } else {
                            $rNewConfig = '; XC_VM Configuration' . "\n" . '; -----------------' . "\n\n" . '[XC_VM]' . "\n" . 'hostname    =   "' . CoreUtilities::$rServers[SERVER_ID]['server_ip'] . '"' . "\n" . 'port        =   ' . intval(CoreUtilities::$rServers[SERVER_ID]['http_broadcast_port']) . "\n" . 'server_id   =   ' . $rServerID;
                        }
                    } else {
                        $rNewConfig = '; XC_VM Configuration' . "\n" . '; -----------------' . "\n\n" . '[XC_VM]' . "\n" . 'hostname    =   "' . CoreUtilities::$rServers[SERVER_ID]['server_ip'] . '"' . "\n" . 'database    =   "xc_vm"' . "\n" . 'port        =   ' . intval(CoreUtilities::$rConfig['port']) . "\n" . 'server_id   =   ' . $rServerID . "\n" . 'is_lb       =   1' . "\n\n" . '[Encrypted]' . "\n" . 'username    =   "' . CoreUtilities::$rConfig['username'] . '"' . "\n" . 'password    =   "' . CoreUtilities::$rConfig['password'] . '"';
                    }
                    file_put_contents(TMP_PATH . 'config_' . $rServerID, $rNewConfig);
                    sendfile($rConn, TMP_PATH . 'config_' . $rServerID, CONFIG_PATH . 'config.ini');
                    echo 'Installing service' . "\n";
                    runCommand($rConn, 'sudo rm /etc/systemd/system/xc_vm.service');
                    $rSystemd = '[Unit]' . "\n" . 'SourcePath=/home/xc_vm/service' . "\n" . 'Description=XC_VM Service' . "\n" . 'After=network.target' . "\n" . 'StartLimitIntervalSec=0' . "\n\n" . '[Service]' . "\n" . 'Type=simple' . "\n" . 'User=root' . "\n" . 'Restart=always' . "\n" . 'RestartSec=1' . "\n" . 'ExecStart=/bin/bash /home/xc_vm/service start' . "\n" . 'ExecRestart=/bin/bash /home/xc_vm/service restart' . "\n" . 'ExecStop=/bin/bash /home/xc_vm/service stop' . "\n\n" . '[Install]' . "\n" . 'WantedBy=multi-user.target';
                    file_put_contents(TMP_PATH . 'systemd_' . $rServerID, $rSystemd);
                    sendfile($rConn, TMP_PATH . 'systemd_' . $rServerID, '/etc/systemd/system/xc_vm.service');
                    runCommand($rConn, 'sudo chmod +x /etc/systemd/system/xc_vm.service');
                    runCommand($rConn, 'sudo rm /etc/init.d/xc_vm');
                    runCommand($rConn, 'sudo systemctl daemon-reload');
                    runCommand($rConn, 'sudo systemctl enable xc_vm');
                    if ($rType == 1) {
                        runCommand($rConn, 'sudo rm /home/xc_vm/bin/nginx/conf/servers/*.conf');
                        foreach ($rParentIDs as $rParentID) {
                            if ($rPrivateIP) {
                                $rIP = CoreUtilities::$rServers[$rParentID]['private_ip'] . ':' . CoreUtilities::$rServers[$rParentID]['http_broadcast_port'];
                            } else {
                                $rIP = CoreUtilities::$rServers[$rParentID]['server_ip'] . ':' . CoreUtilities::$rServers[$rParentID]['http_broadcast_port'];
                            }
                            if (CoreUtilities::$rServers[$rParentID]['is_main']) {
                                $rConfigText = 'location / {' . "\n" . '    include options.conf;' . "\n" . '    proxy_pass http://' . $rIP . '$1;' . "\n" . '}';
                            } else {
                                $rKey = md5($rServerID . '_' . $rParentID . '_' . OPENSSL_EXTRA);
                                $rConfigText = 'location ~/' . $rKey . '(.*)$ {' . "\n" . '    include options.conf;' . "\n" . '    proxy_pass http://' . $rIP . '$1;' . "\n" . '    proxy_set_header X-Token "' . $rKey . '";' . "\n" . '}';
                            }
                            $rTmpPath = TMP_PATH . md5(time() . $rKey . '.conf');
                            file_put_contents($rTmpPath, $rConfigText);
                            sendfile($rConn, $rTmpPath, '/home/xc_vm/bin/nginx/conf/servers/' . intval($rParentID) . '.conf');
                        }
                        runCommand($rConn, 'sudo echo "listen ' . $rHTTPPort . ';" > "/home/xc_vm/bin/nginx/conf/ports/http.conf"');
                        runCommand($rConn, 'sudo echo "listen ' . $rHTTPSPort . ' ssl;" > "/home/xc_vm/bin/nginx/conf/ports/https.conf"');
                        runCommand($rConn, 'sudo chmod 0777 /home/xc_vm/bin');
                    } else {
                        sendfile($rConn, MAIN_HOME . 'bin/nginx/conf/custom.conf', MAIN_HOME . 'bin/nginx/conf/custom.conf');
                        sendfile($rConn, MAIN_HOME . 'bin/nginx/conf/realip_cdn.conf', MAIN_HOME . 'bin/nginx/conf/realip_cdn.conf');
                        sendfile($rConn, MAIN_HOME . 'bin/nginx/conf/realip_cloudflare.conf', MAIN_HOME . 'bin/nginx/conf/realip_cloudflare.conf');
                        sendfile($rConn, MAIN_HOME . 'bin/nginx/conf/realip_xc_vm.conf', MAIN_HOME . 'bin/nginx/conf/realip_xc_vm.conf');
                        runCommand($rConn, 'sudo echo "" > "/home/xc_vm/bin/nginx/conf/limit.conf"');
                        runCommand($rConn, 'sudo echo "" > "/home/xc_vm/bin/nginx/conf/limit_queue.conf"');
                        if ($rType != 2) {
                        } else {
                            $rIP = '127.0.0.1:' . CoreUtilities::$rServers[$rServerID]['http_broadcast_port'];
                            runCommand($rConn, 'sudo echo "on_play http://' . $rIP . '/stream/rtmp; on_publish http://' . $rIP . '/stream/rtmp; on_play_done http://' . $rIP . '/stream/rtmp;" > "/home/xc_vm/bin/nginx_rtmp/conf/live.conf"');
                            $rServices = (intval(runCommand($rConn, 'sudo cat /proc/cpuinfo | grep "^processor" | wc -l')['output']) ?: 4);
                            runCommand($rConn, 'sudo rm ' . MAIN_HOME . 'bin/php/etc/*.conf');
                            $rNewScript = '#! /bin/bash' . "\n";
                            $rNewBalance = 'upstream php {' . "\n" . '    least_conn;' . "\n";
                            $rTemplate = file_get_contents(MAIN_HOME . 'bin/php/etc/template');
                            foreach (range(1, $rServices) as $i) {
                                $rNewScript .= 'start-stop-daemon --start --quiet --pidfile ' . MAIN_HOME . 'bin/php/sockets/' . $i . '.pid --exec ' . MAIN_HOME . 'bin/php/sbin/php-fpm -- --daemonize --fpm-config ' . MAIN_HOME . 'bin/php/etc/' . $i . '.conf' . "\n";
                                $rNewBalance .= '    server unix:' . MAIN_HOME . 'bin/php/sockets/' . $i . '.sock;' . "\n";
                                $rTmpPath = TMP_PATH . md5(time() . $i . '.conf');
                                file_put_contents($rTmpPath, str_replace('#PATH#', MAIN_HOME, str_replace('#ID#', $i, $rTemplate)));
                                sendfile($rConn, $rTmpPath, MAIN_HOME . 'bin/php/etc/' . $i . '.conf');
                            }
                            $rNewBalance .= '}';
                            $rTmpPath = TMP_PATH . md5(time() . 'daemons.sh');
                            file_put_contents($rTmpPath, $rNewScript);
                            sendfile($rConn, $rTmpPath, MAIN_HOME . 'bin/daemons.sh');
                            $rTmpPath = TMP_PATH . md5(time() . 'balance.conf');
                            file_put_contents($rTmpPath, $rNewBalance);
                            sendfile($rConn, $rTmpPath, MAIN_HOME . 'bin/nginx/conf/balance.conf');
                            runCommand($rConn, 'sudo chmod +x ' . MAIN_HOME . 'bin/daemons.sh');
                        }
                    }
                    $rSystemConf = runCommand($rConn, 'sudo cat "/etc/systemd/system.conf"')['output'];
                    if (strpos($rSystemConf, 'DefaultLimitNOFILE=1048576') !== false) {
                    } else {
                        runCommand($rConn, 'sudo echo "' . "\n" . 'DefaultLimitNOFILE=1048576" >> "/etc/systemd/system.conf"');
                        runCommand($rConn, 'sudo echo "' . "\n" . 'DefaultLimitNOFILE=1048576" >> "/etc/systemd/user.conf"');
                    }
                    if (strpos($rSystemConf, 'nDefaultLimitNOFILESoft=1048576') !== false) {
                    } else {
                        runCommand($rConn, 'sudo echo "' . "\n" . 'DefaultLimitNOFILESoft=1048576" >> "/etc/systemd/system.conf"');
                        runCommand($rConn, 'sudo echo "' . "\n" . 'DefaultLimitNOFILESoft=1048576" >> "/etc/systemd/user.conf"');
                    }
                    runCommand($rConn, 'sudo systemctl stop apparmor');
                    runCommand($rConn, 'sudo systemctl disable apparmor');
                    runCommand($rConn, 'sudo mount -a');
                    runCommand($rConn, "sudo echo 'net.ipv4.ip_unprivileged_port_start=0' > /etc/sysctl.d/50-allports-nonroot.conf && sudo sysctl --system");
                    sleep(3);
                    runCommand($rConn, 'sudo chown -R xc_vm:xc_vm ' . MAIN_HOME . 'tmp');
                    runCommand($rConn, 'sudo chown -R xc_vm:xc_vm ' . MAIN_HOME . 'content/streams');
                    runCommand($rConn, 'sudo chown -R xc_vm:xc_vm ' . MAIN_HOME);
                    CoreUtilities::grantPrivileges($rHost);
                    echo 'Installation complete! Starting XC_VM' . "\n";
                    runCommand($rConn, 'sudo service xc_vm restart');
                    if ($rType == 2) {
                        runCommand($rConn, 'sudo ' . MAIN_HOME . 'status 1');
                        runCommand($rConn, 'sudo -u xc_vm ' . PHP_BIN . ' ' . CLI_PATH . 'startup.php');
                        runCommand($rConn, 'sudo -u xc_vm ' . PHP_BIN . ' ' . CRON_PATH . 'servers.php');
                    } else {
                        if ($rType == 3) {
                            runCommand($rConn, 'sudo ' . PHP_BIN . ' ' . CLI_PATH . 'update.php "post-update"');
                            runCommand($rConn, 'sudo ' . MAIN_HOME . 'status 1');
                            runCommand($rConn, 'sudo -u xc_vm ' . PHP_BIN . ' ' . CLI_PATH . 'startup.php');
                            runCommand($rConn, 'sudo -u xc_vm ' . PHP_BIN . ' ' . CRON_PATH . 'servers.php');
                        } else {
                            runCommand($rConn, 'sudo -u xc_vm ' . PHP_BIN . ' ' . INCLUDES_PATH . 'startup.php');
                        }
                    }
                    if (in_array($rType, array(1, 2))) {
                        $db->query('UPDATE `servers` SET `status` = 1, `http_broadcast_port` = ?, `https_broadcast_port` = ?, `total_services` = ? WHERE `id` = ?;', $rHTTPPort, $rHTTPSPort, $rServices, $rServerID);
                    } else {
                        $db->query('UPDATE `servers` SET `status` = 1 WHERE `id` = ?;', $rServerID);
                    }
                    unlink($rInstallDir . $rServerID . '.json');
                } else {
                    $db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
                    echo 'Failed to authenticate using config.ini. Exiting' . "\n";
                    exit();
                }
            } else {
                $db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', $rServerID);
                echo 'Failed to connect to server. Exiting' . "\n";
                exit();
            }
        } else {
            exit();
        }
    } else {
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
}
function sendFile($rConn, $rPath, $rOutput, $rWarn = false) {
    $rMD5 = md5_file($rPath);
    ssh2_scp_send($rConn, $rPath, $rOutput);
    $rOutMD5 = trim(explode(' ', runCommand($rConn, 'md5sum "' . $rOutput . '"')['output'])[0]);
    if ($rMD5 == $rOutMD5) {
        return true;
    }
    if ($rWarn) {
        echo 'Failed to write using SCP, reverting to SFTP transfer... This will be take significantly longer!' . "\n";
    }
    $rSFTP = ssh2_sftp($rConn);
    $rSuccess = true;
    $rStream = @fopen('ssh2.sftp://' . $rSFTP . $rOutput, 'wb');
    try {
        $rData = @file_get_contents($rPath);
        if (@fwrite($rStream, $rData) !== false) {
        } else {
            $rSuccess = false;
        }
        fclose($rStream);
    } catch (Exception $e) {
        $rSuccess = false;
        fclose($rStream);
    }
    return $rSuccess;
}
function runCommand($rConn, $rCommand) {
    $rStream = ssh2_exec($rConn, $rCommand);
    $rError = ssh2_fetch_stream($rStream, SSH2_STREAM_STDERR);
    stream_set_blocking($rError, true);
    stream_set_blocking($rStream, true);
    return array('output' => stream_get_contents($rStream), 'error' => stream_get_contents($rError));
}
function shutdown() {
    global $db;
    if (!is_object($db)) {
    } else {
        $db->close_mysql();
    }
}
