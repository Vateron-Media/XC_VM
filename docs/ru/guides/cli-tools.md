# Инструменты CLI и обновления баз данных

Справочник по интерфейсу командной строки XC_VM, системным инструментам и процессу обновления базы данных после обновления версии. Содержит описание ежедневных операций, экстренного доступа и создания новых этапов обновления базы данных.

---

## Точка входа в консоль

Все команды CLI выполняются через `console.php`:

```bash
/home/xc_vm/console.php <command> [args...]
```

Консоль поддерживает три типа команд:

|Тип|Рассчитывать|Описание|
| --- | --- | --- |
|**Команды**|28|Одноразовые операции (обновление, статус, инструменты и т.д.)|
|**Закадычные друзья**|25|Запланированные задачи (автоматически вызываемые crontab)|
|**Демоны**|8|Длительно выполняющиеся фоновые процессы (команды, использующие `DaemonTrait`)|

> **Примечание:** Демоны - это обычные команды, которые используют `DaemonTrait`. Отдельного каталога `Daemons/` не существует.

Чтобы просмотреть все доступные команды:

```bash
/home/xc_vm/console.php list
```

---

## Полный реестр команд

### Служебные команды

|Команда|Класс|Описание|Пользователь|
| --- | --- | --- | --- |
| `status` | `StatusCommand` |Состояние системы, обновления базы данных, проверка конфигурации|корень|
| `update` | `UpdateCommand` |Обновление системы (update / после обновления)|xc_vm|
| `service` | `ServiceCommand` |Управление службой XC_VM: запуск, остановка, перезапуск, перезагрузка|корень|
| `tools` | `ToolsCommand` |Утилиты технического обслуживания (см. раздел команды "Инструменты")|корень/xc_vm|
| `certbot` | `CertbotCommand` |Сгенерируйте SSL-сертификат с помощью certbot|корень|
| `binaries` | `BinariesCommand` |Обновите двоичные файлы и базу данных GeoLite с GitHub|xc_vm|
| `startup` | `StartupCommand` |Инициализация системы: daemons.sh, crontab, кэш|корень|
| `monitor` | `MonitorCommand` |Отслеживайте поток по идентификатору (запуск/перезапуск/отслеживание)|xc_vm|
| `thumbnail` | `ThumbnailCommand` |Создание рамок миниатюр для потока|xc_vm|
| `plex_item` | `PlexItemCommand` |Обработать один элемент Plex (фильм/сериал)|xc_vm|
| `watch_item` | `WatchItemCommand` |Обработать один элемент наблюдения (поиск/обновление в базе данных TMDB)|xc_vm|
| `migrate` | `MigrateCommand` |Перенос данных из базы данных `xc_vm_migrate`|xc_vm|
| `db:migrate` | `DbMigrateCommand` |Применить ожидающие переноса базы данных из каталога `migrations/`|xc_vm|
| `server:install` | `ServerInstallCommand` |Установка/настройка сервера (Proxy/LB) через SSH|корень|
| `server:diagnose` | `ServerDiagnoseCommand` |Диагностировать, почему прокси-узел/LB-узел не подключен к главному (частота сердечных сокращений, доступность, iptables, сервис)|корень|

> Команды, помеченные **необязательно**, условно регистрируются с помощью `file_exists()` guard: `cache_handler`, `server:install`, `migrate`.

### Команды демона (постоянные процессы)

Эти команды используют `DaemonTrait` и выполняются непрерывно через циклы `while(true)`:

|Команда|Класс|Описание|
| --- | --- | --- |
| `signals` | `SignalsCommand` |Обрабатывать сигналы уничтожения/кэширования из базы данных и Redis|
| `watchdog` | `WatchdogCommand` |Мониторинг системы: процессор, подключения, обновления сервера|
| `queue` | `QueueCommand` |Обрабатывать фоновые задачи в очереди|
| `scanner` | `ScannerCommand` |Поиск новых потоков/устройств|
| `cache_handler` | `CacheHandlerCommand` |Обрабатывать операции с кэшем (необязательно)|

### Команды потоковой обработки

