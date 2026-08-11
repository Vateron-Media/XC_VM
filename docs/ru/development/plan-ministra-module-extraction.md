# План выноса Ministra в полноценный модуль

Статус: черновик плана (июль 2026). Цель — довести ministra от «полумодуля»
(логика в `Modules/ministra_85a7d/`, но вход и 50 MB ассетов в `src/ministra/`)
до самодостаточного модуля в отдельном репозитории `Module_Ministra`,
устанавливаемого и обновляемого штатным модульным механизмом.

> **Обновление 2026-08-11 (ветка `refactor/ministra-to-core`): направление
> изменено — ministra перенесена В ЯДРО, а не в отдельный модуль/репозиторий.**
> Этот план (вынос в `Module_Ministra`) тем самым отменён. Сделано:
> `PortalHandler`/`PortalHelpers` + `portal.php`/`MinistraBootstrap` + STB-фронтенд
> переехали в `src/Ministra/` (namespace `XcVm\Ministra`), `MinistraModule.php` и
> `module.json` удалены, запись убрана из `bundled_modules.php`, nginx-alias и
> `#ALIAS#` в `AuthRepository` переведены на `/home/xc_vm/Ministra`, `Modules` убран
> из `LB_DIRS`. Причина разворота: запутанность ministra — это учёт MAG-устройств,
> который и так живёт в ядре (см. раздел «Связи с ядром»), поэтому протокол портала
> логичнее держать рядом, в ядре, а не тащить в отдельный репозиторий.

---

## 0. Текущее состояние

**Код и ассеты:**

| Что | Где | Примечание |
|---|---|---|
| Вход `portal.php` (~1130 строк, процедурный) | `src/ministra/` | nginx исполняет его напрямую через fastcgi (не через front controller) |
| `MinistraBootstrap.php` | `src/ministra/` | обёртка над `StreamingRequestBootstrap::init('portal')` |
| STB-фронтенд: ~84 JS, `template/`, `external/`, `index.html` | `src/ministra/` (~50 MB) | nginx отдаёт как статику через `alias /home/xc_vm/ministra/` |
| `MinistraModule`, `PortalHandler` (вся серверная логика), `PortalHelpers` (мёртвый класс — нигде не вызывается) | `src/Modules/ministra_85a7d/` | module.json: bundled, `environment: main`, миграций нет |

**Связи с ядром:**

- **nginx**: `template_ministra` (кодовые локации → alias на `/home/xc_vm/ministra/`);
  legacy `/c/` и `/portal.php` с toggle `$ministra_legacy_redirect`
  (генерится `RootSignalsCronJob` из настройки `mag_legacy_redirect`).
- **AuthRepository**: типы access-кодов `ministra` и `ministra/new`
  (каталога `ministra/new` на диске **нет** — см. риски).
- **`Public/index.php`**: scopeMap содержит `ministra` / `ministra/new`.
- **Paths**: `MINISTRA_TMP_PATH` (+ чистка в `TmpCronJob`/`CacheCronJob`).
- **БД** (все таблицы в core `database.sql`):
  - `mag_devices` — ядро пишет (админка, реселлер, `MagService`, `LineService`, `UserRepository`) + портал;
  - `mag_events` — админка (`MagEventController`, quick_tools, topbar), `MagService`, reseller API + портал;
  - `mag_claims` — `MagService`, `StreamRepository` + портал;
  - `mag_logs` — только `MagService`/бэкап.
- **Настройки** (core settings + UI в `settings.php`): `disable_ministra`,
  `mag_legacy_redirect`, `enable_debug_stalker`, `stalker_theme`, `mag_default_type`,
  `mag_disable_ssl`, `allowed_stb_types`, `stalker_lock_images`.
- **LB**: nginx на LB отдаёт 404 для portal; `src/ministra` не входит в `LB_DIRS`
  (не шипится на LB). `bundled_modules.php` уже спроектирован под flip
  `bundled → git` по стабильному `hash_id`.

## 1. Границы модуля (ключевые решения)

