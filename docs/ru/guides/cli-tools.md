# CLI-инструменты и обновление БД

Справочник по командной строке XC_VM, системным утилитам и процессу обновления базы данных после обновления версии. Покрывает повседневные операции, экстренный доступ и создание новых шагов обновления БД.

---

## Точка входа консоли

Все CLI-команды выполняются через `console.php`:

```bash
/home/xc_vm/console.php <command> [args...]
```

Консоль поддерживает три типа команд:

| Тип | Кол-во | Описание |
| --- | --- | --- |
| **Commands** | 28 | Разовые операции (update, status, tools и др.) |
| **CronJobs** | 25 | Запланированные задачи (автозапуск через crontab) |
| **Daemons** | 8 | Фоновые процессы-демоны (Commands, использующие `DaemonTrait`) |

> **Примечание:** Демоны — это обычные Commands с трейтом `DaemonTrait`. Отдельной директории `Daemons/` нет.

Для просмотра всех доступных команд:

```bash
/home/xc_vm/console.php list
```

---

## Полный реестр команд

### Утилитарные команды

| Команда | Класс | Описание | Пользователь |
| --- | --- | --- | --- |
| `status` | `StatusCommand` | Статус системы, обновление БД, проверка конфигурации | root |
| `update` | `UpdateCommand` | Обновление системы (update / post-update) | xc_vm |
| `service` | `ServiceCommand` | Управление сервисом XC_VM: start, stop, restart, reload | root |
| `tools` | `ToolsCommand` | Утилиты обслуживания (см. раздел "Команда tools") | root/xc_vm |
| `certbot` | `CertbotCommand` | Генерация SSL-сертификата через certbot | root |
| `binaries` | `BinariesCommand` | Обновление бинарников и GeoLite из GitHub | xc_vm |
| `startup` | `StartupCommand` | Инициализация системы: daemons.sh, crontab, кеш | root |
| `monitor` | `MonitorCommand` | Мониторинг стрима по ID (запуск/перезапуск/отслеживание) | xc_vm |
| `thumbnail` | `ThumbnailCommand` | Генерация превью-кадров для стрима | xc_vm |
| `plex_item` | `PlexItemCommand` | Обработка элемента Plex (фильм/сериал) | xc_vm |
| `watch_item` | `WatchItemCommand` | Обработка элемента Watch (поиск/обновление TMDB) | xc_vm |
| `migrate` | `MigrateCommand` | Перенос данных из БД `xc_vm_migrate` | xc_vm |
| `db:migrate` | `DbMigrateCommand` | Применить ожидающие миграции БД из каталога `migrations/` | xc_vm |
| `server:install` | `ServerInstallCommand` | Установка/настройка сервера (Proxy/LB) по SSH | root |
| `server:diagnose` | `ServerDiagnoseCommand` | Диагностика причины «молчания» proxy/LB-ноды (heartbeat, доступность, iptables, сервис) | root |

> Команды, отмеченные как **опциональные**, условно регистрируются через `file_exists()` guard: `cache_handler`, `server:install`, `migrate`.

### Команды-демоны (фоновые процессы)

Эти команды используют `DaemonTrait` и работают непрерывно через `while(true)`:

| Команда | Класс | Описание |
| --- | --- | --- |
| `signals` | `SignalsCommand` | Обработка kill/cache сигналов из БД и Redis |
| `watchdog` | `WatchdogCommand` | Мониторинг системы: CPU, соединения, обновление серверов |
| `queue` | `QueueCommand` | Обработка фоновых задач из очереди |
| `scanner` | `ScannerCommand` | Сканирование новых стримов/устройств |
| `cache_handler` | `CacheHandlerCommand` | Операции с кешем (опционально) |

### Команды обработки стримов

| Команда | Класс | Описание |
| --- | --- | --- |
| `proxy` | `ProxyCommand` | Проксирование MPEG-TS потоков через сокеты |
| `archive` | `ArchiveCommand` | TV-архив — запись стрима в сегменты |
| `created` | `CreatedCommand` | Created Channel — компоновка канала из источников |
| `delay` | `DelayCommand` | Задержка воспроизведения HLS-стрима |
| `loopback` | `LoopbackCommand` | Приём MPEG-TS с другого сервера |
| `llod` | `LlodCommand` | Low-Latency On-Demand обработчик стримов |
| `record` | `RecordCommand` | Запись стрима в MP4 |
| `ondemand` | `OndemandCommand` | Завершение стримов без активных зрителей |

