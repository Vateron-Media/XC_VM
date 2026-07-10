<?php

namespace XcVm\Cli;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Config\SettingsRepository;
use XcVm\Core\Process\ProcessManager;
use XcVm\Infrastructure\Redis\RedisManager;

/**
 * Общий функционал для CLI-демонов (signals, watchdog, queue, cache_handler).
 *
 * Выносит повторяющийся boilerplate: проверка пользователя, убийство предыдущих
 * инстансов, обнаружение изменений файла, авторестарт через console.php.
 *
 * @package XC_VM_CLI
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

trait DaemonTrait {

	/** @var string MD5 файла команды при запуске */
	protected $rDaemonMD5;

	/** @var int|null Последний тайм-штамп обновления настроек */
	protected $rLastCheck;

	/** @var int Интервал обновления настроек (секунды) */
	protected $rRefreshInterval = 60;

	/** @var resource|null Файловый lock для singleton-демона */
	protected $rDaemonLockHandle;

	/**
	 * Проверить что процесс запущен от пользователя xc_vm.
	 */
	protected function assertRunAsXcVm(): bool {
		if (posix_getpwuid(posix_geteuid())['name'] !== 'xc_vm') {
			echo "Please run as \XC_VM!\n";
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
		$rPids = trim(@shell_exec('ps aux | grep ' . escapeshellarg($rPattern) . ' | grep -v grep | grep -v ' . $rPID . " | awk '{print \$2}'") ?? '');
		if ($rPids !== '') {
			@shell_exec('kill -9 ' . $rPids . ' > /dev/null 2>&1');
		}
	}

	/**
	 * Запомнить MD5 файла команды для обнаружения обновлений.
	 */
	protected function initDaemonMD5(): void {
		$this->rDaemonMD5 = md5_file((new \ReflectionClass($this))->getFileName());
	}

	/**
	 * Проверить, изменился ли файл команды с момента запуска.
	 */
	protected function hasFileChanged(): bool {
		return md5_file((new \ReflectionClass($this))->getFileName()) !== $this->rDaemonMD5;
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
		// Release the daemon flock BEFORE spawning the successor: the spawned
		// shell (and the next generation) inherit this process's open fds, so
		// an inherited still-held lock makes the successor fail its own
		// acquireDaemonLock with "lock busy" — the respawn chain then dies
		// after every single pass and the heartbeat only refreshes when
		// cron:servers revives it once a minute.
		if (isset($this->rDaemonLockHandle) && is_resource($this->rDaemonLockHandle)) {
			@flock($this->rDaemonLockHandle, LOCK_UN);
			@fclose($this->rDaemonLockHandle);
			$this->rDaemonLockHandle = null;
		}
		// `>` (truncate) — the log always holds ONLY the last generation's
		// output, so when the respawn chain dies its final words survive in
		// tmp/daemon_<name>.log without the file ever growing.
		$rLog = (defined('TMP_PATH') ? TMP_PATH : MAIN_HOME . 'tmp/')
			. 'daemon_' . preg_replace('/[^a-z0-9_\-]/i', '_', $rCommandName) . '.log';
		@shell_exec('(sleep 1; ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php ' . escapeshellarg($rCommandName) . ') > ' . escapeshellarg($rLog) . ' 2>&1 &');
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
	 * Взять singleton-lock для демона.
	 *
	 * @param string $rLockName Имя lock-файла без расширения
	 * @return bool true если lock взят, false если уже запущен другой экземпляр
	 */
	protected function acquireDaemonLock(string $rLockName): bool {
		$rBaseDir = defined('TMP_PATH') ? TMP_PATH . 'crons/' : (defined('MAIN_HOME') ? MAIN_HOME . 'tmp/crons/' : sys_get_temp_dir() . '/');
		if (!is_dir($rBaseDir)) {
			@mkdir($rBaseDir, 0775, true);
		}

		$rLockFile = rtrim($rBaseDir, '/') . '/daemon_' . preg_replace('/[^a-z0-9_\-]/i', '_', $rLockName) . '.lock';
		$rHandle = @fopen($rLockFile, 'c+');
		if (!$rHandle) {
			echo "Cannot open daemon lock file: " . $rLockFile . "\n";
			return false;
		}

		if (!@flock($rHandle, LOCK_EX | LOCK_NB)) {
			echo "Daemon already running (lock busy): " . $rLockName . "\n";
			@fclose($rHandle);
			return false;
		}

		@ftruncate($rHandle, 0);
		@fwrite($rHandle, (string) getmypid());
		@fflush($rHandle);
		$this->rDaemonLockHandle = $rHandle;
		return true;
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
		try {
			if (!RedisManager::instance() || !RedisManager::instance()->ping()) {
				echo "Redis connection lost\n";
				return false;
			}
		} catch (\RedisException $e) {
			echo 'Redis connection lost: ' . $e->getMessage() . "\n";
			return false;
		}
		return true;
	}
}