**Принцип: модуль владеет протоколом портала** (portal.php, PortalHandler,
STB-фронтенд, темы), **ядро владеет учётом MAG-устройств** (это часть
линий/биллинга, используется реселлером и Enigma независимо от портала).

1. Таблицы `mag_devices`, `mag_events`, `mag_claims`, `mag_logs` **остаются в ядре**:
   их пишет админка/реселлер/`MagService`, а правило архитектуры запрещает ядру
   трогать модульные таблицы (обратное — модуль читает core-таблицы — допустимо).
   Следствие: модулю **не нужны** миграции; он чистый потребитель схемы ядра.
2. Настройки остаются в core settings; UI — в `settings.php`
   (опционально позже `has_settings: true` и перенос секции в модуль).
3. Access-code типы `ministra`/`ministra/new` — фича ядра (`AuthRepository`),
   остаются в ядре; при отсутствии модуля кодовая локация отдаёт 404 — приемлемо.
4. `environment: main` сохраняется — на LB портал не работает (как сейчас).

## 2. Фаза A — консолидация кода в модуле (модуль остаётся bundled)

Цель: весь код и ассеты в `Modules/ministra_85a7d/`, ядро ссылается только
на стабильный путь-симлинк.

- **A1.** Перенести `src/ministra/*` → `Modules/ministra_85a7d/www/`
  (portal.php, MinistraBootstrap.php, index.html, JS, template/, external/).
- **A2.** В portal.php подключать `PortalHandler` по `__DIR__` (соседний файл) —
  класс проблем с хеш-каталогом исчезает навсегда (текущий glob-резолв в
  portal.php:20 — временный костыль, фаза A его вытесняет).
- **A3.** Симлинк `/home/xc_vm/ministra → Modules/ministra_<hash>/www`:
  - создаётся в `install()` модуля, удаляется в `uninstall()`
    (через `BaseModule`-hook или логику самого модуля);
  - nginx-конфиги (`template_ministra`, `/c/` alias) **не меняются**;
  - проверить `disable_symlinks` в бандловом nginx (по умолчанию off — ок).
- **A4.** `index.php` scopeMap и `AuthRepository` — без изменений (путь сохранён симлинком).
- **A5.** `MinistraModule::getEntryPoint()` — привести к фактическому пути.
- **A6.** Разобрать мёртвый `PortalHelpers`: либо удалить, либо (рекомендуется)
  перенести в него глобальные функции из portal.php (`getDevice`, `getItems`,
  `updateCache`, `getEPG`, `shutdown`, …) статическими методами — portal.php
  становится тонкой обёрткой. Это же снимет конфликт имён при повторном require.
- **A7.** Гейты: `check-procedural-use` для перенесённых файлов (use-импорты сверху),
  `php -l`, `make gates`.

## 3. Фаза B — развязка с ядром

- **B1.** Поведение при выключенном/удалённом модуле: симлинк снят → nginx 404
  на статику и portal.php; проверить, что fallback `@fc_CODE` не даёт 500.
  Настройка `disable_ministra` продолжает работать как программный выключатель.
- **B2.** `MINISTRA_TMP_PATH` остаётся в core `Paths` (константа безвредна при
  отсутствии модуля, чистку в кронах сохраняем). Рефакторинг «модуль регистрирует
  свои tmp-пути» — не обязателен для v1, отметить как tech debt.
- **B3.** События: пока модуль не владеет таблицами — событийная чистка не нужна.
  Если появятся модульные таблицы — только через `#[ListensTo]` (см. правило ядра).
- **B4.** `bundled_modules.php` — на этой фазе остаётся `bundled`.

## 4. Фаза C — вынос в отдельный репозиторий Module_Ministra

- **C1.** Репозиторий `Vateron-Media/Module_Ministra` по образцу `Module_Watch`:
  - `module.json` **с блоком `update`** (git, stable) — с первого релиза;
  - `changelog.json`;
  - CI-релиз: ассеты `module.tar.gz` + `hashes.md5`;
  - бинарники/шрифты/видео из `external/`, `template/` — Git LFS в репо модуля;
  - проверить размер `module.tar.gz` (~50 MB несжатых ассетов).