### Крон-задачи (всего 26: 22 core + 4 модульных)

Имена всех крон-задач имеют префикс `cron:`. Они используют `CronTrait` и вызываются системным crontab.

**Core-крон-задачи** (в `src/Cli/CronJobs/`):

| Команда | Класс | Описание |
| --- | --- | --- |
| `cron:activity` | `ActivityCronJob` | Импорт логов активности пользователей в БД |
| `cron:backups` | `BackupsCronJob` | Управление бэкапами (опционально) |
| `cron:cache` | `CacheCronJob` | Управление кешем |
| `cron:cache_engine` | `CacheEngineCronJob` | Генерация кеша для линий, стримов, сериалов, групп (опционально) |
| `cron:certbot` | `CertbotCronJob` | Продление SSL-сертификата |
| `cron:cleanup` | `CleanupCronJob` | Очистка временных файлов и логов |
| `cron:epg` | `EpgCronJob` | Загрузка и обработка EPG (опционально) |
| `cron:errors` | `ErrorsCronJob` | Обработка логов ошибок |
| `cron:lines_logs` | `LinesLogsCronJob` | Импорт логов клиентских запросов в БД |
| `cron:maxmind` | `MaxMindCronJob` | Обновление баз MaxMind GeoIP (только по вторникам; `--force` для ручного запуска) |
| `cron:providers` | `ProvidersCronJob` | Обновление провайдеров (опционально) |
| `cron:root_mysql` | `RootMysqlCronJob` | Обслуживание БД (root, опционально) |
| `cron:root_signals` | `RootSignalsCronJob` | Обработка сигналов, iptables, nginx, управление сервисом (root) |
| `cron:series` | `SeriesCronJob` | Обновление данных сериалов (опционально) |
| `cron:servers` | `ServersCronJob` | Мониторинг сервера, запуск демонов, обновление статистики |
| `cron:stats` | `StatsCronJob` | Подсчёт и сохранение статистики |
| `cron:streams` | `StreamsCronJob` | Проверка и обновление статуса стримов |
| `cron:streams_logs` | `StreamsLogsCronJob` | Импорт логов стримов |
| `cron:tmp` | `TmpCronJob` | Очистка временных файлов |
| `cron:update` | `UpdateCronJob` | Проверка и применение обновлений (опционально) |
| `cron:users` | `UsersCronJob` | Управление пользовательскими соединениями, синхронизация Redis |
| `cron:vod` | `VodCronJob` | Обработка VOD-контента |

**Модульные крон-задачи** (регистрируются через `ModuleInterface::registerCommands()`):

| Команда | Класс | Модуль | Описание |
| --- | --- | --- | --- |
| `cron:plex` | `PlexCronJob` | plex | Обработка обновлений Plex |
| `cron:tmdb` | `TmdbCronJob` | tmdb | Получение метаданных TMDB (опционально) |
| `cron:tmdb_popular` | `TmdbPopularCronJob` | tmdb | Получение популярного контента TMDB (опционально) |
| `cron:watch` | `WatchCronJob` | watch | Обработка обновлений Watch-библиотеки |

> Опциональные крон-задачи (условная регистрация): `cron:backups`, `cron:cache_engine`, `cron:epg`, `cron:providers`, `cron:root_mysql`, `cron:series`, `cron:tmdb`, `cron:tmdb_popular`, `cron:update`.

---

## Регистрация новой команды

Все CLI-команды реализуют `CommandInterface`. Core-команды автоматически обнаруживаются из `src/Cli/` через рефлексию в `console.php`. Команды модулей регистрируются через `ModuleLoader::registerAllCommands()`.

### CommandInterface

```php
interface CommandInterface {
    public function getName(): string;        // Уникальное имя команды (используется в CLI)
    public function getDescription(): string; // Однострочное описание (показывается в `list`)
    public function execute(array $rArgs): int; // Точка входа, возвращает код выхода
}
```

### Шаг 1. Создайте класс

