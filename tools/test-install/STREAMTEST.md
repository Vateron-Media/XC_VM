# Стрим-тест панели (e2e)

Сквозная проверка стриминга: локальный **генератор** отдаёт видеопоток →
**панель** его рестримит → `stream_check.py` проверяет **выход панели**
(каждый канал ~2 минуты) и пишет JSON-лог по каждому потоку.

Панель настраивается **один раз вручную** через админку, конфигурация
сохраняется в **дамп БД**, а прогон теста восстанавливает дамп и запускает
checker — повторяемо. Прямых SQL-запросов к БД нет: тест только снимает и
восстанавливает бэкап (`mysqldump` / импорт).

```text
генератор (127.0.0.1:8088)  ──►  панель (рестрим/LLOD)  ──►  get.php m3u  ──►  stream_check.py
   stream_server.py                 xc_fanout / monitor        выход панели         JSON-график
```

## Команды `test_release.sh`

| Команда | Что делает |
|---|---|
| `streamtest-gen` | поднять генератор в контейнере (для ручной настройки панели) + напечатать URL источников и админки |
| `streamtest-gen-stop` | остановить генератор |
| `streamtest-backup` | снять дамп настроенной БД в `fixtures/streamtest-db.sql.gz` |
| `streamtest` | восстановить дамп → прогреть кэш → генератор → забрать m3u линии → прогнать checker (e2e) |

Плюс отдельный инструмент `tools/stream-check/stream_check.py` с подкомандами
`check` (checker), `playlist` (пакетный прогон) и `graph` (SVG-графики).

## Требования

- Контейнер установлен: `./tools/test-install/test_release.sh install`
- `tools/test-stream-generator/sample.mp4` — короткий H.264/AAC .mp4 (в git его нет)
- Внутри контейнера уже есть `python3`, встроенный `ffmpeg`, `mariadb`

## 0. Порты и доступ

- Админка: `http://localhost:8880/<код-доступа>/` — **код обязателен**, голый
  `http://localhost:8880/` отдаёт **404**. Код — в конце `./test_release.sh logs`
  (строка вида `http://…/PjmpGpxM/`); `streamtest-gen` тоже печатает готовую ссылку.
- Генератор: `http://127.0.0.1:8088/` (внутри контейнера), проброшен на хост как
  `http://localhost:8088/`.

## 1. Завершить миграции БД

Свежая установка может показывать «Database Incomplete». Выполнить **от root**
в контейнере:

```bash
docker exec xcvm-test-install /home/xc_vm/bin/php/bin/php /home/xc_vm/console.php status
```

> Известный баг: миграция `010_add_shared_mount_prefixes.sql` не идемпотентна —
> колонка уже есть в базовой схеме `database.sql`, поэтому на свежей установке
> она падает («Duplicate column») и статус остаётся «Incomplete». Если это
> мешает — эту миграцию нужно поправить (guard `IF NOT EXISTS` / убрать из
> базовой схемы) в `src/migrations/010_add_shared_mount_prefixes.sql`.

## 2. Запустить генератор (для ручной настройки)

```bash
./tools/test-install/test_release.sh streamtest-gen
```

Команда поднимает генератор в контейнере и печатает URL источников и готовую
ссылку на админку (с кодом доступа):

- MPEG-TS (LLOD): `http://127.0.0.1:8088/stream.ts`
- HLS: `http://127.0.0.1:8088/stream.m3u8`

Оставьте его запущенным, пока настраиваете панель (остановить —
`./test_release.sh streamtest-gen-stop`).

## 3. Настроить панель вручную (админка)

Цель — чтобы **оба потока реально пошли ONLINE** (панель должна успешно
рестримить источник от генератора). Порядок:

1. **Категория** (Streams → Categories): создать live-категорию, напр. `XC_VM Test Live`.
2. **Поток 1 — MPEG-TS** (Streams → Add): 
   - Source: `http://127.0.0.1:8088/stream.ts`
   - Категория: созданная выше
   - **Сервер**: назначить **Main Server** как *источник* (узел «source» в дереве
     серверов — поток читает свой `stream_source` напрямую).
   - Режим запуска: on-demand (LLOD) либо always-on — на ваш выбор; для
     on-demand поток стартует при первом запросе checker'а.