|Команда|Класс|Описание|
| --- | --- | --- |
| `proxy` | `ProxyCommand` |MPEG-TS потоковое проксирование через сокеты|
| `archive` | `ArchiveCommand` |Телевизионный архив — запись потока на сегменты|
| `created` | `CreatedCommand` |Созданный канал — создание канала из исходных текстов|
| `delay` | `DelayCommand` |Задержка HLS воспроизведения потока|
| `loopback` | `LoopbackCommand` |Получить MPEG-TS с другого сервера|
| `llod` | `LlodCommand` |Потоковый процессор с низкой задержкой по требованию|
| `record` | `RecordCommand` |Запись потока в формате MP4|
| `ondemand` | `OndemandCommand` |Прерывать трансляции без активных зрителей|

### Задания Cron (всего 26: 22 ядра + 4 модуля)

Все имена заданий cron имеют префикс `cron:`. Для них используется `CronTrait`, и они вызываются системой crontab.

**Основные задания cron** (в `src/Cli/CronJobs/`):

|Команда|Класс|Описание|
| --- | --- | --- |
| `cron:activity` | `ActivityCronJob` |Импорт журналов действий пользователей в базу данных|
| `cron:backups` | `BackupsCronJob` |Управление резервными копиями (необязательно)|
| `cron:cache` | `CacheCronJob` |Управление кэшем|
| `cron:cache_engine` | `CacheEngineCronJob` |Генерировать кэш для строк, потоков, серий, групп (необязательно)|
| `cron:certbot` | `CertbotCronJob` |Обновление SSL-сертификата|
| `cron:cleanup` | `CleanupCronJob` |Очистка временных файлов и журналов|
| `cron:epg` | `EpgCronJob` |EPG загрузка и обработка (необязательно)|
| `cron:errors` | `ErrorsCronJob` |Журналы ошибок процесса|
| `cron:lines_logs` | `LinesLogsCronJob` |Импорт журналов клиентских запросов в базу данных|
| `cron:maxmind` | `MaxMindCronJob` |Обновление баз данных MaxMind GeoIP (только по вторникам; `--force` для запуска вручную)|
| `cron:providers` | `ProvidersCronJob` |Поставщики обновлений (необязательно)|
| `cron:root_mysql` | `RootMysqlCronJob` |Обслуживание базы данных (root, необязательно)|
| `cron:root_signals` | `RootSignalsCronJob` |Сигналы обработки, iptables, nginx, управление службами (root)|
| `cron:series` | `SeriesCronJob` |Обновление данных серии (необязательно)|
| `cron:servers` | `ServersCronJob` |Контролируйте сервер, запускайте демонов, обновляйте статистику|
| `cron:stats` | `StatsCronJob` |Вычислять и хранить статистику|
| `cron:streams` | `StreamsCronJob` |Проверка и обновление статуса потока|
| `cron:streams_logs` | `StreamsLogsCronJob` |Импорт журналов потоков|
| `cron:tmp` | `TmpCronJob` |Очистка временных файлов|
| `cron:update` | `UpdateCronJob` |Проверять и применять обновления (необязательно)|
| `cron:users` | `UsersCronJob` |Управление подключениями пользователей, синхронизацией Redis, расхождением|
| `cron:vod` | `VodCronJob` |Содержание процесса VOD|

**Модуль cron заданий** (зарегистрирован через `ModuleInterface::registerCommands()`):

|Команда|Класс|Модуль|Описание|
| --- | --- | --- | --- |
| `cron:plex` | `PlexCronJob` |сплетение|Обрабатывать обновления Plex|
| `cron:tmdb` | `TmdbCronJob` |тмдб|Получение метаданных TMDB (необязательно)|
| `cron:tmdb_popular` | `TmdbPopularCronJob` |тмдб|Выборка популярного содержимого TMDB (необязательно)|
| `cron:watch` | `WatchCronJob` |часы|Обрабатывать обновления библиотеки отслеживания|

> Дополнительные задания cron (условно зарегистрированные): `cron:backups`, `cron:cache_engine`, `cron:epg`, `cron:providers`, `cron:root_mysql`, `cron:series`, `cron:tmdb`, `cron:tmdb_popular`, `cron:update`.

---

## Регистрация новой команды

Все команды CLI реализуют `CommandInterface`. Основные команды автоматически обнаруживаются из `src/Cli/` с помощью отражения в `console.php`. Команды модуля регистрируются с помощью `ModuleLoader::registerAllCommands()`.

### Командный интерфейс

