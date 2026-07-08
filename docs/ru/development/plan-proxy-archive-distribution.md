# План: вынос proxy.tar.gz из Git LFS в релиз-ассеты XC_VM_Proxy

Статус: **черновик, прошёл ревью-панель** (solution/security/devops/qa, 2026-07-08);
схема уточнена под install-time fetch + индекс + крон (паттерн MaxMind)
Дата: 2026-07-08
Связанные репозитории: `Vateron-Media/XC_VM`, `Vateron-Media/XC_VM_Proxy`

---

## 1. Контекст и проблема

Релизная сборка 2.3.5 упала на `git lfs fetch`: репозиторий исчерпал месячную
квоту LFS-трафика (10 ГБ). В XC_VM сейчас 365 МБ в 14 LFS-файлах; каждый
CI-checkout с `lfs: true` и каждый пользовательский клон с установленным Git LFS
списывает эти 365 МБ с квоты — итого ~27 полных загрузок в месяц до блокировки.

`src/bin/install/proxy.tar.gz` (56 МБ) — второй по величине LFS-объект и
единственный, который является **производным артефактом другого нашего
репозитория** (XC_VM_Proxy), а не первичным содержимым. Его вынос — первый и
самый безопасный шаг снижения LFS-нагрузки; ffmpeg/redis (~300 МБ) — отдельный
этап вне рамок этого плана (см. §9).

**Масштаб честно:** 56 из 365 МБ ≈ 15% на каждый клон/CI-checkout. Этот план **не
закрывает квоту** — это пилот паттерна «релиз-ассет вместо LFS», реальный выигрыш
(~85%) даёт §9. **Текущая блокировка снята (2026-07-08): релиз сделан, файлы есть
локально** — ждать сброса квоты не требуется. Этот рефактор — средне-срочный трек
качества доставки, а не срочный unblock.

## 2. Как это работает сейчас

1. В `XC_VM_Proxy` лежат исходники прокси-ноды (`src/`, 161 МБ: бандл PHP 148 МБ,
   nginx 14 МБ) обычными git-файлами. CI и тегов нет.
2. Мейнтейнер локально запускает `make`: копирование `src/` → выставление прав →
   `tar -czf` → `dist/proxy.tar.gz`.
3. Артефакт вручную коммитится в XC_VM как LFS-файл `src/bin/install/proxy.tar.gz`
   и попадает в панельный архив (`make main`), т.е. разъезжается по всем MAIN-серверам.
4. Потребитель один: `console.php` → `Cli/Commands/ServerInstallCommand.php`
   (ветка `proxy`, строки ~77–138) → `ProxyInstallFlow::installArchive()`:
   файл `BIN_PATH . 'install/proxy.tar.gz'` отправляется по SSH на прокси-ноду
   (с проверкой MD5 внутри `$rSendFileSSH`) и распаковывается в `MAIN_HOME`.

Существующая инфраструктура, которую можно переиспользовать:

- `Core/Updates/GitHubReleases.php` — обёртка над GitHub Releases API
  (список релизов, `getAssetHash($version, $asset)`, кэш, каналы stable/unstable,
  скачивание через cURL — обязательное требование окружения).
- `LbInstallFlow.php:264–281` — готовый паттерн: удалённая нода сама делает
  `curl` `releases/latest` → скачивает ассет → сверяет `hashes.md5`.
- `BinariesCommand` + `bin/install/update_binaries.sh` — обновление бинарников
  из `GIT_REPO_BIN` с версионным файлом (`bin_version.json`) и откатом.
- **`MaxMindCronJob` + `MaxMindUpdater` + `bin/maxmind/version.json`** — прямой
  прообраз этого плана: GeoLite не в LFS, качается при установке и обновляется
  кроном `cron:maxmind` (seed в `crons`: `('maxmind','0 4 * * 2',1)`; миграция
  `002_add_maxmind_cron.sql`), индекс-файл хранит версию+хэш. Копируем один-в-один.
- Константы в `Core/Config/AppConfig.php:35–38`: `GIT_OWNER`, `GIT_REPO_MAIN`,
  `GIT_REPO_UPDATE`, `GIT_REPO_BIN`.

## 3. Цели

