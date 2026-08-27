# Инструменты CLI и ссылка на консоль

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
| **Commands** |28|Одноразовые операции (обновление, статус, инструменты и т.д.)|
| **CronJobs** |25|Запланированные задачи (автоматически вызываемые crontab)|
| **Daemons** |8|Длительно выполняющиеся фоновые процессы (команды, использующие `DaemonTrait`)|

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
| `binaries` | `BinariesCommand` |Обновите пакет среды выполнения (php/nginx/...) из выпуска `XC_VM_Binaries`|xc_vm|
| `fanout_binary` | `FanoutBinaryCommand` |Установите/обновите двоичный файл демона `xc_fanout` с момента его выпуска|корень|
| `xcvm_core` | `XcvmCoreCommand` |Установите/обновите расширение `xcvm_core` PHP из хранилища двоичных файлов|корень|
| `ytdlp` | `YtDlpCommand` |Установите/обновите `yt-dlp` из своей предыдущей версии на GitHub|корень|
| `startup` | `StartupCommand` |Инициализация системы: daemons.sh, crontab, кэш|корень|
| `monitor` | `MonitorCommand` |Отслеживайте поток по идентификатору (запуск/перезапуск/отслеживание)|xc_vm|
| `thumbnail` | `ThumbnailCommand` |Создание рамок миниатюр для потока|xc_vm|
| `plex_item` | `PlexItemCommand` |Обработать один элемент Plex (фильм/сериал)|xc_vm|
| `watch_item` | `WatchItemCommand` |Обработать один элемент наблюдения (поиск/обновление в базе данных TMDB)|xc_vm|
| `migrate` | `MigrateCommand` |Перенос данных из базы данных `xc_vm_migrate`|xc_vm|
| `db:migrate` | `DbMigrateCommand` |Применить ожидающие переноса базы данных из каталога `migrations/`|xc_vm|
| `server:install` | `ServerInstallCommand` |Установка/настройка сервера (Proxy/LB) через SSH|корень|
| `server:diagnose` | `ServerDiagnoseCommand` |Диагностировать, почему прокси-узел/LB-узел не подключен к главному (частота сердечных сокращений, доступность, iptables, сервис)|корень|

> `console.php` регистрирует **каждый** класс, который он обнаруживает в `Cli/Commands/` и `Cli/CronJobs/` (глобус + отражение) — есть **нет** `file_exists()` защита. Команда является "необязательной" только в том смысле, что она может быть **снято со сборки LB** (`Makefile` `LB_FILES_TO_REMOVE`) или **обеспечивается установленным модулем**. `plex_item` и `watch_item`, приведенные выше, являются **предоставляемый модулем** (Plex/Watch) — их классы команд отсутствуют в дереве committed core и существуют только тогда, когда этот модуль установлен.

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

### Задания Cron

> Таблицы команд/cron/daemon, приведенные ниже, поддерживаются вручную и могут изменяться. Источник truth — `console.php list` - запустите его, чтобы увидеть текущий реестр.

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
| `cron:root_signals` | `RootSignalsCronJob` |Сигналы обработки, iptables, nginx, управление службами и **бинарное самоисцеление** (root)|
| `cron:series` | `SeriesCronJob` |Обновление данных серии (необязательно)|
| `cron:servers` | `ServersCronJob` |Контролируйте сервер, запускайте демонов, обновляйте статистику|
| `cron:stats` | `StatsCronJob` |Вычислять и хранить статистику|
| `cron:streams` | `StreamsCronJob` |Проверка и обновление статуса потока|
| `cron:streams_logs` | `StreamsLogsCronJob` |Импорт журналов потоков|
| `cron:tmp` | `TmpCronJob` |Очистка временных файлов|
| `cron:update` | `UpdateCronJob` |Проверять и применять обновления (необязательно)|
| `cron:users` | `UsersCronJob` |Управление подключениями пользователей, синхронизацией Redis, расхождением|
| `cron:vod` | `VodCronJob` |Содержание процесса VOD|
| `cron:proxy` | `ProxyArchiveCronJob` |Архивирование/ротация потоковых данных прокси-сервера|
| `cron:module_licenses` | `ModuleLicensesCronJob` |Обновить лицензии на установленные модули|
| `cron:module_updates` | `ModuleUpdatesCronJob` |Проверьте наличие обновлений модуля|
| `cron:tmdb` | `TmdbCronJob` |Получение метаданных TMDB (необязательно)|
| `cron:tmdb_popular` | `TmdbPopularCronJob` |Выборка популярного содержимого TMDB (необязательно)|

