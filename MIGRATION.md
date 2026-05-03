# XC_VM - Рабочий план миграции

> Архитектурные правила: см. [ARCHITECTURE.md](ARCHITECTURE.md).
> Этот файл хранит только незавершенные задачи и фактический статус на сегодня.
> Последнее обновление: 2026-05-01.

## 1. Почему файл переписан

Предыдущая версия была полезной как исторический контекст, но в текущем виде она опасна для исполнения:

1. В ней есть устаревшие факты по runtime-зависимостям (часть уже мигрирована, часть нет).
2. В ней есть противоречия по статусам (например, L-4 одновременно закрыта и фигурирует в активной волне).
3. В ней нет жесткого критического пути с измеримыми gate-критериями по каждой волне.

Итог: такой документ провоцирует неверный порядок работ и регрессии.

## 2. Проверенный текущий статус (факты)

### 2.1. Что реально сделано

1. L-4 выполнена: `src/infrastructure/legacy/` отсутствует.
2. L-2 выполнена частично и стабилизирована.
3. Есть `WebApiBootstrap` и `StreamingRequestBootstrap`.
4. Front controller использует новые bootstrap-классы для API path.
5. `status`-команда использует `StreamingRequestBootstrap::init('status')`.
6. `src/www/init.php` и `src/www/stream/init.php` пока остаются compatibility-слоем.
7. L-3R закрыта: `AdminTableController` больше не подключает procedural `table.php` напрямую.

### 2.2. Что все еще блокирует удаление `src/www/`

| Зона | Факт | Риск |
| --- | --- | --- |
| Nginx MAIN | `src/bin/nginx/conf/nginx.conf` использует `root /home/xc_vm/www/` и rewrite на `www/*.php`/`www/stream/*.php` | Нельзя удалить `www` без outage |
| Nginx LB | `lb_configs/nginx.conf` использует `root /home/xc_vm/www/` и rewrite на `/stream/*.php` | LB останется привязан к `www` |
| Certbot | `src/cli/Commands/CertbotCommand.php` использует `--webroot -w /home/xc_vm/www/` | Поломка issue/renew при cutover |
| Ministra runtime | `src/ministra/portal.php` использует `StreamingRequestBootstrap::init('portal')` напрямую | ~~Закрыто L-6~~ |
| Ministra lifecycle | `RootSignalsCronJob` и `SettingsService` больше не управляют symlink `www/c` и `www/portal.php` | ~~Закрыто L-6~~ |

### 2.3. Модули: состояние интеграции

1. `ModuleLoader` реализован, но web boot модулей в front controller не подключен.
2. В web-контексте нет вызова `loadAll() + bootAll(...)` до dispatch.
3. Маршрут `modules` хардкодится в `src/public/routes/admin.php`.
4. Пункт `Modules` хардкодится в `src/public/Views/admin/header.php`.
5. В `module.json` фактически только базовые поля (`requires_core` без v2-метаданных).
6. `CoreCodePatchableModuleInterface` и `CoreCodePatcher` остаются рабочим механизмом (временный stopgap не выведен из эксплуатации).

### 2.4. Статус L-3R и остаточный долг

1. Маршрут `table` идет напрямую в `TableController`, без дополнительной прослойки `AdminTableController`/`AdminTableRenderer`.
2. `public/Views/admin/table.php` удален, procedural endpoint временно размещен в `TableController::index()`.
3. Вспомогательная прослойка `AdminTableRequestContext` удалена как лишняя после консолидации.

Вывод: L-3R закрыта, L-3D в прогрессе. Основной остаточный долг: декомпозиция query/branch-логики.

## 3. Активный backlog (приоритизирован)

