#!/usr/bin/env php
<?php
/**
 * Диагностика Redis — тестирует соединение, pipeline, таймауты, reconnect.
 *
 * Запуск на сервере:
 *   /home/xc_vm/bin/php/bin/php /home/xc_vm/tools/test_redis.php
 *
 * Или локально (укажите hostname/password вручную):
 *   php tools/test_redis.php --host=127.0.0.1 --pass=YOUR_REDIS_PASSWORD
 */

// ─── Helpers ─────────────────────────────────────────────────────

function out($label, $status, $detail = '') {
    $icon = $status === 'OK' ? "\033[32m✓\033[0m" : ($status === 'FAIL' ? "\033[31m✗\033[0m" : "\033[33m⚠\033[0m");
    echo "  {$icon} {$label}";
    if ($detail) echo " — {$detail}";
    echo "\n";
}

function section($title) {
    echo "\n\033[1;36m── {$title} ──\033[0m\n";
}

// ─── Config ──────────────────────────────────────────────────────

$host = '127.0.0.1';
$port = 6379;
$pass = null;
$connectTimeout = 2.0;
$readTimeout = 2.0;

// Попытка загрузить конфиг из XC_VM bootstrap
$bootstrapLoaded = false;
$projectRoot = dirname(__DIR__);
$baseCandidates = [
    $projectRoot . '/src',   // repo layout: tools/ рядом с src/
    $projectRoot,            // server layout: tools/ рядом с bootstrap.php
    '/home/xc_vm',           // fallback: абсолютный путь на сервере
];

$basePath = null;
foreach ($baseCandidates as $candidate) {
    if (file_exists($candidate . '/autoload.php')) {
        $basePath = $candidate;
        break;
    }
}

if ($basePath && php_sapi_name() === 'cli') {
    try {
        require_once $basePath . '/autoload.php';

        $configIni = $basePath . '/config/config.ini';
        if (file_exists($configIni)) {
            $ini = parse_ini_file($configIni);
            if (!empty($ini['hostname'])) $host = $ini['hostname'];
        }

        $redisConf = $basePath . '/bin/redis/redis.conf';
        if (file_exists($redisConf)) {
            $lines = file($redisConf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (preg_match('/^requirepass\s+(.+)/', $line, $m)) {
                    $pass = trim($m[1]);
                    if ($pass === '#PASSWORD#') $pass = null;
                }
            }
        }
        $bootstrapLoaded = true;
    } catch (Throwable $e) {
        // ignore, will use CLI args
    }
}

// CLI overrides
foreach ($argv as $arg) {
    if (strpos($arg, '--host=') === 0) $host = substr($arg, 7);
    if (strpos($arg, '--port=') === 0) $port = (int)substr($arg, 7);
    if (strpos($arg, '--pass=') === 0) $pass = substr($arg, 7);
    if (strpos($arg, '--timeout=') === 0) $connectTimeout = (float)substr($arg, 10);
}

echo "\033[1;37m╔═══════════════════════════════════════╗\033[0m\n";
echo "\033[1;37m║     XC_VM Redis Diagnostics Tool      ║\033[0m\n";
echo "\033[1;37m╚═══════════════════════════════════════╝\033[0m\n";
echo "\n  Host: {$host}:{$port}  |  Password: " . ($pass ? '***' . substr($pass, -4) : 'NOT SET') . "\n";
echo "  Connect timeout: {$connectTimeout}s  |  Read timeout: {$readTimeout}s\n";

// ─── Test 1: Extension ──────────────────────────────────────────

section('1. PHP Redis Extension');

if (!extension_loaded('redis')) {
    out('redis extension', 'FAIL', 'не загружено! Установите: pecl install redis');
    exit(1);
}
$extVersion = phpversion('redis');
out('redis extension', 'OK', "v{$extVersion}");

$rc = new ReflectionClass('Redis');
$hasMulti = $rc->hasMethod('multi');
$hasExec = $rc->hasMethod('exec');
out('multi/exec support', ($hasMulti && $hasExec) ? 'OK' : 'FAIL');

