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
    }
]
```

### Ключевые настройки

- **`context: "./src/"`** — маппит локальную `src/` на удалённую `/home/xc_vm/`
- **`uploadOnSave: true`** — каждый Ctrl+S мгновенно загружает файл на VDS
- **`ignore`** — защищает серверо-специфичные файлы (`bin/`, `config/`, `tmp/`)

> **Безопасность:** Используйте SSH-ключи вместо пароля. Директория `.vscode/` находится в `.gitignore`, поэтому креды не попадут в git.

### Рабочий процесс

1. Открываете проект в VS Code
2. Редактируете любой файл в `src/`
3. Сохраняете — файл автоматически загружается на VDS
4. Тестируете на VDS
5. Коммитите в git как обычно