```php
interface CommandInterface {
    public function getName(): string;        // Unique command name (used in CLI)
    public function getDescription(): string; // One-line help text (shown in `list`)
    public function execute(array $rArgs): int; // Entry point, returns exit code
}
```

### Шаг 1. Создайте класс

Создайте новый файл в `src/Cli/Commands/` (или `src/Cli/CronJobs/` для заданий cron):

```php
<?php

class MyNewCommand implements CommandInterface {

    public function getName(): string {
        return 'my_command';
    }

    public function getDescription(): string {
        return 'Short description of what it does';
    }

    public function execute(array $rArgs): int {
        // Your logic here
        echo "Done.\n";
        return 0; // 0 = success, 1 = error
    }
}
```

Для команд **daemon** также используйте `DaemonTrait`:

```php
class MyDaemonCommand implements CommandInterface {
    use DaemonTrait;
    // ...
}
```

Для **заданий cron** используйте `CronTrait`:

```php
class MyCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string {
        return 'cron:my_job'; // Cron names are prefixed with cron:
    }
    // ...
}
```

### Шаг 2. Зарегистрируйтесь в console.php

Добавить к `console.php`:

```php
// Always loaded
$rRegistry->register(new MyNewCommand());

// Or conditionally (for optional features)
if (file_exists(CLI_PATH . 'Commands/MyNewCommand.php')) {
    $rRegistry->register(new MyNewCommand());
}
```

### Шаг 3. Добавить в Makefile (если LB-исключен)

Если команда не должна включаться в сборки подсистемы балансировки нагрузки, добавьте ее путь к `LB_FILES_TO_REMOVE` в поле `Makefile`.

### Шаг 4. Тестирование

```bash
# Verify it appears in the list
/home/xc_vm/console.php list

# Run it
/home/xc_vm/console.php my_command
```

---

## Команда инструментов

Команда `tools` предоставляет утилиты для обслуживания системы.

```bash
/home/xc_vm/console.php  tools <subcommand>
```

### Подкоманды (запускаются как `root`)

|Подкомандование|Описание|
| --- | --- |
| `rescue` |Создайте временный код аварийного доступа для доступа к панели экстренной помощи. Введите URL-адрес. **Удалите этот код после использования!**|
| `recaptcha` |Отключите reCAPTCHA (`recaptcha_enable = 0`), чтобы восстановить вход в панель администратора при сбое проверки captcha.|
| `access` |Восстановите все настройки кода доступа nginx и перезагрузите nginx. Печатает URL-адреса для всех кодов панели администратора.|
| `ports` |Восстановите настройки портов nginx (HTTP, HTTPS, RTMP) из базы данных и перезагрузите nginx.|
| `migration` |Очистите промежуточную базу данных (`xc_vm_migrate`) и при необходимости восстановите в ней резервную копию `.sql`.|
| `user` |Создайте пользователя rescue admin со случайными учетными данными. Введите имя пользователя и пароль. **Удалите этого пользователя после использования!**|
| `mysql` |Повторно авторизуйте привилегии MySQL для всех серверов load balancer.|
| `database` |Восстановите пустую базу данных XC_VM из `database.sql`. **Удаляет ВСЕ данные!** Требуется флажок `--confirm`.|
| `flush` |Очистить все заблокированные IP—адреса - очищает правила iptables, удаляет файлы блокировки и обрезает таблицу `blocked_ips`.|

### Подкоманды (запускаются как `xc_vm`)

|Подкомандование|Описание|
| --- | --- |
| `images` |Загрузите отсутствующие изображения потоковых передач/фильмов/сериалов из базы данных TMDB. Сканирует базу данных в поисках URL-адресов изображений и загружает отсутствующие файлы.|
| `duplicates` |Найдите и удалите повторяющиеся потоки VOD. Группируйте по идентичному источнику, сохраняйте первый, удаляйте остальные. **Деструктивный!**|
| `bouquets` |Удалите устаревшие ссылки из букетов. Удаляет идентификаторы, которые больше не существуют в базе данных.|

### Примеры

