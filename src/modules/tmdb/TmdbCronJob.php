<?php

/**
 * TmdbCronJob — tmdb cron job
 *
 * @package XC_VM_Module_Tmdb
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

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