3. **Поток 2 — HLS**: то же, source `http://127.0.0.1:8088/stream.m3u8`.
4. **Проверить, что потоки ONLINE**: в списке стримов у обоих должен появиться
   битрейт/статус «онлайн» (панель подключилась к генератору и рестримит). Это
   ключевая проверка — без неё выход панели будет отдавать заглушку «not on air».
5. **Bouquet** (Bouquets → Add): создать бэкет, добавить оба потока.
6. **Линия** (Lines → Add): 
   - Username / Password: **`xcvmtest` / `xcvmtest`** (значения по умолчанию для
     теста; можно другие — тогда задайте `STREAMTEST_USER` / `STREAMTEST_PASS`).
   - Bouquet: выбрать созданный бэкет.
   - Allowed Output Formats: включить как минимум **MPEG-TS**.
   - Exp date — в будущем, Max connections ≥ 1, линия активна.
7. **Проверка выхода панели** (в браузере или curl):

   ```
   http://localhost:8880/get.php?username=xcvmtest&password=xcvmtest&type=m3u_plus&output=ts
   ```

   Должен вернуться m3u со ссылками на выход панели (`/play/<token>/ts`), и они
   должны **проигрываться** (в плеере/ffprobe), а не отдавать заглушку.

## 4. Снять бэкап настроенной БД

Когда потоки идут ONLINE и выход панели проигрывается:

```bash
./tools/test-install/test_release.sh streamtest-backup
```

Дамп сохранится в `tools/test-install/fixtures/streamtest-db.sql.gz` (в git не
коммитится — он привязан к текущей установке: креды, IP сервера, коды доступа).

## 5. Прогонять тест (повторяемо)

```bash
./tools/test-install/test_release.sh streamtest
```

Что делает: восстанавливает дамп БД → пересобирает кэши (`cron:cache`,
`cron:cache_engine`) → поднимает генератор → забирает m3u линии с выхода панели →
запускает `stream_check.py playlist` (по умолчанию 120 c на канал).

Результат:

- `tools/test-install/out/streamtest-<ts>.json` — сводный отчёт (все каналы + summary);
- `tools/test-install/out/streamtest-<ts>/` — **отдельный JSON-лог на каждый канал**
  (`<NN>-<имя>.json`), пишется по мере прохождения каждого потока;
- краткий pass/fail в консоль.

Код возврата `0` — все каналы healthy, `2` — есть проблемные.

## 6. Итерации

Если с первого раза потоки не проходят:

1. `./test_release.sh streamtest` — восстановит **известное** состояние БД из
   бэкапа (сбрасывает любые накопившиеся изменения) и прогонит checker.
2. По JSON-логу видно, что не так (`healthy=false`, `errors`, счётчики очереди,
   `samples` — тайм-серия kbit/s и буфера).
3. Поправьте настройку в админке (или запустите генератор
   `streamtest-gen` и донастройте), убедитесь, что потоки снова ONLINE.
4. Снимите новый бэкап `streamtest-backup` (перезапишет фикстуру).
5. Повторяйте `streamtest`, пока не будет зелёным.

## Переменные окружения

| Переменная | По умолчанию | Назначение |
|---|---|---|
| `STREAMTEST_USER` / `STREAMTEST_PASS` | `xcvmtest` | креды тест-линии (должны совпадать с настроенной) |
| `STREAMTEST_DURATION` | `120` | секунд на каждый канал |
| `STREAMTEST_TOLERANCE` | `0` | сколько транзиентных разрывов очереди (CC/sync) допускать; у on-demand рестрима на старте обычно 1–2 CC-разрыва — поставьте `2`–`5`, чтобы их не считать за провал |
| `STREAMTEST_STALL_TIMEOUT` | `15` | секунд без новых данных = сталл — **только для непрерывного TS**. HLS этим не валится: длина HLS-сегментов часто намеренно рваная (рандомизация против DPI провайдера), поэтому HLS судится по реальному опустошению буфера (`rebuffers`), а не по паузе доставки |
| `STREAMTEST_WARMUP` | `8` | пауза после старта генератора до забора m3u |
| `STREAMTEST_OUT_DIR` | `/tmp/xcvm-streamlogs` | каталог per-stream логов в контейнере (хост копирует их в `out/streamtest-<ts>/`) |
| `XCVM_STREAM_PORT` | `8088` | порт генератора (хост→контейнер) |