// ─── Test 2: Connection ─────────────────────────────────────────

section('2. Connection');

$redis = new Redis();
try {
    $t0 = microtime(true);
    $connected = $redis->connect($host, $port, $connectTimeout);
    $connectMs = round((microtime(true) - $t0) * 1000, 1);
    out('connect()', $connected ? 'OK' : 'FAIL', "{$connectMs}ms");
} catch (RedisException $e) {
    out('connect()', 'FAIL', $e->getMessage());
    exit(1);
}

if ($pass) {
    try {
        $authed = $redis->auth($pass);
        out('auth()', $authed ? 'OK' : 'FAIL');
    } catch (RedisException $e) {
        out('auth()', 'FAIL', $e->getMessage());
        exit(1);
    }
} else {
    // Проверяем нужна ли авторизация
    try {
        $redis->ping();
        out('auth()', 'WARN', 'пароль не задан, но ping прошёл (Redis без пароля!)');
    } catch (RedisException $e) {
        if (stripos($e->getMessage(), 'NOAUTH') !== false) {
            out('auth()', 'FAIL', 'Redis требует пароль! Укажите --pass=...');
            exit(1);
        }
    }
}

try {
    $pong = $redis->ping();
    out('ping()', ($pong === true || $pong === '+PONG') ? 'OK' : 'FAIL', var_export($pong, true));
} catch (RedisException $e) {
    out('ping()', 'FAIL', $e->getMessage());
    exit(1);
}

// ─── Test 3: Server Info ────────────────────────────────────────

section('3. Redis Server');

try {
    $info = $redis->info();
    out('redis_version', 'OK', $info['redis_version'] ?? 'unknown');
    out('uptime', 'OK', ($info['uptime_in_seconds'] ?? '?') . ' sec');
    out('connected_clients', 'OK', $info['connected_clients'] ?? '?');
    out('used_memory_human', 'OK', $info['used_memory_human'] ?? '?');
    out('maxmemory_policy', 'OK', $info['maxmemory_policy'] ?? '?');

    $maxmem = $info['maxmemory'] ?? 0;
    $usedmem = $info['used_memory'] ?? 0;
    if ($maxmem > 0) {
        $pct = round($usedmem / $maxmem * 100, 1);
        out('memory usage', $pct > 90 ? 'WARN' : 'OK', "{$pct}% ({$info['used_memory_human']} / " . round($maxmem / 1048576) . "MB)");
    } else {
        out('maxmemory', 'WARN', 'не задан (unlimited) — рискованно для production');
    }

    // Keys count
    $dbsize = $redis->dbSize();
    out('total keys', 'OK', number_format($dbsize));
} catch (RedisException $e) {
    out('info()', 'FAIL', $e->getMessage());
}

// ─── Test 4: Read/Write ─────────────────────────────────────────

section('4. Read/Write');

$testKey = '__xcvm_diag_' . getmypid();
try {
    $redis->set($testKey, 'test_value', 5);
    $val = $redis->get($testKey);
    out('set/get', $val === 'test_value' ? 'OK' : 'FAIL', $val === 'test_value' ? '' : "got: " . var_export($val, true));
    $redis->del($testKey);
} catch (RedisException $e) {
    out('set/get', 'FAIL', $e->getMessage());
}

// ─── Test 5: MULTI/EXEC (Pipeline) ─────────────────────────────

section('5. MULTI/EXEC (Pipeline) — ЭТО КЛЮЧЕВОЙ ТЕСТ');

