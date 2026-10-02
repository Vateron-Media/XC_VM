# Точки расширения модуля

Основные точки расширения, к которым подключается модуль: контейнер DI, задачи cron, миграции версий, типизированные события, исходные драйверы, вкладки потоковой формы и виды импорта. Чтобы создать модуль, смотрите [Создание модуля](module-authoring.md); для загрузки/жизненного цикла смотрите [Жизненный цикл модуля](module-lifecycle.md).

## Оформление контейнеров и сервизов DI


Сервисы регистрируются в `boot()` через `ServiceContainer`. Контейнер поддерживает:

- **`set(id, factory)`** — отложенный синглтон с помощью вызываемого или прямого значения
- **`factory(id, callable)`** — новый экземпляр для каждого `get()`
- **`decorate(id, callable, priority)`** — завершение существующей службы

```php
// Decorate a service (adds behaviour around the original)
$container->decorate('stream.encoder', function (mixed $inner, ServiceContainer $c): MyEncoder {
    return new MyEncoder($inner, $c->get('settings'));
}, priority: 20);
```

Декораторы объединены в цепочки по приоритету (самый высокий и самый внешний). Защищенные сервисы
(`db`, `settings`, `config`, `auth`) не удается оформить — любая попытка приводит к результату `RuntimeException`.

### Соответствие требованиям стандарта PSR-11

`ServiceContainer` реализует `ContainerInterface`:

```php
public function get(string $id): mixed;  // throws NotFoundException if missing
public function has(string $id): bool;
```

`NotFoundException` реализует `NotFoundExceptionInterface extends ContainerExceptionInterface`.

---

## События PSR-14

Модули подписываются на типизированные события с помощью атрибута `getEventSubscribers()` или `#[ListensTo]`. Это описано в полном объеме — диспетчеризация, регистрация слушателей, приоритеты, события, которые можно остановить, и встроенный каталог событий - на специальной странице [Система событий](event-system.md).

---

## Потоковое промежуточное программное обеспечение

!!! предупреждение "Устарело — никогда не запускалось"
Ядро никогда не запускало конвейер потокового промежуточного программного обеспечения: `getStreamMiddleware()` никогда не вызывался
и класс конвейера исчез. `StreamMiddlewareProviderInterface`,
`StreamMiddlewareInterface` и `StreamContext` остаются только существующими модулями, которые
их реализация продолжает загружаться. Не основывайтесь на них. Чтобы воздействовать на потоки, используйте события
например, `StreamSavedEvent` и `StreamsDeletedEvent`, или
[исходный драйвер](source-drivers.md).

### Зарезервированные слоты на панели навигации

|Родительский узел|Гнезда для модулей|
| ------------------- | ------------------ |
| `management.service_setup` |`order` ≥ 60|
| `logs.system` |`order` ≥ 50|
| `profile` |`order` 100–980|

Журналы - это вкладка верхнего уровня `logs` с подгруппами `logs.connections`,
`logs.streams`, `logs.system`, `logs.users` — прикрепите журнал работы модуля
в разделе `logs.system`. Присоединение дочернего элемента к родительскому ключу, который не существует
автоматически сбрасывает его, поэтому синхронизируйте эти клавиши с `CoreNavbarProvider`.

---

## Кнопки на верхней панели (`TopbarProviderInterface`)

Значение для каждой страницы **верхняя панель** (основная кнопка действия и связанные с ней инструменты
выпадающий список над страницей) собирается с помощью `XcVm\Core\Util\Topbar`. Основные страницы приходят
из собственного списка Topbar; модуль добавляет свои кнопки через
`TopbarProviderInterface::registerTopbar(TopbarRegistry $registry)`, вызванный в
та же фаза загрузки, что и `registerNavbar()`. `BaseModule` по умолчанию не запускается,
поэтому переопределяйте его только тогда, когда вам нужны кнопки на верхней панели.

A module can do **both** of these, in one `registerTopbar()`:

- **Внедрить кнопки на существующую основную страницу** — передать ключ этой страницы (например,
`movies`); ваши кнопки будут добавлены после основных.
- **Создайте свою собственную совершенно новую страницу** — передать ключ страницы, который ядру неизвестен
(например, `watch`); вся верхняя панель для этой страницы берется из вашего модуля.

