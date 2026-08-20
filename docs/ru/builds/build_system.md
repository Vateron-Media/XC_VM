# XC_VM Система сборки (ОСНОВНАЯ против LB)

Как XC_VM создает два варианта сборки из одной кодовой базы: полноценный основной сервер и облегченный сервер балансировки нагрузки (LB).

---

## Варианты сборки

XC_VM поддерживает две роли развертывания из одного дерева исходных текстов:

|Вариант|Архив|Цель|
| --- | --- | --- |
|**ОСНОВНОЙ**| `xc_vm.tar.gz` |Полное приложение — админ-панель, потоковое вещание, все модули, задания cron|
|**Фунт** (балансировщик нагрузки)| `loadbalancer.tar.gz` |Сервер только для потоковой передачи — нет панели администратора, нет управления пользователями|

**MAIN** - это основной сервер, который управляет всем: пользовательским интерфейсом администратора, записями в базу данных, управлением пользователями/устройствами, обработкой EPG, резервным копированием и т.д.

**LB** - это облегченный потоковый узел, который получает потоки из MAIN (или других источников) и доставляет их клиентам. Он подключается к базе данных master в режиме только для чтения и не имеет панели администратора или возможностей управления.

---

## Целевые объекты Makefile

|Цель|Выход|Описание|
| --- | --- | --- |
| `make main` | `dist/xc_vm.tar.gz` |Полная ОСНОВНАЯ сборка|
| `make lb` | `dist/loadbalancer.tar.gz` |Сборка LB (подмножество только для потоковой передачи)|
| `make main_update` | `dist/update.tar.gz` |Постепенное ОСНОВНОЕ обновление|
| `make lb_update` | `dist/loadbalancer_update.tar.gz` |Постепенное обновление LB|
| `make new` |Обе полные сборки|Короткий путь: `main` + `lb`|

Дополнительные выходы:

- `XC_VM.zip` — установочный пакет (`install/` + `xc_vm.tar.gz`)
- `hashes.md5` — Контрольные суммы MD5 для проверки целостности

---

## Composer Зависимости

`src/vendor/` (автозагрузчик Composer PSR-4 плюс производственные зависимости) - это
**зафиксировано** и отправлено как есть - путь развертывания не содержит Composer и никогда не выполняется
`composer install`. Он поддерживается только в рабочем состоянии через `composer install --no-dev`, так что
оба варианта сборки предназначены для бережливого производства без инструментов разработки.

- `src/composer.lock` фиксируется, поэтому `composer install` является воспроизводимым.
- Инструменты разработки (PHPStan, PHP-CS-Fixer) являются `require-dev` и ** отсутствуют** в
зарегистрированный поставщик или архивы. Разработчики и CI добавляют их с помощью `make dev-tools`
(`composer install`); шлюз `check-vendor-prod-only` завершается сбоем, если пакет разработчика
когда-либо совершались под `src/vendor/`.
- Нет шага поставщика во время сборки — `make main` / `make lb` скопируйте зафиксированный
`vendor/` непосредственно в архив.

---

## Что входит в Каждую Сборку

### ОСНОВНАЯ сборка

ОСНОВНАЯ сборка содержит **весь ** каталог `src/`.

### Каталоги— включенные в сборку LB

Только эти каталоги копируются в архив LB:

```text
bin/        Cli/        config/     content/    Core/
Domain/     Infrastructure/         public/     resources/
signals/    Streaming/  tmp/        www/
```

Плюс корневые файлы: `bootstrap.php`, `console.php`, `service`, `update`.

### Содержимое— исключенное из сборки LB

После копирования содержимое, относящееся к администратору, **удаляется** из сборки LB:

**Удаленные каталоги:**

|Путь|Причина|
| --- | --- |
| `bin/install/` |Установочные скрипты (в LB они не нужны)|
| `bin/redis/` |Redis двоичный файл (LB не запускает свой собственный Redis)|
| `bin/nginx/conf/codes/` |Страницы с кодами ошибок (пользовательский интерфейс администратора)|
| `Public/Controllers/Admin/` |Контроллеры панели администратора|
| `Public/Controllers/Player/` |Контроллеры панели проигрывателя|
| `Public/Controllers/Reseller/` |Контроллеры панели реселлера|
| `Public/Views/` |Шаблоны панелей|
| `Public/assets/` |Панель статических активов|
| `Public/routes/` |Карты маршрутов на панели|
| `Domain/User/` |Управление пользователями|
| `Domain/Device/` |Регистрация устройства|
| `Domain/Auth/` |Управление авторизацией (panel auth)|
| `resources/langs/` |Файлы языковых ресурсов|
| `resources/libs/` |Администрирование библиотечных ресурсов|

