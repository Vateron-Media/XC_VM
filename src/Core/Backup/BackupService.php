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

	private static array $ignoreTables = [
		'detect_restream_logs', 'epg_data', 'lines_activity', 'lines_live',
		'lines_logs', 'login_logs', 'mag_claims', 'mag_logs', 'mysql_syslog',
		'panel_logs', 'panel_stats', 'servers_stats', 'signals',
		'streams_errors', 'streams_logs', 'streams_stats', 'syskill_log',
		'users_credits_logs', 'users_logs', 'watch_logs',
	];

	/**
	 * Create a full database backup (structure + data, excluding large log tables).
	 * Credentials are never exposed to PHP — delegated to XC_VM::db_dump().
	 *
	 * @param string $filename Output SQL file path
	 */
	public static function create($filename) {
		XC_VM::db_dump($filename, self::$ignoreTables);
	}

	/**
	 * Restore a database backup (drops + recreates DB, then imports).
	 * After import the backup file is refreshed with a clean dump.
	 *
	 * @param string $filename SQL file path to restore
	 */
	public static function restore($filename) {
		XC_VM::db_restore($filename);
		XC_VM::db_dump($filename, self::$ignoreTables);
	}

	/**
	 * Grant SELECT/INSERT/UPDATE/DELETE/DROP/ALTER privileges to a remote host.
	 *
	 * @param string $host Remote host IP
	 */
	public static function grantPrivileges($host) {
		XC_VM::db_grant($host);
	}

	/**
	 * Revoke all privileges from a remote host.
	 *
	 * @param string $host Remote host IP
	 */
	public static function revokePrivileges($host) {
		XC_VM::db_revoke($host);
	}

	/**
	 * List local SQL backups with metadata.
	 *
	 * @return array[] Each entry: filename, timestamp, date, filesize.
	 */
	public static function getLocal() {
		$rBackups = array();

		foreach (scandir(MAIN_HOME . 'backups/') as $rBackup) {
			$rInfo = pathinfo(MAIN_HOME . 'backups/' . $rBackup);

			if ($rInfo['extension'] != 'sql') {
			} else {
				$rBackups[] = array('filename' => $rBackup, 'timestamp' => filemtime(MAIN_HOME . 'backups/' . $rBackup), 'date' => date('Y-m-d H:i:s', filemtime(MAIN_HOME . 'backups/' . $rBackup)), 'filesize' => filesize(MAIN_HOME . 'backups/' . $rBackup));
			}
		}
		usort(
			$rBackups,
			function ($a, $b) {
				return $a['timestamp'];
			}
		);

		return $rBackups;
	}

	/**
	 * Test connectivity to the configured Dropbox remote.
	 *
	 * @return bool True if the Dropbox token works and files can be listed.
	 */
	public static function checkRemoteConnection() {
		require_once MAIN_HOME . 'Core/Storage/DropboxClient.php';

		try {
			$rClient = new DropboxClient();
			$rClient->SetBearerToken(array('t' => SettingsManager::getAll()['dropbox_token']));
			$rClient->GetFiles();

			return true;
		} catch (exception $e) {
			return false;
		}
	}

	/**
	 * List remote (Dropbox) SQL backups, sorted by modification time.
	 *
	 * @return array[] Backup file metadata (with a 'time' timestamp).
	 */
	public static function getRemote() {
		require_once MAIN_HOME . 'Core/Storage/DropboxClient.php';

		try {
			$rClient = new DropboxClient();
			$rClient->SetBearerToken(array('t' => SettingsManager::getAll()['dropbox_token']));
			$rFiles = $rClient->GetFiles();
		} catch (exception $e) {
			$rFiles = array();
		}
		$rBackups = array();

		foreach ($rFiles as $rFile) {
			try {
				if (!(!$rFile->isDir && strtolower(pathinfo($rFile->name)['extension']) == 'sql' && 0 < $rFile->size)) {
				} else {
					$rJSON = json_decode(json_encode($rFile, JSON_UNESCAPED_UNICODE), true);
					$rJSON['time'] = strtotime($rFile->server_modified);
					$rBackups[] = $rJSON;
				}
			} catch (exception $e) {
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
	public static function downloadRemote($rPath, $rFilename) {
		require_once MAIN_HOME . 'Core/Storage/DropboxClient.php';
		$rClient = new DropboxClient();

		try {
			$rClient->SetBearerToken(array('t' => SettingsManager::getAll()['dropbox_token']));
			$rClient->downloadFile($rPath, $rFilename);

			return true;
		} catch (exception $e) {
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
	public static function uploadRemote($rPath, $rFilename, $rOverwrite = true) {
		require_once MAIN_HOME . 'Core/Storage/DropboxClient.php';
		$rClient = new DropboxClient();

		try {
			$rClient->SetBearerToken(array('t' => SettingsManager::getAll()['dropbox_token']));

			return $rClient->UploadFile($rFilename, $rPath, $rOverwrite);
		} catch (exception $e) {
			return (object) array('error' => $e);
		}
	}

	/**
	 * Delete a backup file from Dropbox.
	 *
	 * @param string $rPath Remote path to delete.
	 * @return bool True on success.
	 */
	public static function deleteRemote($rPath) {
		require_once MAIN_HOME . 'Core/Storage/DropboxClient.php';
		$rClient = new DropboxClient();

		try {
			$rClient->SetBearerToken(array('t' => SettingsManager::getAll()['dropbox_token']));
			$rClient->Delete($rPath);

			return true;
		} catch (exception $e) {
			return false;
		}
	}
}
