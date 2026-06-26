# Рабочий процесс разработки

Как настроить проект локально, запускать проверки качества и деплоить код на сервер разработки.

---

## Локальная настройка

Закоммиченный `src/vendor/` — **только продакшен**, поэтому dev-инструментов
(PHPStan, PHP-CS-Fixer) в дереве нет. Установите их один раз из закоммиченного lock:

```bash
make dev-tools          # = cd src && composer install
```

Это добавит `require-dev`-пакеты в `src/vendor/`. **Никогда не коммитьте их** —
закоммиченный vendor должен оставаться прод-only (`composer install --no-dev`).
`.gitignore` не даёт dev-пакетам попасть в `git add`, а CI-гейт
(`check-vendor-prod-only`) валит сборку, если такой пакет всё же закоммичен.

## Проверки качества

Запускайте перед push — CI выполняет тот же набор:

| Команда | Что проверяет |
| --- | --- |
| `make phpstan` | Статический анализ против закоммиченного baseline (падает только на НОВЫХ проблемах) |
| `make cs` | Стиль кода — гигиена импортов/namespace (PHP-CS-Fixer, dry-run) |
| `make cs-fix` | Применить исправления стиля |
| `make gates` | Регресс-гейты PSR-4 (ниже) |
| `php tools/.bin/phpunit.phar -c tests/phpunit.xml.dist` | Юнит-тесты |

`make phpstan` и `make cs` требуют dev-инструментов — сначала `make dev-tools`.

`make gates` объединяет три проверки:

- **check-procedural-use** — процедурные / view-файлы импортируют каждый мигрированный класс, который используют (импорты PHP позиционны, поэтому `use` должен идти до использования);
- **verify-lb-archive** — LB-сборка исключает привилегированный код (admin/reseller-контроллеры, домен user/device, install/root-команды);
- **check-vendor-prod-only** — ни один `require-dev`-пакет не закоммичен в `src/vendor/`.

## Деплой кода на VDS через SFTP

Для ежедневной разработки рекомендуем [расширение SFTP](https://marketplace.visualstudio.com/items?itemName=Natizyskunk.sftp) для VS Code — редактируете локально, файлы автоматически загружаются при сохранении.

### Настройка

Создайте `.vscode/sftp.json`:

```json
[
    {
        "name": "My Dev VDS",
        "host": "IP_ВАШЕГО_VDS",
        "protocol": "sftp",
        "port": 22,
        "username": "root",
        "remotePath": "/home/xc_vm",
        "useTempFile": false,
        "uploadOnSave": true,
        "openSsh": false,
        "watcher": {
            "files": "**/*",
            "autoUpload": false,
            "autoDelete": true
        },
        "ignore": [
            ".vscode",
            ".git",
            ".gitattributes",
            ".gitignore",
            "update",
            "*pycache/",
            "*.gitkeep",
            "bin/",
            "config/",
            "tmp/"
        ],
        "context": "./src/",
        "profiles": {}
    },
    {
        "name": "My Dev VDS Tests",
        "host": "IP_ВАШЕГО_VDS",
        "protocol": "sftp",
        "port": 22,
        "username": "root",
        "remotePath": "/home/xc_vm/tests",
        "useTempFile": false,
        "uploadOnSave": true,
        "openSsh": false,
        "watcher": {
            "files": "**/*",
            "autoUpload": false,
            "autoDelete": true
        },
        "ignore": [
            ".vscode",
            ".git",
            ".gitattributes",
            ".gitignore",
            "tmp/",
            ".cache/"
        ],
        "context": "./tests/",
        "profiles": {}
    }
]
```

### Ключевые настройки

- **`context: "./src/"`** — маппит локальную `src/` на удалённую `/home/xc_vm/`
- **`context: "./tests/"`** — маппит локальную `tests/` на удалённую `/home/xc_vm/tests/`
- **`uploadOnSave: true`** — каждый Ctrl+S мгновенно загружает файл на VDS
- **`ignore`** — защищает серверо-специфичные файлы (`bin/`, `config/`, `tmp/`)

> **Безопасность:** Используйте SSH-ключи вместо пароля. Директория `.vscode/` находится в `.gitignore`, поэтому креды не попадут в git.

### Как синхронить папку tests

1. Добавьте второй SFTP entry с `context: "./tests/"` и `remotePath: "/home/xc_vm/tests"`.
2. Сохраняйте файлы внутри `tests/` локально.
3. Расширение будет загружать их отдельно от `src/` прямо в `/home/xc_vm/tests`.
4. Это нужно, потому что тесты не лежат внутри `src/` и не попадут на сервер через основной entry.

### Рабочий процесс

1. Открываете проект в VS Code
2. Редактируете любой файл в `src/`
3. Если пишете тест, редактируете файл в `tests/`
4. Сохраняете — соответствующий entry автоматически загружает файл на VDS
5. Запускаете нужный тест на VDS
6. Коммитите в git как обычно

## Связанные файлы

| Файл | Роль |
| --- | --- |
| `.vscode/sftp.json` | Конфиг синхронизации local → VDS (в gitignore) |
| `Makefile` | `make dev-tools`, `make phpstan`, `make cs`, `make gates` |
| `src/composer.json` | Зависимости + PSR-4-автозагрузка |
