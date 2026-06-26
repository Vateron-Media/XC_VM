# Кэширование и стратегия Redis

XC_VM использует двухуровневую стратегию кэширования:

- **Файловый кэш (igbinary)** — основной уровень, используемый как стриминговым, так и административным путём
- **Redis/KeyDB** — опциональный высокопроизводительный уровень для состояния подключений и продвинутых операций

Стриминговый путь читает исключительно из файлового кэша (без запросов к базе данных).
Административный путь читает напрямую из базы данных с опциональным короткоживущим кэшем.

---

## Интерфейс кэша

Файл: `src/Core/Cache/CacheInterface.php`

```php
get($key, $maxAge = null)
set($key, $data, $ttl = 0)
delete($key)
has($key, $maxAge = null)
flush()
```

- `$ttl = 0` означает кэш навсегда (до ручного удаления или сброса).
- `$maxAge` проверяет время изменения файла для свежести (только FileCache).

---

## FileCache

Файл: `src/Core/Cache/FileCache.php`

Реализация кэша по умолчанию. Хранит igbinary-сериализованные данные в виде плоских файлов.

```php
$cache = new FileCache(CACHE_TMP_PATH);
$cache->set('my_key', $data, 3600);
$data = $cache->get('my_key', 120);     // только если меньше 2 минут
```

Статический упрощённый API (обратная совместимость):

```php
FileCache::setCache($key, $data)
FileCache::getCache($key, $maxAge = null)
```

Характеристики:

- Сериализация: igbinary (если доступна) или резервный PHP serialize.
- Блокировка: `LOCK_EX` при записи во избежание повреждения.
- Расположение файла: `{basePath}/{key}` (без подкаталогов для основных ключей).
- Восстановление при повреждении: определяет битые данные, автоматически удаляет повреждённые файлы.

---

## RedisCache

Файл: `src/Core/Cache/RedisCache.php`

Опциональная высокопроизводительная реализация.

```php
$redis = new RedisCache('127.0.0.1', 6379, $password, 'prefix:');
$redis->set($key, $data, 600);          // TTL 10 минут через SETEX
$redis->getConnection();                 // сырой phpredis для sorted sets, pipelines
```

- Ленивое подключение: подключается при первой операции.
- Нативная поддержка TTL через `SETEX` в Redis.
- Используется в первую очередь для `ConnectionTracker` (sorted sets для состояния активных подключений).

---

## Управление подключением к Redis

Файл: `src/Infrastructure/Redis/RedisManager.php`

Жизненный цикл singleton:

```php
RedisManager::instance()                 // получить активный Redis или null
RedisManager::ensureConnected()          // подключиться, если ещё не подключено
RedisManager::isConnected()              // проверка состояния
RedisManager::closeInstance()            // отключение
```

Проверка состояния пингует Redis каждые 30 секунд (с дебаунсом). Автоматически переподключается при сбое. Возвращает null при сбое подключения (плавная деградация).

Конфигурация:

| Настройка | Источник | По умолчанию |
| --- | --- | --- |
| `hostname` | `config.ini` | — |
| `port` | hardcoded | `6379` |
| `password` | `settings.redis_password` | — |
| `read_timeout` | hardcoded | `2.0 с` |
| `tcp_keepalive` | hardcoded | `60 с` |

---

## Наполнение кэша

Файлы кэша генерируются двумя cron-задачами:

### Лёгкий кэш (CacheCronJob)

Запускается каждый cron-цикл. Перестраивает быстро меняющиеся данные (~1 секунда):

- `settings` — настройки панели
- `servers` — список серверов
- `bouquets` — пакеты каналов
- `categories` — категории потоков
- Блок-листы: `blocked_isp`, `blocked_ua`, `blocked_ips`, `blocked_servers`
- `allowed_ips`, `output_formats`, `hmac_keys`, `rtmp_ips`

### Тяжёлый кэш (CacheEngineCronJob)

Перестраивает данные о потоках, линиях и сериях. Ограничен запуском раз в 5 минут через маркер `heavy_cache_built`:

- `STREAMS_TMP_PATH/stream_{id}` — метаданные отдельного потока
- `LINES_TMP_PATH/line_i_{user_id}` — данные аккаунта пользователя
- `LINES_TMP_PATH/line_c_{username_password}` — username → user_id lookup
- `LINES_TMP_PATH/line_t_{access_token}` — token → user_id lookup
- `SERIES_TMP_PATH/series_{id}` — метаданные сериала

Режим детекции изменений (если включено `cache_changes`): сравнивает временную метку `updated` в БД с `mtime` файла, перестраивает только изменённые элементы.

Режим полной перестройки: пересоздаёт все записи. Управляется настройкой `cache_thread_count`.

