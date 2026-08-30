# Аудит таблицы settings — осиротевшие / мёртвые / дрейф

Дата: 2026-08-30. Объём: таблица `settings` против админской страницы **Settings**
(`src/Public/Views/admin/settings.php`) и остального PHP/JS-кода.

## Метод

- **Живой источник истины:** `SELECT COLUMN_NAME ... information_schema` по
  продовой БД (`xc_vm.settings`) → **274 колонки**.
- **Поля UI:** каждый `name="…"` в `settings.php` → **214 полей формы**.
- **Проверка использования (точная):** настройки читаются по строковому ключу
  (`$rSettings['x']`, `SettingsManager::get('x')`, форма `name="x"`). Поэтому
  колонка считается *используемой* только если её имя встречается как
  **строковый литерал в кавычках** (`'x'` / `"x"`) где-либо в `src/**.php|.js`
  (исключая дамп схемы, `src/migrations/`, `vendor/`, `assets/`). Это отсекает
  ложные срабатывания по подстроке (например, `thread_count` внутри
  `cache_thread_count`, или колонка `ffmpeg_gpu` против глобала `$rFFMPEG_GPU`,
  который берётся из `FfmpegPaths::gpu()`).

## 1. Осиротевшие поля на странице Settings — НЕТ

Все 214 `name=` ложатся в реальную колонку. Единственные «не-колонки» объяснимы:

- `submit_settings` — кнопка отправки формы.
- `user_agent`, `http_proxy`, `cookie`, `headers` — это **не** колонки `settings`;
  `SettingsService::edit()` (строки 30-33) роутит их в таблицу `streams_arguments`
  (`argument_default_value`), а не в `settings`.

## 2. Мёртвые колонки — 12 (в БД, 0 ссылок ни в `src/`, ни в модулях; 2B доделано)

> Обновление 2026-08-30: `ffmpeg_gpu` **оживлён** (динамический `FfmpegBinaries` +
> `FfmpegPaths::resolve($cpu, $gpu)`, дропдаун в settings, таблица на вкладке info).
>
> ⚠️ **Важная поправка метода:** аудит сканировал только `src/` репозитория, а
> **внешние модули** (Plex, Watchfolder — отдельные репозитории, ставятся в рантайме)
> тоже читают/пишут core-колонки `settings`. Перепроверка по ним перенесла 5 колонок
> из «мёртвых» в живые (см. 2C). Перед сносом любой «мёртвой» колонки — обязательно
> грепать по ВСЕМ доступным модулям, не только по репозиторию.

### 2A. «Умерло» — в схеме `database.sql`, но не используются ни в `src/`, ни в модулях (12)

Остатки вырезанных/переделанных фич; всё ещё ставятся на каждой установке, но
ничего их не читает и не пишет.

| Колонка | Вероятная причина / почему мертва |
| --- | --- |
| `custom_ip_header` | кастомный заголовок client-IP — фича удалена |
| `detect_restream_ports` | детект рестрима урезан (живы только `detect_restream_block_user/_ip`) |
| `detect_restream_servers` | то же |
| `release_parser` | старый конфиг парсера релизов (вытеснен `parse_type`/`alternative_titles`) |
| `hls_accelerator` | удалённый тумблер HLS-акселератора |
| `redirect_timeout` | не используется |
| `reissues` | не используется |
| `reseller_restrictions` | не используется |
| `split_clients` | легаси (в отличие от живой `split_by`) |
| `stats_pid` | stats-крон больше не трекает PID здесь (ср. живые `backups_pid`/`watch_pid`) |
| `tmdb_pid` | tmdb-крон больше не трекает PID здесь |
| `stb_change_pass` | удалённый тумблер смены пароля STB |

### 2C. Живы через модули — НЕ мёртвые, НЕ дропать (5)

Core-колонки `settings`, чьи UI и логику предоставляют **внешние модули** (не панель).
Аудит по `src/` их не видел; подтверждены грепом по Module_Plex / Module_Watchfolder.

