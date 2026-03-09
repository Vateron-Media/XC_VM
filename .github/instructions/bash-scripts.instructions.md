---
description: "Use when writing or editing shell scripts in tools/, src/bin/, or src/infrastructure/. Covers bash conventions for XC_VM."
applyTo: "**/*.sh"
---
# Bash Script Conventions — XC_VM

## Required Header
```bash
#!/bin/bash
set -euo pipefail
```

## Style
- Indent with 4 spaces (not tabs — unlike PHP)
- Quote all variables: `"$var"` not `$var`
- Use `[[ ]]` for conditionals, not `[ ]`
- Use `$()` for command substitution, not backticks

## Error Handling
- `set -e` — exit on first error
- `set -u` — treat unset variables as error
- `set -o pipefail` — catch errors in pipes
- Use `trap` for cleanup on exit when creating temp files

## Security
- Never pass secrets via command-line arguments (visible in `ps`)
- Use environment variables or files with restricted permissions (0600)
- Validate external input before using in commands
- Avoid `eval` unless absolutely necessary

## Paths
- Use absolute paths for system binaries: `/usr/bin/php8.2`, `/usr/sbin/nginx`
- Reference project root via variable, not hardcoded paths
