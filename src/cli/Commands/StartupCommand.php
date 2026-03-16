<?php

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
		// Сброс кэша автозагрузки — гарантирует актуальную карту классов после обновления
		XC_Autoloader::clearCache();
		XC_Autoloader::warmCache();

		$rFixCron = false;
		if (!empty($rArgs[0]) && intval($rArgs[0]) == 1) {
			$rFixCron = true;
		}

		global $db;

		ini_set('display_errors', 1);
		ini_set('display_startup_errors', 1);
		error_reporting(32767);

		if (file_exists(MAIN_HOME . 'status')) {
			exec('sudo ' . MAIN_HOME . 'status 1');
		}

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
				file_put_contents(MAIN_HOME . 'bin/php/etc/' . $i . '.conf', str_replace('#PATH#', MAIN_HOME, str_replace('#ID#', $i, $rTemplate)));
			}
			$rNewBalance .= '}';
			file_put_contents(MAIN_HOME . 'bin/daemons.sh', $rNewScript);
			exec('chmod 0771 ' . MAIN_HOME . 'bin/daemons.sh');
			exec('sudo chown xc_vm:xc_vm ' . MAIN_HOME . 'bin/daemons.sh');
			exec('sudo chown xc_vm:xc_vm ' . MAIN_HOME . 'bin/php/etc/*');
			file_put_contents(MAIN_HOME . 'bin/nginx/conf/balance.conf', $rNewBalance);
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
		if (file_exists(MAIN_HOME . 'cli/CronJobs/RootMysqlCronJob.php')) {
			$rCrons[] = '* * * * * ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:root_mysql # XC_VM';
		}
		$rWrite = false;
		$rOutput = array();
		exec('sudo crontab -l', $rOutput);

		// Удаляем старые записи от XC_VM v1.x.x, чтобы не было дубликатов, и проверяем наличие нужных записей
		$rFiltered = array();
		foreach ($rOutput as $rLine) {
			if (strpos($rLine, MAIN_HOME . 'crons/root_') !== false) {
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
			exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine >/dev/null 2>/dev/null &');
		}
	}
}
