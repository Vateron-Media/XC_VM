# Точки расширения модуля

Основные точки расширения, к которым подключается модуль: контейнер DI, потоковое промежуточное программное обеспечение, задачи cron, миграции версий и типизированные события. Чтобы создать модуль, смотрите [Создание модуля](module-authoring.md); для загрузки/жизненного цикла смотрите [Жизненный цикл модуля](module-lifecycle.md).

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


Модули могут внедрять промежуточное программное обеспечение в потоковый конвейер, реализуя
`StreamMiddlewareProviderInterface` (отдельно от `ModuleInterface`):

```php
class MyStreamMiddleware implements StreamMiddlewareInterface {

    public function getPriority(): int {
        return 50;
    }

    public function handle(StreamContext $ctx, callable $next): StreamContext {
        // before — read or set attributes
        $ctx->set('my.key', 'value');
        $ctx = $next($ctx);
        // after
        return $ctx;
    }
}
```

`StreamContext` - это набор атрибутов (`get`, `set`, `has`, `abort`, `isAborted`). `StreamPipeline`
выполняет промежуточное программное обеспечение, отсортированное по убыванию `getPriority()`.

### Приоритеты трубопровода

|Диапазон|Владелец|
| ---------- | ----------------- |
| `80–100` |Ядро (авторизация, разрешение, ограничение подключения)|
| `0–79` |Модули|

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
в реестре. `BaseModule` по умолчанию отправляет сообщение о том, что операции не выполняются.

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
- Возвращает **чистые строки JSON с ключами — без встроенного сервером HTML**. Значки статуса,
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
> обычный DDL/seed. `MigratableInterface` ниже приведен путь **программный** для обновления
> шаги, требующие логики PHP (повторное заполнение данных, условные изменения). Модуль может использовать
> один из них или оба; `ModuleManager::updateModule()` сначала запускает файл delta, затем
> вызываемые миграции.

Модули, для обновления которых требуется PHP логическая реализация `MigratableInterface`:

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