- Релизные сборки XC_VM и клоны репозитория не тратят LFS-трафик на proxy.tar.gz.
- Артефакт прокси собирается воспроизводимо в CI XC_VM_Proxy и версионируется
  независимо от панели.
- Установка прокси-ноды продолжает работать по прежнему UX
  (`console.php` → установка сервера типа proxy) без деградации надёжности
  (контроль целостности сохраняется или усиливается).
- **Проактивная свежесть**: proxy.tar.gz качается при установке панели и
  поддерживается свежим кроном (как GeoLite), с индекс-файлом версии+хэшей →
  установка ноды почти всегда идёт из локального свежего кэша, в т.ч. оффлайн.

### Не-цели

- Вынос ffmpeg/ffprobe/redis-server из LFS (отдельный план, §9).
- Изменение содержимого/структуры самого proxy.tar.gz.
- Автообновление уже установленных прокси-нод.

## 4. Целевая архитектура (install-time fetch + индекс + крон)

Схема **зеркалит проверенный в проде паттерн GeoLite/MaxMind** (`.gitattributes`:
«GeoLite2 … fetched at install time instead»; `MaxMindCronJob` + индекс
`bin/maxmind/version.json`). Тот же паттерн `bin_version.json` уже используют
бинарники (`BinariesCommand`/`update_binaries.sh`). Для proxy.tar.gz — один-в-один.

```
XC_VM_Proxy (release X.Y.Z, CI)
  └─ ассеты: proxy.tar.gz + hashes.md5 + hashes.sha256
        │  (скачивание релиз-ассетов GitHub не тарифицируется)
        ▼
MAIN-сервер — единый источник свежести: ProxyArchiveUpdater
  A. УСТАНОВКА ПАНЕЛИ  → ensure(): скачать latest proxy.tar.gz, сверить хэш,
     положить в bin/install/proxy.tar.gz, записать индекс
     bin/install/proxy_version.json = {version, md5, sha256}
  B. КРОН cron:proxy (периодически, как cron:maxmind) → сравнить индекс с
     latest-в-канале на GitHub; новее/хэш изменился → перекачать + переписать
     индекс; иначе SKIP. Держит MAIN-копию всегда свежей проактивно.
  C. УСТАНОВКА ПРОКСИ-НОДЫ (ServerInstallCommand, ветка proxy) → файл уже локально
     и свеж (из A/B); тонкая ensure()-проверка (self-heal если пропал) →
     SendFileSSH (MD5) → tar на ноде. Сеть на шаге C обычно НЕ нужна.
```

Ключевые свойства:

- **Индекс-файл** `bin/install/proxy_version.json` — watermark версии+хэшей,
  зеркало `bin/maxmind/version.json`. По нему крон решает «качать или SKIP», а
  установка ноды — «файл актуален».
- **Проактивная свежесть:** крон обновляет MAIN-копию заранее → установка
  прокси-ноды почти всегда cache-hit, работает и оффлайн.
- **Один загрузчик:** `ProxyArchiveUpdater` — единственное место скачивания/сверки/
  записи индекса; и install-hook, и крон, и ServerInstallCommand зовут его же
  (никакой дубликации, как у MaxMind).
- Доставку на ноду не меняем (вариант «нода сама качает с GitHub», как у LB,
  отвергнут: прокси-ноды могут не иметь доступа к GitHub, а канал MAIN→нода
  уже есть и проверяется по MD5).

### Отклонённая альтернатива (загрузка на этапе сборки панели)

Релизный workflow XC_VM скачивает proxy.tar.gz в `src/bin/install/` перед
`make main`, артефакт остаётся в панельном архиве. Минусы: панельный архив
не худеет; сборка копирует только git-tracked файлы — нужен костыль в Makefile;
версия прокси жёстко пришивается к релизу панели. Отклонено.

## 5. Этапы работ

> **Черновики для XC_VM_Proxy готовы** (перенести в тот репозиторий): чистый
> `Makefile` (детерминированный tar, `hashes.md5`+`hashes.sha256`, `version.json`
> без wall-clock, `verify_no_lfs_pointers`, `check-reproducible`) и
> `.github/workflows/build-release.yml` (draft→ассеты→undraft, bare-semver guard).
> Они закрывают задачи Этапов 0–1 ниже; чекбоксы Этапа 0/1 остаются открытыми,
> пока файлы не закоммичены в XC_VM_Proxy и не собран первый релиз `1.0.0`.
> Раздел `set_permissions` в Makefile — с дефолтными правами, **сверить с реальным
> деревом** (или портировать 8 строк старого Makefile, заменив `\ `→`\;`).

