# Контексты Bootstrap

`XC_Bootstrap` — единая точка входа для инициализации системы.
Каждый контекст загружает ровно тот набор подсистем, который нужен для конкретного типа запроса.

---

## Быстрый справочник

| Константа              | Строка      | Где используется                         |
| ---------------------- | ----------- | ---------------------------------------- |
| `CONTEXT_MINIMAL`      | `minimal`   | Скрипты, которым нужны только пути/конфиг |
| `CONTEXT_CLI`          | `cli`       | Cron-задачи, CLI-команды                 |
| `CONTEXT_STREAM`       | `stream`    | Стриминговые эндпоинты (live, vod, ts)   |
| `CONTEXT_ADMIN`        | `admin`     | Панель администратора / реселлера        |

---

## Что загружает каждый контекст

### CONTEXT_MINIMAL

Загружает только базовые константы и конфигурацию. База данных не подключается.

**Включает:**

- Автозагрузчик классов (`autoload.php`)
- Константы путей (`MAIN_HOME`, `INCLUDES_PATH`, `CONFIG_PATH`, …)
- Конфиг из `config.ini` → `$_INFO`
- Logger (`Logger::init()`)
- Функции ошибок (`generateError()`, `generate404()`)

**Не включает:** DB, Redis, сессии, Translator, Admin API.

**Пример:**

```php
require_once '/home/xc_vm/bootstrap.php';
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_MINIMAL);

// Теперь доступны MAIN_HOME, $_INFO, Logger
echo MAIN_HOME;
```

---

### CONTEXT_CLI

Предназначен для cron-заданий и CLI-команд. Добавляет подключение к БД поверх `CONTEXT_MINIMAL`.

**Включает** (дополнительно к MINIMAL):

- Подключение к базе данных
- `LegacyInitializer` (легаси-глобалы для функций из `www/`)
- Redis (по умолчанию: `false`; включить через опцию `'redis' => true`)
- `cli_set_process_title()` (если передана опция `'process'`)

**Пример:**

```php
require_once '/home/xc_vm/bootstrap.php';
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_CLI, [
    'cached'  => true,
    'process' => 'xc_vm: my-cron-job',
]);
```

---

### CONTEXT_STREAM

Лёгкий контекст для стриминговых эндпоинтов. Инициализирует только то, что нужно для горячего пути стриминга.

**Включает** (дополнительно к MINIMAL):

- Подключение к базе данных (`cached: true` — всегда)
- Flood-protection и проверка хоста

**Не включает:** Redis, Translator, Admin API, сессии.

> Это намеренное ограничение. Любой дополнительный код в горячем пути увеличивает задержку стриминга.

**Пример:**

```php
require_once '/home/xc_vm/bootstrap.php';
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_STREAM, ['cached' => true]);
```

---

### CONTEXT_ADMIN

Полная инициализация для панели администратора и реселлера.

**Включает** (дополнительно к MINIMAL):

- PHP-сессия (с параметром `SameSite=Strict`)
- Подключение к базе данных (`cached: false`)
- `LegacyInitializer`
- Redis
- Admin API / Reseller API
- Translator (локализация)
- Shutdown-обработчик
- Статусные константы
- Admin globals

**Пример:**

```php
require_once '/home/xc_vm/bootstrap.php';
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_ADMIN);
```

---

## Матрица подсистем

| Подсистема         | MINIMAL | CLI | STREAM | ADMIN |
| ------------------ | :-----: | :-: | :----: | :---: |
| Константы / пути   | ✅      | ✅  | ✅     | ✅    |
| Конфиг (`$_INFO`)  | ✅      | ✅  | ✅     | ✅    |
| Logger             | ✅      | ✅  | ✅     | ✅    |
| Flood-protection   | —       | —   | ✅     | ✅    |
| Проверка хоста     | —       | —   | ✅     | ✅    |
| База данных        | —       | ✅  | ✅     | ✅    |
| LegacyInitializer  | —       | ✅  | —      | ✅    |
| Redis              | —       | opt | —      | ✅    |
| PHP-сессия         | —       | —   | —      | ✅    |
| Admin API          | —       | —   | —      | ✅    |
| Translator         | —       | —   | —      | ✅    |

---

## Опции `boot()`

```php
XC_Bootstrap::boot(string $context, array $options = []);
```

| Опция        | Тип        | Умолчание                    | Описание                              |
| ------------ | ---------- | ---------------------------- | ------------------------------------- |
| `cached`     | `bool`     | `false` (admin), `true` (stream/cli) | Загружать настройки из кеша     |
| `redis`      | `bool`     | `true` (admin), `false` (остальные)  | Подключать Redis                |
| `process`    | `string`   | `''`                         | Имя процесса для `cli_set_process_title()` |
| `shutdown`   | `callable` | встроенный                   | Замена стандартного shutdown-обработчика |

---

## Идемпотентность

`boot()` выполняется **один раз за процесс** — повторные вызовы игнорируются.

```php
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_ADMIN);
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_CLI); // проигнорировано
```

Для сброса состояния (только в тестах):

```php
XC_Bootstrap::reset();
```

---

## Публичные методы

```php
XC_Bootstrap::getContext(): ?string   // текущий контекст
XC_Bootstrap::isBooted(): bool        // выполнен ли boot()
XC_Bootstrap::isCli(): bool           // работает ли в CLI
XC_Bootstrap::getDatabase(): ?Database
XC_Bootstrap::getContainer(): ServiceContainer
```
