<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Infrastructure\Redis\RedisManager;

/**
 * Admin-ajax controller for the "Cache & Handlers" group.
 *
 * Extracted from the legacy `admin/api.php` (actions `regenerate_cache`,
 * `enable_cache`, `disable_cache`, `enable_handler`, `disable_handler`,
 * `clear_redis`). Block logic was ported verbatim — only the scaffolding
 * changed (gate/ok/fail from {@see BaseAjaxController}). Every action is gated
 * on the `adv/backups` permission.

 * Routes are registered in `Public/routes/admin.php` via `$router->api(...)` and
 * reach the controller through `Router::dispatchApi()` before the api.php fallback.
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
        $this->gate('adv', 'backups');

        shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine "force"');

        $this->ok();
    }

    /** action=enable_cache — enable the cache and start cache_handler if not running. */
    public function enableCache(): never {
        $this->requireXhr();
        $this->gate('adv', 'backups');

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
        $this->gate('adv', 'backups');

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
        $this->gate('adv', 'backups');

        global $db;
        $db->query('UPDATE `settings` SET `redis_handler` = 1;');

        if (file_exists(CACHE_TMP_PATH . 'settings')) {
            unlink(CACHE_TMP_PATH . 'settings');
        }

        exec('pgrep -u xc_vm redis-server', $rRedis);

        if (0 < count($rRedis) && is_numeric($rRedis[0])) {
            $rPID = intval($rRedis[0]);
            shell_exec('kill -9 ' . $rPID);
        }

        shell_exec(MAIN_HOME . 'bin/redis/redis-server ' . MAIN_HOME . '/bin/redis/redis.conf > /dev/null 2>/dev/null &');
        sleep(1);
        exec("pgrep -U xc_vm | xargs ps | grep -E 'signals|XC_VM\\[Signals\\]' | awk '{print \$1}'", $rPID);

        if (0 < count($rPID) && is_numeric($rPID[0])) {
            $rPID = intval($rPID[0]);
            shell_exec('kill -9 ' . $rPID);
            shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php signals > /dev/null 2>/dev/null &');
        }

        exec("pgrep -U xc_vm | xargs ps | grep -E 'watchdog|XC_VM\\[Watchdog\\]' | awk '{print \$1}'", $rPID);

        if (0 < count($rPID) && is_numeric($rPID[0])) {
            $rPID = intval($rPID[0]);
            shell_exec('kill -9 ' . $rPID);
            shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php watchdog > /dev/null 2>/dev/null &');
        }

        shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:users 1 > /dev/null 2>/dev/null &');

        $this->ok();
    }

    /** action=disable_handler — disable the Redis handler and stop redis/signals/watchdog. */
    public function disableHandler(): never {
        $this->requireXhr();
        $this->gate('adv', 'backups');

        global $db;
        $db->query('UPDATE `settings` SET `redis_handler` = 0;');

        if (file_exists(CACHE_TMP_PATH . 'settings')) {
            unlink(CACHE_TMP_PATH . 'settings');
        }

        exec('pgrep -u xc_vm redis-server', $rRedis);

        if (0 < count($rRedis) && is_numeric($rRedis[0])) {
            $rPID = intval($rRedis[0]);
            shell_exec('kill -9 ' . $rPID);
        }

        exec("pgrep -U xc_vm | xargs ps | grep -E 'signals|XC_VM\\[Signals\\]' | awk '{print \$1}'", $rPID);

        if (0 < count($rPID) && is_numeric($rPID[0])) {
            $rPID = intval($rPID[0]);
            shell_exec('kill -9 ' . $rPID);
            shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php signals > /dev/null 2>/dev/null &');
        }

        exec("pgrep -U xc_vm | xargs ps | grep -E 'watchdog|XC_VM\\[Watchdog\\]' | awk '{print \$1}'", $rPID);

        if (0 < count($rPID) && is_numeric($rPID[0])) {
            $rPID = intval($rPID[0]);
            shell_exec('kill -9 ' . $rPID);
            shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php watchdog > /dev/null 2>/dev/null &');
        }

        $this->ok();
    }

    /** action=clear_redis — flush the entire Redis store. */
    public function clearRedis(): never {
        $this->requireXhr();
        $this->gate('adv', 'backups');

        $rRedis = RedisManager::instance();

        if (!$rRedis) {
            $this->fail();
        }

        $rRedis->flushAll();

        $this->ok();
    }
}
