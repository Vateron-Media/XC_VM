<?php

/**
 * Backup & Database Privileges Service
 *
 * All methods accept explicit dependencies (config array, db object)
 * instead of reading CoreUtilities static properties.
 *
 * @package XC_VM_Core_Backup
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class BackupService {

	/**
	 * Create a full database backup (structure + data, excluding large log tables)
	 *
	 * @param string $filename  Output SQL file path
	 * @param array  $config    Database config ['username','password','port','database']
	 */
	public static function create($filename, $config) {
		$user = $config['username'];
		$pass = $config['password'];
		$port = $config['port'];
		$db   = $config['database'];

		// Structure only
		shell_exec("mysqldump -h 127.0.0.1 -u {$user} -p{$pass} -P {$port} --no-data {$db} > " . escapeshellarg($filename));

		// Data (excluding heavy log tables)
		$ignoreTables = [
			'detect_restream_logs', 'epg_data', 'lines_activity', 'lines_live',
			'lines_logs', 'login_logs', 'mag_claims', 'mag_logs', 'mysql_syslog',
			'panel_logs', 'panel_stats', 'servers_stats', 'signals',
			'streams_errors', 'streams_logs', 'streams_stats', 'syskill_log',
			'users_credits_logs', 'users_logs', 'watch_logs',
		];

		$ignoreArgs = '';
		foreach ($ignoreTables as $table) {
			$ignoreArgs .= " --ignore-table xc_vm.{$table}";
		}

		shell_exec("mysqldump -h 127.0.0.1 -u {$user} -p{$pass} -P {$port} --no-create-info{$ignoreArgs} {$db} >> " . escapeshellarg($filename));
	}

	/**
	 * Restore a database backup (drops + recreates DB, then imports)
	 *
	 * @param string $filename  SQL file path to restore
	 * @param array  $config    Database config
	 */
	public static function restore($filename, $config) {
		$user = $config['username'];
		$pass = $config['password'];
		$port = $config['port'];
		$db   = $config['database'];

		shell_exec("mysql -u {$user} -p{$pass} -P {$port} {$db} -e \"DROP DATABASE IF EXISTS xc_vm; CREATE DATABASE IF NOT EXISTS xc_vm;\"");
		shell_exec("mysql -u {$user} -p{$pass} -P {$port} {$db} < " . escapeshellarg($filename) . " > /dev/null 2>/dev/null &");

		// Re-dump structure
		shell_exec("mysqldump -h 127.0.0.1 -u {$user} -p{$pass} -P {$port} --no-data {$db} > " . escapeshellarg($filename));

		$ignoreTables = [
			'detect_restream_logs', 'epg_data', 'lines_activity', 'lines_live',
			'lines_logs', 'login_logs', 'mag_claims', 'mag_logs', 'mysql_syslog',
			'panel_logs', 'panel_stats', 'servers_stats', 'signals',
			'streams_errors', 'streams_logs', 'streams_stats', 'syskill_log',
			'users_credits_logs', 'users_logs', 'watch_logs',
		];

		$ignoreArgs = '';
		foreach ($ignoreTables as $table) {
			$ignoreArgs .= " --ignore-table xc_vm.{$table}";
		}

		shell_exec("mysqldump -h 127.0.0.1 -u {$user} -p{$pass} -P {$port} --no-create-info{$ignoreArgs} {$db} >> " . escapeshellarg($filename));
	}

	/**
	 * Grant SELECT/INSERT/UPDATE/DELETE/DROP/ALTER privileges to a remote host
	 *
	 * @param string $host   Remote host IP
	 * @param object $db     Database handler (must have ->query())
	 * @param array  $config Database config
	 */
	public static function grantPrivileges($host, $db, $config) {
		$db->query("GRANT SELECT, INSERT, UPDATE, DELETE, DROP, ALTER ON `" . $config['database'] . "`.* TO '" . $config['username'] . "'@'" . $host . "' IDENTIFIED BY '" . $config['password'] . "';");
	}

	/**
	 * Revoke all privileges from a remote host
	 *
	 * @param string $host   Remote host IP
	 * @param object $db     Database handler
	 * @param array  $config Database config
	 */
	public static function revokePrivileges($host, $db, $config) {
		$db->query("REVOKE ALL PRIVILEGES ON `" . $config['database'] . "`.* FROM '" . $config['username'] . "'@'" . $host . "';");
	}
}