### Этап 0 — XC_VM_Proxy: починка Makefile (нужно при любом варианте)

**Баг:** во всех строках `find … -exec chmod … {} \ 2>/dev/null || [ $$? -eq 1 ]`
стоит `\ ` (экранированный пробел) вместо `\;` → find завершается ошибкой
«missing argument to -exec» (код 1), которую `|| [ $$? -eq 1 ]` молча глотает.
**Ни один find-овый chmod фактически не выполняется**; работают только прямые
`chmod`. Архив «работает» лишь потому, что git сохраняет execute-биты.

Задачи:

- [ ] Исправить `\ ` → `\;` во всех `find -exec` (8 строк в `set_permissions`).
- [ ] Убрать маскировку ошибок: `|| [ $$? -eq 1 ]` заменить точечными
      `2>/dev/null || true` только там, где путь действительно опционален,
      и зафиксировать список опциональных путей комментарием.
- [x] Права в текущем прод-архиве изучены (`tar -tvzf proxy.tar.gz`): **519 файлов
      и 108 каталогов = 0777** (world-writable — find-exec не отработал, легли git-биты);
      «осмысленный» слой: `config/`+`bin/`+`bin/php/{etc,sessions,sockets}/` = `0750`,
      `bin/php/bin/php`+`bin/php/sbin/php-fpm` = `0551`, `server.crt/key` = `0755`.
      **Решение (заложено в новый `set_permissions`): нормализовать** → `0755` dirs /
      `0644` files, приватные рантайм-каталоги `0750`, реальные бинарники (php-toolchain,
      php-fpm, nginx, `service`, `*.sh`) `0755`, `server.key` → `0640`. Строго безопаснее
      0777, функционально эквивалентно (стек работает под owner=xc_vm). **Осознанный дифф**
      к прод-архиву: снятие world-write/world-exec — сверить `tar -tvf` old/new при первом релизе.
- [ ] Генерировать `hashes.md5` (переменная `HASH_FILE` объявлена, но не
      используется): `md5sum proxy.tar.gz > dist/hashes.md5` — формат тот же,
      что потребляет `LbInstallFlow`/`GitHubReleases::getAssetHash` (`<hash>␠␠<name>`).
      Дополнительно генерировать `dist/hashes.sha256` (`sha256sum`) — почти бесплатно,
      MD5 крипто-сломан на коллизии, и тот же формат понадобится для §9.
- [ ] **Детерминированный tar** (иначе «побайтно эквивалентный» архив в этапе 1
      недостижим — `tar -czf` по умолчанию пишет mtime/порядок/gzip-таймстамп):
      `LC_ALL=C tar --sort=name --numeric-owner --owner=0 --group=0 --mtime=<фикс> -cf - … | gzip -n`.
      `LC_ALL=C` обязателен — `--sort=name` locale-зависим; раннер закрепить на
      **GNU tar** (Ubuntu; BSD/macOS tar не знает `--sort`/`--owner`). Если пишем
      `version.json` — только git tag + git sha, **без `date()`/wall-clock**, иначе
      он сам ломает детерминизм. Без этого каждая CI-пересборка даёт новый хэш.
- [ ] `git describe --tags` сейчас падает (тегов нет) — после появления тегов
      (этап 1) записывать версию внутрь архива (например, `version.json`),
      чтобы нода знала, что на ней установлено.

### Этап 1 — XC_VM_Proxy: CI и релизы

- [ ] Workflow `.github/workflows/build-release.yml`:
      триггеры `release: [released, prereleased]` + `workflow_dispatch(tag_name)`
      (зеркально основному репо); шаги: checkout (LFS не нужен) → `make` →
      `md5sum`/`sha256sum` → upload ассетов `proxy.tar.gz`, `hashes.md5`
      в релиз (`gh release upload` или `softprops/action-gh-release`).
