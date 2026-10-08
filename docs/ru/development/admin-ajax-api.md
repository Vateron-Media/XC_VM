# Admin AJAX API (`?action=`)

The admin panel's non-page JSON endpoints are reached as `./api?action=<name>`
(страница главного контроллера `api`). Каждое действие обрабатывается специальным
Контроллер PSR-4 под `XcVm\Public\Controllers\Admin\Ajax`, зарегистрированный как API
маршрут в `src/Public/routes/admin.php` и отправляется
`Router::dispatchApi()`.

> Эти конечные точки заменили устаревшую `src/Public/Views/admin/api.php` — единую
> ~4985-линейная плоская цепочка из `if (action == 'x') { … exit(); }` блоков. Это было
> извлекается действие за действием в приведенные ниже контроллеры и удаляется; остается только
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
    $this->requireXhr();
    $this->gate('adv', 'database');
    // … call a domain service …
    $this->ok();
}
```

Запускает действие с разрешением страницы, на которой отображается ее кнопка (здесь кэш
страница, `database`), поэтому группа, которая не может открыть страницу, не может выполнять свои действия. A
отказ, для которого есть веская причина, переносится в `message`:
`$this->fail(['message' => …])`.

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
| `CacheAjaxController` |Восстановление/включение/отключение кэша, очистка Redis, обработчики|
| `ServerAjaxController` |Добавление/редактирование/удаление сервера и другие операции|
|`StreamAjaxController` / `StreamToolsAjaxController`|Запуск/остановка/перезапуск/очистка потока, списки, обзоры|
| `PackageAjaxController` |Посылки, букеты, группы, категории|
| `ActiveCodeAjaxController` |Генерация кода активации, пакетные действия, экспорт|
| `ModuleAjaxController` |Действия со строками таблицы модулей|
| `UserAjaxController` |Пользователи, линии связи, реселлеры|
| `DeviceAjaxController` |Устройства MAG / Enigma2|
| `EpgAjaxController` |Источники и сопоставления EPG|
| `StatsAjaxController` |Статистика и графики|
| `BlocklistAjaxController` |Списки блокировок / безопасность|
| `BackupAjaxController` |Резервные копии, журналы, отчеты|
| `ProviderAjaxController` |Конечные точки поставщика (таблицы данных)|
| `MultiAjaxController` |Массовые (`multi`) действия с выбранными идентификаторами|
| `SearchAjaxController` |Глобальный нечеткий поиск (см. ниже)|
| `MiscAjaxController` |Оставшиеся мелкие действия|

### Разрешительные ворота

Указывает, что название действия не выдает (все разрешения `adv`):

|Действие|Разрешение|
| --- | --- |
|`regenerate_cache`, `enable_cache`, `disable_cache`, `enable_handler`, `disable_handler`, `clear_redis`| `database` |
|`report` (Экспорт в формате CSV/JSON)| `database` |
| `clear_logs` | The permission of the log page that `type` names: `lines_logs` → `client_request_log`, `lines_activity` → `connection_logs`, `streams_errors` → `stream_errors`, `users_credits_logs` → `credits_log`, `users_logs` → `reg_userlog`, `panel_logs` → `panel_logs`. Any other `type` fails. |
| `download_panel_logs` |`panel_logs`. Таблица очищается после того, как все ее строки собраны.|
|`get_epg`, `get_programme`, `provider_streams`, `provider_import_epg`| `streams` |
| `multi` |С помощью `type`, например `line` → `edit_user`, `series` → `edit_series`, `active_code` → `edit_user` или `mass_edit_lines`|
| `generate_active_codes` | `add_user` |
| `active_codes_batch_action` |`edit_user` или `mass_edit_lines`|
| `active_codes_export_txt` | `users` |
| `module` | `settings` |

### Правила за воротами

Некоторые действия проходят проверку и по-прежнему отвечают `{"result":false}`:

- **`group`** с наборами `sub` = `is_admin` или `is_reseller` (плюс `value` и `group_id`)
этот флаг в соответствии с правилами формы группы. `value` сохраняется как 0 или 1. Группа, которая
не может быть удален, сохраняет свои флаги. Только полноправный администратор (группа 1 или
группа администраторов с пустым списком разрешений) изменяет группу администраторов или
делает группу группой администратора.
- **`package`** с наборами `sub` = `is_trial` или `is_official` (плюс `value` и `package_id`)
этот флаг, и никакой другой флаг не установлен таким образом. `value` должно читаться как включено или выключено (`0`, `1`,
`true`, `false`, `on`, `off`, `yes`, `no`) и сохраняется как 0 или 1. Отсутствующий или другой
`value` или `package_id`, который не существует, отвечает на `{"result":false}`.
Отключение обоих способов приводит к отзыву пакета у реселлеров на каждом пути (он продает их
ничего). Both on - это единственный способ получить пакет, который продает подписки и предоставляет
испытания; сама форма упаковки отключает один переключатель при включении другого.
- **`reg_user`** и **`adjust_credits`** оставьте учетную запись администратора в покое, если только
вызывающий абонент является полноправным администратором.

---

## Глобальный поиск — структурированный JSON-контракт

`SearchAjaxController::search()` (`?action=search`) - это нечеткий полнотекстовый поиск
на разных линиях, устройства MAG/Enigma2, пользователи, трансляции (прямые/VOD/созданные
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
