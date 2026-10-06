<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Domain\Backup\BackupVerifier;
use XcVm\Domain\Server\ServerRepository;

/**
 * cron:backup_verify — weekly on MAIN: restore the newest backup into the
 * scratch database and check it (Domain\Backup\BackupVerifier).
 *
 * @package XC_VM_Cli_CronJobs
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class BackupVerifyCronJob implements CommandInterface {
	use CronTrait;

	public function getName(): string {
		return 'cron:backup_verify';
	}

	public function getDescription(): string {
		return 'Cron: restore the newest backup into a scratch database and check it';
	}

	public function execute(array $rArgs): int {
		if (empty(ServerRepository::getAll()[SERVER_ID]['is_main'])) {
			echo "Please run on main server.\n";
			return 1;
		}
		$this->initCron('XC_VM[BackupVerify]');
		$rResult = BackupVerifier::run(time());
		echo $rResult['state'] . ($rResult['file'] !== '' ? ' ' . $rResult['file'] : '') . ($rResult['error'] !== '' ? ': ' . $rResult['error'] : '') . ' (' . $rResult['tables'] . " tables)\n";
		return $rResult['state'] === 'failed' ? 1 : 0;
	}
}
