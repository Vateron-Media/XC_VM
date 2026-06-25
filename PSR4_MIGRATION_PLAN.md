# План: миграция на PSR-4 + Composer-автолоадер (инкрементально)

## Контекст (зачем)

`src/autoload.php` (`XC_Autoloader`) резолвит **глобальные** имена классов, сканируя
`core/domain/infrastructure/streaming/modules/public`, строит карту класс→файл и
кеширует её через **igbinary** (`tmp/cache/autoload_map`) с логикой chown под root
и записью на shutdown. Этот кеш хрупкий: требует расширение igbinary, ломал PHPStan
(полные пересканы → OOM), непрозрачен.

**Цель:** заменить на **PSR-4 автолоадер без кеширования классов**. В PSR-4 namespace
кодирует путь → резолв это прямой `file_exists` на класс, без скана и persistent-кеша.
Проект уже начал движение в эту сторону (`XcVm\Module\*` у 4 модульных классов).

**Утверждённые решения:** инкрементально (сначала фундамент, потом слой за слоем);
Composer с **обычным PSR-4** (без оптимизированной classmap → без кеша); переименовать
7 нижних верхнеуровневых каталогов в PascalCase (`core→Core` и т.д. — подкаталоги/файлы
уже PascalCase); корневой namespace `XcVm\`. Итог: стандартный автолоадер без кеша;
легаси `XC_Autoloader` остаётся как fallback без кеша на время миграции, удаляется в конце.

## Блокеры (обязательны до старта Фазы 1)

> По итогам ревью командой агентов (technical-auditor, solution-architect, devops-lead,
> security-architect, qa-lead). Два пункта — security-VETO; без них Фазу 1 запускать нельзя.

1. **🔴 CRITICAL — утечка привилегированного кода на LB-серверы.** `Makefile` `LB_DIRS_TO_REMOVE`
   (9 путей) и `LB_FILES_TO_REMOVE` (15 файлов) содержат lowercase-пути (`domain/User`,
   `public/Controllers/Admin`, `public/Controllers/Reseller`, `cli/Commands/*`, `cli/CronJobs/*`,
   `public/Controllers/Api/*`, `domain/Epg/EPG.php`). После `git mv` в PascalCase `rm -rf` молча
   промахивается (exit 0) → Admin/Reseller-контроллеры, auth/user-домен, install-команды и
   `RootMysqlCronJob` попадают в LB-архив (DMZ, internet-facing) = раскрытие исходников +
   привилегированные endpoints. **Правки Makefile обязаны быть в ТОМ ЖЕ коммите, что и `git mv`.**
2. **🟠 HIGH — `vendor/` в git без `composer audit`.** Нет `composer.lock`, нет audit-шага в CI →
   уязвимые зависимости (включая bundled M3uParser/PhpM3u8, парсят пользовательский ввод) не
   детектируются. Закоммитить `composer.lock` + добавить `cd src && composer audit --no-dev` в CI.

## Факты, определяющие план

- 707 PHP-файлов; 661 глобальных, 46 в namespace (вендорные `M3uParser`,
  `Chrisyue\PhpM3u8` + 4 `XcVm\Module\*`). ~6439 вызовов `Class::`, 292
  extends/implements, 31 строковый `class_exists`, ~25 `::class` (безопасны).
- ~190 процедурных файлов в `public/` + bootstrap-склейка/конфиги/шаблоны (всего 236) — **не**
  автолоадятся. Глобальные функции: кандидатов для `autoload.files` **больше 20** — точный список
  требует ручного аудита, т.к. многие файлы это view-шаблоны и точки входа (НЕ для `files`); в
  `autoload.files` идут только файлы с чистыми глобальными функциями **без side-effects** (без
  `echo`/`print`/`header()`/`$_GET`/`$_POST`/`$_SESSION`/DB). Список фиксируется явно в PR Фазы 0.
- **Хардкод-пути `require/include MAIN_HOME . 'core/...'` (и др. lowercase-каталоги) — НЕ только в
  `bootstrap.php`:** 9 файлов с ~41 живым оператором — `bootstrap.php` (10),
  `infrastructure/bootstrap/WebApiBootstrap.php` (9),
  `infrastructure/bootstrap/StreamingRequestBootstrap.php` (6), `core/Backup/BackupService.php` (5),
  `public/progress/index.php` (3), `cli/CronJobs/CacheEngineCronJob.php` (2),
  `streaming/StreamingBootstrap.php` (1), `domain/Stream/StreamService.php` (1),
  `core/Http/RequestGuard.php` (1), `cli/CronJobs/EpgCronJob.php` (1). **PHPStan их НЕ ловит**
  (конкатенация константы с литералом) → падают только в рантайме после переименования (Фаза 1).
- 5 файлов с несколькими классами надо разделить (PSR-4): `Core/Parsing/XmlStringStreamer.php` (7),
  `Core/Storage/DropboxClient.php` (2) и 3× `Public/Controllers/Api/*` —
  `AdminApiController.php` (2), `Enigma2ApiController.php` (2), `ResellerRestApiController.php` (2).
- Два динамических «хаба» ломаются под PSR-4: `console.php` (glob + basename==classname; вдобавок
  ручной `require_once cli/CommandInterface.php`/`CommandRegistry.php` до autoload и проверка
  **глобальной строкой** `implementsInterface('CommandInterface')`) и `ModuleLoader` — резолв
  **двухуровневый**: flat `modulePath/Short.php` + one-level glob `modulePath/*/Short.php` (отсюда
  риск тихих коллизий коротких имён между модулями). Также `resolveClassName()` имеет ветку
  `overrides[$name]['class']`, дополняющую namespace, если класс задан без `\` — перед Фазой 2
  проверить конфиги `modules.php` на кастомные классы без FQN.
- Текущий `XC_Autoloader::registerDirectories()` сканирует **6** каталогов
  (core/domain/infrastructure/streaming/modules/public), **`cli/` НЕ сканируется** (CLI-классы
  грузит `console.php` вручную). Composer-маппинг `Cli/` в Фазе 0 впервые добавит cli в автолоадер.
- `python3 install` распаковывает архив **целиком** (`tar/zip extractall` в `/home/xc_vm/`),
  хардкод-путей к lowercase-каталогам НЕ содержит, chown рекурсивный → переименование (Фаза 1)
  проходит для install-скрипта прозрачно.
- ioncube `\XC_VM` + `ioncube_server_data()` — только за guard'ами `class_exists`/
  `function_exists`; ОСТАЮТСЯ глобальными, не трогаем.
- Билд: `Makefile` копирует `src/` через `git ls-files` (переименования = `git mv`
  + коммит); `python3 install` разворачивает в `/home/xc_vm/` **без composer-шага**;
  `deleted_files.txt` управляет чисткой у клиентов; есть PHPStan baseline + CI.
- igbinary используется ТАКЖЕ для кешей настроек/серверов/доменов — не трогаем;
  убираем только class-map кеш автолоадера.

## Сквозные решения

1. **`composer.json` и `vendor/` лежат ВНУТРИ `src/`** (на проде `src/` → `/home/xc_vm/`,
   копируется только содержимое `src/`). То есть `src/composer.json` и `src/vendor/` →
   на проде `/home/xc_vm/composer.json` и `/home/xc_vm/vendor/`. **Коммитим `src/vendor/`
   в git** (на install-пути нет Composer/сети). Обычный PSR-4 → `vendor/` это лишь склейка
   автолоадера Composer + маппинги 2 вендорных либ, без загрузок. `composer dump-autoload`
   запускать **из `src/`** (никогда `install`), только при изменении префиксов/`files`.
   Добавить `vendor` в `LB_DIRS`; `bootstrap.php` → `require __DIR__ . '/vendor/autoload.php'`.
2. **Обычный PSR-4 в dev — без `-o/-a`** → живой резолв пути, без кеша классов. Для prod-сборки
   `composer dump-autoload -o` допустим **опционально** (OPcache нивелирует stat-overhead, но
   classmap полезен на CLI/cron-нодах без OPcache); `-a` (classmap-authoritative) не использовать.
3. **Атомарное переименование каталогов отдельной фазой** до пофайлового namespace.
4. **Подстраховка (fallback):** `XC_Autoloader` остаётся зарегистрированным, но **в конце
   очереди** (prepend=false) и **без кеша**. Composer (впереди) выигрывает для
   мигрированных `XcVm\`-классов; ещё-глобальные проваливаются в рантайм-сканер.
   Каждый класс ровно в одном состоянии — без коллизий. Удаляется в финале.
5. **НЕ добавлять префикс `XcVm\Module\` в composer.json** — каталоги модулей в нижнем
   регистре (`plex`), а slug'и маркетплейса (`watch-d2bho`) не подходят ни под одно
   PSR-4-правило; модули грузит переписанный `ModuleLoader`, не Composer.

## Фазы

### Фаза 0 — Фундамент Composer (без изменения поведения, без переименований)

- **`src/composer.json`** (внутри `src/`, т.к. `src/`→`/home/xc_vm/`): `autoload.psr-4` =
  `{"XcVm\\":"./", "M3uParser\\":"core/Parsing/M3uParser/src/", "Chrisyue\\PhpM3u8\\":"core/Parsing/PhpM3u8/src/"}`
  (пути относительно `src/`; после Фазы 1 — `Core/Parsing/...`); `autoload.files` = 20 файлов
  с глобальными функциями (пути относительно `src/`); `config.optimize-autoloader:false`,
  `classmap-authoritative:false`.
- `cd src && composer dump-autoload`; закоммитить `src/vendor/` **и `src/composer.lock`**.
- **`.gitignore`:** убедиться, что `src/vendor/` НЕ игнорируется (добавить `!src/vendor/` при
  необходимости) — иначе `git ls-files` соберёт архив **без автолоадера** → fatal на всех серверах.
- **`composer.json` `scripts.post-install-cmd`:** предупреждение + `exit 1` (защита от случайного
  `composer install`, который рассинхронит закоммиченный `vendor/`).
- **`phpstan.dist.neon`:** добавить `excludePaths: analyse: [src/vendor/*]` (сейчас исключён только
  M3uParser) — иначе PHPStan начнёт анализировать vendor → CI красный.
- **CI:** добавить шаг `cd src && composer audit --no-dev` (читает `composer.lock`, без `install`);
  side-effect-grep для `autoload.files` (нет `echo`/`print`/`header()`/`$_GET`/`$_POST`/`$_SESSION`/DB).
- `src/bootstrap.php`: сначала `require __DIR__ . '/vendor/autoload.php'`, потом легаси `autoload.php`.
- `tests/bootstrap.php`: тоже сначала `vendor/autoload.php` (иначе тесты идут по старому стеку = false green).
- `src/autoload.php`: регистрация → в конец очереди; **убрать** igbinary-кеш
  (`enableFileCache`/`saveCache`/shutdown/root-chown + нижний вызов `enableFileCache`);
  оставить in-memory сканер. (Удаление root-chown заодно убирает TOCTOU-symlink-риск — security-плюс.)
- **Деплой Фазы 0 на все LB-ноды атомарно ДО** внесения `require vendor/autoload.php` в `bootstrap.php`
  (иначе окно с LB-нодами без `vendor/` → fatal на каждом запросе).
- Проверка: бутятся все контексты; `tmp/cache/autoload_map` больше не пишется;
  Composer-лоадер первым, `XC_Autoloader` последним; `make` smoke (архив содержит `vendor/`);
  PHPStan + PHPUnit зелёные; `composer audit` зелёный.

### Фаза 1 — Атомарное переименование 7 каталогов в PascalCase + последствия

> Вся фаза = **один атомарный коммит** (`git mv` + ВСЕ правки путей/Makefile/PHPStan ниже).
> Двухшаговый подход запрещён: любой промежуточный `make lb` собирает «отравленный» LB-архив (см. блокер 1).

- `git mv`: `core→Core, domain→Domain, infrastructure→Infrastructure, streaming→Streaming, modules→Modules, cli→Cli, public→Public`.
- Поправить захардкоженные пути во **всех 9 файлах с живыми require** (см. секцию «Факты», ~41 оператор),
  не только `bootstrap.php` (10 шт `require_once MAIN_HOME.'core/...'` → `Core/...`; `resources/` остаётся
  lowercase), плюс `autoload.php registerDirectories()`, `console.php` (`Cli/...`), `public/index.php` +
  точки входа.
- `Makefile` — **в этом же коммите**:
  - `LB_DIRS`: PascalCase 7 каталогов + добавить `vendor` (`bin Cli config content Core Domain Modules
    vendor ...`); `config/content/resources/signals` остаются lowercase.
  - `LB_DIRS_TO_REMOVE`: 9 путей → PascalCase (`Domain/User`, `Domain/Device`, `Domain/Auth`,
    `Public/Controllers/Admin`, `Public/Controllers/Player`, `Public/Controllers/Reseller`, `Public/Views`,
    `Public/assets`, `Public/routes`).
  - `LB_FILES_TO_REMOVE`: 15 файлов → PascalCase (`Public/Controllers/Api/AdminApiController.php`,
    `Public/Controllers/Api/ResellerRestApiController.php`, все `Cli/Commands/*`, `Cli/CronJobs/*`,
    `Domain/Epg/EPG.php`).
  - `lb_delete_files_list` awk-фильтр по `LB_DIRS` — проверить согласованность с PascalCase в `deleted_files.txt`.
- `deleted_files.txt`: добавить старые нижнерегистровые пути (`make generate_deleted_files`).
- `phpunit.xml.dist`: coverage-paths → PascalCase.
- PHPStan: PascalCase `paths`/`scanDirectories`/`excludePaths` (+ исключение `PhpM3u8`; `src/vendor/*` уже
  из Фазы 0); перегенерировать `phpstan-baseline.neon` в этом же коммите.
- nginx/web-roots: grep `lb_configs` + `config/*.conf` по 7 именам каталогов
  (особенно docroot на `public/`).
- Проверка: `git status` = чистые переименования; **grep-gate**: 0 живых `require/include` с lowercase-именами
  7 каталогов в путях; все контексты бутятся; `console.php --list` полный; **verify LB-архива** —
  `tar -tzf` НЕ содержит `Domain/Auth|Public/Controllers/Admin|Public/Controllers/Reseller|Cli/CronJobs`;
  архив содержит переименованные каталоги + `vendor/`.

### Фаза 2 — Переписать динамические хабы (корректно под PSR-4)

- `console.php`: discovery по FQCN из пути (`XcVm\Cli\Commands\` . basename / `…\CronJobs\`),
  убрать ручной require `cli/CommandInterface.php`/`CommandRegistry.php` (Composer автолоадит),
  заменить глобальную строку `implementsInterface('CommandInterface')` на
  `implementsInterface(\XcVm\Cli\CommandInterface::class)`. **Обязано** переключаться в ТОМ ЖЕ коммите,
  что и namespace слоя Cli (жёсткое требование — глобальная строка `'CommandInterface'` сломается, как
  только интерфейс уйдёт в namespace; «временный двойной путь» НЕ годится как постоянное состояние).
- `ModuleLoader`: `resolveClassName()` → `XcVm\Module\{Pascal}\{Pascal}Module`; `load()`
  `require_once modulePath/{Pascal}Module.php` затем `class_exists($fqcn,false)`.
  **Переписать `registerModuleAutoloader()`** в настоящий PSR-4-резолвер: снять префикс
  namespace модуля, маппить ОСТАТОК в подпуть под `modulePath`
  (`XcVm\Module\Watch\Service\WatchService` → `modulePath/Service/WatchService.php`) вместо
  потери информации через short-name+glob. Сохранить обработку зашифрованных файлов;
  slug-каталоги маркетплейса не затрагиваются (require использует реальный `modulePath`).

### Фазы 3..N — Пофайловый namespace по слоям

Порядок (от наименее зависимых): **Core leaf** (`Config, Error, Logging, Parsing(не-вендор),
Storage`) → **Core hubs** (`Database, Http, Module, Auth`) → **Domain** → **Infrastructure**
→ **Streaming** → **Cli** (тут переключаем console.php) → **Modules** (подтверждаем новый
ModuleLoader) → **Public последним** (в основном процедурный).

**Known-исключение нарушителя порядка (Core hub → Domain):** `core/Init/LegacyInitializer.php`
вызывает `BouquetService::getAll()`/`CategoryService::getFromDatabase()`, а `core/Auth/Authenticator.php`
и `core/Auth/AuthRepository.php` — `UserRepository`. При миграции Core hubs использовать
`\BouquetService::`/`\UserRepository::` (ведущий `\`) до завершения слоя Domain, затем обновить импорты
на `use XcVm\Domain\...`.

Механический паттерн на файл:

1. Добавить `namespace XcVm\<Layer>\<SubPath>;` (точный регистр PSR-4).
2. Ссылки на **ещё-глобальные** классы → ведущий `\` (`\SettingsManager::`) или
   `use SettingsManager;`. Ссылки на **мигрированные** → `use XcVm\...\Foo;`. Встроенные
   (`\Exception`,`\PDO`,`\ReflectionClass`,`\ZipArchive`) → `\`. ioncube `\XC_VM` → всегда
   `\XC_VM`, никогда не импортировать.
3. Строковый `class_exists('Foo')` → FQCN-строка / `Foo::class` после миграции `Foo`.
   Оставить `'XC_VM'`/`'ZipArchive'`/ext-guard'ы литералами.
4. Разделить multi-class файлы на своём слое; старые пути → в `deleted_files.txt`.
5. **Процедурные/view-файлы (~190 в `public/`)**: НЕ в namespace — когда вызываемый класс мигрирует,
   добавить вверху файла `use XcVm\...\ClassName;`, чтобы существующие вызовы `ClassName::` не
   менялись (минимум правок). После каждого слоя — **автоматизированная** проверка
   `ci/check-procedural-use-statements.sh <классы слоя>` (а не ручной grep): процедурный файл,
   использующий короткое имя мигрированного класса без `use`, валит проверку. Опционально временно
   добавлять `public/Views` в PHPStan `paths` на время слоя для отлова `Class not found`.

### Финальная фаза — Удалить fallback

Все классы в namespace → перестать require'ить `autoload.php`; оставить его no-op заглушкой
на один релиз, затем удалить; остаются только Composer + ModuleLoader.

**Шаг 1 — ВЫПОЛНЕНО (`9b4f59bb`, `bb226682`):** `XC_Autoloader` retired — `init()` больше не
регистрирует SPL-сканер (no-op), `registerDirectories()` удалён. Файл оставлен no-op заглушкой
на один релиз (методы `clearCache()/warmCache()` ещё вызывает `StartupCommand`). В стеке только
Composer; всё резолвится через Composer / explicit-require / ModuleLoader.

**Шаг 2 — ВЫПОЛНЕНО: `src/autoload.php` полностью удалён.** Composer-only автолоад.

- [x] **Перенесён `define('MAIN_HOME', ...)`** в `src/bootstrap.php` (до Composer-require).
- [x] **Убраны** `\XC_Autoloader::clearCache()/warmCache()` из `StartupCommand`.
- [x] **`src/bootstrap.php`** — убран `require autoload.php` (остался только `vendor/autoload.php`).
- [x] **`tests/bootstrap.php`** — убран require + locate-guard переведён на `vendor/autoload.php`.
- [x] **`phpstan.dist.neon`** — `src/autoload.php` убран из `scanFiles`.
- [x] **`Makefile`** — `autoload.php` убран из `LB_ROOT_FILES`.
- [x] **`src/migrations/deleted_files.txt`** — добавлен `autoload.php`.
- [x] **`git rm src/autoload.php`** + `composer dump-autoload` (vendor без изменений — autoload-конфиг
      не менялся).
- [x] **`AutoloadOrderTest`** обновлён: утверждает, что класс `XC_Autoloader` НЕ существует и файла нет.
- [x] **+7 точек входа** (план их упускал, найдены grep'ом): прямые `require .../autoload.php` в
      `Public/index.php`, `Public/admin/index.php`, `Public/stream/index.php`, `Public/progress/index.php`,
      `ministra/portal.php`, `Public/Controllers/{Admin,Reseller}/TableController.php` → переведены на
      `vendor/autoload.php` (+ `define(MAIN_HOME)` где не было).
- [x] **Проверка:** grep `XC_Autoloader::`=0; `php -l`; `make phpstan` No errors; `make gates`;
      PHPUnit 303/303; bootstrap-smoke (MAIN_HOME+XC_Bootstrap есть, XC_Autoloader нет, в стеке только
      Composer).

> Примечание: вся PSR-4 миграция (Фазы 0…Финал) выпускается одним релизом с ветки `chore/psr4-migration`,
> поэтому шаги 1 и 2 свёрнуты в один цикл — отдельный «релиз с заглушкой» не требовался.

## Ключевые файлы

- `src/composer.json` (внутри `src/`, т.к. `src/`→`/home/xc_vm/`), закоммиченные `src/vendor/` + `src/composer.lock`
- `.gitignore` — НЕ игнорировать `src/vendor/`
- `Makefile` — `LB_DIRS`/`LB_DIRS_TO_REMOVE`/`LB_FILES_TO_REMOVE` PascalCase + `vendor` (блокер 1)
- `.github/workflows/*` — `composer audit` + grep-gate hardcoded-путей + verify LB-архива
- `tests/bootstrap.php` — `vendor/autoload.php` первым
- `src/autoload.php` — убрать кеш, в конец очереди, в итоге удалить
- `src/bootstrap.php` — vendor первым; PascalCase в require-путях
- `src/console.php` — discovery по FQCN
- `src/core/Module/ModuleLoader.php` — PSR-4-резолвер модулей (`resolveClassName`, `registerModuleAutoloader`)
- `phpstan.dist.neon` / `phpstan-baseline.neon` — PascalCase пути + `excludePaths: src/vendor/*`, перегенерировать
- `src/migrations/deleted_files.txt` — старые нижнерегистровые пути + разделённые файлы

## Проверка (каждая фаза)

`php -l` изменённых файлов; `make phpstan` зелёный (перегенерация baseline после
переименования/слоёв — должен сокращаться, не должно появляться «unknown class XcVm\...»);
`php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist`; `console.php --list`; бут
CLI/Stream/Admin/Player; загрузка 4 модулей + класс из под-namespace + модульная команда;
`make`-архив содержит `vendor/` + переименованные каталоги; порядок `spl_autoload_functions()`
(Composer первый, fallback последний до финала).

## Тест-план и CI-гейты

Новые автоматизированные гейты (добавлять на указанной фазе, оставлять в CI):

- **Grep-gate (Фаза 1, CI job):** 0 живых `require/include` с lowercase-именами 7 каталогов в путях.
  Добавить ДО Фазы 1 в baseline-режиме (не давать расти), после — ожидаемое значение 0.
- **`tests/Unit/AutoloadOrderTest.php` (Фаза 0):** Composer первым, `XC_Autoloader` последним в
  `spl_autoload_functions()`; `tmp/cache/autoload_map` не пишется.
- **`tests/Unit/BootstrapPathsTest.php` (Фаза 1):** рантайм-guard — ни один `.php` в `src/` не содержит
  `require/include` с lowercase-именем переименованного каталога в пути.
- **`tests/Unit/ModuleLoaderPsr4ResolverTest.php` (Фаза 2):** резолв класса из под-namespace модуля без
  glob; сохранение обработки зашифрованных `.php.enc`.
- **`tests/Unit/ConsoleDiscoveryTest.php` (Фаза 2):** `console.php --list` == baseline-snapshot.
- **`ci/check-procedural-use-statements.sh` (каждый слой 3..N):** процедурные файлы имеют `use` для
  мигрированных классов слоя.
- **`composer audit` (Фаза 0+):** без `install`; side-effect-grep для `autoload.files`.
- **Smoke 4 контекстов (CLI/Stream/Admin/Player):** `php -l` точек входа + проверка порядка автолоадеров.
- **Verify LB-архива (Фаза 1+):** `tar -tzf` НЕ содержит
  `Domain/Auth|Public/Controllers/Admin|Public/Controllers/Reseller|Cli/CronJobs`.

Acceptance каждой фазы: PHPUnit зелёный (тестов не меньше прежнего); `make phpstan` зелёный, baseline не
растёт (0 новых `unknown class XcVm\`); все гейты выше зелёные.

## Риски и откат

- Неверный namespace/неквалифицированная ссылка → ловит PHPStan; fallback не спасёт → откат правок слоя.
- Процедурные файлы тихо ломаются → после слоя grep + добавление `use` + smoke-рендер страниц.
- Билд пропустил переименование → `git mv` + проверка содержимого архива.
- Устаревшие нижнерегистровые каталоги у клиентов → `deleted_files.txt` + проверка обновления на стейджинге.
- Каждая фаза = изолированный коммит/ветка; в git Фазы 0 и 1 откатываются независимо; `main`
  остаётся пригодным к релизу после каждой смерженной фазы. Fallback прикрывает
  ещё-глобальные классы (но не неверно-неймспейснутые).
- **Откат Фазы 1 на уже задеплоенных клиентах ФАКТИЧЕСКИ НЕОБРАТИМ** через обычный `update`: старый
  `core/` уже удалён через `deleted_files.txt`, а revert вернёт `core/`, оставив `Core/` на диске → два
  каталога одновременно → autoload находит классы в обоих → непредсказуемо. Варианты: (а) отдельный
  rollback-архив с обратным `deleted_files.txt` (содержащим `Core/`, `Cli/`, `Domain/`, ...); либо
  (б) явно задокументировать «откат только через новый релиз-fix вперёд». Дополнительно проверить
  `src/update`: удаляет ли он опустевшие каталоги (`rmdir`) — иначе мёртвый `core/` останется; при
  необходимости добавить сами каталоги в `deleted_files.txt`.
- **Supply chain:** `vendor/` в git → обновлять отдельным коммитом с пометкой `[vendor]`; bundled
  M3uParser/PhpM3u8 (path-mapped, без semver) патчить вручную в `Core/Parsing/` с записью в CONTRIBUTING.
