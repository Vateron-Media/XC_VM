---
description: "Use when editing the Makefile, understanding build targets, or working with MAIN vs LoadBalancer build configurations."
applyTo: "Makefile"
---
# Makefile & Build — XC_VM

## Build Variants
- **MAIN** (`xc_vm.tar.gz`): Full application — admin panel + streaming + all modules
- **LoadBalancer** (`loadbalancer.tar.gz`): Streaming-only subset — admin dirs stripped
- **Installer** (`XC_VM.zip`): Distribution package with install script

## Key Variables
- `MAIN_DIR = ./src` — full application source
- `LB_DIRS` — subset of directories included in LoadBalancer build
- `LB_DIRS_TO_REMOVE` — admin-only paths stripped from LB builds

## Rules
- Any new directory under `src/` that is streaming-relevant must be added to `LB_DIRS`
- Any new admin-only directory must be added to `LB_DIRS_TO_REMOVE`
- Version hashes are generated into the archives
- Do NOT change archive naming convention without updating CI workflows
