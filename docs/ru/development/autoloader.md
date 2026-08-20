# Автоматическая загрузка (PSR-4)

XC_VM автоматически загружает классы с помощью стандартного **Composer PSR-4** автозагрузчика; пространство имен кодирует путь к файлу, поэтому разрешение выполняется напрямую `file_exists` без сканирования и кэширования.

---

## Обзор

Каждый сторонний класс находится в пространстве имен `XcVm\` root, сопоставленном с `src/`:

```text
XcVm\Core\Auth\Authenticator   ->  src/Core/Auth/Authenticator.php
XcVm\Domain\Stream\StreamService ->  src/Domain/Stream/StreamService.php
XcVm\Public\Controllers\Admin\UserController -> src/Public/Controllers/Admin/UserController.php
```

Сопоставление объявлено в `src/composer.json`:

```json
"autoload": {
    "psr-4": {
        "XcVm\\": "./",
        "M3uParser\\": "Core/Parsing/M3uParser/src/",
        "Chrisyue\\PhpM3u8\\": "Core/Parsing/PhpM3u8/src/"
    }
}
```

`src/vendor/` (зависимости Composer autoloader + production) зафиксированы и
отправлено — путь развертывания не содержит Composer и никогда не выполняется `composer install`. Там
нет ли ** кэша карты классов ** (нет `optimize-autoloader`): пропущенный класс - это простой путь
поиск, а не повторное сканирование каталога.

## Добавление нового класса

Создайте файл по пути, соответствующему его пространству имен, — вот и все; Composer разрешает его по требованию:

```php
// src/Domain/Billing/InvoiceService.php
namespace XcVm\Domain\Billing;

class InvoiceService {
    public static function generate(int $userId): string { /* ... */ }
}
```

Ссылайтесь на него из другого кода в пространстве имен с помощью `use` import или по его полному номеру:

```php
use XcVm\Domain\Billing\InvoiceService;
```

Не нужно очищать кэш, редактировать реестр. Совершенно новое подпространство имен (например,
`XcVm\Domain\Billing`) срабатывает немедленно, потому что он отображается прямо на
каталог.

## Правила присвоения имен

|Правило|Пример|
| --- | --- |
|Имя файла **должно ** совпадать с именем класса|`InvoiceService.php` → `class InvoiceService`|
|Один класс на файл|PSR-4 разрешает один класс для каждого пути; разбивает файлы на несколько классов|
|Пространство имен **должно** совпадать с путем к каталогу (с учетом регистра)|`src/Domain/Billing/` → `namespace XcVm\Domain\Billing;`|
|Классы и каталоги PascalCase|`StreamService`, `DatabaseHandler`, `Core/Auth/`|
|Соглашение о проекте: нет `declare(strict_types=1)`|—|

Поскольку пространство имен содержит местоположение, дублируйте короткие имена в разных
пространства имен больше не конфликтуют — `XcVm\Public\Controllers\Admin\PlexController` и
`XcVm\Module\Plex\PlexController` различны.

## Процедурные файлы и файлы третьих лиц

Некоторые файлы намеренно **не** разделены пространством имен и загружаются явным образом.
`require`, а не автозагрузчик:

- процедурные точки входа, представления и загрузочный клей (например, `Public/index.php`,
`Public/Views/**`, `Infrastructure/Bootstrap/*.php`);
- глобальные константы и функции (`Core/Config/*`, обработчик ошибок);
- класс ioncube `XC_VM` и комплектный `Infrastructure/Tmdb/lib/*`.

У продаваемых пакетов `M3uParser` и `Chrisyue\PhpM3u8` есть свои собственные пакеты PSR-4
префиксы (указанные выше) и автозагрузка выполняются в обычном режиме.

## Модули

Классы модулей используют пространство имен `XcVm\Module\<Name>\…`, но **не** зарегистрированы
в `composer.json` (каталогах модулей/торговых площадок — `plex`, `watch-d2bho` —
не соответствуют ни одному правилу PSR-4). Они разрешаются с помощью `ModuleLoader` собственного PSR-4
распознаватель: он удаляет базовое пространство имен модуля и отображает оставшееся в
вложенный путь в каталоге модуля. Смотрите [Модульная система](modules.md).

## Инструменты для разработки

Исправленный `vendor/` предназначен только для рабочей среды. PHPStan и PHP-CS-Fixer являются
`require-dev` пакетов — установите их локально с помощью:

```bash
make dev-tools   # = cd src && composer install
```

Они никогда не фиксируются (CI-шлюз обеспечивает фиксацию поставщика только для продукта). Видеть
[Рабочий процесс разработки](../guides/dev-workflow.md).

## Связанные файлы

|Файл|Роль|
| --- | --- |
| `src/composer.json` |PSR-4 префиксная карта + зависимости|
| `src/composer.lock` |зафиксированная блокировка для воспроизводимого `composer install`|
| `src/vendor/` |зафиксировано Composer автозагрузчик + производственные потери|
| `src/bootstrap.php` |определяет `MAIN_HOME`, требует `vendor/autoload.php`|
| `src/Core/Module/ModuleLoader.php` |PSR-4 распознаватель для классов модулей|
