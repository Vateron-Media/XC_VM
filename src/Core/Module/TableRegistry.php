<?php

namespace XcVm\Core\Module;

/**
 * TableRegistry — serverSide DataTable handlers contributed by modules.
 *
 * The admin `./table` endpoint (XcVm\Public\Controllers\Admin\TableController)
 * dispatches a table `id` to a builder. Core tables are a hard-coded switch;
 * a module registers a handler for its OWN table id here instead of the id
 * living in core, so the core controller no longer knows about module tables.
 *
 * A module implements {@see \XcVm\Core\Module\Contract\TableProviderInterface}
 * and, in registerTables(), calls register() once per table id. When the
 * requested id is not a core case, TableController looks it up here.
 *
 * Handler contract:
 *   fn(array $return, int $start, int $limit, bool $isApi): array
 * It receives the base DataTables response skeleton (`recordsTotal`,
 * `recordsFiltered`, `data`) and returns it populated. TableController encodes
 * the returned array as JSON — the handler must NOT echo or exit. Read request
 * params via RequestManager and query via the global $db, exactly like the core
 * handlers. Return clean, keyed JSON rows (no HTML) — the view renders cells.
 *
 * Registration order per id is last-wins, mirroring the other registries.
 *
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class TableRegistry {

    /** @var array<string,callable> table id => handler */
    private static array $handlers = [];

    /**
     * Register (or override) the serverSide handler for a table id.
     *
     * @param string   $id      Table id (the DataTables ajax `d.id`), e.g. 'watch_output'.
     * @param callable $handler fn(array $return, int $start, int $limit, bool $isApi): array
     */
    public static function register(string $id, callable $handler): void {
        self::$handlers[$id] = $handler;
    }

    /** Whether a module handler is registered for this table id. */
    public static function has(string $id): bool {
        return isset(self::$handlers[$id]);
    }

    /** The handler for a table id, or null. */
    public static function get(string $id): ?callable {
        return self::$handlers[$id] ?? null;
    }

    /** Clear all handlers (used by tests / a fresh boot). */
    public static function reset(): void {
        self::$handlers = [];
    }
}
