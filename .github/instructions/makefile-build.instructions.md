---
description: "Use when editing the Makefile, understanding build targets, or working with MAIN vs LoadBalancer build configurations."
applyTo: "Makefile"
---
# Makefile & Build — XC_VM

## Build Variants
- **MAIN** (`xc_vm.tar.gz`): Full application — admin panel + streaming + all modules. Same archive for install and update.
- **LoadBalancer** (`loadbalancer.tar.gz`): Streaming-only subset — admin dirs stripped. Same archive for install and update.
- **Installer** (`XC_VM.zip`): Distribution package with install script + xc_vm.tar.gz inside.

## Build Targets
- `make new` — clean dist/ directory
- `make main` — build MAIN archive (includes `delete_files_list` for update support)
- `make lb` — build LB archive (includes `lb_delete_files_list` for update support)
- Pass `LAST_TAG=...` to control deleted-files diff base (auto-detected from GitHub API if omitted)

## Update Architecture
- A single archive is used for both clean install and update
- Dirs skipped during update are defined by the hardcoded `UPDATE_EXCLUDE_DIRS` list inside `src/update`
- The update script (`src/update`) extracts to /tmp, removes excluded dirs, then copies remaining files over the live installation
- `migrations/deleted_files.txt` (generated from git diff) lists files removed since last release

## Key Variables
- `MAIN_DIR = ./src` — full application source
- `UPDATE_EXCLUDE_DIRS` — runtime list in `src/update` for dirs excluded during update (binaries, user data, config)
- `LB_DIRS` — subset of directories included in LoadBalancer build
- `LB_DIRS_TO_REMOVE` — admin-only paths stripped from LB builds

## Rules
- Any new directory under `src/` that is streaming-relevant must be added to `LB_DIRS`
- Any new admin-only directory must be added to `LB_DIRS_TO_REMOVE`
- Version hashes are generated into `hashes.md5`
- Do NOT change archive naming convention without updating CI workflows