| ID | Приоритет | Задача | Блокер | Definition of Done |
| --- | --- | --- | --- | --- |
| ~~L-3D~~ | ~~P2~~ | ~~Декомпозировать procedural admin table endpoint~~ | ~~Нет~~ | **Закрыто.** `TableController::index()` — thin switch-dispatcher. 45 веток → private-методы. `filterRow` → `private static`. |
| ~~L-5~~ | ~~P0~~ | ~~Cutover HTTP routing и certbot c `www`~~ | ~~Нет~~ | **Закрыто.** Nginx не роутит в `www/*.php`; certbot не использует `/home/xc_vm/www/`. |
| ~~L-6~~ | ~~P0~~ | ~~Развязка Ministra от `www/c` и `www/portal.php`~~ | ~~L-5~~ | **Закрыто.** `portal.php` использует `StreamingRequestBootstrap::init('portal')`. Symlink-операции удалены из `RootSignalsCronJob` и `SettingsService`. |
| M-1 | P1 | Включить web boot модулей | Нет | В web-контексте реально вызываются `loadAll()` и `bootAll()` |
| M-2 | P1 | Убрать хардкод модульных маршрутов и меню | M-1 | Ядро не содержит статических route/menu для модулей |
| M-3 | P1 | Ввести navbar extension points | M-2 | Модули добавляют навигацию декларативно |
| M-4 | P1 | Manifest v2 и порядок загрузки | M-1 | Поддерживаются `environment`, `dependencies`, `has_navbar`, `has_settings` |
| M-5 | P2 | Убрать core patching как основной путь расширения | M-2, M-3 | Новые модули не используют patching для core/public |
| M-6 | P2 | Перевести Ministra в модульные runtime/assets правила | L-6, M-4 | Ministra не отдельный legacy-остров |
| L-7 | P0 | Финальное удаление `src/www/` | L-5, L-6 | В репозитории нет `src/www/`; smoke-check чистый |

## 4. Критический путь (обязательный порядок)

1. L-5
2. L-6
3. M-1
4. M-2
5. M-3 и M-4 (параллельно после M-2/M-1)
6. M-5
7. M-6
8. L-7

Запрет: удалять `src/www/` до закрытия L-5 и L-6.

## 5. План исполнения по волнам

## Волна A - Infra cutover (`www` как runtime должен перестать быть обязательным)

### L-5. HTTP + certbot cutover

Изменения:

1. Перевести rewrite/location в `src/bin/nginx/conf/nginx.conf` с прямых `www/*.php` на front controller или новые owner endpoints.
2. Синхронизировать эквивалентные правки в `lb_configs/nginx.conf`.
3. Вынести certbot webroot из `/home/xc_vm/www/` в техническую директорию (`certbot-webroot`) и обновить `CertbotCommand`.
4. Проверить, что legacy rewrite больше не указывают на `www/playlist.php`, `www/epg.php`, `www/player_api.php`, `www/probe.php`, `www/stream/*.php`, `www/admin/*.php`.

Проверка:

1. `nginx -t` для MAIN и LB.
2. smoke API: `player_api`, `epg`, `playlist`, `enigma2`, `probe`.
3. smoke streaming: `live`, `vod`, `timeshift`, `subtitle`, `thumb`, `auth`, `key`, `segment`.
4. dry-run certbot и реальный renew path.

Rollback:

1. Вернуть предыдущие nginx-конфиги.
2. Вернуть старый certbot webroot.

## Волна B - Ministra развязка

### L-6. Убрать файловые symlink-зависимости от `www`

Изменения:

1. Убрать прямой `require '/home/xc_vm/www/stream/init.php'` в `src/ministra/portal.php`; перейти на новый bootstrap path.
2. Удалить логику создания/удаления `www/c` и `www/portal.php` из `RootSignalsCronJob` и `SettingsService`.
3. Перенести MAG legacy redirect в nginx/location/alias или модульный runtime path без файловых операций в `www`.

Проверка:

1. `/c/portal.php` работает.
2. MAG redirect работает при `mag_legacy_redirect=1`.
3. Переключение настройки не создает/удаляет объекты внутри `www`.

Rollback:

1. Временно вернуть старую symlink-схему через сигнал.
2. Сохранить предыдущее поведение до следующего релиза.

## Волна C - Модульный cutover

### M-1. Web boot модулей

Изменения:

1. Подключить `ModuleLoader` в web flow до dispatch.
2. Вызывать `loadAll()` и `bootAll($container, $router)` в корректной точке инициализации.

