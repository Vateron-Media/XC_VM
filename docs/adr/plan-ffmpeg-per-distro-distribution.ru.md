# План: per-distro дистрибуция FFmpeg через XC_VM_FFMPEG

Статус: черновик плана (июль 2026). Цель — раздавать `ffmpeg`/`ffprobe` на ноды
**отдельными сборками под каждый дистрибутив** (собранными в контейнере своего
дистро → glibc гарантированно совпадает), фетчить их из релизов
`XC_VM_FFMPEG` при установке и обновлять кроном — по образцу MaxMind/proxy.
Уходим от одного «forward-compatible» бинаря в Git LFS.

**Первопричина.** Единый бинарь собирался на Debian 11 (glibc 2.31) в расчёте на
forward-compat. FFmpeg 8.x требует glibc ≥ 2.34 → на Debian 11 / Ubuntu 20.04
(2.31) не стартует: `ffprobe: ... version 'GLIBC_2.35' not found`. Именно это
уронило воспроизведение фильма на LB2 (Ubuntu 20.04) при `ffmpeg_cpu='8.0'`.
Оперативный обход на проде — переключить настройку `ffmpeg_cpu`/`ffmpeg_gpu` на
`7.1` (статический билд, работает на 2.31).

---

## 0. Текущее состояние

**Репозиторий сборщика — `XC_VM_FFMPEG` (СДЕЛАНО, запушен):**

| Что | Файл | Примечание |
|---|---|---|
| Сборщик (перенесён 1:1 из `XC_VM_Binaries`) | `build_ffmpeg.sh` | все кодеки статически, динамически только glibc; `verify_static` падает, если остался не-glibc .so. Env: `V_FFMPEG`, `FF_LABEL`, `FF_DISTRO`, `OUT_DIR` |
| Matrix-драйвер (единый источник матрицы) | `build_ffmpeg_all.sh` | `(версия × дистро)`, per-distro image + прогон по версиям; `--print-matrix` для CI; `hashes` для md5 |
| Dockerfile под дистро | `docker/Dockerfile` | `ARG BASE_IMAGE` |
| CI | `.github/workflows/build-release.yml` | `workflow_dispatch` → параллельная матрица → draft-релиз + `hashes.md5` |
| Прочее | `Makefile`, `versions.json`, `README.md`, `RELEASE.md`, `.gitignore` | |

**Матрица:** `4.0/7.1/8.1` × `debian_11/12/13, ubuntu_20/22/24` = **18 ассетов**
вида `ffmpeg_<label>_<distro>.tar.gz` + `hashes.md5`. Rocky_9 — TODO.

**Обновление сборщика (в `XC_VM_FFMPEG`, ещё НЕ закоммичено):** набор кодеков
приведён к тому, что реально дёргает код (см. §0a): **+nvenc/cuvid/ffnvcodec**
(GPU), **+librtmp**, оставлен fdk-aac; **−AV1** (aom/dav1d — не используются).
`4.0` теперь **пересобирается** из ffmpeg `4.4.5` (не бинарь XUI): GPU + нативный
DTS-декод. nv-codec-headers пинятся per-version (`4.0→n11.1.5.3`,
`7.1/8.1→n12.2.72.0`); `libfribidi/libharfbuzz` версионно-гейчены (опции ffmpeg
только с 6.1 → для 4.x не включаются, libass тянет их внутри себя).

**Panel-сторона (XC_VM) — как сейчас:**

- FFmpeg лежит в **Git LFS**: `.gitattributes` →
  `src/bin/ffmpeg_bin/** filter=lfs`; каталоги `src/bin/ffmpeg_bin/{4.0,7.1,8.0}/`.
- Константы путей: `src/Core/Config/Binaries.php` →
  `FFMPEG_BIN_40 = BIN_PATH.'ffmpeg_bin/4.0/ffmpeg'`, `_71 = 7.1/`, `_80 = 8.0/`.
