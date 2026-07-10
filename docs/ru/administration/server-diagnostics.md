# Диагностика серверов (`server:diagnose`)

Панель помечает proxy/LB-ноду как **офлайн** исключительно по устаревшему heartbeat (`enabled` + `status = 1` + свежий `last_check_ago`), но никогда не объясняет, *почему* нода перестала отчитываться. Команда `server:diagnose` отвечает именно на этот вопрос.

```bash
/home/xc_vm/console.php server:diagnose [server_id]
```

Команда работает **только на чтение**: выполняет пробы ping/curl/`fsockopen`, `SELECT`-запросы и `sudo -n iptables -nL`. Ничего не перезапускает и не перенастраивает.

Реализована в `src/Cli/Commands/ServerDiagnoseCommand.php`. Команда входит **и в MAIN, и в LB** сборку — локальный режим и есть основная причина её наличия на ноде.

---

## Два режима

Режим выбирается автоматически по месту запуска (`server_id` из `config.ini` → `is_main` в таблице `servers`):

### Режим A — удалённая проверка с MAIN

```bash
sudo /home/xc_vm/console.php server:diagnose <server_id>
```

Проверяет целевую ноду снаружи и читает её состояние в панели:

| Проверка | Что показывает |
| --- | --- |
| Enabled / Status / Heartbeat | Как панель сейчас видит ноду (`status = 4` — упавшая установка/провижининг) |
| ICMP ping | Жив ли хост вообще |
| TCP на `http_broadcast_port` | Доступен ли nginx или порт дропается |
| HTTP `GET /api` | Отвечает ли PHP за nginx |
| Clock offset | `time_offset` относительно панели (расхождение > 30 с может «мигать» нодой онлайн/офлайн) |
| Signal queue | Неразобранные строки в `signals` для этой ноды (бэклог > 120 с — цикл обработки сигналов на ноде завис) |

Комбинации проб указывают на причину:

- **Нет ICMP + порт закрыт** — нода выключена, сетевой разрыв или полностью закрыта файрволом.
- **ICMP отвечает, но порт дропается** — классика: собственный iptables ноды заблокировал IP главного сервера (ложное срабатывание flood-защиты RootSignals), либо упал nginx/сервис. Для точной причины запустите локальный режим на самой ноде.
- **Порт открыт, но `/api` молчит** — nginx работает, PHP нет: проверьте php-fpm на ноде.
- **`/api` отвечает, но heartbeat устарел** — на ноде не работает демон watchdog (писатель heartbeat) либо он не может писать в БД панели. Запустите локальный режим на ноде.

### Режим B — локальная самодиагностика НА ноде

```bash
sudo /home/xc_vm/console.php server:diagnose
```

Запускается **на самой «молчащей» LB/proxy-ноде** — причины обычно живут именно там. Аргумент не нужен: нода определяет себя по `config.ini`. Проверяет:

1. **Собственную строку в панели** — enabled/status/heartbeat глазами main.
2. **Доступность MAIN** — связь с БД (доказана самим запросом), ICMP и TCP до broadcast-порта main.
3. **Не заблокировала ли нода main?** — ищет `DROP` c IP главного сервера в цепочке `iptables INPUT` этой ноды плюс файл-маркер flood-блокировки. Это классическая причина «нода замолчала без причины»: flood-защита молча дропает публичные IP, и коллбэки main перестают доходить.
4. **Сервис / nginx** — `systemctl is-active xc_vm` и локальная TCP-проверка собственного broadcast-порта.
5. **Демон watchdog** — настоящий писатель heartbeat: демон `watchdog` обновляет `last_check_ago` каждые несколько секунд. При обрыве MySQL-соединения с main он **ждёт возвращения базы** (повтор каждые 5 с) и сразу возобновляет heartbeat. Старые сборки вместо этого завершались — поэтому рестарт/сбой MySQL на main ронял **все ноды одновременно**, пока `cron:servers` их не поднимал.
6. **Крон-«нянька»** — три подпроверки, потому что мёртвый watchdog остаётся мёртвым, только если сломана цепочка «няньки»:
   - есть ли `cron:servers` в crontab **пользователя `xc_vm`** (если нет — перегенерировать: `rm -f /home/xc_vm/tmp/crontab` и перезапустить сервис);
   - активен ли системный **сервис cron** (нет cron — crontab вообще не срабатывает);
   - не **завис ли предыдущий экземпляр `cron:servers` на своём локе** — зависший экземпляр блокирует все последующие запуски до 30 минут (stale-таймаут `acquireCronLock`); именно так один сбой БД держит ноду офлайн полчаса. Команда печатает PID держателя лока и команду kill.
