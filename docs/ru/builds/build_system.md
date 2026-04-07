<h1 align="center">📦 XC_VM — Система сборки (MAIN vs LB)</h1>

<p align="center">
  Как XC_VM создаёт два варианта сборки из единой кодовой базы:<br>
  полноценный MAIN-сервер и облегчённый сервер балансировки нагрузки (LB).
</p>

---

## 📚 Навигация

* [🏗 Варианты сборки](#варианты-сборки)
* [⚙️ Цели Makefile](#цели-makefile)
* [📂 Что входит в каждую сборку](#что-входит-в-каждую-сборку)
* [🔀 MAIN vs LB — ключевые отличия](#main-vs-lb--ключевые-отличия)
* [🌐 Nginx-конфигурация LB](#nginx-конфигурация-lb)
* [🔧 Поведение LB в рантайме](#поведение-lb-в-рантайме)
* [➕ Добавление нового кода в сборки](#добавление-нового-кода-в-сборки)
* [✅ Проверка сборки](#проверка-сборки)

---

## 🏗 Варианты сборки

XC_VM поддерживает две роли развёртывания из единого исходного дерева:

| Вариант | Архив | Назначение |
|---|---|---|
| **MAIN** | `xc_vm.tar.gz` | Полное приложение — админ-панель, стриминг, все модули, крон-задачи |
| **LB** (Load Balancer) | `loadbalancer.tar.gz` | Только стриминг — без админ-панели, без управления пользователями |

**MAIN** — основной сервер, который управляет всем: UI администратора, запись в БД, управление пользователями/устройствами, обработка EPG, бэкапы и т.д.

**LB** — облегчённый стриминг-узел, который получает потоки от MAIN (или других источников) и доставляет их клиентам. Подключается к основной БД в режиме только для чтения и не имеет админ-панели или средств управления.

---

## ⚙️ Цели Makefile

| Цель | Результат | Описание |
|---|---|---|
| `make main` | `dist/xc_vm.tar.gz` | Полная MAIN-сборка |
| `make lb` | `dist/loadbalancer.tar.gz` | LB-сборка (только стриминг) |
| `make main_update` | `dist/update.tar.gz` | Инкрементальное обновление MAIN |
| `make lb_update` | `dist/loadbalancer_update.tar.gz` | Инкрементальное обновление LB |
| `make new` | Обе полные сборки | Сокращение: `main` + `lb` |

Дополнительные артефакты:
- `XC_VM.zip` — пакет установщика (`install/` + `xc_vm.tar.gz`)
- `hashes.md5` — контрольные суммы MD5 для проверки целостности

---

## 📂 Что входит в каждую сборку

### Сборка MAIN

MAIN-сборка содержит **всю** директорию `src/` целиком.

### Сборка LB — включённые директории

В LB-архив копируются только эти директории:

```
bin/        cli/        config/     content/    core/
domain/     includes/   infrastructure/         resources/
signals/    streaming/  tmp/        www/
```

Плюс корневые файлы: `autoload.php`, `bootstrap.php`, `console.php`, `service`, `status`, `update`.

### Сборка LB — исключённый контент

После копирования из LB-сборки **удаляется** контент, специфичный для администрирования:

**Удаляемые директории:**
| Путь | Причина |
|---|---|
| `bin/install/` | Скрипты установки (не нужны на LB) |
| `bin/redis/` | Бинарник Redis (LB не запускает свой Redis) |
| `bin/nginx/conf/codes/` | Страницы кодов ошибок (админ UI) |
| `resources/langs/` | Языковые ресурсы |
| `includes/api/` | Маршруты админ API |
| `includes/libs/resources/` | Библиотеки ресурсов админки |
| `domain/User/` | Управление пользователями |
| `domain/Device/` | Регистрация устройств |
| `domain/Auth/` | Авторизация (панельная) |
| `resources/langs/` | Языковые ресурсы |
| `resources/libs/` | Библиотечные ресурсы |

**Удаляемые файлы:**
| Файл | Причина |
|---|---|
| `includes/admin.php`, `includes/admin_api.php` | Логика админ-панели |
| `includes/reseller_api.php` | API реселлеров |
| `www/xplugin.php`, `www/probe.php`, `www/playlist.php` | Эндпоинты админки |
| `www/player_api.php`, `www/epg.php`, `www/enigma2.php` | Клиентское API (обслуживается MAIN) |
| `www/admin/api.php`, `www/admin/proxy_api.php` | Админ API |
| `config/rclone.conf` | Конфиг бэкапов |
| `domain/Epg/EPG.php` | Класс обработки EPG |
| `bin/nginx/conf/gzip.conf` | Gzip-конфиг (LB использует свой) |

**Удаляемые CLI-команды:**
| Файл | Причина |
|---|---|
| `cli/Commands/MigrateCommand.php` | Миграция только на MAIN |
| `cli/Commands/CacheHandlerCommand.php` | Обработчик кеша только на MAIN |
| `cli/Commands/BalancerCommand.php` | Установщик LB (не нужен на самом LB) |

**Удаляемые крон-задачи:**
| Файл | Причина |
|---|---|
| `cli/CronJobs/RootMysqlCronJob.php` | Обслуживание БД (только MAIN) |
| `cli/CronJobs/BackupsCronJob.php` | Бэкапы (только MAIN) |
| `cli/CronJobs/CacheEngineCronJob.php` | Полная перегенерация кеша (только MAIN) |
| `cli/CronJobs/EpgCronJob.php` | Обработка EPG (только MAIN) |
| `cli/CronJobs/UpdateCronJob.php` | Проверка обновлений (только MAIN) |
| `cli/CronJobs/ProvidersCronJob.php` | Синхронизация провайдеров (только MAIN) |
| `cli/CronJobs/SeriesCronJob.php` | Метаданные сериалов (только MAIN) |

> **Примечание:** Кроны модулей (TMDB, Plex, Watch) теперь находятся в `modules/<name>/` и автоматически исключены из LB-сборок, так как `modules/` не входит в `LB_DIRS`.

### Сборка LB — заменяемые конфиги

Эти файлы из `lb_configs/` **заменяют** MAIN-версии:

| Источник | Цель | Назначение |
|---|---|---|
| `lb_configs/nginx.conf` | `bin/nginx/conf/nginx.conf` | Nginx, оптимизированный для стриминга |
| `lb_configs/live.conf` | `bin/nginx_rtmp/conf/live.conf` | RTMP-хуки для аутентификации |

---

## 🔀 MAIN vs LB — ключевые отличия

| Аспект | MAIN | LB |
|---|---|---|
| Админ-панель | ✅ Полный UI | ❌ Не включена |
| Роль БД | Чтение + Запись | Только чтение |
| Управление пользователями/устройствами | ✅ | ❌ |
| Обработка EPG | ✅ | ❌ |
| Бэкапы | ✅ | ❌ |
| Инструмент миграции | ✅ | ❌ |
| Доставка стримов | ✅ | ✅ |
| RTMP-приём | ✅ | ✅ |
| Транскодирование (FFmpeg) | ✅ | ✅ |
| CLI-команды | 26 | ~15 (админские удалены) |
| Крон-задачи | 25 | ~16 (админские удалены) |
| Модульная система | ✅ | ❌ |

---

## 🌐 Nginx-конфигурация LB

LB-сборка использует специальный nginx-конфиг, оптимизированный для высокопропускного стриминга:

| Настройка | Значение | Назначение |
|---|---|---|
| Worker processes | `auto` | Масштабирование под кол-во CPU |
| Worker connections | 16 000 | Высокая конкурентность на воркер |
| Макс. файловых дескрипторов | 300 000 | Лимит системных ресурсов |
| Пул потоков | `pool_xc_vm` (32 потока) | Асинхронный I/O для стриминга |
| Gzip | ВЫКЛ | Стриминговые данные уже сжаты |
| Access-логи | ВЫКЛ | Снижение I/O-нагрузки |
| Rate limiting | 20 запр./сек на IP | Защита от DDoS |
| Send timeout | 20 мин | Поддержка долгих стримов |

RTMP-хуки (`lb_configs/live.conf`) маршрутизируют аутентификацию через локальные HTTP-колбэки вместо админ-панели:

```nginx
on_play http://127.0.0.1:8080/stream/rtmp;
on_publish http://127.0.0.1:8080/stream/rtmp;
on_play_done http://127.0.0.1:8080/stream/rtmp;
```

---

## 🔧 Поведение LB в рантайме

### Условная загрузка команд

`console.php` использует проверки `file_exists()` для команд, которых может не быть на LB-серверах:

```php
if (file_exists(__DIR__ . '/cli/Commands/CacheHandlerCommand.php')) {
    $rRegistry->register(new CacheHandlerCommand());
}
```

Это предотвращает падения, когда LB пытается зарегистрировать команду, чей файл был удалён при сборке.

### Цепочка зависимостей стриминга

LB-серверы сохраняют полный стриминг-пайплайн:

```
www/stream.php
  ├── autoload.php
  ├── bootstrap.php (CONTEXT_STREAM)
  ├── core/* (Config, Database, Cache, Auth, Http, Logging, Util)
  ├── domain/Stream, domain/Server, domain/Vod, domain/Bouquet
  ├── streaming/* (Auth, Delivery, Codec, Protection)
  ├── infrastructure/redis, infrastructure/database
  └── resources/data
```

---

## ➕ Добавление нового кода в сборки

### Новая директория под `src/`, нужная для стриминга

Добавьте в `LB_DIRS` в Makefile:

```makefile
LB_DIRS = bin cli config content core domain ... your_dir
```

### Новая директория только для админки

Добавьте в `LB_DIRS_TO_REMOVE`:

```makefile
LB_DIRS_TO_REMOVE = ... your_dir/admin_stuff
```

### Новый файл только для админки

Добавьте в `LB_FILES_TO_REMOVE`:

```makefile
LB_FILES_TO_REMOVE = ... your_dir/admin_file.php
```

### Новая CLI-команда (только для MAIN)

1. Добавьте проверку `file_exists()` в `console.php`
2. Добавьте файл в `LB_FILES_TO_REMOVE`

---

## ✅ Проверка сборки

После модификации сборки проверьте оба варианта:

```bash
# Собрать оба варианта
make new

# Проверить, что LB содержит стриминг-код
tar -tzf dist/loadbalancer.tar.gz | grep -cE "core/|domain/Stream|streaming/"
# Ожидается: > 0

# Проверить, что LB НЕ содержит админ-код
tar -tzf dist/loadbalancer.tar.gz | grep -cE "admin/|player/|ministra|reseller"
# Ожидается: 0

# Сравнить размеры (LB должен быть значительно меньше)
ls -lh dist/xc_vm.tar.gz dist/loadbalancer.tar.gz
```