Проверка:

1. Маршруты модулей реально регистрируются без ручного route-хардкода.
2. Event subscribers модулей реально подписаны.

### M-2. Удалить хардкод module routes/menu в ядре

Изменения:

1. Убрать статический `modules` route из `src/public/routes/admin.php`.
2. Убрать статический пункт `Modules` из `src/public/Views/admin/header.php`.
3. Обеспечить добавление через модульный контракт.

Проверка:

1. Отключение модуля не требует правки ядра.
2. Включение модуля добавляет route/menu автоматически.

### M-3. Navbar extension points

Изменения:

1. Ввести контракт/DTO для navbar entries.
2. Добавить builder в core/http слой.
3. Перевести текущие модульные пункты на декларативный путь.

### M-4. Manifest v2 + dependency sort

Изменения:

1. Расширить `module.json` до полей: `environment`, `dependencies`, `has_navbar`, `has_settings`.
2. Добавить сортировку загрузки по зависимостям.
3. Добавить fail-fast при циклических зависимостях.

Проверка M-3/M-4:

1. Модуль с unmet dependency не загружается и дает явную ошибку.
2. Порядок boot детерминирован и повторяем.

### M-5. Вывод CoreCodePatcher из основного пути расширения

Изменения:

1. Для текущих модулей заменить patching на новые hook points.
2. Ограничить patching как временный legacy-only путь.

Проверка:

1. Новые модули проходят review без `CoreCodePatchableModuleInterface`.

### M-6. Ministra как модуль

Изменения:

1. Перевести runtime/assets Ministra на модульные правила.
2. Исключить отдельный legacy owner path.

Проверка:

1. Ministra включается/отключается через модульный lifecycle, без вмешательства в `www`.

## Волна D - Финальное удаление `src/www/`

### L-7. Удаление compatibility-слоя

Изменения:

1. Один релиз держать `www` в compatibility-only режиме.
2. Подтвердить, что продовый трафик ушел со старых URL.
3. Удалять по порядку: `www/admin/*`, `www/stream/*`, затем корневые legacy endpoints.
4. Удалить директорию `src/www/`.

Проверка:

1. Нет call sites на `src/www/**`.
2. smoke-check по всем контурам зеленый.
3. `php src/console.php --list` и критичные cron-команды работают.

Rollback:

1. В рамках релиза вернуть compatibility layer из release branch.

## 6. Обязательная smoke-check матрица

| Контур | Минимум проверки |
| --- | --- |
| bootstrap | admin login, reseller login, player login, `console.php --list` |
| API | `player_api`, `epg`, `playlist`, `enigma2`, reseller AJAX |
| streaming | live, vod, timeshift, subtitle, thumb, auth, key, segment |
| Ministra | `/c/portal.php`, MAG redirect, portal auth |
| infra | `nginx -t`, nginx reload, certbot issue/renew |

## 7. Gate-критерии между волнами

1. Gate A (после L-5): нет nginx rewrite в `www/*.php`; certbot не использует `/home/xc_vm/www/`.
2. Gate B (после L-6): Ministra работает без symlink в `www`.
3. Gate C (после M-4): web boot модулей активен, route/menu модулей не хардкодятся.
4. Gate D (после L-7): `src/www/` отсутствует, smoke-check полностью зеленый.

## 8. Текущее целевое окно работ

### Итерация 1 (сейчас)

1. ~~Закрыть L-5 полностью.~~
2. Подготовить PR только по infra/certbot cutover.
3. Отдельно прогнать smoke-check и зафиксировать отчёт.

### Итерация 2

1. ~~Закрыть L-6 (Ministra отвязка от `www`).~~
2. Перейти к модульной волне M-1/M-2.

## 9. Жесткие правила удаления

Любой legacy-файл удаляется только если одновременно выполнены все условия:

1. Есть новый владелец или прямой заменяющий path.
2. Все call sites переключены.
3. Smoke-check пройден.
4. Есть рабочий rollback на один релиз.

Если хотя бы одно условие не выполнено, удаление запрещено.
