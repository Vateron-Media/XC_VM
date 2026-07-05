<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Domain\Vod\TmdbPopularCron;
use XcVm\Infrastructure\Tmdb\TmdbApiService;

/**
 * TmdbPopularCronJob — tmdb popular cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

require_once __DIR__ . '/../CronTrait.php';

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

        TmdbApiService::requireLibrary();

        TmdbPopularCron::run();

        return 0;
    }
}
