---
description: "Migrate a legacy PHP file from global variable usage to the Service/Repository pattern. Step-by-step guided migration."
---
# Migrate to Service Pattern

Migrate the specified file from legacy globals to the target architecture.

## Input
Provide the file path to migrate.

## Process

### 1. Analyze Current State
- List all `global $` declarations in the file
- Map which methods use which globals
- Identify SQL queries that should move to a Repository
- Identify business logic that should move to a Service

### 2. Plan Migration
- Determine target context: `src/domain/{Context}/`
- Decide if Service + Repository should be one file or separate (< 150 lines combined → one file)
- List all external call sites (grep for class/function names)

### 3. Create Service/Repository
- Follow Controller → Service → Repository pattern
- Use constructor injection for Database, Cache, etc.
- Preserve `$r` variable naming convention
- Use K&R brace style, tab indentation

### 4. Update Call Sites
- Replace old calls with new Service/Repository calls
- Preserve backward compatibility where possible

### 5. Verify
- `php -l` on every changed file
- `grep` for old method names to confirm no orphaned references
- Count remaining globals in the migrated file (should be 0 or reduced)

## Output
- New Service/Repository file(s)
- Updated original file
- List of changed call sites
- Syntax verification results