**Задания cron, предоставляемые модулем.** Регистрируются дополнительными модулями через `CronProviderInterface::getCronEntries()`; они существуют только тогда, когда этот модуль установлен, и находятся **нет** в дереве committed core (`src/Modules/` отправляются пустыми). (`cron:tmdb`/`cron:tmdb_popular` — это **ядро**, перечисленные выше, а не задания cron модуля.)

|Команда|Класс|Модуль|Описание|
| --- | --- | --- | --- |
| `cron:plex` | `PlexCronJob` |сплетение|Обрабатывать обновления Plex|
| `cron:watch` | `WatchCronJob` |часы|Обрабатывать обновления библиотеки отслеживания|

> "Необязательные" задания cron регистрируются **нет** условно — регистрируется каждый обнаруженный класс `CronJob`. "Необязательно" означает, что задание не выполняется, если не включена его функция/настройка (например, `cron:epg`, `cron:series`, `cron:update`), или если задание не удалено из сборки LB.

---

## Двоичное самообновление (самовосстановление)

Некоторые связанные двоичные файлы **нет** поставляются внутри пакета heavy runtime bundle и будут
в противном случае никогда не обновляйтесь между выпусками панели (новый узел LB или узел, оставленный включенным
старая сборка, никогда не сходилась бы). `cron:root_signals` (root, каждую минуту) сохраняет
они становятся текущими путем опроса их идемпотентных команд обновления для каждого двоичного файла на
расписание с ограниченным использованием штампов - каждая загрузка выполняется только при несоответствии версии, проверяется
контрольная сумма, запуск-тестирует новый двоичный файл, затем заменяет его атомарно (неработающая загрузка
никогда не заменяет рабочий). Выполняется на каждом узле (main **и** LB).

|Двоичный|Команда|Источник|Проверить|Опрос|
| --- | --- | --- | --- | --- |
|`xc_fanout` демон| `fanout_binary` |`XC_VM_Fanout` освободить актив| `SHA256SUMS` |~ежечасно|
|`xcvm_core` расширение| `xcvm_core` |`XC_VM_Binaries` дерево репозиториев (`bin/xcvm_core/`)|`SHA256SUMS` + нагрузочный тест|~ежечасно|
| `yt-dlp` | `ytdlp` |вышестоящий `yt-dlp/yt-dlp` релиз|`SHA2-256SUMS` + `--version`|ежедневный|

Марки живут в `CRONS_TMP_PATH` (`fanout_binary_check`, `xcvm_core_check`,
`ytdlp_check`); первый проход (штамп отсутствует) выполняется немедленно, поэтому новый
install/LB получает двоичный файл в течение минуты. Пакет heavy runtime bundle
(php/nginx/ffmpeg) вместо этого обновляется командой `binaries`, запускаемой
`update_binaries` сигнал от ГЛАВНОГО устройства.

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

Для команд **демон** также используйте `DaemonTrait`:

```php
class MyDaemonCommand implements CommandInterface {
    use DaemonTrait;
    // ...
}
```

Для **задания cron** используйте `CronTrait`:

```php
class MyCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string {
        return 'cron:my_job'; // Cron names are prefixed with cron:
    }
    // ...
}
```

### Шаг 2. Регистрация происходит автоматически

