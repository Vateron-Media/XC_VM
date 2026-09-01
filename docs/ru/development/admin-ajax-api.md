# Admin AJAX API (`?action=`)

The admin panel's non-page JSON endpoints are reached as `./api?action=<name>`
(страница главного контроллера `api`). Каждое действие обрабатывается специальным
PSR-4 контроллер под `XcVm\Public\Controllers\Admin\Ajax`, зарегистрированный как API
маршрут в `src/Public/routes/admin.php` и отправляется
`Router::dispatchApi()`.

> Эти конечные точки заменили устаревшую `src/Public/Views/admin/api.php` — единую
> ~4985-линейная плоская цепочка из `if (action == 'x') { … exit(); }` блоков. Это было
> извлекается действие за действием в нижеприведенные контроллеры и удаляется; остается только
> неизвестное или удаленное действие по-прежнему имеет ограниченный запасной вариант `AjaxController`.

---

## Заказ на отправку

`src/Public/index.php` запускает отправку страницы API dispatch **до** для `api`
страница, потому что устаревший обработчик страницы `AjaxController` завершает работу внутри системы:

```text
./api?action=search
  -> Router::dispatchApi('search')      # registered Admin\Ajax controller — wins
       (falls through only if no api route matches)
  -> Router::dispatch('api')            # AjaxController fallback -> {"result":false}
```

Зарегистрированное действие никогда не достигает резервного варианта; незарегистрированное действие достигает резервного варианта, и
резервные ответы `{"result":false}` (защищены только для AJAX, как и действия
сами). Проверка подлинности администратора уже выполняется с помощью
`AdminScopeBootstrap::boot()` прежде чем что-либо из этого запустится.

Регистрация выглядит следующим образом:

```php
// src/Public/routes/admin.php
$router->api('search', [SearchAjaxController::class, 'search']);
$router->api('regenerate_cache', [CacheAjaxController::class, 'regenerate']);
```

---

## `BaseAjaxController`

Файл: `src/Public/Controllers/Admin/Ajax/BaseAjaxController.php`

База `abstract`, которая выдает только JSON (без макета / шаблонов), поэтому она выполняет **нет**
расширить `BaseAdminController`. Это обеспечивает основу для повторного использования каждого действия:

|Метод|Цель|
| --- | --- |
| `ok(array $extra = [])` |Введите `{"result":true}` (+ дополнительные ключи) и завершите запрос|
| `fail(array $extra = [])` |Введите `{"result":false}` (+ дополнительные ключи) и завершите запрос|
| `gate(string $type, string $key)` |затвор `Authorization::check()`; при сбое выдает сигнал `{"result":false}` и останавливается|
| `gateAny(array $checks)` |OR-gate: проходит, если какая-либо проверка `[type, key]` выполнена успешно, в противном случае происходит сбой|
| `requireXhr()` |Отклонять запросы, отличные от AJAX, если не включен режим отладки (`PHP_ERRORS`)|
| `json(array $data, int $flags = 0)` |Необработанное тело JSON с правильным значением `Content-Type`, затем выйдите|

A typical action collapses the legacy `check → … → echo json_encode(); exit;`
идиому в несколько удобочитаемых строк:

```php
public function regenerate(): never {
    $this->gate('adv', 'manage_streams');
    // … call a domain service …
    $this->ok();
}
```

### Общая линия/состояние устройства — `LineStateTrait`