// 5a: Простой pipeline
try {
    $multi = $redis->multi();
    $multi->set($testKey . '_a', 'val_a');
    $multi->set($testKey . '_b', 'val_b');
    $multi->get($testKey . '_a');
    $multi->get($testKey . '_b');
    $result = $multi->exec();

    if ($result === false) {
        out('multi/exec basic', 'FAIL', 'exec() вернул false — pipeline сломан!');
    } elseif (!is_array($result)) {
        out('multi/exec basic', 'FAIL', 'exec() вернул ' . gettype($result) . ': ' . var_export($result, true));
    } else {
        $ok = count($result) === 4 && $result[2] === 'val_a' && $result[3] === 'val_b';
        out('multi/exec basic', $ok ? 'OK' : 'FAIL', 'results: ' . json_encode($result));
    }
    $redis->del($testKey . '_a', $testKey . '_b');
} catch (RedisException $e) {
    out('multi/exec basic', 'FAIL', $e->getMessage());
}

// 5b: Pipeline с sorted sets (как ConnectionTracker)
try {
    // Подготовка данных
    $redis->zAdd($testKey . '_zset', 100, 'member_1');
    $redis->zAdd($testKey . '_zset', 200, 'member_2');

    $multi = $redis->multi();
    $multi->zRevRangeByScore($testKey . '_zset', '+inf', '-inf');
    $multi->zRevRangeByScore($testKey . '_zset', '+inf', '-inf', ['limit' => [0, 1]]);
    $multi->zCard($testKey . '_zset');
    $result = $multi->exec();

    if ($result === false) {
        out('multi/exec zset', 'FAIL', 'exec() вернул false');
    } elseif (!is_array($result)) {
        out('multi/exec zset', 'FAIL', 'exec() вернул ' . gettype($result));
    } else {
        $rangeOk = is_array($result[0]) && count($result[0]) === 2;
        $limitOk = is_array($result[1]) && count($result[1]) === 1;
        $cardOk = $result[2] === 2;
        out('zRevRangeByScore', $rangeOk ? 'OK' : 'FAIL', json_encode($result[0]));
        out('zRevRangeByScore+limit', $limitOk ? 'OK' : 'FAIL', json_encode($result[1]));
        out('zCard', $cardOk ? 'OK' : 'FAIL', (string)$result[2]);
    }
    $redis->del($testKey . '_zset');
} catch (RedisException $e) {
    out('multi/exec zset', 'FAIL', $e->getMessage());
}

// 5c: Пустой pipeline (0 команд)
try {
    $multi = $redis->multi();
    $result = $multi->exec();

    if ($result === false) {
        out('multi/exec empty', 'WARN', 'exec() на пустом pipeline вернул false');
    } else {
        out('multi/exec empty', 'OK', 'результат: ' . json_encode($result));
    }
} catch (RedisException $e) {
    out('multi/exec empty', 'WARN', $e->getMessage());
}

// 5d: Большой pipeline (100 команд — имитация таблицы с 100 юзерами)
try {
    $multi = $redis->multi();
    for ($i = 0; $i < 100; $i++) {
        $multi->zRevRangeByScore('LINE#999999' . $i, '+inf', '-inf', ['limit' => [0, 1]]);
    }
    $t0 = microtime(true);
    $result = $multi->exec();
    $execMs = round((microtime(true) - $t0) * 1000, 1);

    if ($result === false) {
        out('multi/exec 100cmd', 'FAIL', 'exec() вернул false');
    } elseif (!is_array($result)) {
        out('multi/exec 100cmd', 'FAIL', gettype($result));
    } else {
        out('multi/exec 100cmd', 'OK', count($result) . " results in {$execMs}ms");
    }
} catch (RedisException $e) {
    out('multi/exec 100cmd', 'FAIL', $e->getMessage());
}

// ─── Test 6: Serializer ─────────────────────────────────────────

section('6. Serializer (igbinary)');

$hasIgbinary = extension_loaded('igbinary');
out('igbinary extension', $hasIgbinary ? 'OK' : 'FAIL', $hasIgbinary ? 'v' . phpversion('igbinary') : 'не загружено!');