```php
use XcVm\Core\Module\TopbarRegistry;

public function registerTopbar(TopbarRegistry $registry): void
{
    // add($page, $label, $url = null, $permission = null, $attr = null, $order = 100)

    // A page the module owns — first entry becomes the primary button.
    $registry->add('watch', 'Add Folder', 'watch_add', 'folder_watch_add', null, 10);
    $registry->add('watch', 'Settings',   'settings_watch', 'folder_watch_settings', null, 20);
    // JS-only action: no url, carry an onClick via $attr.
    $registry->add('watch', 'Kill Running', null, 'folder_watch_settings', 'onClick="killWatchFolder();"', 40);

    // Inject a button into an existing CORE page.
    $registry->add('movies', 'Watch Folder', 'watch', 'folder_watch', null, 200);
}
```

**Клавиша страницы** равно `AdminHelpers::getPageName()` для страницы, на которой отображается кнопка
— то же значение, с которым совпадает верхняя панель.

**Форма входа** отражает `[url, permission, attr]` ядро:

|Аргумент|Значение|
| --- | --- |
| `$url` | Target page/URL. `null` for a JS-only action (pair with `$attr`). |
| `$permission` |`adv` дополнительное разрешение для кнопки. `null` = отображается всегда.|
| `$attr` | Raw extra attributes: `onClick="…"`, or a well-known `id="…"`. |
| `$order` |Порядок сортировки **среди записей модуля страницы** (по возрастанию).|

**Оформление заказа и основная кнопка.** На странице сначала появляются основные записи, затем
записи в модуле отсортированы по `$order`. `Topbar::items()` означает первое
разрешение-сохраняющаяся запись в виде кнопки **первичный**; остальные попадают в поле
выпадающий. На странице, полностью принадлежащей модулю, самая низкая запись-`$order` - это
первичный.

**Фильтрация разрешений.** Каждая запись с ненулевым значением `$permission` удаляется
если только `Authorization::check('adv', $permission)` не пройдет, так что кнопки никогда не протекут
к ролям, на которые не имеют права.

**Хорошо известные идентификаторы действий** в общем случае связаны оболочкой (`footer.php`) и
управляется ядром, поэтому модулю нужно только выдать идентификатор:

| `id="…"` |Эффект|
| --- | --- |
|`btn-export-csv` / `btn-export-json`|Экспорт отчета — выводится только на страницу журнала/отчета с основным списком **и** с разрешением `backups`.|
| `btn-clear-logs` |Модальный режим очистки журналов - тип журнала берется из карты core `LOG_TYPES` для страницы.|

Повторная регистрация того же самого `(page, label)` переопределяет более раннюю запись
(последние выигрыши), соответствующие `NavbarRegistry`.

---

## Табличные данные (`TableProviderInterface`)

Серверная таблица данных отправляет свои данные `id` в конечную точку администратора `./table`
(`TableController`). Идентификаторы основных таблиц - это жестко запрограммированный переключатель; модуль служит
свой СОБСТВЕННЫЙ идентификатор таблицы через `TableProviderInterface::registerTables(TableRegistry
$registry)` (та же фаза загрузки, что и у других), поэтому разработчик находится в модуле
вместо core. Когда `./table` получает идентификатор, который не является регистром core, он выглядит так
в реестре. `BaseModule` по умолчанию отправлено сообщение о том, что операции не выполняются.

```php
use XcVm\Core\Module\TableRegistry;

public function registerTables(TableRegistry $registry): void
{
    $registry->register('watch_output', [WatchController::class, 'tableWatchOutput']);
}
```

**Контракт с обработчиком** — `fn(array $return, int $start, int $limit, bool $isApi): array`:

```php
public static function tableWatchOutput(array $rReturn, int $rStart, int $rLimit, bool $rIsAPI): array
{
    global $db;                       // same access the core handlers use
    if (!Authorization::check('adv', 'folder_watch_output')) {
        return $rReturn;              // empty skeleton = no access
    }
    // …read RequestManager params, run COUNT + paged SELECT…
    $rReturn['recordsTotal']    = $rTotal;
    $rReturn['recordsFiltered'] = $rTotal;
    foreach ($rRows as $rRow) {
        // Return CLEAN, KEYED JSON — never HTML. The view renders every cell.
        $rReturn['data'][] = ['id' => (int) $rRow['id'], 'status' => (int) $rRow['status'], /* … */];
    }
    return $rReturn;                  // do NOT echo/exit — TableController encodes it
}
```

Правила:

