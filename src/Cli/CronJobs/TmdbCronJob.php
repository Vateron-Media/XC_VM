<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Domain\Vod\TmdbCron;
use XcVm\Infrastructure\Tmdb\TmdbApiService;

/**
 * TmdbCronJob — tmdb cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

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

        TmdbApiService::requireLibrary();

        $this->initCron('XC_VM[TMDB]');

        $rTimeout = 3600;
        set_time_limit($rTimeout);
        ini_set('max_execution_time', $rTimeout);

        TmdbCron::run();

        return 0;
    }
}