Создайте файл в `src/Cli/Commands/` (или `src/Cli/CronJobs/` для крон-задач):

```php
<?php

class MyNewCommand implements CommandInterface {

    public function getName(): string {
        return 'my_command';
    }

    public function getDescription(): string {
        return 'Краткое описание команды';
    }

    public function execute(array $rArgs): int {
        // Ваша логика
        echo "Done.\n";
        return 0; // 0 = успех, 1 = ошибка
    }
}
```

Для **команд-демонов** используйте также `DaemonTrait`:

```php
class MyDaemonCommand implements CommandInterface {
    use DaemonTrait;
    // ...
}
```

Для **крон-задач** используйте `CronTrait`:

```php
class MyCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string {
        return 'cron:my_job'; // Имена кронов имеют префикс cron:
    }
    // ...
}
```

### Шаг 2. Зарегистрируйте в console.php

Добавьте в `console.php`:

```php
// Всегда загружается
$rRegistry->register(new MyNewCommand());

// Или условно (для опциональных функций)
if (file_exists(CLI_PATH . 'Commands/MyNewCommand.php')) {
    $rRegistry->register(new MyNewCommand());
}
```

### Шаг 3. Добавьте в Makefile (если исключается из LB)

Если команда НЕ должна входить в сборку Load Balancer, добавьте её путь в `LB_FILES_TO_REMOVE` в `Makefile`.

### Шаг 4. Тестирование

```bash
# Проверьте, что команда отображается в списке
/home/xc_vm/console.php list

# Запустите
/home/xc_vm/console.php my_command
```

---

## Команда tools

Команда `tools` предоставляет утилиты для обслуживания системы.

```bash
console.php tools <subcommand>
```

### Подкоманды (запуск от `root`)

| Подкоманда | Описание |
| --- | --- |
| `rescue` | Создать временный код доступа для экстренного входа в панель. Выводит URL. **Удалите код после использования!** |
| `recaptcha` | Отключить reCAPTCHA (`recaptcha_enable = 0`), чтобы восстановить вход в админ-панель, когда проверка капчи не проходит. |
| `access` | Перегенерировать все конфигурации кодов доступа nginx и перезагрузить nginx. Выводит URL всех кодов админ-панели. |
| `ports` | Перегенерировать конфигурации портов nginx (HTTP, HTTPS, RTMP) из базы данных и перезагрузить nginx. |
| `migration` | Очистить промежуточную БД (`xc_vm_migrate`) и опционально восстановить `.sql`-бэкап. |
| `user` | Создать rescue-администратора со случайными учётными данными. Выводит логин и пароль. **Удалите пользователя после использования!** |
| `mysql` | Переавторизовать привилегии MySQL для всех серверов балансировки нагрузки. |
| `database` | Восстановить чистую БД XC_VM из `database.sql`. **Удаляет ВСЕ данные!** Требует флаг `--confirm`. |
| `flush` | Сбросить все заблокированные IP — очищает правила iptables, удаляет файлы блокировок и очищает таблицу `blocked_ips`. |

### Подкоманды (запуск от `xc_vm`)

| Подкоманда | Описание |
| --- | --- |
| `images` | Скачать недостающие изображения стримов/фильмов/сериалов с TMDB. Сканирует БД и загружает отсутствующие файлы. |
| `duplicates` | Найти и удалить дубликаты VOD-стримов. Группирует по одинаковому источнику, оставляет первый, удаляет остальные. **Деструктивная операция!** |
| `bouquets` | Очистить устаревшие ссылки в букетах. Удаляет ID, которых больше нет в базе данных. |

### Примеры

