<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Domain\Alert\ExpiryReminders;
use XcVm\Domain\Server\ServerRepository;

/**
 * cron:reminders — daily on MAIN: remind the owners, MAG devices and webhooks
 * of lines that expire soon (Domain\Alert\ExpiryReminders).
 *
 * @package XC_VM_Cli_CronJobs
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class RemindersCronJob implements CommandInterface {
	use CronTrait;

	public function getName(): string {
		return 'cron:reminders';
	}

	public function getDescription(): string {
		return 'Cron: send the expiry reminders of lines that expire soon';
	}

	public function execute(array $rArgs): int {
		if (empty(ServerRepository::getAll()[SERVER_ID]['is_main'])) {
			echo "Please run on main server.\n";
			return 1;
		}
		$this->initCron('XC_VM[Reminders]');
		$rDone = ExpiryReminders::run(time());
		echo $rDone['lines'] . ' line(s): ' . $rDone['emails'] . ' e-mail(s), ' . $rDone['mag'] . ' MAG message(s), ' . $rDone['telegram'] . ' Telegram message(s), ' . $rDone['webhooks'] . " webhook(s)\n";
		return 0;
	}
}