if ($hasIgbinary) {
    try {
        $testData = ['user_id' => 42, 'stream_id' => 557, 'uuid' => 'test-uuid'];
        $serialized = igbinary_serialize($testData);
        $redis->set($testKey . '_igb', $serialized, 5);
        $raw = $redis->get($testKey . '_igb');
        $deserialized = igbinary_unserialize($raw);
        $ok = $deserialized['user_id'] === 42 && $deserialized['stream_id'] === 557;
        out('igbinary round-trip', $ok ? 'OK' : 'FAIL', $ok ? '' : var_export($deserialized, true));
        $redis->del($testKey . '_igb');
    } catch (Throwable $e) {
        out('igbinary round-trip', 'FAIL', $e->getMessage());
    }
}

// ─── Test 7: Timeout Behavior ───────────────────────────────────

section('7. Options & Timeouts');

try {
    $rOpt = $redis->getOption(Redis::OPT_READ_TIMEOUT);
    out('OPT_READ_TIMEOUT', 'OK', "{$rOpt} sec");
    if ((float)$rOpt < 2.0) {
        out('read timeout', 'WARN', "слишком малый ({$rOpt}s), pipeline может не успевать");
    }
} catch (Throwable $e) {
    out('OPT_READ_TIMEOUT', 'WARN', $e->getMessage());
}

try {
    $tcpKeepalive = $redis->getOption(Redis::OPT_TCP_KEEPALIVE);
    out('OPT_TCP_KEEPALIVE', 'OK', "{$tcpKeepalive} sec");
} catch (Throwable $e) {
    out('OPT_TCP_KEEPALIVE', 'WARN', $e->getMessage());
}

// Проверяем timeout сервера
try {
    $configTimeout = $redis->config('GET', 'timeout');
    $t = $configTimeout['timeout'] ?? '?';
    out('server timeout', ($t == 0) ? 'WARN' : 'OK', "{$t} sec" . ($t == 0 ? ' (0 = connections never timeout — OK for persistent)' : ''));
} catch (Throwable $e) {
    out('server timeout', 'WARN', $e->getMessage());
}

// ─── Test 8: Проверка XC_VM ключей ──────────────────────────────

section('8. XC_VM Live Data');

$liveKeys = ['LIVE', 'SIGNALS#*', 'SERVER#*', 'LINE#*', 'STREAM#*', 'PROXY#*', 'ENDED'];
foreach ($liveKeys as $pattern) {
    try {
        if (strpos($pattern, '*') !== false) {
            $keys = $redis->keys($pattern);
            $count = count($keys);
            out($pattern, 'OK', "{$count} keys");
        } else {
            $type = $redis->type($pattern);
            $typeNames = [0 => 'none', 1 => 'string', 2 => 'set', 3 => 'list', 4 => 'zset', 5 => 'hash'];
            $typeName = $typeNames[$type] ?? "unknown({$type})";
            if ($type === 4) { // zset
                $card = $redis->zCard($pattern);
                out($pattern, 'OK', "{$typeName}, {$card} members");
            } elseif ($type === 2) { // set
                $card = $redis->sCard($pattern);
                out($pattern, 'OK', "{$typeName}, {$card} members");
            } elseif ($type === 0) {
                out($pattern, 'WARN', 'не существует');
            } else {
                out($pattern, 'OK', $typeName);
            }
        }
    } catch (Throwable $e) {
        out($pattern, 'FAIL', $e->getMessage());
    }
}

// ─── Test 9: Reconnect после close ──────────────────────────────

section('9. Reconnect Simulation');

try {
    $redis->close();
    out('close()', 'OK');
} catch (Throwable $e) {
    out('close()', 'WARN', $e->getMessage());
}