| Колонка | Модуль(и) | Использование |
| --- | --- | --- |
| `scan_seconds` | Plex + Watchfolder | UPDATE settings + форма + чтение |
| `thread_count_movie` | Plex | UPDATE + `getAll()['thread_count_movie']` в PlexCron |
| `thread_count_show` | Plex | UPDATE + чтение в PlexCron |
| `thread_count` | Watchfolder | UPDATE + чтение в WatchCron |
| `fallback_parser` | Watchfolder | UPDATE + чтение в WatchCron/WatchItem |

### 2B. «Пропустили» — ✅ РЕШЕНО (доделано, коммит `1dd488fb`)

`auto_unban_ip` / `ban_duration_value` / `ban_duration_unit` были только в живой БД
(ручной ALTER), логики не было. **Доделана фича авто-разбана**: миграция `012`
+ seed в `database.sql` (дрейф устранён), UI в секции flood/bruteforce,
прун протухших авто-банов в `RootSignalsCronJob` (MAIN, только авто-баны по `notes`;
ручные баны не трогаются, существующий синк `blocked_ips`→iptables снимает их с нод).

## 3. Дрейф схемы

- **В живой БД, но не в `database.sql`:** было 3 (`auto_unban_ip`, `ban_duration_*`)
  — **устранено** миграцией `012` + добавлением в `database.sql` (см. 2B).
- **В `database.sql`, но не в живой БД:** нет.

## 4. Колонки в БД, но не редактируемые на странице Settings — 61

Включает 12 мёртвых + 5 модульных (2C: `scan_seconds`, `thread_count*`, `fallback_parser`
— UI даёт модуль, как dropbox/cache задаются на других страницах). Остальные 44 — легитимны:

**Рантайм / внутреннее (корректно скрыто):**
`id`, `backups_pid`, `watch_pid`, `stats_pid`*, `tmdb_pid`*, `last_backup`,
`last_cache`, `last_cache_taken`, `status_uuid`, `update_data`, `update_version`,
`xc_vm_version`, `total_users`, `license`, `log_clear`.
(*`stats_pid`/`tmdb_pid` также в списке мёртвых — живого PID-трекинга нет.)

**Задаются на других страницах админки (корректно):**
`automatic_backups`, `backups_to_keep`, `dropbox_keep`, `dropbox_remote`,
`dropbox_token` (страница Backup → `editBackup`); `cache_changes`,
`cache_thread_count`, `enable_cache` (Cache/Cron → `editCacheCron`);
`redis_handler`, `redis_password` (инсталлер / другое).

**Используются кодом, но без UI на этой странице — проверить, намеренно (advanced) или пропущено:**
`alternative_titles`, `auto_update_lbs`, `cc_time`, `connection_loop_count`,
`connection_loop_per`, `cpu_limit`, `mem_limit`, `detect_restream_block_ip`,
`legacy_mag_auth`, `mag_security`, `max_genres`, `max_items` (только в JS),
`ministra_allow_blank`, `percentage_match`, `send_altsvc_header`,
`send_protection_headers`, `send_server_header`, `send_unique_header`,
`send_unique_header_domain`, `send_xc_vm_header`, `stalker_lock_images`.

## 5. Рекомендованные шаги

1. **Снести 12 «умерших» колонок** (раздел 2A; НЕ трогать 2C-модульные) — миграция `013_drop_dead_settings.sql`
   (`ALTER TABLE settings DROP COLUMN …`) **и** убрать их из seed `database.sql`.
   Необратимо; делать только после финальной проверки grep.
2. ✅ **2B сделано** — фича авто-разбана доделана (коммит `1dd488fb`, миграция `012`).
3. **Пройтись по 21 «used but no UI»** — по каждой решить: вывести на страницу,
   перенести в advanced-вкладку или оставить дефолтом только в коде.

## Приложение — сырые счётчики

- 274 живых колонки · 218 полей формы · 12 мёртвых (2A) · 5 модульных (Plex/Watchfolder) · 3 доделано (2B: auto_unban) · 61 без UI.