```bash
# Emergency panel access (root)
sudo /home/xc_vm/console.php tools rescue

# Disable reCAPTCHA to recover admin login (root)
sudo /home/xc_vm/console.php tools recaptcha

# Regenerate access codes (root) — required after nginx template changes
sudo /home/xc_vm/console.php tools access

# Regenerate port configuration (root)
sudo /home/xc_vm/console.php tools ports

# Clear staging database (root)
sudo /home/xc_vm/console.php tools migration

# Clear staging database and restore a backup (root)
sudo /home/xc_vm/console.php tools migration /path/to/backup.sql

# Create rescue admin user (root)
sudo /home/xc_vm/console.php tools user

# Reauthorise MySQL privileges on all servers (root)
sudo /home/xc_vm/console.php tools mysql

# Restore blank database (root) — DESTRUCTIVE!
sudo /home/xc_vm/console.php tools database --confirm

# Flush all blocked IPs (root)
sudo /home/xc_vm/console.php tools flush

# Download missing images (xc_vm)
su - xc_vm -c '/home/xc_vm/console.php tools images'

# Remove duplicate VOD entries (xc_vm)
su - xc_vm -c '/home/xc_vm/console.php tools duplicates'

# Clean orphaned bouquet references (xc_vm)
su - xc_vm -c '/home/xc_vm/console.php tools bouquets'
```

- ❗️ **Внимание:** `duplicates` стримы и все связанные с ними данные (журналы, статистика, эпизоды, записи) удаляются безвозвратно. Всегда создавайте резервную копию перед запуском.
- ❗️ **Внимание:** `database --confirm` удаляет всю базу данных и заменяет ее пустой схемой. Это необратимо.
- 💡 ** Совет:** После запуска `rescue` всегда удаляйте код через панель администратора или запустив `tools access`, как только вы восстановите доступ.
- 💡 ** Совет:** После запуска `user` немедленно измените пароль и по завершении удалите пользователя для восстановления.

---

## Обновления базы данных после Обновления Версии

XC_VM использует файловую систему обновления базы данных для управления изменениями схемы между версиями. Обновления базы данных выполняются автоматически во время обновлений и проверок состояния системы.

### как это работает

- SQL-файлы для обновлений базы данных хранятся в `/home/xc_vm/migrations/`.

- Каждому файлу присваивается имя с префиксом последовательного номера, например:

```text
001_drop_watch_folders_plex_token.sql
002_panel_logs_add_file_env.sql
003_drop_settings_segment_type.sql
```

- Применяемые шаги обновления базы данных отслеживаются в таблице базы данных `migrations`. Каждый шаг выполняется **ровно один раз** - если шаг уже был применен, он пропускается.

- Обновления базы данных выполняются автоматически:
  - `console.php update post-update` - после обновления панели
  - `console.php status` - во время проверки состояния системы (только для главного сервера)

### Поток выполнения обновления базы данных

```text
[ MigrationRunner::run() — DB update execution ]
        │
        ▼
[ CREATE TABLE IF NOT EXISTS `migrations` ]
        │
        ▼
[ Read all *.sql files from migrations/ ]
        │
        ▼
[ For each file not in `migrations` table: ]
    ├── Execute SQL statements
    ├── Record in `migrations` table
    └── Output [OK] or [WARN]
```

---

## Создание нового шага обновления базы данных

Когда вам нужно изменить схему базы данных (добавить столбцы, создать таблицы, вставить данные и т.д.), создайте новый SQL-файл для этапа обновления базы данных.

### Шаг 1. Выберите имя файла

Используйте следующий порядковый номер и описательное название:

```text
NNN_short_description.sql
```

**Правила форматирования:**

- Числовой префикс: 3 цифры, дополненные нулем (например, `006`, `007`)
- Разделитель: символ подчеркивания `_`
- Название: нижний регистр, подчеркивание, описывающее, что делает шаг обновления
- Добавочный номер: `.sql`

**Примеры:**

```text
006_add_user_timezone.sql
007_create_audit_log_table.sql
008_insert_default_codec_settings.sql
```

### Шаг 2. Напишите SQL-код

Поместите в файл необработанные инструкции SQL. Несколько инструкций разделяются символом `;`.

**Правила для этапов обновления базы данных SQL:**

- **Используйте `IF EXISTS` / `IF NOT EXISTS`**, чтобы сделать шаги обновления базы данных идемпотентными:

```sql
-- Adding a column (safe)
ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `timezone` VARCHAR(64) DEFAULT 'UTC';

-- Dropping a column (safe)
ALTER TABLE `settings` DROP COLUMN IF EXISTS `old_column`;

-- Creating a table (safe)
CREATE TABLE IF NOT EXISTS `audit_log` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `action` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
```