- [ ] Схема версионирования: **bare semver-теги `X.Y.Z` без `v`-префикса** —
      как в основном репо (`2.3.5`), это обязательно: `GitHubReleases::isValidVersion()`
      (regex `^[0-9]+\.[0-9]+\.[0-9]+$`) и `version_compare` работают
      только с bare-тегами; `v`-префикс всегда фейлит валидацию. Канал pre-release
      поддерживается из коробки (`GitHubReleases` фильтрует по каналам).
- [ ] CI: публиковать релиз как **draft**, залить `proxy.tar.gz`+хэши, и только
      последним шагом снять draft. Неаутентифицированный `GET /releases` не отдаёт
      draft — иначе `ensure()`/крон словят тег без ассетов в окне гонки `[released]`.
- [ ] Smoke воспроизводимости: прогнать `make` дважды в одной job, сверить
      `sha256sum` двух архивов (не верить флагам «на слово»). На tag-сборках —
      распаковать в чистый контейнер + `nginx -t`/`php -v` бандла (ловит регресс
      прав, который `tar -tzf` не видит). Дифф `tar -tvf old/new` закоммитить как артефакт.
- [ ] Первый релиз `1.0.0`. Цель — **content-эквивалентность** текущему
      LFS-файлу по `tar -tvf` (состав + пути), а НЕ побайтность: фикс прав
      (этап 0) и переход на детерминированный tar гарантированно меняют байты.
      Сверить дифф `tar -tvf` old/new, осознанно принять только исправленные права.
- [ ] (Опц.) CI-джоба на push: собрать архив, `tar -tzf` — smoke-проверка
      целостности без публикации.

### Этап 2 — XC_VM: ProxyArchiveUpdater + индекс + крон + install-hook ✅ РЕАЛИЗОВАНО

**Загрузчик-примитив (общий):**

- [x] Извлечён `CurlClient::downloadToFile(string $url, string $dest): void`
      в `src/Core/Http/CurlClient.php` (https-only guard, `CURLOPT_FILE`,
      `FAILONERROR`, `unlink()` при ошибке). `ModuleManager::downloadToFile()` —
      тонкий делегат (call-сайты 564/1005 не тронуты). В `GitHubReleases` скачивание
      НЕ добавлено.
- [x] `Core/Config/AppConfig.php`: `define('GIT_REPO_PROXY', 'XC_VM_Proxy');`.
      Версия — **чистый latest-в-канале** (`getReleases()[0]`, отфильтрован по
      `update_channel`; `stable` отбрасывает pre-release). Компенсация: kill-switch +
      last-known-good; критична дисциплина релизов.

**`ProxyArchiveUpdater` — единый источник свежести (зеркало `MaxMindUpdater`):**

- [x] Новый класс `src/Core/Proxy/ProxyArchiveUpdater.php` (`namespace XcVm\Core\Proxy`),
      конструктор `(GitHubReleases $repo, ?string $installDir=null)` (installDir
      переопределяется для тестов). Метод `ensure(bool $force=false, bool $forceLocal=false): array`
      возвращает `['version'=>…, 'action'=>'skip|download|local|stale-fallback|error', 'error'=>…]`.
      Гарантирует `bin/install/proxy.tar.gz` + индекс `bin/install/proxy_version.json`:
      1. Читает индекс `{version, md5}` (**sha256 отложен** — `getAssetHash` тянет
         только `hashes.md5`; как весь sibling-код binaries/maxmind/lb).
      2. `getReleases()[0]` + `isValidVersion()` перед URL (bare-теги). НЕ
         `getLatestVersion()`.
      3. индекс.version==latest И `md5_file`==индекс.md5 → **SKIP**.
      4. Иначе `getAssetHash` → `CurlClient::downloadToFile()` в `tempnam(installDir,
         '.proxy_tmp_')` (в целевом каталоге → `rename` атомарен, без EXDEV/TOCTOU),
         сверка md5, атомарный `rename`, `chmod 0644`, переписать индекс. 2 попытки
         при mismatch/сбое.
      5. GitHub недоступен/хэш не совпал, но локальный файл валиден → warning
         `stale-fallback`, вернуть его. Битую копию НЕ принимаем.
      6. Недоступен/битый и валидного файла нет → `error` с путём. Различает
         «хэш отсутствует» от «не совпал».
- [x] **Kill-switch**: settings-флаг `proxy_force_local` → `ensure(_, true)` работает
      только с локальным файлом (по умолчанию off — ключа нет в settings → `null`).