- Обработчик получает скелет ответа (`recordsTotal`, `recordsFiltered`,
`data`) и возвращает его заполненным. Оно должно быть **нет** `echo` или `exit` —
`TableController` JSON - кодирует возвращаемый массив.
- Возвращает **чистые строки в формате JSON с ключами — без встроенного сервером HTML**. Значки статуса,
кнопки действий и ссылки отображаются на стороне клиента с помощью представления (то же самое
соглашение, которому следуют основные таблицы), что позволяет исключить представление
контроллер и позволяет ячейкам, зависящим от разрешений, использовать флаги, выдаваемые представлением.
- Для ветки REST API (`$isApi`) повторное использование
`TableController::filterRow($row, $show, $hide)` для столбца включить/исключить.
- Данные ajax `d.id` в представлении должны совпадать с зарегистрированным идентификатором.

---

## Разрешения торгового посредника (`PermissionProviderInterface`)

Каталог дополнительных разрешений для реселлеров редактора группы
(`XcVm\Core\Reference\PermissionReference`) - это основной список. Модуль добавляет свой
СОБСТВЕННЫЕ ключи доступа через `PermissionProviderInterface::registerPermissions(Регистрация разрешений
$registry)" (та же фаза загрузки), таким образом, модуль владеет разрешениями, на которые он ссылается,
вместо того, чтобы они были жестко запрограммированы в core. Ключи объединяются после списка core.

```php
use XcVm\Core\Module\PermissionRegistry;

public function registerPermissions(PermissionRegistry $registry): void
{
    $registry->add('folder_watch');
    $registry->add('folder_watch_output');
}
```

Каждая клавиша отображается в редакторе с метками из переводчика — добавить
`permission_<key>` и `permission_<key>_text` языковых записей. Маршруты перехода,
элементы навигационной и верхней панелей на ключе точно такие же, как и при использовании основного разрешения
(`Authorization::check('adv', 'folder_watch')`); принудительное выполнение считывает сохраненный
групповые разрешения и не зависит от того, где объявлен ключ.

> **Владение сквозной таблицей модулей.** Журнал/таблица данных модуля полностью соответствует
> модуль: строит свои строки с помощью `TableProviderInterface` (чистый JSON) и сохраняет
> его бухгалтерия также удаляется / очищается / импортируется в модуле — expose module
> `->api(...)` направляет действия в строке и реагирует на ядро **событие** (например
> `VodImportedEvent`) с помощью `#[ListensTo]` вместо записи таблицы в ядро
> непосредственно. Ядро никогда не должно быть `DELETE`/`UPDATE`/`TRUNCATE` таблицей, принадлежащей модулю
> (после удаления модуля он может исчезнуть).

---

## Быстрые инструменты (`QuickToolsProviderInterface`)

Страница "Быстрые инструменты администратора" представляет собой набор одноразовых кнопок обслуживания; каждая из них содержит
его ключ равен `post.php?action=quick_tools`, который запускает действие сопоставления. Оба
список кнопок и обработчики являются основными. Модуль добавляет свой собственный инструмент — кнопку
**и** действие — через интерфейс quicktoolsprovider::registerQuickTools(QuickToolsRegistry).
$реестр)`.

```php
use XcVm\Core\Module\QuickToolsRegistry;

