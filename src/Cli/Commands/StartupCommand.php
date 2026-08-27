<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\DaemonTrait;
use XcVm\Core\Module\ModuleLoader;

/**
 * StartupCommand — startup command
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

require_once __DIR__ . '/../DaemonTrait.php';

class StartupCommand implements CommandInterface {
	use DaemonTrait;

	public function getName(): string {
		return 'startup';
	}

	public function getDescription(): string {
		return 'System initialization: daemons.sh, crontab, cache';
	}

	public function execute(array $rArgs): int {
		$rFixCron = false;
		if (!empty($rArgs[0]) && intval($rArgs[0]) == 1) {
			$rFixCron = true;
		}

		global $db;

		ini_set('display_errors', 1);
		ini_set('display_startup_errors', 1);
		error_reporting(32767);

		exec('sudo ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php status 1');

		// ── Восстановление daemons.sh при повреждении ────────
		if (filesize(MAIN_HOME . 'bin/daemons.sh') == 0) {
			echo "Daemons corrupted! Regenerating...\n";
			$rNewScript = '#! /bin/bash' . "\n";
			$rNewBalance = 'upstream php {' . "\n" . '    least_conn;' . "\n";
			$rTemplate = file_get_contents(MAIN_HOME . 'bin/php/etc/template');
			exec('rm -f ' . MAIN_HOME . 'bin/php/etc/*.conf');
			foreach (range(1, 4) as $i) {
				$rNewScript .= 'start-stop-daemon --start --quiet --pidfile ' . MAIN_HOME . 'bin/php/sockets/' . $i . '.pid --exec ' . MAIN_HOME . 'bin/php/sbin/php-fpm -- --daemonize --fpm-config ' . MAIN_HOME . 'bin/php/etc/' . $i . '.conf' . "\n";
				$rNewBalance .= '    server unix:' . MAIN_HOME . 'bin/php/sockets/' . $i . '.sock;' . "\n";
				file_put_contents(MAIN_HOME . 'bin/php/etc/' . $i . '.conf', str_replace('#PATH#', MAIN_HOME, str_replace('#ID#', (string) $i, $rTemplate)));
			}
			$rNewBalance .= '}';
			file_put_contents(MAIN_HOME . 'bin/daemons.sh', $rNewScript);
			exec('chmod 0771 ' . MAIN_HOME . 'bin/daemons.sh');
			exec('sudo chown xc_vm:xc_vm ' . MAIN_HOME . 'bin/daemons.sh');
			exec('sudo chown xc_vm:xc_vm ' . MAIN_HOME . 'bin/php/etc/*');
			file_put_contents(MAIN_HOME . 'bin/nginx/conf/balance.conf', $rNewBalance);
		}

		// ── Права на console.php (могут сброситься после обновления) ──
		$rConsolePath = MAIN_HOME . 'console.php';
		if (file_exists($rConsolePath) && !is_executable($rConsolePath)) {
			@chmod($rConsolePath, 0755);
		}

		// ── Права на bin/xc_fanout/run.sh (супервизор демона; режим теряется
		//    при mode-роняющем деплое → service не может его запустить → xc_fanout
		//    не поднимается → все потоки уходят в not-on-air). service запускает
		//    его через `bash`, это лишь второй пояс на случай прямого вызова. ──
		$rRunSh = MAIN_HOME . 'bin/xc_fanout/run.sh';
		if (file_exists($rRunSh) && !is_executable($rRunSh)) {
			@chmod($rRunSh, 0755);
		}

		// ── Установка crontab и запуск кэша ──────────────────
		if (posix_getpwuid(posix_geteuid())['name'] == 'root') {
			$this->installRootCrontab();
			if (!$rFixCron) {
				exec('sudo -u xc_vm ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache 1', $rOutput);
				$this->generateCacheIfNeeded();
			}
		} else {
			if (!$rFixCron) {
				exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache 1');
				$this->generateCacheIfNeeded();
			}
		}

		echo "\n";
		return 0;
	}

	private function installRootCrontab(): void {
		$rCrons = array();
		$rCrons[] = '* * * * * ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:root_signals # XC_VM';
		if (file_exists(MAIN_HOME . 'Cli/CronJobs/RootMysqlCronJob.php')) {
			$rCrons[] = '* * * * * ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:root_mysql # XC_VM';
		}
		// Renew per-machine ionCube licenses for platform modules before they
		// expire (runs as xc_vm so the .lic is owned by the panel user). No-op
		// when no licensed modules are installed.
		if (file_exists(MAIN_HOME . 'Cli/CronJobs/ModuleLicensesCronJob.php')) {
			$rCrons[] = '17 3 * * * sudo -u xc_vm ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:module_licenses # XC_VM';
		}

		foreach ((new ModuleLoader())->loadAll()->collectCronEntries() as $rEntry) {
			$rCrons[] = $rEntry;
		}

		$rWrite = false;
		$rOutput = array();
		exec('sudo crontab -l', $rOutput);

		// Удаляем старые записи XC_VM: путь v1.x.x (crons/root_) и любые
		// строки с нашим маркером — включая старый '# \XC_VM' от прошлой
		// миграции — чтобы при апгрейде не появлялись дубликаты.
		$rFiltered = array();
		foreach ($rOutput as $rLine) {
			if (strpos($rLine, MAIN_HOME . 'crons/root_') !== false
				|| strpos($rLine, '# XC_VM') !== false
				|| strpos($rLine, '# \XC_VM') !== false) {
				$rWrite = true;
				continue;
			}
			$rFiltered[] = $rLine;
		}
		$rOutput = $rFiltered;

		foreach ($rCrons as $rCron) {
			if (!in_array($rCron, $rOutput)) {
				$rOutput[] = $rCron;
				$rWrite = true;
			}
		}
		if ($rWrite) {
			$rCronFile = tempnam(TMP_PATH, 'crontab');
			file_put_contents($rCronFile, implode("\n", $rOutput) . "\n");
			exec('sudo chattr -i /var/spool/cron/crontabs/root');
			exec('sudo crontab -r');
			exec('sudo crontab ' . $rCronFile);
			exec('sudo chattr +i /var/spool/cron/crontabs/root');
			echo "Crontab installed\n";
		} else {
			echo "Crontab already installed\n";
		}
	}

	private function generateCacheIfNeeded(): void {
		if (!file_exists(CACHE_TMP_PATH . 'cache_complete')) {
			echo "Generating cache...\n";
			// Drop to xc_vm when running as root (service boot / installer):
			// cache files written by root cannot be refreshed later by the
			// xc_vm daemons and crons.
			$rPrefix = ((posix_getpwuid(posix_geteuid())['name'] ?? null) === 'root') ? 'sudo -u xc_vm ' : '';
			exec($rPrefix . PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine >/dev/null 2>/dev/null &');
		}
	}
}