**Крон (зеркало `cron:maxmind`), авто-дискавери:**

- [x] `src/Cli/CronJobs/ProxyArchiveCronJob.php`, `getName()='cron:proxy'`,
      **`assertRunAsXcVm()`** (не root: диспетчер гоняет DB-croны через crontab
      пользователя xc_vm, а `bin/install/` — его владелец, sudo/chown не нужны).
      Зовёт `ensure()`, печатает `[OK]/[SKIP]/[WARN]/[ERROR]`. Регистрируется
      автоматически (`console.php` глобит `Cli/CronJobs/*.php`).
- [x] Регистрация в таблице **`crontab`** (не `crons`) через миграцию
      `src/migrations/009_add_proxy_cron.sql` (модель `002_add_maxmind_cron.sql`) +
      seed в `database.sql`: `(29, 'proxy', '0 5 * * *', 1)`. Диспетчер
      `RootSignalsCronJob:452` строит `console.php cron:proxy` из `filename='proxy'`.

**Хуки установки/установки-ноды:**

- [x] **При установке/старте панели**: `StartupCommand::prefetchProxyArchive()` —
      фоновый `console.php cron:proxy` (под `sudo -u xc_vm` если root), идемпотентный
      (SKIP если свежо), не блокирует загрузку. `bin/install/` получает proxy.tar.gz
      проактивно.
- [x] **`ServerInstallCommand`, ветка `$rType==1`**: перед `installArchive()` —
      `ensure()` self-heal; при `error` и отсутствии файла → **`servers.status=4`**
      с внятным текстом (detached-команда иначе висит «installing»).
- [ ] Metadata установки: в `{id}.json` добавить `proxy_version` из индекса — **TODO**.
- [ ] Очистка temp-файла при SIGINT — сейчас только unlink-on-error; **TODO** (низкий приоритет).
- [x] `make gates` зелёный (procedural-use/LB/vendor), `php -l` ×7 чисто, PSR-4
      автолоад резолвит. `make phpstan`/`cs` — требуют `make dev-tools`, не прогнаны.

### Этап 3 — XC_VM: удаление LFS-объекта

- [ ] `git rm src/bin/install/proxy.tar.gz` + убрать паттерн из `.gitattributes`.
      Проверено: строка 15 — **точечный** `src/bin/install/proxy.tar.gz`, не маска,
      другие файлы не заденет; `git lfs ls-files` до/после для подтверждения.
- [ ] Проверить `Makefile`/сборку: `verify_no_lfs_pointers`; `LB_DIRS_TO_REMOVE`
      уже содержит `bin/install` (Makefile:34) → из LB-архива файл и так уходит;
      `make main`/`lb` копируют только `git ls-files` → после `git rm` файл
      исчезнет и из панельного архива (это и есть цель). Упоминания в `install/`.
- [x] ~~Проверить, что распаковка апдейта не затирает кэш.~~ **Подтверждено кодом**
      (`src/update`, вызывается `UpdateCommand`): апдейт распаковывает во временный
      каталог, удаляет `bin/install` (в `UPDATE_EXCLUDE_DIRS`) из временной копии и
      переносит аддитивным `cp -a tmp/. → live` — никакого `rsync --delete`. Значит
      `bin/install/` на живой инсталляции апдейтом **не трогается** независимо от
      содержимого архива. Следствие: рантайм-фетч — единственный способ доставить
      контент `bin/install/` на уже установленные серверы, а не костыль только для прокси.
- [ ] Обновить docs (`docs/ru|en/administration/…` — установка прокси-ноды:
      требование сетевого доступа MAIN→GitHub либо ручное размещение файла).
- [ ] CHANGELOG/release notes: breaking-note для оффлайн-инсталляций.
- [ ] История git: старые LFS-версии proxy.tar.gz остаются в хранилище
      (влияют на storage-квоту, не на bandwidth новых клонов, т.к. LFS
      скачивает только checked-out версии). Решить: чистить ли историю
      (`git lfs migrate export` / BFG) — **отдельное решение**, ломает хэши
      коммитов; по умолчанию НЕ делаем.

### Этап 4 — верификация и релиз

