# Database Updates / Migrations

XC_VM uses a file-based DB update system to manage schema changes between versions. DB updates run automatically during panel updates and system status checks, and can be applied by hand with `db:migrate`.

> For the console entry point, command registry and how to register a command, see [CLI Tools & Console Reference](cli-tools.md).

---

## How It Works

- SQL files for DB updates are stored in `/home/xc_vm/migrations/` (`src/migrations/` in the source repository).

- Each file is named with a sequential number prefix, for example:

```text
001_drop_watch_folders_plex_token.sql
002_panel_logs_add_file_env.sql
003_drop_settings_segment_type.sql
```

- Applied DB update steps are tracked in the `migrations` database table. Each step runs **exactly once** — if a step has already been applied, it is skipped. There is no down/rollback path: migrations are forward-only, so keep them backward-compatible where possible.

- DB updates are executed automatically by:
  - `console.php update post-update` — after a panel update
  - `console.php status` — during system status check (MAIN server only)

Core logic lives in `MigrationRunner` (`src/Core/Database/MigrationRunner.php`).

### DB Update Execution Flow

```text
[ MigrationRunner::run() — DB update execution ]
        │
        ▼
[ CREATE TABLE IF NOT EXISTS `migrations` ]
        │
        ▼
[ Read all *.sql files from migrations/ ]
        │
        ▼
[ For each file not in `migrations` table: ]
    ├── Execute SQL statements
    ├── Record in `migrations` table
    └── Output [OK] (recorded) or [FAIL] (not recorded)
```

---

## Creating a New DB Update Step

When you need to modify the database schema (add columns, create tables, insert data, etc.), create a new SQL file for a DB update step.

### Step 1. Choose a File Name

Use the next sequential number and a descriptive name:

```text
NNN_short_description.sql
```

**Format rules:**

- Number prefix: 3 digits, zero-padded (e.g., `006`, `007`)
- Separator: underscore `_`
- Name: lowercase, underscores, describing what the update step does
- Extension: `.sql`

**Examples:**

```text
006_add_user_timezone.sql
007_create_audit_log_table.sql
008_insert_default_codec_settings.sql
```

### Step 2. Write the SQL

Place raw SQL statements in the file. Multiple statements are separated by `;`.

**Rules for SQL DB update steps:**

- **Use `IF EXISTS` / `IF NOT EXISTS`** to make DB update steps idempotent:

```sql
-- Adding a column (safe)
ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `timezone` VARCHAR(64) DEFAULT 'UTC';

-- Dropping a column (safe)
ALTER TABLE `settings` DROP COLUMN IF EXISTS `old_column`;

-- Creating a table (safe)
CREATE TABLE IF NOT EXISTS `audit_log` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `action` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
```

- **Use conditional `INSERT`** to avoid duplicates:

```sql
INSERT INTO `streams_arguments` (argument_key, argument_name, argument_cmd)
SELECT 'my_key', 'My Argument', '-my_flag %s'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `streams_arguments` WHERE argument_key = 'my_key');
```

- **Do not mix DDL and DML** that depend on each other in the same file. If you need to add a column and then populate it, use two DB update step files.

- **Comments** are supported with `--` prefix (they are skipped during execution).

> Idempotency matters because a failed step is **not** recorded — it prints `[FAIL] <name> (not recorded — will retry on next run)` and re-runs on the **next** `db:migrate`. A non-idempotent step that half-applied before failing will be retried from the top, so every statement must be safe to run again (use `IF NOT EXISTS`, `INSERT ... ON DUPLICATE KEY UPDATE`, etc.).

### Step 3. Place the File

Copy the SQL file for the DB update step to:

```text
/home/xc_vm/migrations/
```

> 💡 In the source repository, this is `src/migrations/`.

### Step 4. Validate DB Update

Run `db:migrate` to apply pending DB update steps:

```bash
su - xc_vm -c '/home/xc_vm/console.php db:migrate'
```

Or via `status first-run` (also runs migrations):

```bash
sudo /home/xc_vm/console.php status first-run
```

Expected output:

```text
Migrations
------------------------------
  [OK]   006_add_user_timezone.sql

```

If a statement fails, the step prints `[FAIL]` and is **not** recorded — so it will be retried on the next run. Review the SQL, fix it, and re-run `db:migrate`.

---

## Applying Migrations Manually

Apply all pending `.sql` files from `/home/xc_vm/migrations/` without a full system update:

```bash
su - xc_vm -c '/home/xc_vm/console.php db:migrate'
```

### Data migration from another system

Transfer data from the staging database `xc_vm_migrate`:

```bash
/home/xc_vm/console.php migrate
```

See the [Database Update Guide](../info/migration_guide.md) for details.

---

## Related files

| File | Role |
| --- | --- |
| `src/migrations/` | Database migration `.sql` files |
| `src/Core/Database/MigrationRunner.php` | Executes pending migrations, records the `migrations` table |
| `src/console.php` | `db:migrate` / `status` / `migrate` entry point |
