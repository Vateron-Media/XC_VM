# Флаги функций разработки

XC_VM использует константы и флаги, управляемые настройками, для управления поведением среды.

Константы приложения определяются отображением `appConfig()` в `src/Core/Config/ConstantsInitializer.php`.

---

## Активный флаг времени выполнения

### `PHP_ERRORS`

```php
define('PHP_ERRORS', $rShowErrors); // derived from $rSettings['debug_show_errors']
```

`PHP_ERRORS` управляет подробностями PHP/debug и выводом на экран регистратора:

```php
Logger::init(PHP_ERRORS, LOGS_TMP_PATH . 'error_log.log');
```

### `DB_ACCESS_ENABLED`

```php
define('DB_ACCESS_ENABLED', false); // enables phpMiniAdmin tab/page in admin panel
```

`DB_ACCESS_ENABLED` управляет доступом к phpMiniAdmin только из пользовательского интерфейса администратора.
Это не блокирует подключения к базе данных основного приложения. Его спутник `DB_ACCESS_PWD`
(также на карте `appConfig()`) устанавливает пароль, защищающий эту страницу — оставьте его пустым, чтобы сохранить
отключите вкладку, устанавливайте строгое значение только тогда, когда оно вам нужно.

### `DEV_MODE`

```php
define('DEV_MODE', false); // master development-mode flag
```

`DEV_MODE` - это параметр разработки во время компиляции (`bootstrap.php` преобразует его в `self::$devMode`).
Когда `true` включается `PHP_ERRORS` (подробные ошибки на экране), запускается диагностика повторной проверки,
и обеспечивает другие удобства для разработчиков.

> ⚠️ **Никогда не включайте `DEV_MODE` или `debug_show_errors` в рабочей среде** — оба раскрывают внутренние
> ошибки/пути к посетителям. `PHP_ERRORS` заканчивается на `true`, если **любой** константа `DEV_MODE` равна
> установлено (путь начальной загрузки) **или** значение `debug_show_errors` включено (путь защиты запроса); это
> правило разрешения, когда они накладываются друг на друга.

---

## Флаги, зависящие от настроек (`$rSettings`)

Загружается из кэша настроек и используется в точках принятия решений во время выполнения.

|Ключ|Тип|Значение|
| --- | --- | --- |
| `debug_show_errors` | `bool` |показывать подробные результаты ошибок/отладки|
| `recaptcha_enable` | `bool` |включите reCAPTCHA v2 при входе в систему|
| `verify_host` | `bool` |принудительная проверка списка разрешенных хостов (по умолчанию включено): имя хоста в запросе должно быть именем сервера `domain_name` или IP-адресом активного реселлера `reseller_dns`; IP-адрес всегда проходит, поэтому администратор, заблокированный по имени хоста, все равно может войти по IP и отключить его|
| `save_login_logs` | `bool` |постоянные попытки входа в систему в `login_logs`|
| `fanout_enabled` | `bool` |главный переключатель для демона xc_fanout (по умолчанию включен); off останавливает его на каждом узле, и оперативная доставка использует пути предварительного разветвления (см. подсистему потоковой передачи, "Отключение разветвления").|
| `fanout_supervise` | `bool` |передавайте прямые трансляции супервизору xc_fanout вместо PHP-монитора (по умолчанию включен)|
| `fanout_source_backend` |`auto` / `ffmpeg` / `native`|как исходные тексты преобразуются в формат MPEG-TS; под наблюдением, будут ли потоки, предназначенные только для копирования, работать в собственном ремуксоре (`auto`: с резервным ffmpeg)|
| `gateway_mode` |`off` / `shadow` / `segments` / `segments+playlist`|сегментный шлюз в xc_fanout отвечает на сегменты HLS, ключи и (с `segments+playlist`) обновления плейлиста, а также просмотр MPEG-TS (их первый запрос и повторное подключение) без использования PHP-FPM; `shadow` оценивает только зеркальную копию; `segments+playlist` на новой панели обновленная версия сохраняет свое значение (см. раздел подсистема потоковой передачи, "Сегментный шлюз")|

Эти значения загружаются из `CACHE_TMP_PATH/settings` защитниками запросов.

---

## Статические константы приложения

Из карты `appConfig()` в `src/Core/Config/ConstantsInitializer.php` (каждая запись является
`define()`заменено на `ConstantsInitializer::init()`):

```php
'DB_ACCESS_ENABLED' => false,
'DB_ACCESS_PWD'     => '',       // password for the phpMiniAdmin tab (empty = off)
'DEV_MODE'          => false,    // master development-mode switch
'XC_VM_VERSION'     => '2.4.1',  // bumped every release — treat as illustrative
'GIT_OWNER'         => 'Vateron-Media',
'GIT_REPO_MAIN'     => 'XC_VM',
'GIT_REPO_UPDATE'   => 'XC_VM_Update',
'GIT_REPO_BIN'      => 'XC_VM_Binaries',
'GIT_REPO_FANOUT'   => 'XC_VM_Fanout', // xc_fanout daemon source + binaries
'GIT_REPO_PROXY'    => 'XC_VM_Proxy',
'MONITOR_CALLS'     => 3,
'OPENSSL_EXTRA'     => '...',    // literal fallback; the real per-install secret is read
                                 // from the config/openssl_extra file by init()
```

---

## Добавление новых флагов

Используйте статические константы в карте `appConfig()` (`ConstantsInitializer.php`) для фиксированных констант инфраструктуры/среды выполнения (отредактированных в
код, вступающий в силу при следующем запросе). Используйте настройки (`$rSettings`) для значений, которые оператор переключает
из панели администратора (страница **Настройки**) — они сохраняются в таблице `settings` базы данных и
считывается с `CACHE_TMP_PATH/settings`.

Избегайте определения одного и того же поведения в обоих местах. Когда они неизбежно пересекаются (как в случае
`DEV_MODE` против `debug_show_errors` → `PHP_ERRORS`), эффективным значением является **операционная** из двух —
выигрывает любой из вариантов, включающий его.

---

## Связанные файлы

|Файл|Цель|
| --- | --- |
| `src/Core/Config/ConstantsInitializer.php` |статические константы приложения (`appConfig()` map)|
| `src/Core/Http/RequestGuard.php` |загружает `$rSettings`, устанавливает `PHP_ERRORS`|
| `src/Core/Error/ErrorHandler.php` |использует поведение `debug_show_errors`|
| `src/Core/Logging/Logger.php` |поведение при отладке/детализации|
