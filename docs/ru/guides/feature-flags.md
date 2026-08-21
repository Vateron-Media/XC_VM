# Флаги функций разработки

XC_VM использует константы и флаги, управляемые настройками, для управления поведением среды.

Константы приложения хранятся в виде `src/Core/Config/AppConfig.php`.

---

## Активный флаг времени выполнения

### `PHP_ERRORS`

```php
define('PHP_ERRORS', $rShowErrors); // derived from $rSettings['debug_show_errors']
```

`PHP_ERRORS` управляет PHP детализацией/отладкой и выводом на экран регистратора:

```php
Logger::init(PHP_ERRORS, LOGS_TMP_PATH . 'error_log.log');
```

### `DB_ACCESS_ENABLED`

```php
define('DB_ACCESS_ENABLED', false); // enables phpMiniAdmin tab/page in admin panel
```

`DB_ACCESS_ENABLED` управляет доступом к phpMiniAdmin только из пользовательского интерфейса администратора.
Это не блокирует подключения к базе данных основного приложения.

---

## Флаги, зависящие от настроек (`$rSettings`)

Загружается из кэша настроек и используется в точках принятия решений во время выполнения.

|Ключ|Тип|Значение|
| --- | --- | --- |
| `debug_show_errors` | `bool` |показывать подробные результаты ошибок/отладки|
| `recaptcha_enable` | `bool` |включите reCAPTCHA v2 при входе в систему|
| `verify_host` | `bool` |принудительная проверка списка разрешений хоста|
| `save_login_logs` | `bool` |постоянные попытки входа в систему в `login_logs`|

Эти значения загружаются из `CACHE_TMP_PATH/settings` защитниками запросов.

---

## Статические константы приложения

Из `src/Core/Config/AppConfig.php`:

```php
define('DB_ACCESS_ENABLED', false);
define('XC_VM_VERSION', '2.2.1');
define('GIT_OWNER', 'Vateron-Media');
define('GIT_REPO_MAIN', 'XC_VM');
define('GIT_REPO_UPDATE', 'XC_VM_Update');
define('GIT_REPO_BIN', 'XC_VM_Binaries');
define('MONITOR_CALLS', 3);
define('OPENSSL_EXTRA', '...');
```

---

## Добавление новых флагов

Используйте статические константы в `AppConfig.php` для фиксированных констант инфраструктуры/среды выполнения.
Используйте настройки (`$rSettings`) для значений, которыми необходимо управлять из пользовательского интерфейса панели.

Избегайте определения одного и того же поведения в обоих местах.

---

## Связанные файлы

|Файл|Цель|
| --- | --- |
| `src/Core/Config/AppConfig.php` |статические константы приложения|
| `src/Core/Http/RequestGuard.php` |загружает `$rSettings`, устанавливает `PHP_ERRORS`|
| `src/Core/Error/ErrorHandler.php` |использует поведение `debug_show_errors`|
| `src/Core/Logging/Logger.php` |поведение при отладке/детализации|
