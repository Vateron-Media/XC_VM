# Обновления / миграции баз данных

XC_VM использует файловую систему обновления базы данных для управления изменениями схемы между версиями. Обновления базы данных запускаются автоматически при обновлении панели управления и проверке состояния системы и могут быть применены вручную с помощью `db:migrate`.

> Информацию о точке входа в консоль, реестре команд и о том, как зарегистрировать команду, смотрите в [CLI Tools & Console Reference](cli-tools.md).

---

## как это работает

- SQL-файлы для обновлений базы данных хранятся в `/home/xc_vm/migrations/` (`src/migrations/` в репозитории исходных текстов).

- Каждому файлу присваивается имя с префиксом последовательного номера, например:

```text
001_drop_watch_folders_plex_token.sql
002_panel_logs_add_file_env.sql
003_drop_settings_segment_type.sql
```

- Применяемые шаги обновления базы данных отслеживаются в таблице базы данных `migrations`. Каждый шаг выполняется **ровно один раз** — если какой-либо шаг уже был применен, он пропускается. Нет пути возврата: миграции выполняются только в прямом направлении, поэтому по возможности поддерживайте их обратную совместимость.

- Обновления базы данных выполняются автоматически:
  - `console.php update post-update` — после обновления панели
  - `console.php status` — во время проверки состояния системы (только для главного сервера)

Основная логика находится в `MigrationRunner` (`src/Core/Database/MigrationRunner.php`).

### Поток выполнения обновления базы данных

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

## Создание нового шага обновления базы данных

Когда вам нужно изменить схему базы данных (добавить столбцы, создать таблицы, вставить данные и т.д.), создайте новый SQL-файл для этапа обновления базы данных.

### Шаг 1. Выберите имя файла

Используйте следующий порядковый номер и описательное название:

```text
NNN_short_description.sql
```

**Format rules:**

- Числовой префикс: 3 цифры, дополненные нулем (например, `006`, `007`)
- Разделитель: символ подчеркивания `_`
- Название: нижний регистр, подчеркивание, описывающее, что делает шаг обновления
- Добавочный номер: `.sql`

**Examples:**

```text
006_add_user_timezone.sql
007_create_audit_log_table.sql
008_insert_default_codec_settings.sql
```

### Шаг 2. Напишите SQL-код

Поместите в файл необработанные инструкции SQL. Несколько инструкций разделяются символом `;`.

**Rules for SQL DB update steps:**

- **Используйте `IF EXISTS` / `IF NOT EXISTS`** чтобы сделать шаги обновления базы данных идемпотентными:

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

- **Использовать условное значение `INSERT`** чтобы избежать дублирования:

```sql
INSERT INTO `streams_arguments` (argument_key, argument_name, argument_cmd)
SELECT 'my_key', 'My Argument', '-my_flag %s'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `streams_arguments` WHERE argument_key = 'my_key');
```

- **Не смешивайте DDL и DML**, которые зависят друг от друга в одном файле. Если вам нужно добавить столбец и затем заполнить его, используйте два файла шагов обновления базы данных.

- **Комментарии** поддерживаются с префиксом `--` (они пропускаются во время выполнения).

> Idempotency matters because a failed step is **not** recorded — it prints `[FAIL] <name> (not recorded — will retry on next run)` and re-runs on the **next** `db:migrate`. A non-idempotent step that half-applied before failing will be retried from the top, so every statement must be safe to run again (use `IF NOT EXISTS`, `INSERT ... ON DUPLICATE KEY UPDATE`, etc.).

### Шаг 3. Поместите файл

Скопируйте SQL-файл для шага обновления базы данных в:

```text
/home/xc_vm/migrations/
```

> 💡 В хранилище исходных текстов это значение равно `src/migrations/`.

### Шаг 4. Подтвердите обновление базы данных

Запустите `db:migrate`, чтобы применить ожидающие обновления БД шаги:

```bash
su - xc_vm -c '/home/xc_vm/console.php db:migrate'
```

Или через `status first-run` (также запускает миграции):

```bash
sudo /home/xc_vm/console.php status first-run
```

Ожидаемый результат:

```text
Migrations
------------------------------
  [OK]   006_add_user_timezone.sql

```

Если оператор завершается ошибкой, шаг выводит значение `[FAIL]` и записывается значение **нет**, поэтому он будет повторен при следующем запуске. Просмотрите SQL, исправьте его и запустите повторно `db:migrate`.

---

## Применение Миграций вручную

Применить все ожидающие `.sql` файлы из `/home/xc_vm/migrations/` без полного обновления системы:

```bash
su - xc_vm -c '/home/xc_vm/console.php db:migrate'
```

### Перенос данных из другой системы

Перенести данные из промежуточной базы данных `xc_vm_migrate`:

```bash
/home/xc_vm/console.php migrate
```

Более подробную информацию смотрите в [Руководстве по обновлению базы данных](../info/migration_guide.md).

---

## Связанные файлы

|Файл|Роль|
| --- | --- |
| `src/migrations/` |Перенос файлов базы данных `.sql`|
| `src/Core/Database/MigrationRunner.php` |Выполняет отложенные миграции, записывает таблицу `migrations`|
| `src/console.php` |`db:migrate` / `status` / `migrate` точка входа|
