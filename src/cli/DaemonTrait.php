<?php

/**
 * Общий функционал для CLI-демонов (signals, watchdog, queue, cache_handler).
 *
 * Выносит повторяющийся boilerplate: проверка пользователя, убийство предыдущих
 * инстансов, обнаружение изменений файла, авторестарт через console.php.
 */
trait DaemonTrait {

	/** @var string MD5 файла команды при запуске */
	protected $rDaemonMD5;

	/** @var int|null Последний тайм-штамп обновления настроек */
	protected $rLastCheck;

	/** @var int Интервал обновления настроек (секунды) */
	protected $rRefreshInterval = 60;

	/**
	 * Проверить что процесс запущен от пользователя xc_vm.
	 */
	protected function assertRunAsXcVm(): bool {
		if (posix_getpwuid(posix_geteuid())['name'] !== 'xc_vm') {
			echo "Please run as XC_VM!\n";
			return false;
		}
		return true;
	}

	/**
	 * Убить предыдущие инстансы этого демона.
	 *
	 * @param string $rPattern Grep-паттерн для поиска процессов
	 */
	protected function killStaleProcesses(string $rPattern): void {
		$rPID = intval(getmypid());
		$rPids = trim(shell_exec('ps aux | grep ' . escapeshellarg($rPattern) . ' | grep -v grep | grep -v ' . $rPID . " | awk '{print \$2}'") ?? '');
		if ($rPids !== '') {
			shell_exec('kill -9 ' . $rPids . ' > /dev/null 2>&1');
		}
	}

	/**
	 * Запомнить MD5 файла команды для обнаружения обновлений.
	 */
	protected function initDaemonMD5(): void {
		$this->rDaemonMD5 = md5_file((new ReflectionClass($this))->getFileName());
	}

	/**
	 * Проверить, изменился ли файл команды с момента запуска.
	 */
	protected function hasFileChanged(): bool {
		return md5_file((new ReflectionClass($this))->getFileName()) !== $this->rDaemonMD5;
	}

	/**
	 * Проверить нужно ли обновить настройки (по таймеру).
	 */
	protected function shouldRefreshSettings(): bool {
		if ($this->rLastCheck && $this->rRefreshInterval > time() - $this->rLastCheck) {
			return false;
		}
		return true;
	}

	/**
	 * Обновить настройки и сервера, проверить nginx и MD5.
	 *
	 * @return bool true = всё ОК, false = нужен break (файл изменился или nginx не работает)
	 */
	protected function refreshOrBreak(): bool {
		if (!$this->shouldRefreshSettings()) {
			return true;
		}

		if (!ProcessManager::isNginxRunning()) {
			echo "Not running! Break.\n";
			return false;
		}

		if ($this->hasFileChanged()) {
			echo "File changed! Break.\n";
			return false;
		}

		SettingsManager::set(SettingsRepository::getAll(true));
		$this->rLastCheck = time();
		return true;
	}

	/**
	 * Закрыть БД и перезапустить демон через console.php.
	 *
	 * @param string $rCommandName Имя команды (например 'signals', 'watchdog')
	 */
	protected function restartDaemon(string $rCommandName): void {
		global $db;
		if (is_object($db)) {
			$db->close_mysql();
		}
		shell_exec('(sleep 1; ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php ' . escapeshellarg($rCommandName) . ') > /dev/null 2>/dev/null &');
	}

	/**
	 * Установить заголовок процесса.
	 *
	 * @param string $rTitle Например 'XC_VM[Signals]'
	 */
	protected function setProcessTitle(string $rTitle): void {
		set_time_limit(0);
		cli_set_process_title($rTitle);
	}

	/**
	 * Инициализировать Redis если включён в настройках.
	 */
	protected function initRedisIfEnabled(): void {
		if (SettingsManager::getAll()['redis_handler'] ?? false) {
			RedisManager::ensureConnected();
		}
	}

	/**
	 * Проверить что Redis жив (если включён).
	 *
	 * @return bool true = OK или Redis не используется, false = соединение потеряно
	 */
	protected function checkRedisHealth(): bool {
		if (!(SettingsManager::getAll()['redis_handler'] ?? false)) {
			return true;
		}
		if (!RedisManager::instance() || !RedisManager::instance()->ping()) {
			echo "Redis connection lost\n";
			return false;
		}
		return true;
	}
}