// Проверяем поведение exec() после close
// phpredis ≥6.x авто-реконнектится → exec() вернёт array (нормально).
// phpredis <6 → exec() вернёт false или выбросит исключение.
try {
    $multi = @$redis->multi();
    if ($multi) {
        @$multi->ping();
        $result = @$multi->exec();
        if ($result === false) {
            out('exec() after close', 'OK', 'вернул false — авто-реконнект выключен');
        } elseif (is_array($result)) {
            out('exec() after close', 'OK', 'вернул array — phpredis авто-реконнект (v6.x+)');
        } else {
            out('exec() after close', 'WARN', 'неожиданный тип: ' . gettype($result));
        }
    } else {
        out('multi() after close', 'OK', 'вернул false');
    }
} catch (RedisException $e) {
    out('exec() after close', 'OK', 'RedisException (ожидаемо): ' . $e->getMessage());
}

// Reconnect
try {
    $redis = new Redis();
    $redis->connect($host, $port, $connectTimeout);
    if ($pass) $redis->auth($pass);
    $pong = $redis->ping();
    out('reconnect', ($pong === true || $pong === '+PONG') ? 'OK' : 'FAIL');
} catch (Throwable $e) {
    out('reconnect', 'FAIL', $e->getMessage());
}

// ─── Test 10: RedisManager singleton behavior ───────────────────

section('10. RedisManager singleton (если доступен)');

// Paths.php определяет CONFIG_PATH и другие константы, нужные RedisManager → ConfigReader
if ($basePath && !defined('CONFIG_PATH')) {
    $pathsFile = $basePath . '/core/Config/Paths.php';
    if (file_exists($pathsFile)) {
        require_once $pathsFile;
    }
}

if (class_exists('RedisManager')) {
    // RedisManager зависит от ConfigReader (config.ini) и SettingsManager (БД).
    // Без полного bootstrap SettingsManager пуст → connect() вернёт null.
    $hasConfig = class_exists('ConfigReader', false) && !empty(ConfigReader::getAll());
    $hasSettings = class_exists('SettingsManager', false) && !empty(SettingsManager::getAll());

    if (!$hasConfig || !$hasSettings) {
        // Прокидываем данные из redis.conf/config.ini чтобы тест работал без БД
        if (!$hasConfig && $host && defined('CONFIG_PATH')) {
            // ConfigReader::getAll() сам загрузит config.ini при наличии CONFIG_PATH
            $hasConfig = !empty(ConfigReader::getAll());
        }
        if (!$hasSettings && $pass) {
            SettingsManager::set(['redis_password' => $pass]);
            $hasSettings = true;
        }
    }

    if (!$hasConfig || !$hasSettings) {
        out('RedisManager', 'WARN', 'ConfigReader/SettingsManager не инициализированы (нужен полный bootstrap с БД)');
    } else {
        try {
            $inst = RedisManager::instance();
            out('instance()', is_object($inst) ? 'OK' : 'FAIL', is_object($inst) ? get_class($inst) : 'null!');

            if (is_object($inst)) {
                // Проверяем: instance() при живом соединении не переподключается
                $inst2 = RedisManager::instance();
                out('singleton identity', ($inst === $inst2) ? 'OK' : 'WARN', ($inst === $inst2) ? 'тот же объект' : 'РАЗНЫЕ объекты!');

                // Проверяем: после close, instance() делает reconnect?
                RedisManager::closeInstance();
                $inst3 = RedisManager::instance();
                out('reconnect after close', is_object($inst3) ? 'OK' : 'FAIL',
                    is_object($inst3) ? 'singleton переподключился' : 'instance() вернул null после close!'
                );
            }
        } catch (Throwable $e) {
            out('RedisManager', 'FAIL', $e->getMessage());
        }
    }
} else {
    out('RedisManager', 'WARN', 'класс не загружен (запустите на сервере через console или с bootstrap)');
}

// ─── Summary ────────────────────────────────────────────────────

section('Summary');
echo "  Если тесты 5 (MULTI/EXEC) прошли — причина ошибок NOT в Redis.\n";
echo "  Если тесты 5 FAIL — проблема на стороне Redis-сервера.\n";
echo "  Если тест 9 показывает exec()=false после close — ConnectionTracker\n";
echo "  получает dead connection из RedisManager::instance().\n";
echo "\n";

$redis->close();
