<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'root') {
    if ($argc) {
        register_shutdown_function('shutdown');
        require str_replace('\\', '/', dirname($argv[0])) . '/../../www/init.php';
        $rBaseDir = '/home/xc_vm/bin/';
        $geolitejsonFile = '/home/xc_vm/bin/maxmind/version.json';
        loadcli();
    } else {
        exit(0);
    }
} else {
    exit('Please run as root!' . "\n");
}

function loadcli() {
    global $rBaseDir;
    global $geolitejsonFile;

    // Check if apparmor_status command exists
    if (shell_exec('which apparmor_status')) {
        exec('sudo apparmor_status', $rAppArmor);

        // If the first line indicates AppArmor is loaded
        if (strtolower(trim($rAppArmor[0])) == 'apparmor module is loaded.') {
            exec('sudo systemctl is-active apparmor', $rStatus);

            // If AppArmor service is active, stop and disable it
            if (strtolower(trim($rStatus[0])) == 'active') {
                echo 'AppArmor is loaded! Disabling...' . "\n";
                shell_exec('sudo systemctl stop apparmor');
                shell_exec('sudo systemctl disable apparmor');
            }
        }
    }

    $rUpdated = false;
    $repo = new GitHubReleases(GIT_OWNER, GIT_REPO_UPDATE);

    // Get GeoLite data files info from GitHub
    $datageolite = $repo->getGeolite();
    if (is_array($datageolite)) {
        foreach ($datageolite['files'] as $rFile) {
            // Check if file is missing OR checksum mismatch
            if (!file_exists($rFile['path']) || md5_file($rFile['path']) != $rFile['md5']) {
                $rFolderPath = pathinfo($rFile['path'])['dirname'] . '/';

                // Ensure target folder exists
                if (!file_exists($rFolderPath)) {
                    shell_exec('sudo mkdir -p "' . $rFolderPath . '"');
                }

                // Download file with cURL
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $rFile['fileurl']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
                curl_setopt($ch, CURLOPT_TIMEOUT, 300);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Handle redirects
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'); // Custom User-Agent
                $rData = curl_exec($ch);
                $rMD5 = md5($rData);

                // Verify checksum before saving file
                if ($rFile['md5'] == $rMD5) {
                    echo 'Updated binary: ' . $rFile['path'] . "\n";
                    file_put_contents($rFile['path'], $rData);

                    // Set correct owner and permissions
                    shell_exec('sudo chown xc_vm:xc_vm "' . $rFile['path'] . '"');
                    shell_exec('sudo chmod ' . $rFile["permision"] . ' "' . $rFile['path'] . '"');
                    $rUpdated = true;
                }
            }
        }

        // Update geolite version in JSON metadata file
        $jsonData = file_get_contents($geolitejsonFile);
        $data = json_decode($jsonData, true);

        if (isset($data['geolite2_version'])) {
            $data['geolite2_version'] = $datageolite["version"];

            // Save updated JSON back to file
            file_put_contents($geolitejsonFile, json_encode($data, JSON_PRETTY_PRINT));
        }
    }

    // If any file was updated → fix ownership for the whole base directory
    if ($rUpdated) {
        shell_exec('sudo chown -R xc_vm:xc_vm "' . $rBaseDir . '"');
    }
}

function shutdown() {
    global $db;
    if (is_object($db)) {
        $db->close_mysql();
    }
}
