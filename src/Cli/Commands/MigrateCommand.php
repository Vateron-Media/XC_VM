<?php

namespace XcVm\Cli\Commands;

use XcVm\Core\Database\DatabaseHandler;
use XcVm\Cli\CommandInterface;

/**
 * MigrateCommand — migrate command
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class MigrateCommand implements CommandInterface {
	/** The tables of an XUI.one backup the core migrates itself. */
	public const XUI_TABLES = ['access_codes', 'users', 'blocked_ips', 'blocked_uas', 'blocked_isps', 'bouquets', 'enigma2_devices', 'mag_devices', 'epg', 'users_groups', 'users_packages', 'rtmp_ips', 'streams_series', 'streams_episodes', 'servers', 'streams', 'streams_options', 'streams_servers', 'streams_categories', 'tickets', 'tickets_replies', 'profiles', 'lines'];

	/** The tables of an Xtream Codes backup the core migrates itself. */
	public const XC_TABLES = ['reg_users', 'users', 'enigma2_devices', 'mag_devices', 'user_output', 'streaming_servers', 'series', 'series_episodes', 'streams', 'streams_sys', 'streams_options', 'stream_categories', 'bouquets', 'member_groups', 'packages', 'rtmp_ips', 'epg', 'blocked_ips', 'blocked_user_agents', 'isp_addon', 'tickets', 'tickets_replies', 'transcoding_profiles', 'categories', 'epg_sources', 'members', 'blocked_isps', 'groups', 'servers', 'stream_servers'];

	/**
	 * Tables of the old panels' schemas that nobody takes over: logs, activity,
	 * statistics and state. Dropped, not saved. Those schemas no longer change;
	 * a table missing here is saved for modules, never lost.
	 */
	public const LEGACY_JUNK = [
		'user_activity', 'user_activity_now', 'client_logs', 'stream_logs', 'reg_userlog', 'credits_log', 'login_users',
		'login_flood', 'server_activity', 'dashboard_statistics', 'admin_settings', 'xtream_main', 'licence', 'cronjobs',
		'tmdb_async', 'movie_containers', 'stream_subcategories', 'streams_seasons', 'reseller_imex', 'devices',
	];

	/**
	 * The tables the core has: those bin/install/database.sql creates, those its
	 * migrations (migrations/database/up/) create later, and MigrationRunner's
	 * own log. A backup table by one of these names is the core's — migrated
	 * or not wanted — whatever panel the backup came from.
	 *
	 * @return list<string>
	 */
	public static function coreSchemaTables(?string $rHome = null): array {
		$rHome ??= MAIN_HOME;
		$rTables = ['migrations'];
		foreach (array_merge([$rHome . 'bin/install/database.sql'], glob($rHome . 'migrations/database/up/*.sql') ?: []) as $rFile) {
			preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?`?([a-z0-9_]+)`?/i', (string) @file_get_contents($rFile), $rMatches);
			$rTables = array_merge($rTables, $rMatches[1]);
		}
		return array_values(array_unique($rTables));
	}

	/**
	 * The backup's tables that are not the core's: tables of modules, under
	 * whatever names they have. The migration saves each to a file for its
	 * module (LegacyTableMigrationEvent); no list of modules is kept.
	 *
	 * @param list<string> $rTables    the backup's tables
	 * @param list<string> $rCoreTables coreSchemaTables()
	 * @return list<string>
	 */
	public static function moduleTables(array $rTables, array $rCoreTables): array {
		$rCore = array_flip(array_merge(self::XUI_TABLES, self::XC_TABLES, self::LEGACY_JUNK, $rCoreTables));
		return array_values(array_filter($rTables, static fn(string $rTable): bool => !isset($rCore[$rTable])));
	}

	public function getName(): string {
		return 'migrate';
	}

	public function getDescription(): string {
		return 'Migrate — database migration from xc_vm_migrate';
	}

	/**
	 * Is the backup's handle on a schema of its own, not the panel's? Asked
	 * before anything of the backup is saved aside and dropped: tables dropped
	 * through a handle that had moved to the panel's schema were the panel's.
	 */
	public static function onItsOwnSchema(DatabaseHandler $rBackup, DatabaseHandler $rPanel): bool {
		$rNames = [];
		foreach ([$rBackup, $rPanel] as $rHandle) {
			if (!$rHandle->query('SELECT DATABASE() AS `name`;')) {
				return false;
			}
			$rNames[] = (string) ($rHandle->get_row()['name'] ?? '');
		}
		return $rNames[0] !== '' && $rNames[0] !== $rNames[1];
	}

	public function execute(array $rArgs): int {
		// migrate requires admin bootstrap (CONTEXT_ADMIN) for $_INFO credentials
		// Expose $argc/$argv for migration_logic.php compatibility
		global $argc, $argv;

		require __DIR__ . '/../migration_logic.php';

		return 0;
	}
}