- **C2.** `module.json`: bump версии (1.1.0), update-блок.
- **C3.** Панель: flip записи в `bundled_modules.php` (hash_id `85a7d…`) на
  `source: git` + repository — `syncBundledModules()` начнёт ставить модуль
  с GitHub на fresh install / `console.php status`.
- **C4.** Удалить из панели `src/Modules/ministra_85a7d` и `src/ministra`
  (**только после** публикации релиза модуля).
- **C5.** Обновление существующих инсталляций (порядок критичен):
  1. апдейт панели не должен ломать работающий портал до установки модуля;
  2. если `Modules/ministra_*` уже есть локально — не трогать, иначе
     `syncBundledModules()` тянет с GitHub;
  3. legacy-каталог `/home/xc_vm/ministra` удалять **только после** успешной
     установки модуля и создания симлинка (в каталоге нет данных — только
     код/ассеты; сессии устройств живут в `MINISTRA_TMP_PATH`).
- **C6.** GitHub rate-limit: установка/обновление без токена упирается в
  60 req/h на IP (уже наблюдалось на боевом сервере). Завести настройку
  `github_token` и пробрасывать её в `GitHubReleases` (конструктор уже принимает).

## 5. Фаза D — сборка и инфраструктура

- **D1.** Makefile: после C4 `src/ministra` исчезает из main-архива сам;
  **аудит `LB_DIRS`** — комментарий говорит «Modules/ intentionally excluded»,
  но список фактически содержит `Modules` (строка 26) — выяснить и починить
  расхождение отдельным фиксом.
- **D2.** `grep -r ministra` по `install/`, `update/`, `tools/` — не должно
  остаться ссылок на старую раскладку.
- **D3.** Гейты: `verify-lb-archive`, `check-procedural-use` — зелёные.

## 6. Тест-план / приёмка

Матрица:

- fresh install (модуль приходит через `syncBundledModules` с GitHub);
- update со старой версии: legacy `/home/xc_vm/ministra`, legacy bare
  `Modules/ministra` (без хеша), текущий `ministra_85a7d`;
- портал: handshake → get_profile → авторизация MAG → live/vod/series/EPG/
  tv_archive/radio/watchdog/account_info;
- legacy `/c/` и `/portal.php` при обоих значениях `mag_legacy_redirect`;
- оба типа кодов (`ministra`, `ministra/new`);
- uninstall/reinstall модуля (симлинк создаётся/удаляется);
- обновление модуля кнопкой Update (git-релиз) и кроном `cron:module_updates`;
- **регрессия ядра при ВЫКЛЮЧЕННОМ модуле**: страницы mags/mag_events админки,
  reseller MAG flows, Enigma — учёт устройств не зависит от портала;
- LB не затронут (portal 404, архив без модуля).

## 7. Риски

| Риск | Митигация |
|---|---|
| Симлинк + nginx alias (права, `disable_symlinks`, open_basedir) | проверить на стейдже; fallback — копирование `www/` в `/home/xc_vm/ministra` при install (дороже на диск) |
| 50 MB релизный ассет тянется при каждом install/update | кэш архивов `storeModuleArchive` уже есть; LFS в репо модуля |
| Тип кода `ministra/new` без каталога на диске — похоже, сломан уже сейчас | отдельный аудит: починить или удалить тип |
| OPcache держит старый portal.php после переноса | reload PHP-FPM в шаге обновления (RootSignals) |
| Кастомные темы пользователей внутри `/home/xc_vm/ministra` пропадут при удалении каталога | предупреждение в release notes + бэкап каталога в update-скрипте |
| GitHub API 403 при установке | C6 (`github_token`) |

## 8. Порядок релизов

- **Release N** — фазы A+B: модуль самодостаточен, но всё ещё bundled; симлинк;
  раскладка новая, источник старый.
- **Release N+1** — фазы C+D: вынос в репо, flip на git, удаление из панели.

Разнесение по двум релизам разделяет два рискованных изменения (смена раскладки
и смена источника) и даёт точку отката: если git-дистрибуция забуксует,
Release N полностью рабочий.
