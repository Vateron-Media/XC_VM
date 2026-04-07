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
    }
]
```

### Key Settings

- **`context: "./src/"`** — maps local `src/` to remote `/home/xc_vm/`
- **`uploadOnSave: true`** — every Ctrl+S pushes the file to VDS instantly
- **`ignore`** — protects server-specific files (`bin/`, `config/`, `tmp/`)

> **Security:** Use SSH keys instead of password. The `.vscode/` directory is in `.gitignore`, so credentials won't leak to git.

### Workflow

1. Open project in VS Code
2. Edit any file under `src/`
3. Save — file is automatically uploaded to VDS
4. Test on VDS
5. Commit to git as usual
