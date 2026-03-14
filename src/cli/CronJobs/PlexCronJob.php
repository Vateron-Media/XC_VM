<?php

require_once __DIR__ . '/../CronTrait.php';

class PlexCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string {
        return 'cron:plex';
    }

    public function getDescription(): string {
        return 'Cron: Plex Sync — scan and synchronize media';
    }

    public function execute(array $rArgs): int {
        if (!$this->assertRunAsXcVm()) {
            return 1;
        }

        ini_set('memory_limit', -1);
        setlocale(LC_ALL, 'en_US.UTF-8');
        putenv('LC_ALL=en_US.UTF-8');

        $this->registerShutdown();

        ini_set('display_errors', 1);
        ini_set('display_startup_errors', 1);
        error_reporting(30711);

        $rForce = null;
        if (!empty($rArgs[0])) {
            $rForce = intval($rArgs[0]);
        }

        if (!$rForce) {
            if (file_exists(CACHE_TMP_PATH . 'plex_pid')) {
                $rPrevPID = intval(file_get_contents(CACHE_TMP_PATH . 'plex_pid'));
            } else {
                $rPrevPID = null;
            }
            if ($rPrevPID && ProcessManager::isRunning($rPrevPID, 'php')) {
                echo 'Plex Sync is already running. Please wait until it finishes.' . "\n";
                return 0;
            }
        }

        $this->rIdentifier = CACHE_TMP_PATH . 'plex_pid';
        file_put_contents($this->rIdentifier, getmypid());
        $this->setProcessTitle('XC_VM[Plex Sync]');

        set_time_limit(0);
        if (!empty(SettingsManager::getAll()['tmdb_api_key'])) {
            PlexCron::run();
        } else {
            echo 'No TMDb API key.' . "\n";
            return 1;
        }

        return 0;
    }
}