Есть **нечего добавить к `console.php`**. При запуске он выдает `Cli/Commands/*.php` и
`Cli/CronJobs/*.php` и, посредством отражения, `register()` для каждого неабстрактного класса, реализующего
`CommandInterface`. Переместите ваш класс в нужный каталог (с помощью `getName()`, который возвращает
его имя команды) — это все, что требуется - смотрите [Подключение ядра → регистрация команды CLI](../development/core-wiring.md#cli-command-registration).

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
| `rescue` | Create a temporary rescue access code for emergency panel access. Prints the URL. **Delete this code after use!** |
| `recaptcha` |Отключите reCAPTCHA (`recaptcha_enable = 0`), чтобы восстановить вход в панель администратора при сбое проверки captcha.|
| `access` |Восстановите все настройки кода доступа nginx и перезагрузите nginx. Печатает URL-адреса для всех кодов панели администратора.|
| `ports` |Восстановите настройки портов nginx (HTTP, HTTPS, RTMP) из базы данных и перезагрузите nginx.|
| `migration` |Очистите промежуточную базу данных (`xc_vm_migrate`) и при необходимости восстановите в ней резервную копию `.sql`.|
| `user` |Создайте пользователя rescue admin со случайными учетными данными. Введите имя пользователя и пароль. **Удалите этого пользователя после использования!**|
| `mysql` |Повторно авторизуйте привилегии MySQL для всех серверов load balancer.|
| `database` |Восстановите пустую базу данных XC_VM из `database.sql`. **Стирает ВСЕ данные!** Требуется установить флаг `--confirm`.|
| `flush` |Очистить все заблокированные IP—адреса - очищает правила iptables, удаляет файлы блокировки и обрезает таблицу `blocked_ips`.|

### Подкоманды (запускаются как `xc_vm`)

|Подкомандование|Описание|
| --- | --- |
| `images` |Загрузите отсутствующие изображения потоковых передач/фильмов/сериалов из базы данных TMDB. Сканирует базу данных в поисках URL-адресов изображений и загружает отсутствующие файлы.|
| `duplicates` |Найдите и удалите повторяющиеся потоки VOD. Группируйте по идентичному источнику, сначала сохраняйте, а остальные удаляйте. **Разрушительный!**|
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

- ⚠️ **Предупреждение:** `duplicates` стримы и все связанные с ними данные (журналы, статистика, эпизоды, записи) удаляются безвозвратно. Всегда создавайте резервную копию перед запуском.
- ⚠️ **Предупреждение:** `database --confirm` удаляет всю базу данных и заменяет ее пустой схемой. Это необратимо.
- 💡 **Совет:** После запуска `rescue` всегда удаляйте код через панель администратора или запустив `tools access`, как только вы восстановите доступ.
- 💡 **Совет:** После запуска `user` немедленно измените пароль и по завершении удалите пользователя для восстановления.

---

## Обновления / миграции баз данных

Файловая система обновления базы данных (создание шага `.sql`, таблицы `migrations`, потока выполнения `db:migrate`) теперь доступна на отдельной странице — см. [Обновления / миграции баз данных](database-migrations.md).

---

## Общие операции CLI

### Проверка состояния

```bash
sudo /home/xc_vm/console.php status
```

Проверяет, запущен ли XC_VM, подключается к базе данных, выполняет ожидающие обновления шаги, исправляет разрешения и проверяет конфигурацию nginx. Требуется после установки или восстановления.

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

Обнаруживает **почему?** прокси—сервер/LB—узел, отображаемый в автономном режиме на панели: проверяет частоту сердечных сокращений, доступность (ICMP/TCP/HTTP `/api`), перекос часов, очередь сигналов и - локально на узле - выполняет ли узел брандмауэр на главном IP-адресе в своем собственном iptables, запущена ли служба `xc_vm`/nginx, запущен ли демон сердцебиения `watchdog` и находится ли `cron:servers` в crontab `xc_vm`. Доступно только для чтения; код выхода `0` = проблем не обнаружено, `2` = указаны вероятные причины. Более подробную информацию смотрите в [Руководстве по диагностике сервера](../administration/server-diagnostics.md).

### SSL-сертификат

```bash
sudo /home/xc_vm/console.php certbot
```

### Миграция баз данных

Выполните ожидающие действия `.sql` вручную или импортируйте данные из другой системы — см. [Обновления / миграции базы данных](database-migrations.md#applying-migrations-manually).

---

## Связанные файлы

|Файл|Роль|
| --- | --- |
| `src/console.php` |Точка входа CLI + обнаружение команды FQCN|
| `src/Cli/Commands/` |Консольные команды|
| `src/Cli/CronJobs/` |Классы заданий Cron|
| `src/migrations/` |Миграция баз данных|
