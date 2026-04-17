# Development Workflow

## Deploying Code to VDS via SFTP

For daily development, we recommend the [SFTP extension](https://marketplace.visualstudio.com/items?itemName=Natizyskunk.sftp) for VS Code — edit locally, auto-upload on save.

### Setup

Create `.vscode/sftp.json`:

```json
[
    {
        "name": "My Dev VDS",
        "host": "YOUR_VDS_IP",
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
        "host": "YOUR_VDS_IP",
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

### Key Settings

- **`context: "./src/"`** — maps local `src/` to remote `/home/xc_vm/`
- **`context: "./tests/"`** — maps local `tests/` to remote `/home/xc_vm/tests/`
- **`uploadOnSave: true`** — every Ctrl+S pushes the file to VDS instantly
- **`ignore`** — protects server-specific files (`bin/`, `config/`, `tmp/`)

> **Security:** Use SSH keys instead of password. The `.vscode/` directory is in `.gitignore`, so credentials won't leak to git.

### How to sync the tests folder

1. Add a second SFTP entry with `context: "./tests/"` and `remotePath: "/home/xc_vm/tests"`.
2. Save files under `tests/` locally.
3. The extension will upload them separately from `src/` into `/home/xc_vm/tests`.
4. This is required because tests are stored outside `src/` and will not be uploaded by the main entry.

### Workflow

1. Open project in VS Code
2. Edit any file under `src/`
3. If you add a test, edit the file under `tests/`
4. Save — the matching SFTP entry uploads the file to VDS
5. Run the relevant test on VDS
6. Commit to git as usual