7. **Расхождение часов** — `time_offset` относительно панели.

> **Примечание:** проверка iptables требует беспарольного sudo (`sudo -n`). Без него проверка выводит `cannot check (need sudo iptables)`, а не падает — для полной картины запускайте команду от `root`.

---

## Вывод и коды выхода

Каждая проверка печатает одну выровненную строку `[OK]`/`[WARN]`, затем идёт нумерованная сводка **Probable cause(s)** с точной командой исправления, где она есть (например, строка разблокировки `iptables -D INPUT ... -j DROP`).

| Код выхода | Значение |
| --- | --- |
| `0` | Явная причина не найдена (или цель — сам MAIN, диагностировать нечего) |
| `1` | Ошибка использования: не указан/не найден `server_id` |
| `2` | Найдена и выведена одна или несколько вероятных причин |

Пример (локальный режим, нода сама заблокировала main):

```text
Self-diagnosis on node #3 — LB-Frankfurt (type 1)
----------------------------------------------------------------
[OK]   Enabled          yes
[OK]   Status           1 (online)
[WARN] Heartbeat        last check-in 641s ago (limit 180s)
[OK]   DB → main        reachable (this query ran)
[OK]   Ping main        reply (203.0.113.10)
[WARN] Main :8080       closed/timeout
[WARN] Main in iptables DROP present (+flood marker)
[OK]   Service xc_vm    active
[OK]   nginx :8080      listening
[OK]   watchdog daemon  running
[OK]   cron:servers     in xc_vm crontab
[OK]   Clock offset     2s vs panel
----------------------------------------------------------------
Probable cause(s):
  1. Heartbeat is stale (641s > 180s): the node stopped reporting — the checks below narrow down why.
  2. This node has DROPPED the main's IP 203.0.113.10 in its own iptables (flood/block false-positive). Unblock: `sudo iptables -D INPUT -s 203.0.113.10 -j DROP && sudo rm -f /home/xc_vm/tmp/flood/block_203.0.113.10`.
```

---

## Шпаргалка — типичные причины

| Симптом | Вероятная причина | Исправление |
| --- | --- | --- |
| Пингуется, порт дропается | iptables ноды заблокировал IP main | `sudo iptables -D INPUT -s <main_ip> -j DROP` + удалить маркер `block_<ip>` (команда печатает точную строку) |
| Нет пинга и порта | Хост выключен / сетевой разрыв / внешний файрвол | Проверить консоль хостинга, маршруты, файрвол провайдера |
| Порт открыт, `/api` мёртв | Упал php-fpm | Перезапустить сервис `xc_vm` на ноде |
| `/api` в порядке, heartbeat устарел | Демон watchdog мёртв (завершается при обрыве соединения с БД) | `sudo -u xc_vm console.php watchdog` на ноде; проверить гранты БД (`tools mysql` на main) |
| **Все ноды отвалились в один момент** | Рестарт/сбой MySQL на main ударил по watchdog всех нод разом (фатально для старых сборок; текущие пережидают сбой) | Смотреть error log MySQL на main в момент падения; обновить ноды, чтобы watchdog переживал сбои |
| Нода «мигает» онлайн/офлайн | Расхождение часов > 30 с (или повторяющиеся сбои MySQL) | Синхронизировать NTP на ноде; проверить стабильность MySQL на main |
| Status = 4 | Ошибка установки/провижининга | Повторить `server:install` с main |

---

## Связанные файлы

| Файл | Роль |
| --- | --- |
| `src/Cli/Commands/ServerDiagnoseCommand.php` | Команда диагностики (оба режима) |
| `src/Cli/Commands/WatchdogCommand.php` | Демон watchdog — пишет heartbeat (`last_check_ago`) |
| `src/Cli/CronJobs/ServersCronJob.php` | Крон-«нянька» — перезапускает умерший watchdog |
| `src/Cli/CronJobs/RootSignalsCronJob.php` | Ставит блокировки iptables (источник ложных срабатываний) |
| `src/Domain/Server/ServerRepository.php` | Доступ к таблице `servers` |

См. также: [CLI-инструменты](ru-ru/guides/cli-tools.md), [Обновление сервера](ru-ru/administration/server-update.md).
