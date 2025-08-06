<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'root') {
    if ($argc) {
        register_shutdown_function('shutdown');
        require str_replace('\\', '/', dirname($argv[0])) . '/../../www/init.php';
        $rBaseDir = '/home/xc_vm/bin/';
        $rBaseURL = 'https://update.xc_vm.com/binaries/';
        $rPermissions = '0755';
        loadcli();
    } else {
        exit(0);
    }
} else {
    exit('Please run as root!' . "\n");
}
function loadcli() {
    global $rBaseURL;
    global $rPermissions;
    global $rBaseDir;
    if (!shell_exec('which apparmor_status')) {
    } else {
        exec('sudo apparmor_status', $rAppArmor);
        if (strtolower(trim($rAppArmor[0])) != 'apparmor module is loaded.') {
        } else {
            exec('sudo systemctl is-active apparmor', $rStatus);
            if (strtolower(trim($rStatus[0])) != 'active') {
            } else {
                echo 'AppArmor is loaded! Disabling...' . "\n";
                shell_exec('sudo systemctl stop apparmor');
                shell_exec('sudo systemctl disable apparmor');
            }
        }
    }
    $rPHPUpdated = $rUpdated = false;
    exec('sudo lsb_release -r -s', $osReleaseVersion);
    $rAPI = json_decode(file_get_contents(($rBaseURL . '?version=' . XC_VM_VERSION . '&ubv=' . floatval($osReleaseVersion[0]) ?: '')), true);
    if (is_array($rAPI)) {
        foreach ($rAPI['files'] as $rFile) {
            if (!(file_exists($rFile['path']) && md5_file($rFile['path']) == $rFile['md5'])) {
                $rFolderPath = pathinfo($rFile['path'])['dirname'] . '/';
                if (!file_exists($rFolderPath)) {
                    shell_exec('sudo mkdir -p "' . $rFolderPath . '"');
                }
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $rBaseURL . $rFile['md5']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
                curl_setopt($ch, CURLOPT_TIMEOUT, 300);
                $rData = curl_exec($ch);
                $rMD5 = md5($rData);
                if ($rFile['md5'] == $rMD5) {
                    echo 'Updated binary: ' . $rFile['path'] . "\n";
                    shell_exec('sudo rm -rf "' . $rFile['path'] . '"');
                    file_put_contents($rFile['path'], $rData);
                    shell_exec('sudo chown xc_vm:xc_vm "' . $rFile['path'] . '"');
                    shell_exec('sudo chmod ' . $rPermissions . ' "' . $rFile['path'] . '"');
                    $rUpdated = true;
                    if (substr(basename($rFile['path']), 0, 3) == 'php') {
                        $rPHPUpdated = true;
                    }
                }
            }
        }
    }
    if ($rUpdated) {
        shell_exec('sudo chown -R xc_vm:xc_vm "' . $rBaseDir . '"');
    }
    // if ($rPHPUpdated) {
    //     $rVersion = (array(72 => '7.2', 74 => '7.4')[CoreUtilities::$rServers[SERVER_ID]['php_version']] ?: '7.4');
    //     shell_exec('sudo ln -sfn ' . PHP_BIN . '_' . $rVersion . ' ' . PHP_BIN);
    //     shell_exec('sudo ln -sfn ' . BIN_PATH . 'php/sbin/php-fpm_' . $rVersion . ' ' . BIN_PATH . 'php/sbin/php-fpm');
    //     shell_exec('sudo chown -R xc_vm:xc_vm ' . BIN_PATH . 'php');
    //     shell_exec('sudo service xc_vm restart');
    // }
}
function shutdown() {
    global $db;
    if (is_object($db)) {
        $db->close_mysql();
    }
}
