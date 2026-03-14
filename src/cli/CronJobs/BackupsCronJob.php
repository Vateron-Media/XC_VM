<?php

require_once __DIR__ . '/../CronTrait.php';

class BackupsCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string {
        return 'cron:backups';
    }

    public function getDescription(): string {
        return 'Cron: automatic database backup (local + Dropbox)';
    }

    public function execute(array $rArgs): int {
        if (!$this->assertRunAsXcVm()) {
            return 1;
        }

        ini_set('display_errors', 1);
        ini_set('display_startup_errors', 1);
        error_reporting(32757);

        require INCLUDES_PATH . 'admin.php';

        if (!ServerRepository::getAll()[SERVER_ID]['is_main']) {
            echo 'Please run on main server.' . "\n";
            return 1;
        }

        $this->setProcessTitle('XC_VM[Backups]');
        $this->acquireCronLock();

        global $db;

        $rForce = false;
        if (!empty($rArgs[0]) && intval($rArgs[0]) == 1) {
            $rForce = true;
        }

        $rBackups = SettingsManager::getAll()['automatic_backups'];
        $rLastBackup = intval(SettingsManager::getAll()['last_backup']);
        $rPeriod = array('hourly' => 3600, 'daily' => 86400, 'weekly' => 604800, 'monthly' => 2419200);

        if (!$rForce) {
            $rPID = getmypid();
            if (file_exists('/proc/' . SettingsManager::getAll()['backups_pid']) && 0 < strlen(SettingsManager::getAll()['backups_pid'])) {
                return 0;
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

                BackupService::create($rFilename, ConfigReader::getAll());

                if (0 < filesize($rFilename)) {
                    if (SettingsManager::getAll()['dropbox_remote']) {
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
        if (intval(SettingsManager::getAll()['backups_to_keep']) < count($rBackups) && 0 < intval(SettingsManager::getAll()['backups_to_keep'])) {
            $rDelete = array_slice($rBackups, 0, count($rBackups) - intval(SettingsManager::getAll()['backups_to_keep']));
            foreach ($rDelete as $rItem) {
                if (file_exists(MAIN_HOME . 'backups/' . $rItem['filename'])) {
                    unlink(MAIN_HOME . 'backups/' . $rItem['filename']);
                }
            }
        }

        if (SettingsManager::getAll()['dropbox_remote']) {
            $rRemoteBackups = getRemoteBackups();
            if (intval(SettingsManager::getAll()['dropbox_keep']) < count($rRemoteBackups) && 0 < intval(SettingsManager::getAll()['dropbox_keep'])) {
                $rDelete = array_slice($rRemoteBackups, 0, count($rRemoteBackups) - intval(SettingsManager::getAll()['dropbox_keep']));
                foreach ($rDelete as $rItem) {
                    try {
                        deleteRemoteBackup($rItem['path']);
                    } catch (exception $e) {
                    }
                }
            }
        }

        @unlink($this->rIdentifier);

        return 0;
    }
}
