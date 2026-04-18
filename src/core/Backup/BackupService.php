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
		if ($db->query('SELECT COUNT(*) AS `count` FROM `mysql`.`db` WHERE `User` = ? AND `Host` = ? AND `Db` = ?;', $config['username'], $host, $config['database'])) {
			$rGrantRow = $db->get_row();
			if (empty($rGrantRow) || intval($rGrantRow['count']) === 0) {
				return;
			}
		}

		$db->query("REVOKE ALL PRIVILEGES ON `" . $config['database'] . "`.* FROM '" . $config['username'] . "'@'" . $host . "';");
	}

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

	public static function checkRemoteConnection() {
		require_once MAIN_HOME . 'core/Storage/DropboxClient.php';

		try {
			$rClient = new DropboxClient();
			$rClient->SetBearerToken(array('t' => SettingsManager::getAll()['dropbox_token']));
			$rClient->GetFiles();

			return true;
		} catch (exception $e) {
			return false;
		}
	}

	public static function getRemote() {
		require_once MAIN_HOME . 'core/Storage/DropboxClient.php';

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

	public static function downloadRemote($rPath, $rFilename) {
		require_once MAIN_HOME . 'core/Storage/DropboxClient.php';
		$rClient = new DropboxClient();

		try {
			$rClient->SetBearerToken(array('t' => SettingsManager::getAll()['dropbox_token']));
			$rClient->downloadFile($rPath, $rFilename);

			return true;
		} catch (exception $e) {
			return false;
		}
	}

	public static function uploadRemote($rPath, $rFilename, $rOverwrite = true) {
		require_once MAIN_HOME . 'core/Storage/DropboxClient.php';
		$rClient = new DropboxClient();

		try {
			$rClient->SetBearerToken(array('t' => SettingsManager::getAll()['dropbox_token']));

			return $rClient->UploadFile($rFilename, $rPath, $rOverwrite);
		} catch (exception $e) {
			return (object) array('error' => $e);
		}
	}

	public static function deleteRemote($rPath) {
		require_once MAIN_HOME . 'core/Storage/DropboxClient.php';
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
