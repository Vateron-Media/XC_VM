---
applyTo: "**"
---
# Commit Message Format

Use Conventional Commits: `<type>(<scope>): <subject>`

## Types

| Type       | When to use                                              |
|------------|----------------------------------------------------------|
| `feat`     | New feature or behaviour                                 |
| `fix`      | Bug fix                                                  |
| `refactor` | Code change that neither fixes a bug nor adds a feature  |
| `chore`    | Build, tooling, config, dependency changes               |
| `docs`     | Documentation only                                       |
| `test`     | Adding or fixing tests                                   |
| `perf`     | Performance improvement                                  |
| `revert`   | Reverts a previous commit                                |

## Scopes

Match the module, domain area, or file group being changed:

`table`, `auth`, `epg`, `stream`, `vod`, `user`, `bouquet`, `line`, `server`,
`nginx`, `redis`, `cache`, `migrations`, `routing`, `cli`, `admin`, `install`, `tools`

## Subject

- Imperative mood, lowercase, no trailing period
- Max 72 characters
- English only

## Body (optional)

- Separate from subject with a blank line
- Wrap at 72 characters
- Explain *what* and *why*, not *how*
- Reference MIGRATION.md tasks: `Ref: MIGRATION.md L-3D`

## Footer (optional)

- `Closes #<issue>` — when resolving a GitHub issue
- `BREAKING CHANGE: <description>` — when breaking backward compatibility

## Examples

```
feat(table): decompose index() into 45 private handler methods

Extracts each $rType branch into a dedicated private method.
Dispatcher is now a thin switch() block.

Ref: MIGRATION.md L-3D
```

```
refactor(table): remove unused globals from handler methods
```

```
fix(stream): prevent duplicate connection spike on auth check

Ref: mysql-connection-spike-stream-auth
```

```
chore(tools): add cleanup_globals.py for bulk global removal
```

```
docs(migration): close L-3D, mark L-3R as complete
```

## Rules

- One logical change per commit — do not bundle unrelated fixes
- Do not commit generated files, backups (`*.bak.*`), or temp files
- If a commit only touches one file, the scope should reflect the module, not the filename
