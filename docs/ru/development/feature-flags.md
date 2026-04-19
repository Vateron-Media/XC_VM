# Флаги разработки

XC_VM использует константы и флаги из настроек для управления поведением системы.

Константы приложения хранятся в `src/core/Config/AppConfig.php`.

---

## Активные runtime-флаги

### `PHP_ERRORS`

```php
define('PHP_ERRORS', $rShowErrors); // из $rSettings['debug_show_errors']
```

`PHP_ERRORS` используется для управления отображением ошибок PHP и уровнем debug-вывода в логгере:

```php
Logger::init(PHP_ERRORS, LOGS_TMP_PATH . 'error_log.log');
```

При `PHP_ERRORS=true` логгер выводит расширенные сообщения на экран.

### `DB_ACCESS_ENABLED`

```php
define('DB_ACCESS_ENABLED', false); // включает вкладку/страницу phpMiniAdmin в админ-панели
```

`DB_ACCESS_ENABLED` управляет только доступом к phpMiniAdmin из admin UI.
Этот флаг не блокирует основные подключения приложения к базе данных.

---

## Флаги из настроек (`$rSettings`)

Ряд поведенческих флагов хранится в настройках панели и загружается в `$rSettings`:

| Ключ | Тип | Описание |
| --- | --- | --- |
| `debug_show_errors` | `bool` | Показывать детальные ошибки вместо 404 (только dev) |
| `recaptcha_enable` | `bool` | Включить reCAPTCHA v2 на форме входа |
| `verify_host` | `bool` | Проверять домен из `allowed_domains` при каждом запросе |
| `save_login_logs` | `bool` | Записывать все попытки входа в `login_logs` |

Эти настройки загружаются из файлового кеша (`CACHE_TMP_PATH/settings`) через `RequestGuard`.

---

## Статические константы приложения

```php
define('DB_ACCESS_ENABLED', false);
define('XC_VM_VERSION', '2.1.2');
define('GIT_OWNER', 'Vateron-Media');
define('GIT_REPO_MAIN', 'XC_VM');
define('GIT_REPO_UPDATE', 'XC_VM_Update');
define('GIT_REPO_BIN', 'XC_VM_Binaries');
define('MONITOR_CALLS', 3);
define('OPENSSL_EXTRA', '...');
```

---

## Добавление нового флага

Для добавления флага уровня приложения — добавьте `define()` в `AppConfig.php`:

```php
define('MY_FEATURE_FLAG', false);
```

Для флагов, которыми должен управлять администратор через UI — добавьте ключ в таблицу `settings` и используйте `$rSettings['my_feature_flag']`.

> Не дублируйте одну и ту же настройку в обоих местах. Статические константы — для инфраструктурных значений. `$rSettings` — для пользовательских настроек.

---

## Связанные файлы

| Файл                              | Назначение                              |
| --------------------------------- | --------------------------------------- |
| `src/core/Config/AppConfig.php`   | Статические флаги и константы           |
| `src/core/Http/RequestGuard.php`  | Загружает `$rSettings` из кеша          |
| `src/core/Error/ErrorHandler.php` | Использует `debug_show_errors`          |
| `src/core/Logging/Logger.php`     | Использует `PHP_ERRORS`                 |