- **Используйте условное обозначение `INSERT`**, чтобы избежать дублирования:

```sql
INSERT INTO `streams_arguments` (argument_key, argument_name, argument_cmd)
SELECT 'my_key', 'My Argument', '-my_flag %s'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `streams_arguments` WHERE argument_key = 'my_key');
```

- **Не смешивайте DDL и DML**, которые зависят друг от друга, в одном файле. Если вам нужно добавить столбец и затем заполнить его, используйте два файла шага обновления базы данных.

- **Комментарии** поддерживаются с префиксом `--` (они пропускаются во время выполнения).

### Шаг 3. Поместите файл

Скопируйте SQL-файл для шага обновления базы данных в:

```text
/home/xc_vm/migrations/
```

> 💡 В хранилище исходных текстов это значение равно `src/migrations/`.

### Шаг 4. Подтвердите обновление базы данных

Запустите `db:migrate`, чтобы применить ожидающие обновления БД шаги:

```bash
su - xc_vm -c '/home/xc_vm/console.php db:migrate'
```

Или через `status first-run` (также запускает миграции):

```bash
sudo /home/xc_vm/console.php status first-run
```

Ожидаемый результат:

```text
Migrations
------------------------------
  [OK]   006_add_user_timezone.sql

```

Если инструкция не выполняется, шаг все равно будет записан, но покажет `[WARN]` — проверьте SQL и устраните любые проблемы.

---

## Общие операции CLI

### Проверка состояния

```bash
sudo /home/xc_vm/console.php status
```

Проверяет, запущен ли параметр XC_VM, подключается к базе данных, выполняет ожидающие обновления шаги, исправляет разрешения и проверяет конфигурацию nginx. Требуется после установки или восстановления.

С аргументом `first-run` пропускает текущую проверку, используемую для начальной настройки:

```bash
sudo /home/xc_vm/console.php status first-run
```

### Управление услугами

```bash
sudo /home/xc_vm/console.php service start|stop|restart|reload
```

### Обновление вручную

```bash
sudo -u xc_vm /home/xc_vm/console.php update update
```

Загружает и применяет последнее обновление с GitHub. Обычно запускается автоматически через веб-панель.

### Диагностика потока

```bash
sudo -u xc_vm /home/xc_vm/console.php monitor <stream_id>
```

Запускает поток вручную и отображает все ошибки. Полезно для диагностики сбоев при запуске потока.

### Диагностика сервера (узла)

```bash
# On the MAIN — remote-probe a node by its server id
sudo /home/xc_vm/console.php server:diagnose <server_id>

# On the LB/proxy node itself — local self-diagnosis (no arguments)
sudo /home/xc_vm/console.php server:diagnose
```

Выясняет, почему прокси—узел/LB—узел отображается в автономном режиме на панели: проверяет частоту сердечных сокращений, доступность (ICMP/TCP/HTTP `/api`), перекос часов, очередь сигналов и - локально на узле - выполняет ли узел брандмауэр на главном IP-адресе в своем собственном iptables, запущена ли служба `xc_vm`/nginx, запущен ли демон heartbeat `watchdog` и находится ли `cron:servers` в crontab `xc_vm`. Доступно только для чтения; код выхода `0` = проблем не обнаружено, `2` = указаны вероятные причины. Более подробную информацию смотрите в [Руководстве по диагностике сервера](../administration/server-diagnostics.md).

### SSL-сертификат

```bash
sudo /home/xc_vm/console.php certbot
```

### Применяйте перенос базы данных вручную

```bash
su - xc_vm -c '/home/xc_vm/console.php db:migrate'
```

Применяет все ожидающие `.sql` файлы из `/home/xc_vm/migrations/`. Используйте это, когда вам нужно выполнить миграцию без полного обновления системы.

### Обновление базы данных данными из других систем

```bash
/home/xc_vm/console.php migrate
```

Передает данные из промежуточной базы данных `xc_vm_migrate`. Подробности см. в [Руководстве по обновлению базы данных](../info/migration_guide.md).

---

## Связанные файлы

|Файл|Роль|
| --- | --- |
| `src/console.php` |Точка входа CLI + обнаружение команды FQCN|
| `src/Cli/Commands/` |Консольные команды|
| `src/Cli/CronJobs/` |Классы заданий Cron|
| `src/migrations/` |Миграция баз данных|
