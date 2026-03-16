<?php

require_once MAIN_HOME . 'cli/CronTrait.php';

class TmdbCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string {
        return 'cron:tmdb';
    }

    public function getDescription(): string {
        return 'Cron: update TMDB data (series, movies)';
    }

    public function execute(array $rArgs): int {
        if (!$this->assertRunAsXcVm()) {
            return 1;
        }

        require INCLUDES_PATH . 'admin.php';
        require_once INCLUDES_PATH . 'libs/tmdb.php';
        require_once INCLUDES_PATH . 'libs/tmdb_release.php';
        require_once __DIR__ . '/TmdbCron.php';

        $this->initCron('XC_VM[TMDB]');

        $rTimeout = 3600;
        set_time_limit($rTimeout);
        ini_set('max_execution_time', $rTimeout);

        TmdbCron::run();

        return 0;
    }
}
