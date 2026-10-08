# Автоматическая загрузка (PSR-4)

XC_VM автоматически загружает классы с помощью стандартного автозагрузчика **Композитор PSR-4**; пространство имен кодирует путь к файлу, поэтому разрешение является прямым `file_exists` без сканирования и кэширования.

---

## Обзор

Каждый сторонний класс находится в корневом пространстве имен `XcVm\`, сопоставленном с `src/`:

```text
XcVm\Core\Auth\Authenticator   ->  src/Core/Auth/Authenticator.php
XcVm\Domain\Stream\StreamService ->  src/Domain/Stream/StreamService.php
XcVm\Public\Controllers\Admin\UserController -> src/Public/Controllers/Admin/UserController.php
```

Отображение объявлено в `src/composer.json`:

```json
"autoload": {
    "psr-4": {
        "XcVm\\": "./"
    }
}
```

`src/vendor/` (автозагрузчик Composer + производственные зависимости) зафиксирован и
отправлено — путь развертывания не содержит Composer и никогда не выполняется `composer install`. Там
is **нет кэша карт классов** (no `optimize-autoloader`): пропуск класса - это простой путь
поиск, а не повторное сканирование каталога.

## Добавление нового класса

Создайте файл по пути, соответствующему его пространству имен, — вот и все; Composer разрешит его по требованию:

```php
// src/Domain/Billing/InvoiceService.php
namespace XcVm\Domain\Billing;

class InvoiceService {
    public static function generate(int $userId): string { /* ... */ }
}
```

Ссылайтесь на него из другого кода в пространстве имен с помощью импорта `use` или по его полному номеру:

```php
use XcVm\Domain\Billing\InvoiceService;
```

Не нужно очищать кэш, редактировать реестр. Совершенно новое подпространство имен (например,
`XcVm\Domain\Billing`) срабатывает немедленно, поскольку он отображается прямо на
каталог.

## Правила присвоения имен

|Правило|Пример|
| --- | --- |
|Имя файла **должен** соответствует имени класса|`InvoiceService.php` → `class InvoiceService`|
|Один класс на файл|PSR-4 разрешает один класс для каждого пути; разбивает файлы на несколько классов|
|Пространство имен **должен** соответствует пути к каталогу (с учетом регистра).|`src/Domain/Billing/` → `namespace XcVm\Domain\Billing;`|
|Классы и каталоги PascalCase|`StreamService`, `DatabaseHandler`, `Core/Auth/`|
|Соглашение о проекте: нет `declare(strict_types=1)`|—|

Поскольку пространство имен содержит местоположение, дублируйте короткие имена в разных
пространства имен больше не конфликтуют — `XcVm\Public\Controllers\Player\HomeController` и
`XcVm\Public\Controllers\PlayerV2\HomeController` различны.

## Процедурные файлы и файлы третьих лиц

Некоторые файлы намеренно разделены пространством имен **нет** и загружаются явным образом.
`require`, а не автозагрузчик:

- процедурные точки входа, представления и загрузочный клей (например, `Public/index.php`,
`Public/Views/**`, `Infrastructure/Bootstrap/*.php`);
- глобальные константы и функции (`Core/Config/*`, обработчик ошибок);
- класс ioncube `XC_VM` и комплект поставки `Infrastructure/Tmdb/lib/*`.

Сторонние библиотеки (например, `gemorroj/m3u-parser`, `chrisyue/php-m3u8`,
`mobiledetect/mobiledetectlib`, `geoip2/geoip2`) являются обычными композиторами `require`
зависимости, объявленные в `src/composer.json`; они находятся в соответствии с `src/vendor/` и
автозагрузка через автозагрузчик поставщика Composer — они указаны как **нет** в списке
`psr-4` блок выше.

## Модули

Классы модулей используют пространство имен `XcVm\Module\<Name>\…`, но зарегистрированы в **нет**
в `composer.json` (каталоги модулей/торговых площадок — `plex`, `watch-d2bho` —
не соответствуют ни одному правилу PSR-4). Они разрешаются с помощью `ModuleLoader` собственного PSR-4
распознаватель: он удаляет базовое пространство имен модуля и отображает оставшееся в
дополнительный путь в каталоге модуля. Смотрите [Создание модуля](module-authoring.md).

## Инструменты для разработки

Зафиксированное значение `vendor/` доступно только для производства. PHPStan и phpcs являются
`require-dev` пакеты — установите их локально с помощью:

```bash
make dev-tools   # = cd src && composer install
```

Они никогда не фиксируются (CI-шлюз обеспечивает фиксацию поставщика только для продукта). Видеть
[Рабочий процесс разработки](../guides/dev-workflow.md).

## Связанные файлы

|Файл|Роль|
| --- | --- |
| `src/composer.json` |Карта префиксов PSR-4 + зависимости|
| `src/composer.lock` |зафиксированная блокировка для воспроизводимого `composer install`|
| `src/vendor/` |исправленный автозагрузчик Composer + производственный процесс|
| `src/bootstrap.php` |определяет `MAIN_HOME`, требует `vendor/autoload.php`|
| `src/Core/Module/ModuleLoader.php` |Преобразователь PSR-4 для классов модулей|
