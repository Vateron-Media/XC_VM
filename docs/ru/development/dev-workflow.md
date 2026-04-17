# Рабочий процесс разработки

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