- [ ] `make gates`, `make phpstan`, `make cs`, PHPUnit.
- [ ] Юнит-тесты `ProxyArchiveUpdater::ensure()`: SKIP (индекс==latest+хэш ок),
      скачивание (индекс устарел/файла нет), запись/чтение индекса
      `proxy_version.json`, битый хэш, GitHub недоступен (+локальный файл есть/нет),
      force-local kill-switch. Сетевую часть — за моком `GitHubReleases`.
- [ ] Тест крона `cron:proxy` (SKIP/OK) и что install-hook кладёт архив+индекс.
- [ ] Интеграционный прогон на чистой VM: (а) установка панели → архив+индекс есть;
      (б) крон при новой версии → перекачал+переписал индекс; (в) установка
      прокси-ноды из свежего кэша (0 сети); (г) оффлайн с заранее положенным файлом.
- [ ] Пересобрать релиз — убедиться, что checkout больше не тянет 56 МБ файла.

## 6. Риски

| Риск | Вероятность | Влияние | Митигация |
|---|---|---|---|
| Air-gapped/оффлайн MAIN не сможет установить прокси | средняя | среднее | fallback на локальный файл (этап 2.5–2.6) + docs |
| GitHub API rate limit (60 req/h **per-MAIN-IP**, не глобально) при bulk-provisioning | средняя | низкое | `getReleases` кэш 30м; но `getAssetHash` НЕ кэшируется — кэшировать сайдкаром + проверять локальный файл до сети (этап 2.2–2.3); опц. PAT через SettingsManager |
| Новый архив из CI отличается правами/байтами от исторического (фикс Makefile + детерминированный tar) | высокая | низкое | цель — content-эквивалентность по `tar -tvf`, не побайтность; явный дифф, осознанное принятие |
| Битый **stable** релиз прокси уедет на все новые установки (гейта нет — выбран чистый latest) | средняя | высокое | **осознанно принято**; компенсация: stable не тянет prerelease + kill-switch «force-local» + last-known-good кэш; критична дисциплина релизов XC_VM_Proxy. На первой установке нового MAIN кэша нет — единственная защита это kill-switch |
| Draft-релиз: тег виден, ассеты ещё не залиты (гонка `[released]`) | средняя | среднее | publish как draft → залить ассеты → undraft последним шагом (этап 1) |
| Тег с `v`-префиксом фейлит `isValidVersion`/`version_compare` | — | высокое | bare-теги `X.Y.Z` (этап 1); юнит-тест на формат |
| `rename()` через границу tmpfs→диск не атомарен (`EXDEV`) | низкая | среднее | `tempnam()` в целевом `bin/install/`, не в `TMP_PATH` (этап 2.4) |
| Уже развёрнутые панели после обновления: файл остаётся на диске | — | нет | это и есть fallback-кэш; крон `cron:proxy` заметит устаревание по индексу+хэшу |
| Индекс `proxy_version.json` рассинхронизирован с файлом (ручная правка/сбой) | низкая | среднее | `ensure()` сверяет `md5_file` против индекса, не доверяет только version-полю; при расхождении перекачивает |
| Удаление LFS-паттерна заденет другие файлы в `.gitattributes` | нет | — | подтверждено: точечный per-file паттерн (строка 15), не маска |
| XC_VM_Proxy — публичный репо с бандлом PHP: релизы качают все | — | нет | ассеты не тарифицируются; это цель, а не риск |

## 7. Критерии приёмки

1. `git lfs ls-files` на релиз-теге **не содержит** `proxy.tar.gz`; клон тега
   показывает **0 байт LFS-трафика именно на этот файл** (не «квота не превышена»).
2. **Установка панели** → `ProxyArchiveUpdater::ensure(true)` кладёт
   `bin/install/proxy.tar.gz` + `proxy_version.json` `{version,md5,sha256}`; при
   доступном GitHub — свежий latest; оффлайн + положенный файл — принят по индексу.
3. **Крон `cron:proxy`**: при неизменной версии → `[SKIP]` (0 скачиваний); при
   новой версии/несовпадении хэша → `[OK]` перекачал + переписал индекс.
4. `console.php server:install 1` — три исхода, по `bin/install/{id}.install` +
   `servers.status` (команда **detached**): online без кэша → `status=1`, лог с
   latest-версией; offline + валидный кэш → `status=1` + **grep-able** warning;
   offline без/битый кэш → **`status=4`** с точным путём и ремедией.
