<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Core\Localization\Translator;
use XcVm\Domain\Alert\Alerts;
use XcVm\Domain\Alert\ExpiryReminders;
use XcVm\Domain\Alert\TelegramLinks;
use XcVm\Domain\Server\ServerRepository;

/**
 * cron:alerts — every minute on MAIN: evaluate the alert rules and send what
 * fired or was resolved (Domain\Alert\Alerts).
 *
 * @package XC_VM_Cli_CronJobs
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class AlertsCronJob implements CommandInterface {
	use CronTrait;

	public function getName(): string {
		return 'cron:alerts';
	}

	public function getDescription(): string {
		return 'Cron: evaluate the alert rules and send their messages';
	}

	public function execute(array $rArgs): int {
		if (empty(ServerRepository::getAll()[SERVER_ID]['is_main'])) {
			echo "Please run on main server.\n";
			return 1;
		}
		// One run at a time: a slow channel must not make the next minute send twice.
		$this->initCron('XC_VM[Alerts]');
		// The dashboard checks' titles, in English.
		Translator::init();
		echo Alerts::run(time()) . " alert message(s) sent\n";
		// Subscribers who wrote /start or /stop to the reminders' bot.
		if (($rBot = ExpiryReminders::bot()) !== null) {
			TelegramLinks::poll($rBot, time());
		}
		return 0;
	}
}
