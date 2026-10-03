<?php

namespace XcVm\Core\Module;

/**
 * AdminApiRegistry — module actions of the Admin REST API (`/api/admin`,
 * key-authenticated), so core's AdminApiController needs no case per module
 * action.
 *
 * A module adds its actions from boot() (bootAll() resets the registry):
 *   add($action, $handler, $columns)
 *   - $action  [a-z0-9_], unique; core's own actions win.
 *   - $handler fn(array $data): array — the posted fields (api_key, action,
 *              start and limit removed); returns the ['status' => …] reply.
 *   - $columns How show_columns / hide_columns apply to `data`: 'rows' (a
 *              list), 'row' (one record) or null (not at all).
 *
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class AdminApiRegistry {
	/** @var array<string, array{handler: callable, columns: ?string}> */
	private static array $actions = [];

	/** @throws \InvalidArgumentException On a malformed action or column mode. */
	public static function add(string $action, callable $handler, ?string $columns = null): void {
		if (!preg_match('/^[a-z0-9_]+$/', $action) || !in_array($columns, [null, 'rows', 'row'], true)) {
			throw new \InvalidArgumentException("AdminApiRegistry: invalid action '{$action}'");
		}
		self::$actions[$action] = ['handler' => $handler, 'columns' => $columns];
	}

	/** The registered action, or null. */
	public static function get(string $action): ?array {
		return self::$actions[$action] ?? null;
	}

	/** Clear all actions (a fresh boot, tests). */
	public static function reset(): void {
		self::$actions = [];
	}
}