3. Хэш-цепочка, каждое звено отдельно: (а) ассет ↔ свой `hashes.{md5,sha256}`
   (CI XC_VM_Proxy); (б) `md5_file`/sha256 скачанного temp ↔ `getAssetHash` до
   `rename`; (в) существующий MAIN↔нода MD5-раунд (не изменён).
4. `make gates` (3 подпроверки) / `phpstan` (без новых suppress) / `cs` /
   PHPUnit — exit 0; число тестов **было→стало+N** явно в PR; LB-архив не изменился.
5. Кэш `bin/install/proxy.tar.gz` **переживает апдейт панели** (E4) — повторная
   установка идёт из кэша с 0 сетевых вызовов.
6. XC_VM_Proxy: `make` дважды на чистом checkout → **идентичный `sha256sum`**
   архива; все chmod реально выполняются (ошибки find не маскируются).

### Решено ревью-панелью (2026-07-08)

- **Q1 (solution-architect):** отдельный класс — реализован как
  `ProxyArchiveUpdater` в `src/Core/Proxy/` (зеркало `MaxMindUpdater` в
  `Core/GeoIP/`, а не `Cli/Commands` — теперь его зовут крон + install-hook +
  ServerInstallCommand, значит место Core, не Cli). Конструктор с `GitHubReleases`
  мокается. Общий `ReleaseAssetResolver` **не** проектировать сейчас (YAGNI — §9
  может быть node-side/per-distro). Разделяемый примитив — `CurlClient` (Q3).
- **Q2 (security-architect):** APPROVED. MD5-из-того-же-релиза достаточно и **не
  расширяет** поверхность атаки — идентичная модель уже в проде для `XC_VM_Binaries`.
  sha256 добавить (гигиена, не безопасность). Подпись — оверинжиниринг для пилота
  (только если ключ вне пайплайна и сразу для всех артефактов). Условие: org-hardening
  `XC_VM_Proxy` (2FA, branch protection, токен `contents:write`). Impl-условия
  (streaming-cURL, `tempnam` в целевом каталоге, `isValidVersion`) — заложены в этап 2.
- **Q3 (devops-lead-reviewer + владелец):** devops рекомендовал pin-with-override,
  но **владелец выбрал чистый latest-в-канале** — фиксы прокси прокидываются без
  релиза панели, максимальная простота. Риск «битый stable уедет без гейта»
  **осознанно принят**; компенсация — stable-канал не тянет prerelease + kill-switch
  + LKG-кэш + дисциплина релизов XC_VM_Proxy. Заложено в этап 2. CI — draft→ассеты→undraft.
- **db-architect:** схема не меняется, но добавляется **seed-строка в `crons`**
  (миграция `00X_add_proxy_cron.sql`, модель `002_add_maxmind_cron.sql`) +
  `servers.status=4` через существующий механизм.

### Открытые решения (нужен владелец продукта)

1. ~~Pin vs floor vs latest~~ — **РЕШЕНО: чистый latest-в-канале** (см. Q3 выше).
2. **Air-gapped первая установка нового MAIN** (нет ни сети, ни индекса): при
   вручную положенном `proxy.tar.gz` без `proxy_version.json` — принять по TOFU
   (сгенерировать индекс из `md5_file`) или требовать, чтобы оператор положил и
   индекс? Индекс-модель делает TOFU дешёвым, но это ослабляет проверку — решить явно.
3. **Чистка LFS-истории** (этап 3): делаем ли `git lfs migrate export`/BFG вообще,
   и если да — когда (ломает хэши коммитов, координация с форками). По умолчанию НЕТ.
4. ~~Краткосрочный unblock квоты~~ — **СНЯТО: релиз сделан, файлы локально**
   (2026-07-08). Ждать сброса квоты не требуется.

## 9. Следующий этап (вне рамок плана)

Аналогичный вынос ffmpeg/ffprobe (3 версии, ~236 МБ), redis-server (27 МБ),
proxy-независимых бинарников в `XC_VM_Binaries` — после обкатки схемы на
proxy.tar.gz. Это снимет ~85% оставшегося LFS-веса; `update_binaries.sh` и
`GIT_REPO_BIN` уже покрывают доставку на установленные серверы, останется
решить вопрос первичной установки и dev-окружения.