- Выбор версии: `FfmpegPaths::resolve()` маппит настройку `8.0`→`_80`,
  `7.1`→`_71`, иначе→`_40` (`ffmpeg_cpu`/`ffmpeg_gpu`, varchar(8), default `4.0`).
- `4.0` неспроста: `StreamProcess.php:943/947` — `-nofix_dts`/`-nofix` только для
  `FFMPEG_BIN_40` (legacy DTS-HD путь `dts_legacy_ffmpeg`).
- Установка бинарей: `src/bin/install/update_binaries.sh` детектит дистро
  (`ubuntu_<major>.tar.gz`/`debian_<major>.tar.gz`), пишет
  `bin/bin_version.json`. **ffmpeg он сейчас НЕ качает** — ffmpeg приезжает через
  LFS вместе с деплоем.
- Паттерн-образец уже есть: `MaxMindCronJob` + `ProxyArchiveCronJob`
  (`cron:proxy`) на `GitHubReleases(GIT_OWNER, GIT_REPO_PROXY, update_channel)`;
  константы репо в `src/Core/Config/AppConfig.php` (`GIT_OWNER`, `GIT_REPO_*`).

**Лейбл 8.x — РЕШЕНО:** обновляемся до `8.1`. Панель сейчас маппит настройку
`8.0`→`FFMPEG_BIN_80`, поэтому при проводке (Stage 4) панель приводится к `8.1`.

---

## 0a. Что выяснили про сборки (провенанс + кодеки + DTS)

**Три задеплоенных бинаря — три разных сборки** (из `ffmpeg -version`):
- `4.0` = master-снапшот **XUI.one 2018** (`XUI10FFMPEG`, gcc 6.5/Ubuntu 14.04) —
  древний glibc, работает везде; несёт кастомный флаг `-fix_dts`.
- `7.1` = личная сборка **mardock2009** (`compiled-by-Mardock2009`, Ubuntu 20.04) —
  **без GPU** (nvenc/cuvid пустые!). Кто на 7.1 — GPU-транскод не работает.
- `8.0` = **наша** сборка `XCVM` на Ubuntu 22.04 → glibc **2.35** = первопричина.

**Целевой набор кодеков** выбран из того, что реально вызывает код
(`StreamProcess`/`ProfileService`), а не объединения трёх сборок:
- **must:** x264, x265, **nvenc/cuvid/ffnvcodec** (GPU: `*_cuvid`, `hevc_nvenc`,
  `-hwaccel cuvid`), нативный aac + `aac_adtstoasc`, mp3lame, нативный DTS-декод
  (`dca`), libass/freetype/fontconfig, openssl, hls/segment/mpegts/flv-муксеры.
- **keep:** vpx, opus, vorbis, theora, fdk-aac, librtmp (нативный rtmp уже есть,
  librtmp — паритет + rtmpe/rtmps), fribidi/harfbuzz (6.1+).
- **drop:** AV1 (aom/dav1d), libsrt (в коде `srt` = субтитры SubRip, не протокол),
  xavs/xvid/webp/vidstab/openjpeg/xml2/amr/speex/bluray — ни одной ссылки.
- **GPU в статике безопасен:** libnvidia-encode/libcuda грузятся `dlopen` в
  рантайме → на CPU-ноде ffmpeg просто не видит карту, вреда нет.

**`-nofix_dts` — кастомный CLI-флаг XUI**, в стоке ffmpeg его НЕТ (7.1/8.0 не
понимают — проверено). В бинаре: `-fix_dts "fix invalid dts"`, стоит рядом с
`-copyts`/`-start_at_zero` → тумблер коррекции «битых» DTS (по умолчанию ON; код
гасит его `-nofix_dts` для ac3/eac3 при `dts_legacy_ffmpeg`, `StreamProcess:947`).
Раз `4.0` пересобираем стоковым — **панель обязана перестать слать `-nofix_dts`**;
нативный `dca`-декод DTS сохраняется. Кандидат-замена — `-copyts -fflags +igndts`;
проверяется скриптами **`tools/dts-audio-test/`** (`gen-problem-stream.sh` +
`compare-audio.sh`).

