<?php

namespace XcVm\Cli;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\SettingsAudit;
use XcVm\Core\Config\SettingsRepository;
use XcVm\Core\Process\ProcessManager;
use XcVm\Domain\Server\ServerRepository;
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

	/** @var bool|null Did this daemon start on a node that reads no database of MAIN's (mode 2)? modeChanged() */
	protected $rStartedApi;

	/** @var int|null Последний тайм-штамп обновления настроек */
	protected $rLastCheck;

	/** @var int Интервал обновления настроек (секунды) */
	protected $rRefreshInterval = 60;

	/** @var int|null When the servers list was last reloaded from the DB. */
	protected $rServersRefreshedAt;

	/** @var resource|null Файловый lock для singleton-демона */
	protected $rDaemonLockHandle;

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
		$this->rStartedApi = NodeRole::refusesConnects();
	}

	/**
	 * Has the node moved into or out of mode 2 since this daemon started? A
	 * daemon decides once, at its start, whether it reads MAIN's database, and
	 * keeps the connection it opened: after a move to mode 2 it would go on
	 * reading MAIN's MySQL and Redis over it, and after a move back it would
	 * read nothing. It leaves its loop, and its next generation decides again.
	 */
	protected function modeChanged(): bool {
		return $this->rStartedApi !== null && $this->rStartedApi !== NodeRole::refusesConnects();
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

	/**
	 * Reload the servers list at most this often. Often enough that routing,
	 * ports and node state are never more than five seconds stale; rare
	 * enough that a tight loop does not re-read `servers` on every pass.
	 */
	protected function serversRefreshInterval(): int {
		return 5;
	}

	/** Is the servers list due for a reload? */
	protected function serversRefreshDue(?int $rNow = null): bool {
		return $this->rServersRefreshedAt === null
			|| ($rNow ?? time()) - $this->rServersRefreshedAt >= $this->serversRefreshInterval();
	}

	/**
	 * Reload the servers list from the DB (which also rewrites its cache).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function refreshServers(): array {
		$this->rServersRefreshedAt = time();
		return ServerRepository::getAll(true);
	}

	protected function shouldRefreshSettings(): bool {
		return !$this->rLastCheck || $this->rRefreshInterval <= time() - $this->rLastCheck;
	}

	/**
	 * Обновить настройки и сервера, проверить nginx и MD5.
	 *
	 * @return bool true = всё ОК, false = нужен break (файл изменился или nginx не работает)
	 */
	protected function refreshOrBreak(): bool {
		// Every pass, not with the settings: a daemon that started reading MAIN's
		// database stops within a pass of the node entering mode 2.
		if ($this->modeChanged()) {
			echo "Mode changed! Break.\n";
			// The node's report follows the move at once: its connect counts, and
			// whether it reads its streams on itself (MAIN shows both).
			SettingsAudit::republish();
			return false;
		}

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
		if (SettingsManager::get('redis_handler') ?? false) {
			RedisManager::ensureConnected();
		}
	}

	/**
	 * Проверить что Redis жив (если включён).
	 *
	 * @return bool true = OK или Redis не используется, false = соединение потеряно
	 */
	protected function checkRedisHealth(): bool {
		if (!(SettingsManager::get('redis_handler') ?? false)) {
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
