<?php

namespace XcVm\Infrastructure\Database;

/**
 * DatabaseAware — shared static database-access pattern for domain/module classes.
 *
 * Domain services historically used a per-class `setDb()` / `db()` pair where
 * `db()` threw if `setDb()` had not been called first. That forced EVERY
 * bootstrap path (admin, web API, streaming, CLI, modules) to remember to wire
 * $db into every class before use — and forgetting it in any single path
 * produced a runtime "setDb() must be called before use" fatal (it happened
 * repeatedly).
 *
 * This trait removes that fragility: `db()` lazily resolves the connection from
 * the central {@see DatabaseFactory} registry (its sibling in this package)
 * when no instance was explicitly injected. Since every entry point already
 * calls `DatabaseFactory::set()` / `DatabaseFactory::connect()`, the connection
 * is always available and explicit wiring becomes optional.
 *
 * Resolution order:
 *   1. An instance explicitly injected via setDb() (e.g. tests, or legacy
 *      DomainDatabaseWiring). Wins so overrides/mocks keep working.
 *   2. Otherwise the current DatabaseFactory instance. Not cached, so a later
 *      reconnect (DatabaseFactory::connect()) is picked up automatically.
 *
 * Each using class gets its own static $db (trait static properties are
 * per-using-class), preserving the previous behaviour.
 *
 * @package XC_VM_Infrastructure_Database
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
trait DatabaseAware {
	/** @var \XcVm\Core\Database\DatabaseHandler|null Explicitly injected handler (optional). */
	private static $db = null;

	/**
	 * Explicitly inject the database handler.
	 *
	 * Optional — when omitted, db() falls back to DatabaseFactory. Kept for
	 * tests/mocks and backward compatibility with DomainDatabaseWiring.
	 *
	 * @param \XcVm\Core\Database\DatabaseHandler $db Database handler.
	 * @return void
	 */
	public static function setDb($db): void {
		self::$db = $db;
	}

	/**
	 * Resolve the database handler — injected instance, else DatabaseFactory.
	 *
	 * @return object Database handler.
	 * @throws \RuntimeException If no handler is available from either source.
	 */
	private static function db(): object {
		$db = self::$db ?? DatabaseFactory::get();
		if ($db === null) {
			throw new \RuntimeException(static::class . ': no database connection available (DatabaseFactory not initialised).');
		}
		return $db;
	}
}
