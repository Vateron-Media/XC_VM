<?php

require_once __DIR__ . '/../CronTrait.php';

class WatchCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string {
        return 'cron:watch';
    }

    public function getDescription(): string {
        return 'Cron: Watch Folder — automatic media scanning';
    }

    public function execute(array $rArgs): int {
        if (!$this->assertRunAsXcVm()) {
            return 1;
        }

        ini_set('memory_limit', -1);
        setlocale(LC_ALL, 'en_US.UTF-8');
        putenv('LC_ALL=en_US.UTF-8');

        $this->registerShutdown();

        require_once MAIN_HOME . 'modules/watch/WatchCron.php';

        $rForce = null;
        if (!empty($rArgs[0])) {
            $rForce = intval($rArgs[0]);
        }

        if (!$rForce) {
            if (file_exists(CACHE_TMP_PATH . 'watch_pid')) {
                $rPrevPID = intval(file_get_contents(CACHE_TMP_PATH . 'watch_pid'));
            } else {
                $rPrevPID = null;
            }
            if ($rPrevPID && ProcessManager::isRunning($rPrevPID, 'php')) {
                echo 'Watch folder is already running. Please wait until it finishes.' . "\n";
                return 0;
            }
        }

        file_put_contents(CACHE_TMP_PATH . 'watch_pid', getmypid());
        $this->setProcessTitle('XC_VM[Watch Folder]');

        set_time_limit(0);
        if (strlen(SettingsManager::getAll()['tmdb_api_key']) != 0) {
            WatchCron::run();
        } else {
            echo 'No TMDb API key.' . "\n";
            return 1;
        }

        return 0;
    }
}