Пример: быстрый прогон по 20 с на канал —
`STREAMTEST_DURATION=20 ./test_release.sh streamtest`.

## Формат JSON-отчёта

```json
{
  "generated_at": "2026-…Z",
  "duration_per_stream": 120,
  "streams": [
    {
      "name": "XC_VM Test TS", "url": "http://…/play/…/ts", "mode": "ts",
      "healthy": true,
      "details": { "kbit_s": 3372, "cc_errors": 0, "sync_errors": 0,
                   "hls_gaps": 0, "hls_disc": 0, "received_s": 120.0,
                   "rebuffers": 0, "max_data_gap_s": 1.2, "stalled": false },
      "samples": [ { "t": 0.0, "kbit_s": 0, "buffer_s": 0.0, "received_s": 0.0,
                     "state": "PREBUFFER", "cc_errors": 0, "sync_errors": 0,
                     "gaps": 0, "disc": 0 }, "… по одному в секунду …" ],
      "errors": []
    }
  ],
  "summary": { "total": 2, "healthy": 2, "failed": 0 }
}
```

`samples` — это «график» живого дашборда (`stream_check.py check --live`),
сериализованный по секундам: пропускная способность, буфер, накопленные секунды
и счётчики разрывов очереди (CC/sync для TS, gaps/disc для HLS).

**Правило `healthy`:** получены данные, `queue_breaks ≤ tolerance`, нет ошибок и —
по режиму — **TS**: не было сталла (пауза доставки > `stall-timeout`); **HLS**: не
было ребуферов (буфер не опустошался). Длина HLS-сегментов часто намеренно рваная
(анти-DPI), поэтому одна лишь длинная пауза доставки HLS не валит — важно, реально
ли опустел буфер.

## Графики (SVG) для сравнения

`stream_check.py graph` рисует из JSON статические **SVG-графики** (чистый
stdlib, без зависимостей) — их удобно сравнивать как картинки и вставлять в
PR/доки.

На **каждый поток** — один SVG: пропускная способность (kbit/s) во времени
с заливкой, кривая буфера на второй оси, амбровые полосы там, где виртуальный
плеер не в состоянии PLAYING, красные штрихи разрывов очереди
(CC/sync/gaps/disc), а в шапке — имя, `mode`, бейдж **OK/FAIL** и строка цифр
(`avg kbit/s · recv · cc/sync/gaps/disc · stall`). Поток без сэмплов (упавший на
открытии) тоже даёт картинку — с вердиктом и текстом ошибки.

### Вход

Позиционные аргументы — **любое сочетание файлов и каталогов** (`nargs="+"`):

- **вся папка** — сканируется по `*.json` (не рекурсивно);
- **отдельные файлы** — по одному или списком/glob (`logs/0*.json`);
- **смесь** папок и файлов в одной команде;

Один и тот же файл, попавший и через папку, и как отдельный аргумент, читается
**один раз** (дедуп по `realpath`). Каждый JSON — либо per-stream запись (есть
`samples`), либо сводный отчёт (`"streams": [...]`, разворачивается в потоки).

```bash
# вся папка per-stream логов + сводный comparison.svg
python3 tools/stream-check/stream_check.py graph tools/test-install/out/streamtest-<ts>/ --combined --out-dir graphs/

# сводный отчёт одного прогона
python3 tools/stream-check/stream_check.py graph tools/test-install/out/streamtest-<ts>.json

# отдельные файлы / glob
python3 tools/stream-check/stream_check.py graph logs/01-MPEG-TS.json logs/02-*.json

# смесь: папка + отдельный файл (без дублей)
python3 tools/stream-check/stream_check.py graph logs/ other-run/07-HLS.json --combined
```

### Опции

| Опция | По умолчанию | Назначение |
|---|---|---|
| `--out-dir DIR` | `graphs` | каталог для SVG |
| `--combined` | выкл. | доп. `comparison.svg` — наложение битрейта всех потоков с легендой и вердиктами |
| `--width PX` | `920` | ширина графика |
| `--height PX` | `460` | высота графика |

