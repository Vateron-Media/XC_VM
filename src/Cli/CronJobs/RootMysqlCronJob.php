<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Core\Config\SettingsManager;

/**
 * RootMysqlCronJob — root mysql cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class RootMysqlCronJob implements CommandInterface {
	use CronTrait;

	public function getName(): string {
		return 'cron:root_mysql';
	}

	public function getDescription(): string {
		return 'Cron: monitor MariaDB, parse syslog (root)';
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsRoot()) {
			return 1;
		}

		if (!$this->checkMariaDB()) {
			return 1;
		}

		global $db;

		$this->setProcessTitle('XC_VM[MysqlErrors]');
		$this->acquireCronLock();

		$rIgnoreErrors = ['innodb: page_cleaner', 'aborted connection', 'got an error reading communication packets', 'got packets out of order', 'got timeout reading communication packets'];

		if (SettingsManager::get('mysql_sleep_kill') > 0) {
			$db->query("SELECT `id` FROM `INFORMATION_SCHEMA`.`PROCESSLIST` WHERE `COMMAND` = 'Sleep' AND `TIME` > ?;", intval(SettingsManager::get('mysql_sleep_kill')));
			foreach ($db->get_rows() as $rRow) {
				$db->query('KILL ?;', $rRow['id']);
			}
		}

		$db->query('SELECT MAX(`date`) AS `date` FROM `mysql_syslog`;');
		$rMaxTime = intval($db->get_row()['date']);
		// A line older than System Logs keep (keep_syslog) is not stored: cron:cleanup
		// has pruned it, and the rows left no longer say it was read.
		if (0 < intval(SettingsManager::get('keep_syslog'))) {
			$rMaxTime = max($rMaxTime, time() - intval(SettingsManager::get('keep_syslog')));
		}

		// Fast-path: skip expensive syslog tail/grep when file size has not changed.
		$rSyslogMarker = CRONS_TMP_PATH . 'mysql_syslog_size';
		$rCurrentSize = @filesize('/var/log/syslog') ?: 0;
		$rLastSize = file_exists($rSyslogMarker) ? intval(@file_get_contents($rSyslogMarker)) : -1;

		if ($rCurrentSize === $rLastSize) {
			@unlink($this->rIdentifier);
			return 0;
		}

		@file_put_contents($rSyslogMarker, $rCurrentSize);
		exec('sudo tail -n 1000 /var/log/syslog | grep mysqld', $rOutput, $rRetVal);
		foreach ($rOutput as $rError) {
			$rMySQLDParts = explode('mysqld[', $rError, 2);
			if (count($rMySQLDParts) < 2) {
				continue;
			}

			$rErrorParts = explode(']:', $rMySQLDParts[1], 2);
			if (count($rErrorParts) < 2) {
				continue;
			}

			$rStrip = trim($rErrorParts[1]);
			$rTime = strtotime(substr($rStrip, 0, 19));

			if ($rMaxTime >= $rTime) {
				continue;
			}

			if (empty($rStrip) || $this->inArray($rIgnoreErrors, $rStrip)) {
				continue;
			}

			$rNote = null;
			$rType = null;

			if (stripos($rStrip, '[Note]') !== false) {
				$rNote = trim(explode('[Note]', $rStrip)[1]);
				$rType = 'NOTICE';
			} elseif (stripos($rStrip, '[Warning]') !== false) {
				// A refused login included: it is kept as the warning it is, with
				// no address of its own, and no address is blocked for it.
				$rNote = trim(explode('[Warning]', $rStrip)[1]);
				$rType = 'WARNING';
			} elseif (strpos($rStrip, '[ERROR]') !== false) {
				// MariaDB writes the level of an error in capitals, unlike Note and Warning.
				$rNote = trim(explode('[ERROR]', $rStrip)[1]);
				$rType = 'ERROR';
			}

			if (!$rNote) {
				continue;
			}

			$rUsername = null;
			$rHost = null;
			$rDatabase = null;

			if (stripos($rNote, 'user:') !== false) {
				$rUsername = trim(explode("'", explode("user: '", $rNote)[1])[0]);
				$rHost = trim(explode("'", explode("host: '", $rNote)[1])[0]);
				$rDatabase = trim(explode("'", explode("db: '", $rNote)[1])[0]);
				$rType = 'ABORTED';
			}

			$db->query('INSERT INTO `mysql_syslog`(`type`,`error`,`username`,`ip`,`database`,`date`) VALUES(?,?,?,?,?,?)', $rType, $rNote, $rUsername, $rHost, $rDatabase, $rTime);
		}

		@unlink($this->rIdentifier);

		return 0;
	}

	private function inArray(array $needles, string $haystack): bool {
		foreach ($needles as $needle) {
			if (stristr($haystack, $needle)) {
				return true;
			}
		}
		return false;
	}

	private function checkMariaDB(): bool {
		exec('systemctl is-active mariadb 2>/dev/null', $out, $code);
		$isActive = isset($out[0]) && trim($out[0]) === 'active';

		if (!$isActive) {
			echo "[MYSQL] MariaDB is DOWN, restarting...\n";
			exec('systemctl restart mariadb 2>&1', $restartOut, $restartCode);
			sleep(3);

			exec('systemctl is-active mariadb 2>/dev/null', $checkOut);
			if (isset($checkOut[0]) && trim($checkOut[0]) === 'active') {
				echo "[MYSQL] MariaDB successfully restarted\n";
				return true;
			}
			echo "[MYSQL] FAILED to restart MariaDB\n";
			return false;
		}

		return true;
	}
}