---

## 1. Стадии (обзор)

- [x] **Stage 0 — репозиторий `XC_VM_FFMPEG`** (сборщик, матрица, CI, docs). Запушен.
- [ ] **Stage 1 — первый релиз** матрицы (собрать 18 ассетов, выпустить `1.0.0`).
- [ ] **Stage 2 — фетч на панели**: `update_binaries.sh`/новый шаг тянет
  `ffmpeg_<label>_<distro>` под дистро ноды, проверяет `hashes.md5`, кладёт в
  `ffmpeg_bin/<label>/`.
- [ ] **Stage 3 — индекс + крон** (`version.json` + `cron:ffmpeg` по образцу maxmind).
- [ ] **Stage 4 — привести панель к лейблу `8.1`** (`FfmpegPaths`/`Binaries`/dropdown).
- [ ] **Stage 5 — вынос `ffmpeg_bin/*` из Git LFS.**
- [ ] **Stage 6 — Rocky_9** (dnf-порт `install_build_tools`).
- [ ] **Stage 7 — валидация `4.0`**: реальная сборка `4.4.5` (линковка современных
  кодеков) + DTS-звук через `tools/dts-audio-test/` (замена `-nofix_dts`).

---

## 2. Stage 1 — первый релиз XC_VM_FFMPEG

Цель: получить в релизах `XC_VM_FFMPEG` все ассеты, которые будет тянуть панель.

1. На билд-машине с Docker: `make build` (или `Actions → Build & Release FFmpeg`,
   тег bare-semver `1.0.0`, draft).
2. Проверить `verify_static` прошёл для каждого билда (в логах `logs/`), особенно
   для **4.0** (риск линковки, см. §8).
3. Сверить `hashes.md5` содержит ровно 18 имён.
4. Опубликовать релиз (снять draft).

**Решение (открыто):** какой канал — `stable`/`unstable` (как у proxy,
`GitHubReleases` поддерживает оба; панель берёт `update_channel`).

## 3. Stage 2 — фетч ffmpeg на панели

По образцу `ProxyArchiveCronJob`/`update_binaries.sh`:

1. `AppConfig.php`: добавить `define('GIT_REPO_FFMPEG', 'XC_VM_FFMPEG');`.
2. Детект дистро уже есть в `update_binaries.sh` (`DIST_ID`/`DIST_MAJOR` →
   `ubuntu_20`/`debian_12`…). Переиспользовать его для имени ассета.
3. Для каждой нужной версии `<label>` (см. §4, что реально ставить):
   скачать `ffmpeg_<label>_<distro>.tar.gz`, проверить md5 через
   `GitHubReleases::getAssetHash()` (читает `hashes.md5`), распаковать в
   `BIN_PATH.'ffmpeg_bin/<label>/'` (внутри архива `ffmpeg`, `ffprobe`,
   `BUILD_INFO`).
4. Использовать `CurlClient::downloadToFile()` (стриминг, https-only,
   unlink-on-fail) — уже вынесен для proxy/maxmind.
5. Права: `ffmpeg`/`ffprobe` → `0755` (как в текущем LFS-дереве).
6. **Снять `-nofix_dts`** из `StreamProcess:947` (кастомный флаг XUI, стоковый
   пересобранный `4.0` его не понимает). Заменить на проверенный эквивалент из
   `tools/dts-audio-test/` (кандидат `-copyts -fflags +igndts`) или убрать — DTS
   декодируется нативно (`dca`). Иначе legacy-DTS-команда упадёт.

**Открытый вопрос:** ставить все 3 версии на каждую ноду, или только выбранную
`ffmpeg_cpu`/`ffmpeg_gpu` + `4.0` (для DTS)? Меньше трафика vs. мгновенное
переключение в UI. Рекомендация: тянуть все, что реально выбираемы в dropdown.