```bash
# Экстренный доступ к панели (root)
sudo /home/xc_vm/console.php tools rescue

# Отключить reCAPTCHA для восстановления входа в админку (root)
sudo /home/xc_vm/console.php tools recaptcha

# Перегенерация кодов доступа (root) — требуется после изменения шаблона nginx
sudo /home/xc_vm/console.php tools access

# Перегенерация конфигурации портов (root)
sudo /home/xc_vm/console.php tools ports

# Очистить промежуточную БД (root)
sudo /home/xc_vm/console.php tools migration

# Очистить промежуточную БД и восстановить бэкап (root)
sudo /home/xc_vm/console.php tools migration /path/to/backup.sql

# Создать rescue-администратора (root)
sudo /home/xc_vm/console.php tools user

# Переавторизовать MySQL на всех серверах (root)
sudo /home/xc_vm/console.php tools mysql

# Восстановить чистую базу данных (root) — ДЕСТРУКТИВНАЯ ОПЕРАЦИЯ!
sudo /home/xc_vm/console.php tools database --confirm

# Сбросить все заблокированные IP (root)
sudo /home/xc_vm/console.php tools flush

# Скачать недостающие изображения (xc_vm)
su - xc_vm -c '/home/xc_vm/console.php tools images'

# Удалить дубликаты VOD (xc_vm)
su - xc_vm -c '/home/xc_vm/console.php tools duplicates'

# Очистить ссылки в букетах (xc_vm)
su - xc_vm -c '/home/xc_vm/console.php tools bouquets'
```

- ⚠️ **Внимание:** `duplicates` безвозвратно удаляет стримы и все связанные данные (логи, статистика, эпизоды, записи). Всегда делайте бэкап перед запуском.
- ⚠️ **Внимание:** `database --confirm` стирает всю базу данных и заменяет её чистой схемой. Это необратимая операция.
- 💡 **Совет:** После использования `rescue` всегда удаляйте код через админ-панель или запустив `tools access` после восстановления доступа.
- 💡 **Совет:** После использования `user` немедленно смените пароль и удалите rescue-пользователя по завершении работы.

---

## Обновление БД после обновления версии

XC_VM использует файловую систему обновления БД для управления изменениями схемы между версиями. Обновление БД выполняется автоматически при обновлениях и проверке статуса системы.

### Как это работает

- SQL-файлы обновления БД хранятся в `/home/xc_vm/migrations/`.

- Каждый файл именуется с числовым префиксом, например:

```text
001_drop_watch_folders_plex_token.sql
002_panel_logs_add_file_env.sql
003_drop_settings_segment_type.sql
```

- Применённые шаги обновления БД отслеживаются в таблице `migrations`. Каждый шаг выполняется **ровно один раз** - если шаг уже применён, он пропускается.

- Обновление БД выполняется автоматически:
  - `console.php update post-update` - после обновления панели
  - `console.php status` - при проверке статуса системы (только на MAIN-сервере)

### Порядок выполнения обновления БД

```text
[ MigrationRunner::run() — запуск обновления БД ]
        │
        ▼
[ CREATE TABLE IF NOT EXISTS `migrations` ]
        │
        ▼
[ Чтение всех *.sql файлов из migrations/ ]
        │
        ▼
[ Для каждого файла, отсутствующего в таблице `migrations`: ]
    ├── Выполнить SQL-запросы
    ├── Записать в таблицу `migrations`
    └── Вывести [OK] или [WARN]
```

---

## Создание нового шага обновления БД

Когда нужно изменить схему БД (добавить колонки, создать таблицы, вставить данные и т.д.), создайте новый SQL-файл шага обновления.

### Шаг 1. Выберите имя файла

Используйте следующий порядковый номер и описательное имя:

```text
NNN_short_description.sql
```

**Правила формата:**

- Числовой префикс: 3 цифры с ведущими нулями (например, `006`, `007`)
- Разделитель: нижнее подчёркивание `_`
- Имя: строчные буквы, подчёркивания, описание действия
- Расширение: `.sql`

**Примеры:**

```text
006_add_user_timezone.sql
007_create_audit_log_table.sql
008_insert_default_codec_settings.sql
```

### Шаг 2. Напишите SQL

Поместите SQL-запросы в файл. Несколько запросов разделяются `;`.

**Правила для SQL-шагов обновления БД:**

- **Используйте `IF EXISTS` / `IF NOT EXISTS`** для идемпотентности:

```sql
-- Добавление колонки (безопасно)
ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `timezone` VARCHAR(64) DEFAULT 'UTC';

-- Удаление колонки (безопасно)
ALTER TABLE `settings` DROP COLUMN IF EXISTS `old_column`;

-- Создание таблицы (безопасно)
CREATE TABLE IF NOT EXISTS `audit_log` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `action` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
```

