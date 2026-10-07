<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Infrastructure\Redis\RedisManager;

/**
 * Admin-ajax controller for cache and connection-handler operations:
 * regenerate_cache, enable_cache, disable_cache, enable_handler,
 * disable_handler, clear_redis. Every action is gated on `adv/database`,
 * the permission of the cache page.
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class CacheAjaxController extends BaseAjaxController {
	/** action=regenerate_cache — force a run of the cache engine. */
	public function regenerate(): never {
		$this->requireXhr();
		$this->gate('adv', 'database');

		shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine "force"');

		$this->ok();
	}

	/** action=enable_cache — enable the cache and start cache_handler if not running. */
	public function enableCache(): never {
		$this->requireXhr();
		$this->gate('adv', 'database');

		global $db;
		$db->query('UPDATE `settings` SET `enable_cache` = 1;');

		if (file_exists(CACHE_TMP_PATH . 'settings')) {
			unlink(CACHE_TMP_PATH . 'settings');
		}

		shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine');
		$rCache = intval(trim(shell_exec('pgrep -U xc_vm | xargs ps -f -p | grep -E "cache_handler|XC_VM\\[CacheHandler\\]" | grep -v grep | grep -v pgrep | wc -l')));

		if ($rCache == 0) {
			shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cache_handler > /dev/null 2>/dev/null &');
		}

		$this->ok();
	}

	/** action=disable_cache — disable the cache. */
	public function disableCache(): never {
		$this->requireXhr();
		$this->gate('adv', 'database');

		global $db;
		$db->query('UPDATE `settings` SET `enable_cache` = 0;');

		if (file_exists(CACHE_TMP_PATH . 'settings')) {
			unlink(CACHE_TMP_PATH . 'settings');
		}

		shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache');

		$this->ok();
	}

	/** action=enable_handler — enable the Redis handler and restart redis/signals/watchdog. */
	public function enableHandler(): never {
		$this->requireXhr();
		$this->gate('adv', 'database');

		global $db;
		$db->query('UPDATE `settings` SET `redis_handler` = 1;');

		if (file_exists(CACHE_TMP_PATH . 'settings')) {
			unlink(CACHE_TMP_PATH . 'settings');
		}

		// The panel's Redis by its pidfile: the first redis-server of the user
		// can be the cluster bus. The watchdog starts it again within seconds
		// (cron:servers within a minute), not this page: a server an FPM worker
		// starts inherits the pool's rlimit_files (4000), which caps its
		// maxclients near that. The signals and watchdog daemons read the
		// setting themselves; the sync below waits for Redis.
		if (($rPID = RedisManager::panelServerPid()) > 0) {
			posix_kill($rPID, 9);
		}

		shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:users 1 > /dev/null 2>/dev/null &');

		$this->ok();
	}

	/** action=disable_handler — disable the Redis handler and stop redis/signals/watchdog. */
	public function disableHandler(): never {
		$this->requireXhr();
		$this->gate('adv', 'database');

		global $db;
		$db->query('UPDATE `settings` SET `redis_handler` = 0;');

		if (file_exists(CACHE_TMP_PATH . 'settings')) {
			unlink(CACHE_TMP_PATH . 'settings');
		}

		// The panel's Redis by its pidfile: the first redis-server of the user
		// can be the cluster bus. The signals and watchdog daemons read the
		// setting themselves (restarted from here, they kept the FPM pool's
		// 4000-file limit for good).
		if (($rPID = RedisManager::panelServerPid()) > 0) {
			posix_kill($rPID, 9);
		}

		$this->ok();
	}

	/** action=clear_redis — flush the entire Redis store. */
	public function clearRedis(): never {
		$this->requireXhr();
		$this->gate('adv', 'database');

		$rRedis = RedisManager::instance();

		if (!$rRedis instanceof \Redis) {
			$this->fail();
		}

		$rRedis->flushAll(true); // ASYNC: freed in the background, not while Redis blocks every client

		$this->ok();
	}
}