**Удаленные файлы:**

|Файл|Причина|
| --- | --- |
| `Public/Controllers/Api/AdminApiController.php` |Полный admin API удален из LB|
| `Public/Controllers/Api/ResellerRestApiController.php` |API реселлера удален из LB|
| `Infrastructure/legacy/reseller_api.php` |Устаревший bootstrap API реселлера не нужен в LB|
|`www/xplugin.php`, `www/probe.php`, `www/playlist.php`|Конечные точки администрирования|
|`www/player_api.php`, `www/epg.php`, `www/enigma2.php`|Конечные точки клиентского API (обслуживаемые MAIN)|
| `www/stream/auth.php` |Конечная точка аутентификации удалена из пакета LB|
|`www/admin/api.php`, `www/admin/proxy_api.php`|API администратора|
| `bin/maxmind/GeoLite2-City.mmdb` |GeoIP DB поставляется отдельно|
| `config/rclone.conf` |Конфигурация резервного копирования|
| `Domain/Epg/EPG.php` |EPG класс обработки|
| `bin/nginx/conf/gzip.conf` |Конфигурация Gzip (LB использует собственную)|

**Команды CLI удалены:**

|Файл|Причина|
| --- | --- |
| `Cli/Commands/MigrateCommand.php` |Миграция является ОСНОВНОЙ|
| `Cli/Commands/CacheHandlerCommand.php` |Обработчик кэша доступен только для MAIN|
| `Cli/Commands/ServerInstallCommand.php` |Установщик сервера (не требуется для самой LB)|
| `Cli/Commands/LbInstallFlow.php` |Помощник по установке LB (не требуется для самого LB)|
| `Cli/Commands/ProxyInstallFlow.php` |Помощник по установке прокси-сервера (не требуется для самой LB)|

**Удалены задания Cron:**

|Файл|Причина|
| --- | --- |
| `Cli/CronJobs/RootMysqlCronJob.php` |Обслуживание базы данных (только для ОСНОВНОЙ системы)|
| `Cli/CronJobs/BackupsCronJob.php` |Резервные копии (только для ОСНОВНОЙ системы)|
| `Cli/CronJobs/CacheEngineCronJob.php` |Полная перестройка кэша (только для основного)|
| `Cli/CronJobs/EpgCronJob.php` |EPG обработка (только для ОСНОВНОЙ системы)|
| `Cli/CronJobs/UpdateCronJob.php` |Проверка обновлений (только для основной системы)|
| `Cli/CronJobs/ProvidersCronJob.php` |Синхронизация с поставщиком (только для ОСНОВНОГО)|
| `Cli/CronJobs/SeriesCronJob.php` |Метаданные серии (только для основной версии)|

> ** Примечание:** Связанные с модулем crons (TMDB, Plex, Watch) теперь находятся внутри `modules/<name>/` и автоматически исключаются из сборок LB, поскольку `modules/` отсутствует в `LB_DIRS`.

### Конфигурации, замененные при сборке LB

Эти файлы из `lb_configs/` ** заменяют** основные версии:

|Источник|Цель|Цель|
| --- | --- | --- |
| `lb_configs/nginx.conf` | `bin/nginx/conf/nginx.conf` |Настроенная производительность nginx для потоковой передачи|
| `lb_configs/live.conf` | `bin/nginx_rtmp/conf/live.conf` |RTMP перехваты обратного вызова|

---

## ОСНОВНЫЕ отличия от LB — Key Differences