### Готовность кэша

Файл `cache_complete` записывается после каждой полной перестройки кэша. Стриминговый путь проверяет этот файл и завершается с ошибкой, если он отсутствует.

---

## Соглашения о ключах кэша

### Системные ключи (CACHE_TMP_PATH)

| Ключ | Содержимое |
| --- | --- |
| `settings` | массив настроек панели |
| `servers` | `array[server_id]` → конфигурация сервера |
| `bouquets` | `array[bouquet_id]` → определение букета |
| `categories` | `array[category_id]` → данные категории |
| `bouquet_map` | `array[stream_id]` → `array[bouquet_id]` |
| `category_map` | `array[bouquet_id]` → `array[category_id]` |
| `permissions_{group_id}` | набор прав группы |
| `cache_complete` | временная метка `time()` последней полной перестройки |

### Ключи потоков (STREAMS_TMP_PATH)

| Ключ | Содержимое |
| --- | --- |
| `stream_{id}` | информация о потоке + букеты + состояние по серверам |
| `channels_categories` | `array[stream_id]` → `array[category_id]` |

### Ключи линий (LINES_TMP_PATH)

| Ключ | Содержимое |
| --- | --- |
| `line_i_{user_id}` | полная запись пользователя |
| `line_c_{username_password}` | user_id (lookup по учётным данным) |
| `line_t_{access_token}` | user_id (lookup по токену) |

### Ключи сериалов (SERIES_TMP_PATH)

| Ключ | Содержимое |
| --- | --- |
| `series_{id}` | метаданные сериала |
| `series_map` | `array[stream_id]` → series_id |
| `episodes_{series_id}` | `array[season_num]` → список эпизодов |

---

## Паттерны инвалидации

| Триггер | Затронутые ключи | Механизм |
| --- | --- | --- |
| Админ редактирует поток | `stream_{id}`, `bouquet_map` | сигнал → следующий `cron:cache_engine` |
| Админ редактирует линию | `line_i_*`, `line_c_*`, `line_t_*` | следующий `cron:cache_engine` |
| Изменены настройки | `settings`, categories, blocklists | `SettingsManager::clearCache()` + cron |
| Обновлён список серверов | `servers`, `bouquet_map` | cron |
| Старт потока (FFprobe) | `{md5(source)}` | TTL 5 минут через проверку mtime файла |
| Кнопка flush в админке | все файлы в `CACHE_TMP_PATH` | `rm -rf` |

---

## Стриминговый путь vs административный

### Стриминговый путь (`www/stream/*`)

- `cached: true` по умолчанию.
- Читает только из файлового кэша (без запросов к БД).
- Сырая igbinary-десериализация: `igbinary_unserialize(file_get_contents(...))`.
- Если `cache_complete` отсутствует — выход с ошибкой.

### Административный путь (`Public/Controllers/Admin/*`)

- `cached: false` по умолчанию.
- Читает напрямую из базы данных через доменные сервисы.
- Опциональный короткоживущий кэш (пример из `BouquetService::getAll()`):

```php
$rCache = FileCache::getCache('bouquets', 60);  // только если меньше 60 с
if (!empty($rCache)) {
    return $rCache;
}
// промах: запрос к базе и запись кэша
FileCache::setCache('bouquets', $rOutput);
```

---

## Структура файлов кэша

```text
/home/xc_vm/tmp/cache/
├── settings
├── servers
├── bouquets
├── categories
├── bouquet_map
├── category_map
├── cache_complete
├── heavy_cache_built
├── streams/
│   ├── stream_{id}
│   └── channels_categories
├── lines/
│   ├── line_i_{user_id}
│   ├── line_c_{username_password}
│   └── line_t_{access_token}
└── series/
    ├── series_{id}
    ├── series_map
    └── episodes_{series_id}
```

---

## Связанные файлы

| Файл | Назначение |
| --- | --- |
| `src/Core/Cache/CacheInterface.php` | контракт кэша |
| `src/Core/Cache/FileCache.php` | реализация файлового кэша |
| `src/Core/Cache/RedisCache.php` | реализация Redis-кэша |
| `src/Infrastructure/Redis/RedisManager.php` | singleton подключения к Redis |
| `src/Infrastructure/Cache/CacheReader.php` | мост для legacy-чтения кэша |
| `src/Cli/CronJobs/CacheCronJob.php` | генерация лёгкого кэша |
| `src/Cli/CronJobs/CacheEngineCronJob.php` | генерация тяжёлого кэша (потоки, линии, сериалы) |
| `src/Domain/Bouquet/BouquetService.php` | пример кэширования на админ-пути |
| `src/Domain/Stream/ConnectionTracker.php` | Redis sorted sets для состояния подключений |
