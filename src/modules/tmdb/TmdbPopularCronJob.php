<?php

require_once MAIN_HOME . 'cli/CronTrait.php';

class TmdbPopularCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string {
        return 'cron:tmdb_popular';
    }

    public function getDescription(): string {
        return 'Cron: update popular TMDB movies';
    }

    public function execute(array $rArgs): int {
        if (!$this->assertRunAsXcVm()) {
            return 1;
        }

        $this->initCron('XC_VM[Popular]');

        require_once INCLUDES_PATH . 'libs/tmdb.php';
        require_once __DIR__ . '/TmdbPopularCron.php';

        TmdbPopularCron::run();

        return 0;
    }
}
