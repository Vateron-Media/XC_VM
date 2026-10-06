<?php

namespace XcVm\Core\Backup;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\Database;
use XcVm\Core\Storage\DropboxClient;
use XcVm\Domain\Cluster\DbCredentials;

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
	private static array $ignoreTables = [
		'detect_restream_logs',
		'epg_data',
		'lines_activity',
		'lines_live',
		'lines_logs',
		'login_logs',
		'mag_claims',
		'mag_logs',
		'mysql_syslog',
		'panel_logs',
		'panel_stats',
		'servers_stats',
		'signals',
		'streams_errors',
		'streams_logs',
		'streams_stats',
		'syskill_log',
		'users_credits_logs',
		'users_logs',
		'watch_logs',
	];

	/** Automatic backup schedules and their periods in seconds; anything else is off. */
	public const PERIODS = ['hourly' => 3600, 'daily' => 86400, 'weekly' => 604800, 'monthly' => 2419200];

	/** Prefix of the dump a restore takes of the live database first: the way back, which retention leaves alone. */
	public const PRE_RESTORE = 'pre_restore_';

	/**
	 * Create a full database backup (structure + data, excluding large log tables).
	 * Credentials are never exposed to PHP — delegated to \XC_VM::db_dump().
	 *
	 * @param string $filename Output SQL file path
	 * @return bool Whether the dump finished; one that did not leaves no file
	 */
	public static function create(string $filename): bool {
		$rDumped = (bool) \XC_VM::db_dump($filename, self::$ignoreTables);
		clearstatcache(true, $filename);
		if ($rDumped && is_file($filename) && 0 < filesize($filename)) {
			return true;
		}
		@unlink($filename);
		return false;
	}

	/**
	 * Dump the database to backups/<name>_<date>.sql from a command that holds a
	 * connection: it is closed for the dump and opened again after it.
	 *
	 * @return string|null The file, or null when the dump failed (it leaves none)
	 */
	public static function dumpFor(Database $db, string $rName): ?string {
		$rFile = MAIN_HOME . 'backups/' . $rName . '_' . date('Y-m-d_H-i-s') . '.sql';
		$db->close_mysql();
		$rDumped = self::create($rFile);
		$db->db_connect();
		return $rDumped ? $rFile : null;
	}

	/** Bytes the dumped tables hold: a dump comes to about this size. */
	public static function estimateSize(Database $db): int {
		$db->query('SELECT COALESCE(SUM(`data_length`), 0) FROM `information_schema`.`tables` WHERE `table_schema` = DATABASE() AND `table_name` NOT IN (' . implode(', ', array_fill(0, count(self::$ignoreTables), '?')) . ');', ...self::$ignoreTables);
		return (int) $db->get_col();
	}

	/** Room for a dump of $rNeed bytes: twice over (the migrations copy tables too), plus 512 MiB for the update's own files. */
	public static function roomForDump(int $rNeed, float $rFree): bool {
		return 2 * $rNeed + 512 * 1024 * 1024 <= $rFree;
	}

	/** Remove every <prefix>*.sql in $rDir but $rKeep. */
	public static function keepOnly(string $rDir, string $rPrefix, string $rKeep): void {
		foreach (glob($rDir . $rPrefix . '*.sql') ?: [] as $rFile) {
			if (basename($rFile) !== basename($rKeep)) {
				@unlink($rFile);
			}
		}
	}

	/**
	 * Restore a database backup (drops + recreates DB, then imports). The live
	 * database is dumped to backups/pre_restore_<date>.sql first; when it cannot
	 * be, nothing is touched unless $rForce. The file restored from is left as it is.
	 *
	 * @param string $filename SQL file path to restore
	 * @return bool|null true: restored. false: the import failed. null: refused, the live database could not be dumped first.
	 */
	public static function restore(string $filename, bool $rForce = false): ?bool {
		if (!$rForce && !self::create(MAIN_HOME . 'backups/' . self::PRE_RESTORE . date('Y-m-d_H:i:s') . '.sql')) {
			return null;
		}
		return (bool) \XC_VM::db_restore($filename);
	}

	/**
	 * Grant SELECT/INSERT/UPDATE/DELETE/DROP/ALTER privileges to a remote host.
	 *
	 * Never to the host of a load balancer that must hold none of MAIN's
	 * credentials — a cluster node in mode 2, or one whose grant MAIN revoked
	 * (DbCredentials::credentialFree(), plan section 10) — whichever path asks:
	 * the server form, the install, "re-authorise MySQL" or `tools mysql`.
	 *
	 * @param string $host Remote host IP
	 * @return bool False when nothing was granted (such a node, or the extension refused)
	 */
	public static function grantPrivileges(string $host): bool {
		if (class_exists(DbCredentials::class) && DbCredentials::credentialFreeHost($host)) {
			return false;
		}
		return (bool) \XC_VM::db_grant($host);
	}

	/**
	 * Revoke all privileges from a remote host.
	 *
	 * @param string $host Remote host IP
	 */
	public static function revokePrivileges(string $host) {
		\XC_VM::db_revoke($host);
	}

	/**
	 * List local SQL backups with metadata, oldest first.
	 *
	 * @return array[] Each entry: filename, timestamp, date, filesize.
	 */
	public static function getLocal() {
		$rBackups = [];

		foreach (scandir(MAIN_HOME . 'backups/') as $rBackup) {
			$rInfo = pathinfo(MAIN_HOME . 'backups/' . $rBackup);

			if ($rInfo['extension'] == 'sql') {
				$rBackups[] = ['filename' => $rBackup, 'timestamp' => filemtime(MAIN_HOME . 'backups/' . $rBackup), 'date' => date('Y-m-d H:i:s', filemtime(MAIN_HOME . 'backups/' . $rBackup)), 'filesize' => filesize(MAIN_HOME . 'backups/' . $rBackup)];
			}
		}
		usort(
			$rBackups,
			function ($a, $b) {
				return $a['timestamp'] <=> $b['timestamp'];
			}
		);

		return $rBackups;
	}

	/**
	 * The newest backup a backup run made (backup_*.sql, scheduled or manual), and
	 * whether its Dropbox upload failed (the .error file beside it).
	 *
	 * @return array{timestamp: int, upload_failed: bool, filename: string}|null null when there is none
	 */
	public static function newestBackup(): ?array {
		if (!is_dir(MAIN_HOME . 'backups/')) {
			return null;
		}
		$rRuns = array_values(array_filter(self::getLocal(), static fn(array $rBackup): bool => str_starts_with($rBackup['filename'], 'backup_')));
		if ($rRuns === []) {
			return null;
		}
		$rNewest = end($rRuns);
		return ['timestamp' => (int) $rNewest['timestamp'], 'upload_failed' => is_file(MAIN_HOME . 'backups/' . $rNewest['filename'] . '.error'), 'filename' => (string) $rNewest['filename']];
	}

	/**
	 * Test connectivity to the configured Dropbox remote.
	 *
	 * @return bool True if the Dropbox token works and files can be listed.
	 */
	public static function checkRemoteConnection() {

		try {
			$rClient = new DropboxClient();
			$rClient->SetBearerToken(['t' => SettingsManager::get('dropbox_token')]);
			$rClient->GetFiles();

			return true;
		} catch (\exception $e) {
			return false;
		}
	}

	/**
	 * List remote (Dropbox) SQL backups, sorted by modification time.
	 *
	 * @return array[] Backup file metadata (with a 'time' timestamp).
	 */
	public static function getRemote() {

		try {
			$rClient = new DropboxClient();
			$rClient->SetBearerToken(['t' => SettingsManager::get('dropbox_token')]);
			$rFiles = $rClient->GetFiles();
		} catch (\exception $e) {
			$rFiles = [];
		}
		$rBackups = [];

		foreach ($rFiles as $rFile) {
			try {
				if (!$rFile->isDir && strtolower(pathinfo($rFile->name)['extension']) == 'sql' && 0 < $rFile->size) {
					$rJSON = json_decode(json_encode($rFile, JSON_UNESCAPED_UNICODE), true);
					$rJSON['time'] = strtotime($rFile->server_modified);
					$rBackups[] = $rJSON;
				}
			} catch (\exception $e) {
			}
		}
		array_multisort(array_column($rBackups, 'time'), SORT_ASC, $rBackups);

		return $rBackups;
	}

	/**
	 * Download a backup file from Dropbox.
	 *
	 * @param string $rPath     Remote path on Dropbox.
	 * @param string $rFilename Local destination path.
	 * @return bool True on success.
	 */
	public static function downloadRemote(string $rPath, string $rFilename) {
		$rClient = new DropboxClient();

		try {
			$rClient->SetBearerToken(['t' => SettingsManager::get('dropbox_token')]);
			$rClient->downloadFile($rPath, $rFilename);

			return true;
		} catch (\exception $e) {
			return false;
		}
	}

	/**
	 * Upload a backup file to Dropbox.
	 *
	 * @param string $rPath      Remote destination path.
	 * @param string $rFilename  Local source file.
	 * @param bool   $rOverwrite Overwrite an existing remote file.
	 * @return mixed Upload result, or an object with an 'error' key on failure.
	 */
	public static function uploadRemote(string $rPath, string $rFilename, bool $rOverwrite = true) {
		$rClient = new DropboxClient();

		try {
			$rClient->SetBearerToken(['t' => SettingsManager::get('dropbox_token')]);

			return $rClient->UploadFile($rFilename, $rPath, $rOverwrite);
		} catch (\exception $e) {
			return (object) ['error' => $e];
		}
	}

	/**
	 * Delete a backup file from Dropbox.
	 *
	 * @param string $rPath Remote path to delete.
	 * @return bool True on success.
	 */
	public static function deleteRemote(string $rPath) {
		$rClient = new DropboxClient();

		try {
			$rClient->SetBearerToken(['t' => SettingsManager::get('dropbox_token')]);
			$rClient->Delete($rPath);

			return true;
		} catch (\exception $e) {
			return false;
		}
	}
}