- **Используйте условный `INSERT`** для избежания дубликатов:

```sql
INSERT INTO `streams_arguments` (argument_key, argument_name, argument_cmd)
SELECT 'my_key', 'My Argument', '-my_flag %s'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `streams_arguments` WHERE argument_key = 'my_key');
```

- **Не смешивайте DDL и DML**, зависящие друг от друга, в одном файле. Если нужно добавить колонку и затем заполнить её, используйте два SQL-файла обновления БД.

- **Комментарии** поддерживаются с префиксом `--` (они пропускаются при выполнении).

### Шаг 3. Разместите файл

Скопируйте SQL-файл шага обновления БД в:

```text
/home/xc_vm/migrations/
```

> 💡 В репозитории исходного кода это `src/migrations/`.

### Шаг 4. Проверка обновления БД

Запустите `db:migrate` для применения ожидающих шагов обновления БД:

```bash
su - xc_vm -c '/home/xc_vm/console.php db:migrate'
```

Или через `status first-run` (также запускает миграции):

```bash
sudo /home/xc_vm/console.php status first-run
```

Ожидаемый вывод:

```text
Migrations
------------------------------
  [OK]   006_add_user_timezone.sql

```

Если запрос завершился с ошибкой, шаг всё равно будет записан, но покажет `[WARN]` — проверьте SQL и исправьте проблемы.

---

## Типичные CLI-операции

### Проверка статуса

```bash
sudo /home/xc_vm/console.php status
```

Проверяет, запущен ли XC_VM, подключается к БД, выполняет ожидающие шаги обновления БД, исправляет права доступа и валидирует конфигурацию nginx. Требуется после установки или восстановления.

С аргументом `first-run` пропускает проверку статуса — используется при первоначальной настройке:

```bash
sudo /home/xc_vm/console.php status first-run
```

### Управление сервисом

```bash
sudo /home/xc_vm/console.php service start|stop|restart|reload
```

### Ручное обновление

```bash
sudo -u xc_vm /home/xc_vm/console.php update update
```

Скачивает и применяет последнее обновление с GitHub. Обычно запускается автоматически через веб-панель.

### Диагностика стримов

```bash
sudo -u xc_vm /home/xc_vm/console.php monitor <stream_id>
```

Запускает стрим вручную и отображает ошибки. Полезно для диагностики проблем запуска стримов.

### Диагностика сервера (ноды)

```bash
# На MAIN — удалённая проверка ноды по её server id
sudo /home/xc_vm/console.php server:diagnose <server_id>

# На самой LB/proxy-ноде — локальная самодиагностика (без аргументов)
sudo /home/xc_vm/console.php server:diagnose
```

Выясняет, **почему** proxy/LB-нода отображается офлайн в панели: проверяет heartbeat, доступность (ICMP/TCP/HTTP `/api`), расхождение часов, очередь сигналов, а локально на ноде — не заблокировала ли нода IP главного сервера в собственном iptables, запущены ли сервис `xc_vm`/nginx и heartbeat-демон `watchdog`, и есть ли `cron:servers` в crontab пользователя `xc_vm`. Только чтение; код выхода `0` — проблем не найдено, `2` — выведены вероятные причины. Подробности — в [руководстве по диагностике серверов](ru-ru/administration/server-diagnostics.md).

### SSL-сертификат

```bash
sudo /home/xc_vm/console.php certbot
```

### Применить миграции БД вручную

```bash
su - xc_vm -c '/home/xc_vm/console.php db:migrate'
```

Применяет все ожидающие `.sql`-файлы из `/home/xc_vm/migrations/`. Используется когда нужно накатить миграции без полного обновления системы.

### Обновление БД данными из других систем

```bash
/home/xc_vm/console.php migrate
```

Переносит данные из промежуточной БД `xc_vm_migrate`. Подробности в [Руководстве по обновлению БД](ru-ru/info/migration_guide.md).

---

## Связанные файлы

| Файл | Роль |
| --- | --- |
| `src/console.php` | Точка входа CLI + FQCN-discovery команд |
| `src/Cli/Commands/` | Консольные команды |
| `src/Cli/CronJobs/` | Классы cron-задач |
| `src/migrations/` | Миграции БД |
