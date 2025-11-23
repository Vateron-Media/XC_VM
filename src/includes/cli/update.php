<?php
// Disable script timeout to allow long-running operations
set_time_limit(0);

// Check if script is executed from CLI with exactly one argument
if ($argc && count($argv) == 2) {
    // Register shutdown function to close DB on script termination
    register_shutdown_function('shutdown');

    // Load application initialization file
    require str_replace('\\', '/', dirname($argv[0])) . '/../../www/init.php';

    // Get command passed via CLI
    $rCommand = $argv[1];

    // Start CLI operation handler
    loadcli();
} else {
    exit(0);
}

/**
 * Handles CLI commands such as update and post-update.
 */
function loadcli() {
    global $db;
    global $rCommand;
    global $gitRelease;

    switch ($rCommand) {
        case 'update':
            if (CoreUtilities::$rServers[SERVER_ID]['is_main']) {
                $UpdateData = $gitRelease->getUpdateFile("main", XC_VM_VERSION);
            } else {
                $UpdateData = $gitRelease->getUpdateFile("lb_update", CoreUtilities::$rServers[SERVER_ID]['xc_vm_version']);
            }

            // Download and validate main update archive
            if ($UpdateData && 0 < strlen($UpdateData['url'])) {
                $rOutputDir = TMP_PATH . '.update.tar.gz';
                if (download_file($UpdateData['url'], $rOutputDir) && md5_file($rOutputDir) === $UpdateData['md5']) {
                    // Mark current server as updating
                    $db->query('UPDATE `servers` SET `status` = 5 WHERE `id` = ?;', SERVER_ID);

                    // Execute update script in background
                    $rCommand = 'sudo /usr/bin/python3 ' . MAIN_HOME . 'update "' . $rOutputDir . '" "' . $UpdateData['md5'] . '" > /dev/null 2>&1 &';
                    shell_exec($rCommand);
                    exit(1);
                } else {
                    // Download failed or corrupted
                    exit(-1);
                }
            }

            exit(0);

        case 'post-update':
            // Notify other load balancers to update
            if (CoreUtilities::$rServers[SERVER_ID]['is_main'] && CoreUtilities::$rSettings['auto_update_lbs']) {
                foreach (CoreUtilities::$rServers as $rServer) {
                    if (($rServer['enabled'] && $rServer['status'] == 1 && time() - $rServer['last_check_ago'] <= 180) || !$rServer['is_main']) {
                        $db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServer['id'], time(), json_encode(array('action' => 'update')));
                    }
                }
            }

            // Mark server as updated and set current version
            $db->query('UPDATE `servers` SET `status` = 1, `xc_vm_version` = ? WHERE `id` = ?;', XC_VM_VERSION, SERVER_ID);
            $db->query('UPDATE `settings` SET `update_data` = NULL;');

            // Update checkpoint
            if (file_exists(MAIN_HOME . 'includes/python/__pycache__/guess.cpython-36.pyc')) {
                unlink(MAIN_HOME . 'includes/python/__pycache__/guess.cpython-36.pyc');
            }
            if (file_exists(MAIN_HOME . 'includes/python/build/guess/Analysis-00.toc')) {
                unlink(MAIN_HOME . 'includes/python/build/guess/Analysis-00.toc');
            }
            if (file_exists(MAIN_HOME . 'includes/python/build/guess/EXE-00.toc')) {
                unlink(MAIN_HOME . 'includes/python/build/guess/EXE-00.toc');
            }
            if (file_exists(MAIN_HOME . 'includes/python/build/guess/PKG-00.pkg')) {
                unlink(MAIN_HOME . 'includes/python/build/guess/PKG-00.pkg');
            }
            if (file_exists(MAIN_HOME . 'includes/python/build/guess/PKG-00.toc')) {
                unlink(MAIN_HOME . 'includes/python/build/guess/PKG-00.toc');
            }
            if (file_exists(MAIN_HOME . 'includes/python/build/guess/PYZ-00.pyz')) {
                unlink(MAIN_HOME . 'includes/python/build/guess/PYZ-00.pyz');
            }
            if (file_exists(MAIN_HOME . 'includes/python/build/guess/PYZ-00.toc')) {
                unlink(MAIN_HOME . 'includes/python/build/guess/PYZ-00.toc');
            }
            if (file_exists(MAIN_HOME . 'includes/python/build/guess/base_library.zip')) {
                unlink(MAIN_HOME . 'includes/python/build/guess/base_library.zip');
            }
            if (file_exists(MAIN_HOME . 'includes/python/build/guess/warn-guess.txt')) {
                unlink(MAIN_HOME . 'includes/python/build/guess/warn-guess.txt');
            }
            if (file_exists(MAIN_HOME . 'includes/python/build/guess/xref-guess.html')) {
                unlink(MAIN_HOME . 'includes/python/build/guess/xref-guess.html');
            }
            if (file_exists(MAIN_HOME . 'includes/python/guess.py')) {
                unlink(MAIN_HOME . 'includes/python/guess.py');
            }
            if (file_exists(MAIN_HOME . 'includes/python/guess.spec')) {
                unlink(MAIN_HOME . 'includes/python/guess.spec');
            }

            // Remove 'reuseport' from nginx port configs
            foreach (array('http', 'https') as $rType) {
                $rPortConfig = file_get_contents(MAIN_HOME . 'bin/nginx/ports/' . $rType . '.conf');
                if (stripos($rPortConfig, ' reuseport') !== false) {
                    file_put_contents(MAIN_HOME . 'bin/nginx/ports/' . $rType . '.conf', str_replace(' reuseport', '', $rPortConfig));
                }
            }

            // Fix permissions and reload services
            exec('sudo chown -R xc_vm:xc_vm ' . MAIN_HOME);
            exec('sudo systemctl daemon-reload');
            exec("sudo echo 'net.ipv4.ip_unprivileged_port_start=0' > /etc/sysctl.d/50-allports-nonroot.conf && sudo sysctl --system");
            exec('sudo ' . MAIN_HOME . 'status');
            break;
    }
}

/**
 * Downloads a file from the specified URL and saves it to the given local path.
 *
 * @param string $url        The URL of the file to download.
 * @param string $targetPath The local file path where the downloaded file will be saved.
 *
 * @return bool Returns true on success, or false on failure (e.g., if the URL could not be opened).
 */
function download_file($url, $targetPath) {
    $rData = @fopen($url, 'rb');
    if (!$rData) return false;
    $rOutput = fopen($targetPath, 'wb');
    stream_copy_to_stream($rData, $rOutput);
    fclose($rData);
    fclose($rOutput);
    return true;
}

/**
 * Shutdown function to clean up the database connection if it exists.
 */
function shutdown() {
    global $db;
    if (is_object($db)) {
        $db->close_mysql();
    }
}
