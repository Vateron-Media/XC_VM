# AI Agent & Assistant Guidelines for XC_VM

This file establishes the operational and architectural guidelines for AI coding assistants (Gemini, Antigravity, Claude, etc.) working inside `/home/xc_vm` and `/home/XC_VM_repo`.

---

## 🏗️ Core Architectural Principles

1. **Modular Monolith & Extensible Platform**:
   - `Core/` contains shared framework components (DI container, event dispatcher, routing, database abstraction).
   - Core **never** hardcodes dependencies on specific modules.
   - Modules live in `Modules/{name}_{hash5}/` (e.g. `Modules/telegram_3b6df/`, `Modules/watch_2541a/`, `Modules/plex_20cd9/`).
   - Modules declare their entry point via `<Name>Module extends \XcVm\Core\Module\BaseModule`.
   - Core notifies modules via typed PSR-14 events (e.g. `MediaAnalyzedEvent`, `StreamsDeletedEvent`), and modules subscribe using `#[ListensTo(Event::class)]`.

2. **Database Access Pattern**:
   - Do NOT pass `$db` via constructors or static setters.
   - Classes use the `\XcVm\Infrastructure\Database\DatabaseAware` trait and call `self::db()`.
   - `Core/Database/DatabaseHandler` provides PDO-wrapped query execution with prepared statements (`$db->query(..., ...)`).

3. **Production Safety & Secrets Protection**:
   - **NEVER** push or commit sensitive runtime files:
     - `config/config.enc`
     - `config/install_id`
     - `bin/nginx/conf/codes/*`
     - `bin/nginx/conf/server.crt` & `bin/nginx/conf/server.key`
     - `tmp/`, `content/`, `backups/`, `signals/`
   - All code synchronization must run through `xc-git` or respect the defined exclusion rules.

4. **Code Quality & Syntax**:
   - PHP 8.1+ features: typed properties, readonly, enums, union types, nullsafe operator.
   - Before completing any task or pushing code, always validate syntax:
     ```bash
     /home/xc_vm/bin/php/bin/php -n -l <file.php>
     ```
   - Never run `composer install` on deploy paths. `vendor/` is committed and production-only.

---

## 🤖 Specialized Review Personas (Available in `.claude/agents/` and `.agents/rules/`)

When developing or auditing features, adhere to the domain standards set by:
- **`backend-lead-reviewer.md`**: Business logic, API design, event subscriptions, service resilience.
- **`db-architect.md`**: Schema normalization, indexing, transaction bounds, non-blocking migrations.
- **`security-architect.md`**: Input sanitization, SQL injection prevention, authentication/authorization checks.
- **`php-architect-reviewer.md`**: PSR-4 compliance, DI container contracts, strict typing.
- **`devops-lead-reviewer.md`**: Service reliability, systemd/nginx compatibility, daemon supervision.
- **`qa-lead-reviewer.md`**: Edge cases, failure recovery, regression prevention.

---

## 🛠️ Global CLI Tools

- **`xc-git`**: Manages git commits, releases, and synchronization with `https://github.com/Rosmi720/XC_VM`.
- **`xc-module-telegram`**: Dedicated manager for the Telegram module and its independent repository `https://github.com/Rosmi720/Module_Telegram`.
