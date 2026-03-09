---
description: "Use when analyzing full project architecture, mapping entry points, subsystems, bootstrap flow, and logical layers of XC_VM."
---
# Research Architecture

Goal:
Understand the full architecture of the XC_VM project.

Instructions:

1. Scan the entire repository structure.

2. Identify:
   - main directories
   - core modules
   - configuration locations
   - scripts and services
   - CLI tools
   - web entry points

3. Determine:
   - application entrypoints (index.php, api, cron jobs)
   - bootstrap files
   - autoloaders
   - configuration loading flow

4. Identify major subsystems:
   - authentication
   - streaming management
   - user management
   - API
   - background workers
   - monitoring

5. Detect framework usage if present.

6. Map logical architecture layers:
   - presentation layer
   - business logic
   - database layer
   - system interaction

Output format:

1. Repository Structure
2. Main Entry Points
3. Core Modules
4. System Components
5. Configuration Flow
6. Initial Architecture Diagram (logical)