Результат — по `graphs/<NN>-<имя>.svg` на поток (+ `comparison.svg` при
`--combined`). SVG открывается в браузере/IDE и при необходимости конвертируется
в PNG (`rsvg-convert`, `inkscape` и т.п.).

## Диагностика

- **Пустой m3u / панель отдаёт 404 на `get.php`** — в восстановленном бэкапе нет
  линии с такими кредами (частый случай: линия названа не `xcvmtest`). Передайте
  реальное имя: `STREAMTEST_USER=<line> STREAMTEST_PASS=<pass> ./test_release.sh streamtest`.
  Также проверьте, что линия активна и бэкет не пуст (шаг 3.6–3.7).
- **`healthy=false`, `stalled=true`, ~100 КБ и тишина** — панель отдаёт заглушку
  «not on air»: поток НЕ идёт ONLINE. Причины: поток не назначен на Main Server
  как источник; источник (генератор) недоступен; источник не стартовал. Убедитесь
  на шаге 3.4, что в админке у потоков есть битрейт.
- **HLS-поток FAIL с `rebuffers > 0`** — буфер реально опустел посреди
  воспроизведения. Длина HLS-сегментов намеренно рваная (анти-DPI), и checker это
  учитывает — HLS судится по `rebuffers`, а не по паузе доставки; так что
  `rebuffers > 0` означает, что буфер слишком мелкий, чтобы поглотить длинный
  сегмент. Увеличьте буфер/окно на панели (`client_prebuffer` /
  `request_prebuffer` / `seg_list_size`) — это чаще тонкий буфер выбранного
  LLOD-режима, а не поломка нарезки. (`stalled=true` при `rebuffers=0` у HLS —
  информативно, но за провал не считается.)
- **LLOD-поток не стартует**, в логах панели «Undefined array key 0» в
  `StreamProcess.php` (`startLLOD`) — у этого потока пустой/невалидный
  `stream_source`. Впишите валидный источник в поле Source (шаг 3.2); панель
  обрабатывает llod 2 и 3 одинаково, «режим v3» тут ни при чём.
- **Генератор не запросили** — в логе генератора
  (`docker exec xcvm-test-install cat /tmp/xcvm-streamgen.log`) нет `GET /stream.ts`:
  панель не пытается тянуть источник (см. предыдущий пункт).
- **Бэкап не подходит после переустановки** — фикстура привязана к конкретной
  установке (креды/IP). После `clean` + `install` повторите ручную настройку и
  `streamtest-backup`.

## Одиночная проверка (без панели)

`stream_check.py` работает и как одиночный чекер:

```bash
python3 tools/stream-check/stream_check.py check 'http://host/stream.ts' --duration 30 --json
python3 tools/stream-check/stream_check.py check 'http://host/stream.m3u8' --live         # ANSI-дашборд
python3 tools/stream-check/stream_check.py playlist 'path/or/url.m3u'                      # batch → сводный JSON в stdout
python3 tools/stream-check/stream_check.py playlist 'path/or/url.m3u' --out-dir logs/      # + отдельный файл на каждый стрим
```

`--out-dir DIR` (только для `playlist`) дополнительно пишет по одному файлу
`<NN>-<имя>.json` на каждый поток — по мере его прохождения.

Сводный вывод зависит от того, куда идёт stdout: в **интерактивном терминале** —
короткая сводка (без простыни JSON), при **перенаправлении/пайпе** (в файл, в
`streamtest`) — полный сводный JSON. То есть `… playlist url` в терминале не
засоряет консоль, а `… playlist url > all.json` даёт машинный JSON.

> **Берите URL в кавычки.** Если в ссылке есть `&` (напр.
> `.../playlist/TestLine/TestLine/m3u?output=hls&key=live`), без кавычек bash
> посчитает `&` концом команды и уведёт её в фон, а `key=live` выполнит отдельно.
> Одинарные кавычки экранируют `&`, `?`, `=` — весь URL уйдёт в `playlist`:
>
> ```bash
> python3 tools/stream-check/stream_check.py playlist 'http://172.18.0.2:80/playlist/TestLine/TestLine/m3u?output=hls&key=live'
> ```
>
> Через `test_release.sh streamtest` это не нужно — там URL уже передаётся в кавычках.
