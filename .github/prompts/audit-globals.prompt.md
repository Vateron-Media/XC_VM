---
description: "Audit global variable usage across the codebase. Count and categorize occurrences of global $db, $rSettings, $rUserInfo, $rServers."
---
# Audit Global Variables

Scan the specified files or directories for global variable usage.

## Target Globals
- `global $db`
- `global $rSettings`
- `global $rUserInfo`
- `global $rServers`
- `global $rPermissions`
- Any other `global $` declarations

## Steps

1. Run `grep -rn "global \$" <target_path>` to find all occurrences
2. Count by variable name: how many files use each global
3. Count by directory: which areas have the most global usage
4. Categorize files by migration difficulty:
   - **Easy**: 1-2 globals, simple usage, clear replacement path
   - **Medium**: 3-5 globals or complex data flow
   - **Hard**: Deep nesting, globals used in conditions/loops, cross-file dependencies

## Output Format

```
## Summary
- Total global declarations: N
- Files affected: N
- Most common global: $xxx (N files)

## By Variable
| Global | File Count | Occurrences |
|--------|-----------|-------------|

## By Directory
| Directory | Count | Top Global |
|-----------|-------|------------|

## Migration Candidates (easiest first)
1. file.php — 1 global ($db), simple replacement
2. ...
```
