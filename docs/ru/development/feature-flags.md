# Фичефлаги разработки

XC_VM использует набор констант в `src/core/Config/AppConfig.php` для управления поведением системы в разных окружениях.

---

## Текущие флаги

### `PHP_ERRORS`

```php
define('PHP_ERRORS', $rShowErrors); // из $rSettings['debug_show_errors']
```

`PHP_ERRORS` используется для управления отображением ошибок PHP и уровнем debug-вывода в логгере:

```php
Logger::init(PHP_ERRORS, LOGS_TMP_PATH . 'error_log.log');
```

При `PHP_ERRORS=true` логгер выводит расширенные сообщения на экран.

---

## Настройки из `config.ini` (`$rSettings`)

Ряд поведенческих флагов хранится в настройках панели и загружается в `$rSettings`:

| Ключ | Тип | Описание |
| --- | --- | --- |
| `debug_show_errors` | `bool` | Показывать детальные ошибки вместо 404 (только dev) |
| `recaptcha_enable` | `bool` | Включить reCAPTCHA v2 на форме входа |
| `verify_host` | `bool` | Проверять домен из `allowed_domains` при каждом запросе |
| `save_login_logs` | `bool` | Записывать все попытки входа в `login_logs` |

Эти настройки загружаются из файлового кеша (`CACHE_TMP_PATH/settings`) через `RequestGuard`, без обращения к БД на каждом запросе.

---

## Константы версии

```php
define('XC_VM_VERSION', '2.1.1');
```

Используется в UI панели и при проверке обновлений.

---

## Git-репозитории

```php
define('GIT_OWNER',       'Vateron-Media');
define('GIT_REPO_MAIN',   'XC_VM');
define('GIT_REPO_UPDATE', 'XC_VM_Update');
define('GIT_REPO_BIN',    'XC_VM_Binaries');
```

Используются системой обновлений для формирования URL к GitHub API и загрузки бинарных файлов.

---

## Прочие константы

| Константа       | Значение         | Описание                                         |
| --------------- | ---------------- | ------------------------------------------------ |
| `MONITOR_CALLS` | `3`              | Количество попыток повтора для задач мониторинга |
| `OPENSSL_EXTRA` | строка           | Дополнительная энтропия для OpenSSL-операций     |

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
