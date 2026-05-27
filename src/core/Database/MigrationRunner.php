<?php

/**
 * MigrationRunner — migration runner
 *
 * @package XC_VM_Core_Database
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class MigrationRunner {

	public static function run($db): void {
		echo "Migrations\n------------------------------\n";

		$db->query("CREATE TABLE IF NOT EXISTS `migrations` (
			`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			`migration` VARCHAR(255) NOT NULL UNIQUE,
			`applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
		) ENGINE=InnoDB DEFAULT CHARSET=utf8;");

		$db->query("SELECT `migration` FROM `migrations`;");
		$rApplied = array();
		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rApplied[] = $rRow['migration'];
			}
		}

		$rPath = MAIN_HOME . 'migrations/';
		if (!is_dir($rPath)) {
			echo "No migrations directory found.\n\n";
			return;
		}

		$rFiles = glob($rPath . '*.sql');
		sort($rFiles);

		$rCount = 0;
		foreach ($rFiles as $rFile) {
			$rName = basename($rFile);
			if (in_array($rName, $rApplied)) {
				continue;
			}

			$rSQL = trim(file_get_contents($rFile));
			if (empty($rSQL)) {
				continue;
			}

			$rStatements = array_filter(array_map('trim', explode(';', $rSQL)));
			$rFailed = false;

			foreach ($rStatements as $rStatement) {
				if (empty($rStatement) || strpos($rStatement, '--') === 0) {
					continue;
				}
				if (!$db->query($rStatement . ';')) {
					$rFailed = true;
				}
			}

			$db->query("INSERT INTO `migrations` (`migration`) VALUES (?);", $rName);
			echo ($rFailed ? "  [WARN] " : "  [OK]   ") . $rName . "\n";
			$rCount++;
		}

		if ($rCount === 0) {
			echo "No pending migrations.\n";
		}
		echo "\n";
	}

	public static function runFileCleanup(): void {
		echo "File Cleanup\n------------------------------\n";

		$rFile = MAIN_HOME . 'migrations/deleted_files.txt';
		if (!file_exists($rFile)) {
			echo "No deleted files list found.\n\n";
			return;
		}

		$rLines = array_filter(array_map('trim', file($rFile)));
		$rCount = 0;
		$rDirs = [];

		foreach ($rLines as $rLine) {
			if ($rLine === '' || $rLine[0] === '#') {
				continue;
			}
			$rFullPath = MAIN_HOME . $rLine;
			if (file_exists($rFullPath) && is_file($rFullPath)) {
				$rDirs[dirname($rFullPath)] = true;
				unlink($rFullPath);
				echo "  [DEL] " . $rLine . "\n";
				$rCount++;
			}
		}

		// Удаляем опустевшие директории (только если они пусты)
		foreach (array_keys($rDirs) as $rDir) {
			if (is_dir($rDir) && count(glob($rDir . '/*')) === 0) {
				rmdir($rDir);
				echo "  [RMDIR] " . str_replace(MAIN_HOME, '', $rDir) . "\n";
			}
		}

		unlink($rFile);

		if ($rCount === 0) {
			echo "No files to delete.\n";
		} else {
			echo "Deleted " . $rCount . " file(s).\n";
		}
		echo "\n";
	}
}