public function registerQuickTools(QuickToolsRegistry $registry): void
{
    // add($group, $key, $label, $handler)
    $registry->add('logs', 'clear_watch_logs', 'clear_watch_logs', static function (): void {
        WatchService::clearAllLogs();   // do the work; query via global $db
    });
}
```

- `$group` - существующая клавиша табуляции (`streams`, `lines`, `logs`, `general`, ...) —
к нему добавляется инструмент — или новый ключ, отображаемый в виде новой вкладки с
общий значок и `$group` в качестве его метки-ключа.
- `$label` - это клавиша перевода для кнопки.
- `$handler` (`fn(): void`) выполняет действие и должен **нет** повторить/завершить —
`post.php` выдает стандартный JSON-файл `{result:true}` success после его запуска.

---

## Задача Cron


**Логика Cron** (`MyCron.php`) — только бизнес-логика, без подключения к интерфейсу командной строки.

**Обертка от CronJob** (`MyCronJob.php`) — реализует `CommandInterface`, использует `CronTrait`:

```php
class MyCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string { return 'cron:my_task'; }
    public function getDescription(): string { return 'Cron: my task'; }

    public function execute(array $rArgs): int {
        if (!$this->assertRunAsXcVm()) {
            return 1;
        }

        require INCLUDES_PATH . 'admin.php';
        require_once __DIR__ . '/MyCron.php';

        $this->initCron('XC_VM[MyTask]');
        MyCron::run();

        return 0;
    }
}
```

Регистрация в модуле:

```php
public function registerCommands(CommandRegistry $registry): void {
    $registry->register(new MyCronJob());
}
```

Объявите запись crontab, переопределив `getCronEntries()` в классе module:

```php
public function getCronEntries(): array {
    return [
        '*/5 * * * *' => 'cron:my_task',
    ];
}
```

`ModuleLoader::collectCronEntries()` объединяет записи всех модулей и `StartupCommand` /
`StatusCommand` автоматически записывайте их в системный crontab — никаких изменений в основных файлах не требуется.

**Формат:** ключ = выражение cron, значение = имя консольной команды, зарегистрированное с помощью `registerCommands()`.

---

## Версионные миграции (MigratableInterface)


> **Два механизма, оба аддитивные.** **файловая схема**, описанный в разделе
> [Структура каталогов модулей](module-authoring.md#module-directory-structure) (`database.sql` мастер +
> `database_drop.sql` разборка + `migrations/<semver>.sql` дельты) используется по умолчанию для
> обычный DDL/seed. `MigratableInterface` ниже приведен **программный** путь для обновления
> шаги, требующие логики PHP (обратная загрузка данных, условные изменения). Модуль может использовать
> один из них или оба; `ModuleManager::updateModule()` сначала запускает файл delta, затем
> вызываемые миграции.

Модули, для обновления которых требуется логическая реализация PHP `MigratableInterface`:

```php
namespace XcVm\Module\MyModule;

use BaseModule;
use MigratableInterface;
use ServiceContainer;

class MyModuleModule extends BaseModule implements MigratableInterface {

    public function getMigrations(): array {
        return [
            '1.1.0' => function (): void {
                // runs when upgrading from any version < 1.1.0 to >= 1.1.0
                global $db;
                $db->query("ALTER TABLE xc_my_table ADD COLUMN new_col INT DEFAULT 0");
            },
            '1.2.0' => function (): void {
                // runs when upgrading from < 1.2.0 to >= 1.2.0
            },
        ];
    }
}
```

`ModuleManager::updateModule()` считывает `installed_version` из хранилища переопределений, фильтрует
сопоставляет только записи `> fromVersion && <= toVersion`, сортирует по полу и запускает каждую из них.
вызываемый в своей собственной транзакции базы данных. `installModule()` записи `installed_version` после
успешная установка; `uninstallModule()` удаляет ее.

**Key rules:**

- Ключи - это полустрочные строки (`'1.1.0'`, `'2.0.0'`) — `version_compare` используется упорядочение
- Каждая миграция выполняется в рамках своей собственной транзакции — сбой откатывает только этот шаг
- `BaseModule` предоставляет значение по умолчанию `getMigrations(): array { return []; }`, поэтому реализация
`MigratableInterface` является необязательным

---

## Исходные драйверы (`SourceDriverInterface`)

Модулю может принадлежать какой-то живой исходный код, который ffmpeg не может прочитать (например, DASH с
DRM) и запустит для него свой собственный движок вместо ffmpeg. Модуль объявляет драйвер
классы в `module.json` (`"source_drivers": [...]`), и каждый драйвер задает свой собственный URL
схема. Потоки остаются обычными потоками XC_VM. Смотрите [Исходные драйверы](source-drivers.md) для получения
интерфейс, контракт с производителем и полный пример.

---

## Вкладки потоковой формы (`StreamFormRegistry`)

Модуль может добавить свою собственную вкладку на страницу добавления/редактирования потока администратором и сохранить то, что находится на этой вкладке.
записи в своих собственных таблицах. Зарегистрируйте вкладку с `boot()`. `bootAll()` сбрасывает реестр
при каждой загрузке, таким образом, вкладка существует только до тех пор, пока загружен ее модуль.

```php
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Events\ListensTo;
use XcVm\Core\Events\Stream\StreamSavedEvent;
use XcVm\Core\Module\StreamFormRegistry;

public function boot(ServiceContainer $container): void {
    StreamFormRegistry::add(
        'acme-dash',                                        // id: [a-z0-9_-]
        'DASH engine',                                      // tab title, already translated
        static fn(?array $stream, string $mode): string =>  // $mode: 'add' | 'edit'
            AcmeDashForm::render($stream === null ? null : (int) $stream['id']),
        'manage_acme_dash',                                 // 'adv' permission, or null
        static fn(array $fields, ?array $stream): ?string =>
            ($fields['provider'] ?? '') === '' ? 'Choose a provider' : null,
    );
}