`src/Public/Controllers/Admin/Ajax/LineStateTrait.php` содержит параметр включения /
логика отключения / запрета / разбанивания / уничтожения, совместно используемая устройствами line, MAG и Enigma2
контроллеры. Это признак (а не базовый класс), потому что эти контроллеры уже
extend `BaseAjaxController`; в нем объявляется `@phpstan-require-extends
BaseAjaxController` and abstract `ok()`/`fail() завершает работу, поэтому статический анализ и
IDE разрешает унаследованные помощники.

---

## Контроллеры

Каждый контроллер группирует согласованный набор действий (они перечислены в его классе docblock).:

|Контроллер|Область|
| --- | --- |
| `CacheAjaxController` |Восстановление кэша/включение/выключение, очистка Redis, обработчики|
| `ServerAjaxController` |Добавление/редактирование/удаление сервера и другие операции|
|`StreamAjaxController` / `StreamToolsAjaxController`|Запуск/остановка/перезапуск/очистка потока, списки, обзоры|
| `PackageAjaxController` |Посылки/букеты|
| `UserAjaxController` |Пользователи, линии связи, реселлеры|
| `DeviceAjaxController` |Устройства MAG / Enigma2|
| `EpgAjaxController` |EPG источники и сопоставления|
| `StatsAjaxController` |Статистика и графики|
| `BlocklistAjaxController` |Списки блокировок / безопасность|
| `BackupAjaxController` |Резервные копии, журналы, отчеты|
| `ProviderAjaxController` |Конечные точки поставщика (таблицы данных)|
| `MultiAjaxController` |Массовые (`multi`) действия с выбранными идентификаторами|
| `SearchAjaxController` |Глобальный нечеткий поиск (см. ниже)|
| `MiscAjaxController` |Оставшиеся мелкие действия|

---

## Глобальный поиск — структурированный JSON-контракт

`SearchAjaxController::search()` (`?action=search`) - это нечеткий полнотекстовый поиск
на разных линиях, устройства MAG/Enigma2, пользователи, трансляции (текущие/VOD/созданные
каналы/радио/эпизоды) и сериалы. Возвращается значение **структурированные данные**, а не
HTML, отображаемый сервером: клиент отображает каждый результат в виде карточки. Разрешение
проверки, определение статуса и поиск категорий/серверов остаются на стороне сервера; только
разметка находится в браузере.

### Конверт

```jsonc
{ "result": true, "total_count": 12, "items": [ Item, … ] }
```

Пустой поиск возвращает один элемент `no_results` для сопоставления со стилизованным
Выберите выпадающий список 2.

### Предмет

```jsonc
{
  "id":     "streams#512",         // stable identity (kept for Select2)
  "url":    "stream_view?id=512",  // primary navigation target
  "text":   "CNN HD",              // plain label (Select2 matching)
  "entity": "stream",              // stream|movie|channel|radio|episode|series|user|line|mag|enigma
  "data":   { … }                  // entity-specific payload
}
```

Каждая запись `data.actions[]` равна **самоописывающий**, поэтому клиенту не нужно
логика для каждого действия - она сопоставляет `kind` с существующим глобальным помощником:

| `kind` |Звонок клиента|
| --- | --- |
| `navigate` | `navigate(target)` |
| `api` | `searchAPI(entity, id, sub)` |
| `fingerprint` | `modalFingerprint(id, context)` |
| `credits` | `addCredits(id)` |

`enabled: false` отображает отключенную кнопку. Коды состояния потока (`-1…10`) являются
разрешен на стороне сервера точно так же, как и раньше; метки/варианты являются производными от существующих
`$rSearchStatusArray` постоянный, поэтому он остается единственным источником истины.

### Клиентское средство визуализации

`src/Public/assets/admin/js/search.js` (`renderSearchItem(item)`) рассылки по
`item.entity` для создания карточек для каждого объекта и передачи описывающих себя действий.
Он загружается до `common.js`, чей выбор 2 выполняется в режиме быстрого поиска `templateResult`.
вызывает его (с защитой состояния загрузки) вместо использования сервера `html`
поле.

> Это изменяет только **путь рендеринга**, а не поисковое соответствие. База данных собирает
> (пакетированный `MATCH … AGAINST` полнотекстовый текст с сортировкой по баллам и поиском в предложениях)
> не изменился. Если в результатах поиска отсутствуют прямые трансляции, восстановите устаревший
> `streams` ПОЛНОТЕКСТОВЫЙ индекс в базе данных (`ALTER TABLE streams ENGINE=InnoDB;`)
> — проблема во время выполнения, а не в пути к коду.

---

## Связанные файлы

|Файл|Цель|
| --- | --- |
| `src/Public/Controllers/Admin/Ajax/BaseAjaxController.php` |Строительные леса JSON (ok/fail/gate/requireXhr/json)|
| `src/Public/Controllers/Admin/Ajax/LineStateTrait.php` |Действия с общей линией/состоянием устройства|
| `src/Public/Controllers/Admin/Ajax/*AjaxController.php` |Контроллеры действий для каждой области|
| `src/Public/Controllers/Admin/AjaxController.php` |Резервный вариант для неизвестных действий (`{"result":false}`)|
| `src/Public/routes/admin.php` |`$router->api(...)` регистрации|
| `src/Public/assets/admin/js/search.js` |Средство визуализации поисковой карточки на стороне клиента|
