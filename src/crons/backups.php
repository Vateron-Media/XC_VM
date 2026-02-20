<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(32757);
if (posix_getpwuid(posix_geteuid())['name'] != 'xc_vm') {
    exit('Please run as XC_VM!' . "\n");
}

set_time_limit(0);
if (!$argc) {
    exit(0);
}

require str_replace('\\', '/', dirname($argv[0])) . '/../includes/admin.php';
if (!CoreUtilities::$rServers[SERVER_ID]['is_main']) {
    exit('Please run on main server.');
}
cli_set_process_title('XC_VM[Backups]');
$rIdentifier = CRONS_TMP_PATH . md5(CoreUtilities::generateUniqueCode() . __FILE__);
CoreUtilities::checkCron($rIdentifier);
$rForce = false;
if (count($argv) > 1) {
    if (intval($argv[1]) == 1) {
        $rForce = true;
    }
}
$rBackups = CoreUtilities::$rSettings['automatic_backups'];
$rLastBackup = intval(CoreUtilities::$rSettings['last_backup']);
$rPeriod = array('hourly' => 3600, 'daily' => 86400, 'weekly' => 604800, 'monthly' => 2419200);
if (!$rForce) {
    $rPID = getmypid();
    if (file_exists('/proc/' . CoreUtilities::$rSettings['backups_pid']) && 0 < strlen(CoreUtilities::$rSettings['backups_pid'])) {
        exit();
    }
    $db->query('UPDATE `settings` SET `backups_pid` = ?;', $rPID);
}
if (isset($rBackups) && $rBackups != 'off' || $rForce) {
    if ($rLastBackup + $rPeriod[$rBackups] <= time() || $rForce) {
        if (!$rForce) {
            $db->query('UPDATE `settings` SET `last_backup` = ?;', time());
        }
        $db->close_mysql();
        $rFilename = MAIN_HOME . 'backups/backup_' . date('Y-m-d_H:i:s') . '.sql';

        CoreUtilities::createBackup($rFilename);

        if (0 < filesize($rFilename)) {
            if (CoreUtilities::$rSettings['dropbox_remote']) {
                file_put_contents($rFilename . '.uploading', time());
                $rResponse = uploadRemoteBackup(basename($rFilename), $rFilename);
                if (!isset($rResponse->error)) {
                    $rResponse = json_decode(json_encode($rResponse, JSON_UNESCAPED_UNICODE), true);
                    if (!(isset($rResponse['size']) && intval($rResponse['size']) == filesize($rFilename))) {
                        $rError = 'Failed to upload';
                        file_put_contents($rFilename . '.error', $rError);
                    }
                } else {
                    try {
                        $rError = json_decode(explode(', in apiCall', $rResponse->error->getMessage())[0], true)['error_summary'];
                    } catch (exception $e) {
                        $rError = 'Unknown error';
                    }
                    file_put_contents($rFilename . '.error', $rError);
                }
                unlink($rFilename . '.uploading');
            }
        } else {
            unlink($rFilename);
        }
    }
}
$rBackups = getBackups();
if (intval(CoreUtilities::$rSettings['backups_to_keep']) < count($rBackups) && 0 < intval(CoreUtilities::$rSettings['backups_to_keep'])) {
    $rDelete = array_slice($rBackups, 0, count($rBackups) - intval(CoreUtilities::$rSettings['backups_to_keep']));
    foreach ($rDelete as $rItem) {
        if (file_exists(MAIN_HOME . 'backups/' . $rItem['filename'])) {
            unlink(MAIN_HOME . 'backups/' . $rItem['filename']);
        }
    }
}
if (CoreUtilities::$rSettings['dropbox_remote']) {
    $rRemoteBackups = getRemoteBackups();
    if (intval(CoreUtilities::$rSettings['dropbox_keep']) < count($rRemoteBackups) && 0 < intval(CoreUtilities::$rSettings['dropbox_keep'])) {
        $rDelete = array_slice($rRemoteBackups, 0, count($rRemoteBackups) - intval(CoreUtilities::$rSettings['dropbox_keep']));
        foreach ($rDelete as $rItem) {
            try {
                deleteRemoteBackup($rItem['path']);
            } catch (exception $e) {
            }
        }
    }
}
@unlink($rIdentifier);