## 4. Stage 4 — привести панель к лейблу 8.1

Решено обновляться до `8.1`, поэтому панель приводится к нему (вариант B):
1. `Binaries.php`: `FFMPEG_BIN_80` → `ffmpeg_bin/8.1/ffmpeg` (или добавить `_81`).
2. `FfmpegPaths::resolve()`: маппинг `8.1`.
3. `settings.php`: значение в dropdown `8.0`→`8.1`.
4. Миграция настройки: `UPDATE settings SET ffmpeg_cpu='8.1' WHERE ffmpeg_cpu='8.0'`
   (и `ffmpeg_gpu`).

## 5. Stage 5 — вынос из LFS

После того как фетч (Stage 2–3) работает end-to-end и проверен на живой ноде:

1. `git rm -r --cached src/bin/ffmpeg_bin` и убрать строку из `.gitattributes`.
2. Убедиться, что билд-архивы (`make main`/`make lb`) больше не ждут
   `ffmpeg_bin/*` в дереве (проверить `verify_no_lfs_pointers` и упаковку).
3. Обновить установщик: свежая нода получает ffmpeg только через фетч.
4. Освобождает LFS-квоту — главная причина всей затеи (как с proxy.tar.gz).

## 6. Stage 6 — Rocky_9

`build_ffmpeg.sh` целиком apt-ориентирован (`install_build_tools`,
`--enable`-набор универсален). Нужен dnf-порт:
`apt-get`→`dnf`, `build-essential`→`gcc-toolset-13`, имена `-devel`/tool-пакетов,
включить `crb`/EPEL для nasm/yasm. Добавить `rocky_9`→`rockylinux:9` в матрицу
драйвера и `versions.json`. Панель уже умеет `rocky` в бандлах — имя ассета
`ffmpeg_<label>_rocky_9.tar.gz` встанет в общий механизм фетча.

## 7. Открытые вопросы / решения

- **РЕШЕНО:** лейбл 8.x → `8.1` (§4); `4.0` пересобираем из `4.4.5` (§0a);
  набор кодеков зафиксирован (§0a).
- Канал релиза (`stable`/`unstable`).
- Ставить все версии на ноду или только выбранную + `4.0` (§3).
- Частота `cron:ffmpeg` (у maxmind/proxy свой интервал в `crontab`).
- Замена `-nofix_dts`: `-copyts` vs убрать — по итогам `tools/dts-audio-test/`.

## 8. Риски

- **FFmpeg 4.0 (`4.4.5`) с современным набором кодеков** (x265 4.1, vpx 1.15,
  fdk 2.0.3…): API новее, чем ждёт ffmpeg 4.x — линковка может упасть.
  `libfribidi/libharfbuzz` уже сняты для 4.x (нет до 6.1). При провале линковки
  прочего — пинить старую версию конкретного кодека для 4.0 (per-version хук).
- **`-nofix_dts` теряется** при пересборке `4.0` (кастом XUI) → без правки панели
  (Stage 2 п.6) legacy-DTS-команда упадёт. DTS-декод сохраняется нативно.
- **7.1 сейчас без GPU** (сборка mardock) → пересборка добавляет nvenc/cuvid;
  проверить, что GPU-профили заработали на 7.1-нодах после релиза.
- **Полнота матрицы vs квота LFS**: 18 архивов на релиз — но это release-assets,
  не LFS, и качается только нужный дистро (как maxmind/proxy).
- **Обрыв фетча на ноде**: должен быть TOFU-фолбэк на существующий бинарь (как у
  `ProxyArchiveCronJob`: «using existing archive/local copy»), иначе нода без
  ffmpeg = нет VOD/транскода.
- **Тяжесть CI**: каждый job — сборка ~20 кодеков из исходников. Параллельно 6,
  `timeout-minutes: 240`. Первый релиз лучше собрать локально (`make build`).
