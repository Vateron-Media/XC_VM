# Автозагрузка (PSR-4)

XC_VM загружает классы стандартным автозагрузчиком **Composer PSR-4**; namespace кодирует путь к файлу, поэтому разрешение — это прямой `file_exists` без сканирования и без кэша.

---

## Обзор

Каждый класс первого уровня живёт под корневым namespace `XcVm\`, отображённым на `src/`:

```text
XcVm\Core\Auth\Authenticator   ->  src/Core/Auth/Authenticator.php
XcVm\Domain\Stream\StreamService ->  src/Domain/Stream/StreamService.php
XcVm\Public\Controllers\Admin\UserController -> src/Public/Controllers/Admin/UserController.php
```

Маппинг объявлен в `src/composer.json`:

```json
"autoload": {
    "psr-4": {
        "XcVm\\": "./",
        "M3uParser\\": "Core/Parsing/M3uParser/src/",
        "Chrisyue\\PhpM3u8\\": "Core/Parsing/PhpM3u8/src/"
    }
}
```

`src/vendor/` (автозагрузчик Composer + продакшен-зависимости) закоммичен и
поставляется как есть — на пути деплоя нет Composer и `composer install` не
запускается. **Кэша карты классов нет** (без `optimize-autoloader`): промах по
классу — это обычный поиск по пути, а не пересканирование каталогов.

## Добавление нового класса

Создайте файл по пути, на который отображается его namespace — и всё; Composer разрешит его по требованию:

```php
// src/Domain/Billing/InvoiceService.php
namespace XcVm\Domain\Billing;

class InvoiceService {
    public static function generate(int $userId): string { /* ... */ }
}
```

Ссылайтесь на него из другого namespaced-кода через `use` или по FQCN:

```php
use XcVm\Domain\Billing\InvoiceService;
```

Кэш чистить не нужно, реестр править не нужно. Новый под-namespace (например,
`XcVm\Domain\Billing`) работает сразу, потому что отображается прямо на каталог.

## Правила именования

| Правило | Пример |
| --- | --- |
| Имя файла **должно** совпадать с именем класса | `InvoiceService.php` → `class InvoiceService` |
| Один класс на файл | PSR-4 разрешает один класс на путь; multi-class файлы разделять |
| Namespace **должен** совпадать с путём каталога (регистрозависимо) | `src/Domain/Billing/` → `namespace XcVm\Domain\Billing;` |
| PascalCase для классов и каталогов | `StreamService`, `DatabaseHandler`, `Core/Auth/` |
| Соглашение проекта: без `declare(strict_types=1)` | — |

Поскольку namespace несёт расположение, одинаковые короткие имена в разных
namespace больше не конфликтуют — `XcVm\Public\Controllers\Admin\PlexController` и
`XcVm\Module\Plex\PlexController` различны.

## Процедурные и сторонние файлы

Некоторые файлы намеренно **не** в namespace и подключаются явным `require`, а не
автозагрузчиком:

- процедурные точки входа, view и bootstrap-склейка (`Public/index.php`,
  `Public/Views/**`, `Infrastructure/Bootstrap/*.php`);
- глобальные константы и функции (`Core/Config/*`, обработчик ошибок);
- ioncube-класс `XC_VM` и встроенный `Modules/tmdb/lib/*`.

Вендорные пакеты `M3uParser` и `Chrisyue\PhpM3u8` имеют собственные PSR-4-префиксы
(выше) и автозагружаются штатно.

## Модули

Классы модулей используют namespace `XcVm\Module\<Name>\…`, но **не**
регистрируются в `composer.json` (slug-каталоги модулей/маркетплейса — `plex`,
`watch-d2bho` — не укладываются в одно PSR-4-правило). Их разрешает собственный
PSR-4-резолвер `ModuleLoader`: снимает базовый namespace модуля и отображает
остаток на под-путь внутри каталога модуля. См. [Систему модулей](modules.md).

## Инструменты разработки

Закоммиченный `vendor/` — только продакшен. PHPStan и PHP-CS-Fixer — это
`require-dev`-пакеты; ставьте их локально командой:

```bash
make dev-tools   # = cd src && composer install
```

Они никогда не коммитятся (CI-гейт требует прод-only закоммиченный vendor). См.
[Рабочий процесс разработки](../guides/dev-workflow.md).

## Связанные файлы

| Файл | Роль |
| --- | --- |
| `src/composer.json` | Карта PSR-4-префиксов + зависимости |
| `src/composer.lock` | закоммиченный lock для воспроизводимого `composer install` |
| `src/vendor/` | закоммиченный автозагрузчик Composer + прод-зависимости |
| `src/bootstrap.php` | определяет `MAIN_HOME`, подключает `vendor/autoload.php` |
| `src/Core/Module/ModuleLoader.php` | PSR-4-резолвер классов модулей |