|Аспект|главный|фунт|
| --- | --- | --- |
|Панель администратора|✅ Полный пользовательский интерфейс|❌ Не входит в комплект поставки|
|Роль базы данных|Чтение + запись|Пользователь, доступный только для чтения|
|Управление пользователями/устройствами|✅|❌|
|EPG обработка|✅|❌|
|Резервные копии|✅|❌|
|Инструмент для миграции|✅|❌|
|Потоковая доставка|✅|✅|
|RTMP прием внутрь|✅|✅|
|Транскодирование (FFmpeg)|✅|✅|
|Команды CLI|26|~15 (удалено только для администратора)|
|Задания Cron|25|~16 (удалено только для администратора)|
|Модульная система|✅|❌|

---

## Конфигурация LB Nginx

В сборке LB используется специализированная конфигурация nginx, оптимизированная для потоковой передачи с высокой пропускной способностью:

|Установка|Ценность|Цель|
| --- | --- | --- |
|Рабочие процессы| `auto` |Масштабирование до ядер центрального процессора|
|Рабочие связи|16,000|Высокая параллельность на одного работника|
|Максимальное количество файловых дескрипторов|300,000|Ограничение системных ресурсов|
|Пул потоков|`pool_xc_vm` (32 потока)|Асинхронный ввод-вывод для потоковой передачи|
|Gzip-файл|прочь|Потоковые данные уже сжаты|
|Журналы доступа|прочь|Сократите накладные расходы на ввод-вывод|
|Ограничение скорости|20 запросов в секунду на IP-адрес|Смягчение последствий DDoS-атак|
|Тайм-аут отправки|20 мин|Поддержка длительных потоков|

RTMP перехватывает (`lb_configs/live.conf`) маршрутизацию аутентификации через локальные HTTP-обратные вызовы вместо панели администратора:

```nginx
on_play http://127.0.0.1:8080/stream/rtmp;
on_publish http://127.0.0.1:8080/stream/rtmp;
on_play_done http://127.0.0.1:8080/stream/rtmp;
```

---

## Поведение во время выполнения на LB

### Загрузка условной команды

`console.php` использует `file_exists()` guards для команд, которые могут отсутствовать на серверах LB:

```php
if (file_exists(__DIR__ . '/Cli/Commands/CacheHandlerCommand.php')) {
    $rRegistry->register(new CacheHandlerCommand());
}
```

Это предотвращает сбои при попытке LB зарегистрировать команду, файл которой был удален во время сборки.

### Цепочка потоковых зависимостей

Серверы LB сохраняют полный конвейер потоковой передачи:

```text
www/stream/*.php
  ├── www/stream/init.php
  ├── vendor/autoload.php (Composer PSR-4 autoloader)
  ├── bootstrap.php (lightweight stream/bootstrap path)
  ├── Core/* (Config, Database, Cache, Auth, Http, Logging, Util)
  ├── Domain/Stream, Domain/Server, Domain/Vod, Domain/Bouquet
  ├── Streaming/* (Auth, Delivery, Codec, Protection)
  ├── Infrastructure/Redis, Infrastructure/Database
  └── resources/data
```

---

## Добавление нового кода в сборки

### Новый каталог, относящийся к потоковой передаче, в разделе `src/`

Добавьте его в `LB_DIRS` в Makefile:

```makefile
LB_DIRS = bin cli config content core domain ... your_dir
```

### Новый каталог, доступный только для администратора

Добавьте его в `LB_DIRS_TO_REMOVE`:

```makefile
LB_DIRS_TO_REMOVE = ... your_dir/admin_stuff
```

### Новый файл, доступный только для администратора

Добавьте его в `LB_FILES_TO_REMOVE`:

```makefile
LB_FILES_TO_REMOVE = ... your_dir/admin_file.php
```

### Новая команда CLI (только для администратора)

1. Добавить `file_exists()` guard в `console.php`
2. Добавьте файл в `LB_FILES_TO_REMOVE`

---

## Проверка сборки

После изменения сборки проверьте оба варианта:

```bash
# Build both
make new

# Check LB contains streaming code
tar -tzf dist/loadbalancer.tar.gz | grep -cE "Core/|Domain/Stream|Streaming/"
# Expected: > 0

# Check LB does NOT contain admin code
tar -tzf dist/loadbalancer.tar.gz | grep -cE "admin/|player/|ministra|reseller"
# Expected: 0

# Compare sizes (LB should be significantly smaller)
ls -lh dist/xc_vm.tar.gz dist/loadbalancer.tar.gz
```
