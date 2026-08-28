<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Infrastructure\Redis\RedisManager;

/**
 * Admin-ajax контроллер группы «Cache & Handlers».
 *
 * Вынесен из legacy `admin/api.php` (экшены `regenerate_cache`, `enable_cache`,
 * `disable_cache`, `enable_handler`, `disable_handler`, `clear_redis`). Логика
 * блоков перенесена дословно — изменён только обвес (gate/ok/fail из
 * {@see BaseAjaxController}). Все экшены гейтятся правом `adv/backups`.
 *
 * Маршруты регистрируются в `Public/routes/admin.php` через `$router->api(...)`
 * и достигают контроллера через `Router::dispatchApi()` до fallback на api.php.
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class CacheAjaxController extends BaseAjaxController {

    /** action=regenerate_cache — принудительный прогон cache-движка. */
    public function regenerate(): never {
        $this->requireXhr();
        $this->gate('adv', 'backups');

        shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine "force"');

        $this->ok();
    }

    /** action=enable_cache — включить кэш и поднять cache_handler, если не запущен. */
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

    /** action=disable_cache — выключить кэш. */
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

    /** action=enable_handler — включить Redis-handler и перезапустить redis/signals/watchdog. */
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

    /** action=disable_handler — выключить Redis-handler и погасить redis/signals/watchdog. */
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

    /** action=clear_redis — сбросить весь Redis. */
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