#[ListensTo(StreamSavedEvent::class)]
public function onStreamSaved(StreamSavedEvent $event): void {
    if (!isset($event->moduleFields['acme-dash'])) {
        return; // an import, an API call or a form without this tab: keep what is stored
    }
    foreach ($event->streamIds as $id) {
        AcmeDashSettings::save($id, $event->moduleFields['acme-dash']);
    }
}
```

- **Входные** должно быть присвоено имя `module[<id>][<field>]`, например
`<input name="module[acme-dash][provider]">`. Ядро передает именно этот подмассив
назад. Поле модуля никогда не достигает столбца `streams`, и ядро отбрасывает поля из
вкладки, которые администратор может не видеть.
- **`render`** returns the pane's HTML. `$stream` is the stream row when editing and
`null` при добавлении. Вкладка не отображается в форме импорта, а для массового редактирования нет
вкладки модулей.
- **`validate`** выполняется до того, как что-либо будет записано, и только тогда, когда поля вкладки были заполнены.
опубликовано: сохранение API или импорт, который не содержит ничего, никогда не отклоняются им. Возвращающийся
строка отказывается сохраняться, и форма показывает этот текст как есть, поэтому переведите его
себя.
- **`StreamSavedEvent`** отправляется один раз за сохранение, после записи каждой строки. Оно
носит:
  - `streamIds`;
  - `isNew`: `false` для редактирования;
  - `source`: `form` (форма или API администратора), `import` (M3U) или `review`
(Импорт и обзор);
  - `moduleFields`: идентификатор вкладки => опубликованные поля.

Действуйте только тогда, когда ваш идентификатор находится в `moduleFields`, в противном случае импорт или сохранение API
это уничтожило бы ваши настройки. Прослушиватель, который выдает сообщение, регистрируется в журнале и не завершает работу
сохранить.
- При сохранении **Не добавляйте внешние ключи к `streams`.** строка (`REPLACE INTO`) будет переписана заново.
Вместо этого выполните очистку на `StreamsDeletedEvent`.

---

## Виды импорта (`ImportSourceRegistry`)

Модуль может добавить свой собственный источник на страницу **Импорт и обзор** для прямых трансляций, далее
к встроенному файлу M3U. Администратор выбирает его в селекторе **Источник** и заполняет
входы модуля. В модуле перечислены каналы, и они проходят через обычный
просмотрите и импортируйте шаги, чтобы каждый канал стал обычным потоком. Зарегистрируйте
вид из `boot()`; `bootAll()` сбрасывает реестр при каждой загрузке.

```php
use XcVm\Core\Module\ImportSourceRegistry;

public function boot(ServiceContainer $container): void {
    ImportSourceRegistry::add(
        'acme-dash',                                   // key: [a-z0-9_-]
        'Acme DASH provider',                          // label in the Source picker
        static fn(): string => AcmeDashImport::form(), // inputs: import_source[acme-dash][...]
        static fn(array $fields): array => AcmeDashImport::channels($fields['provider'] ?? ''),
        'manage_acme_dash',                            // 'adv' permission, or null
    );
}

// AcmeDashImport::channels() returns rows like:
// ['url' => 'acmedash://prov1/demo-001', 'title' => 'Demo One',
//  'logo' => 'https://…/logo.png', 'tvg_id' => 'demo.one', 'category' => 'News']
```

- **Входные** такого рода называются `import_source[<key>][<field>]`. Его `list`
callable получает именно этот подмассив.
- **Строки** нужен `url`. `title` возвращает к URL-адресу и `logo`, `tvg_id` (соответствует
против EPG, такого как M3U `tvg-id`) и `category` являются необязательными.
- **Существующие источники.** URL-адрес источника, который уже есть в панели, не указывается, если только администратор не
галочки *Показывают потенциальные дубликаты*; затем они отображаются и помечаются.
- **Ограничение по строкам.** Одна страница обзора занимает не более `ImportSourceRegistry::MAX_ROWS` (500) страниц
строк; кроме того, на странице отображается слишком много результатов.
- **Неудачи.** Вызываемый объект `list`, который выдает сообщение "нет источников" и регистрирует сообщение в журнале,
поэтому держите вызовы провайдера в пределах тайм-аута.
- **После импорта,** `StreamSavedEvent` запускается с помощью `source = 'review'`, и новый
идентификаторы потоков. Идентификатор канала указан в URL-адресе источника каждого потока.

---
