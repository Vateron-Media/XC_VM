<?php

namespace XcVm\Core\Database;

use XcVm\Core\Logging\FileLogger;

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
	/**
	 * Apply pending SQL migrations from the migrations/database/up/ directory.
	 *
	 * Ensures the `migrations` tracking table exists, then runs each unapplied
	 * `*.sql` file (statement by statement) and records successful ones.
	 *
	 * A file that fails is not recorded and runs again next time; each failure
	 * also goes to the panel log, by name only: the update's output gives its cause.
	 *
	 * @param Database    $db          Database handle.
	 * @param string|null $databaseDir Override for the migrations/database/ directory (tests); defaults to MAIN_HOME . 'migrations/database/'.
	 * @return string[] The files that failed, each as "<file>: <driver message>"; [] when none did.
	 */
	public static function run(Database $db, ?string $databaseDir = null): array {
		echo "Migrations\n------------------------------\n";

		self::ensureTable($db);

		$rApplied = self::appliedMigrations($db);

		$rPath = ($databaseDir ?? MAIN_HOME . 'migrations/database/') . 'up/';
		if (!is_dir($rPath)) {
			echo "No migrations directory found.\n\n";
			return [];
		}

		$rFiles = glob($rPath . '*.sql');
		sort($rFiles);

		$rCount = 0;
		$rFailed = [];
		foreach ($rFiles as $rFile) {
			$rName = basename($rFile);
			if (in_array($rName, $rApplied)) {
				continue;
			}

			$rError = '';
			if (!self::executeSqlFile($db, $rFile, $rError)) {
				echo "  [FAIL] " . $rName . " (not recorded — will retry on next run)\n";
				$rFailed[] = $rName . ': ' . $rError;
				// The cause stays out: the panel log drops some driver messages (lock waits,
				// duplicate entries), and a message can quote the data.
				FileLogger::log('migration', 'Database migration failed: ' . $rName . ' (run console.php status as root for the cause)');
			} else {
				$db->query("INSERT INTO `migrations` (`migration`) VALUES (?);", $rName);
				echo "  [OK]   " . $rName . "\n";
			}
			$rCount++;
		}

		if ($rCount === 0) {
			echo "No pending migrations.\n";
		}
		echo "\n";

		return $rFailed;
	}

	/**
	 * Reverse migrations applied on this server that the target version's
	 * migrations/database/up/ folder no longer carries — used before a
	 * version rollback swaps in older code, so the older code doesn't hit
	 * schema it doesn't expect (a column/table a newer migration dropped) or,
	 * conversely, isn't confused into re-running a migration that never
	 * existed for it.
	 *
	 * Only migrations with a same-named counterpart in migrations/database/down/
	 * are reversed; migrations without one are left applied (echoed as
	 * skipped) — safe by design, since every migration lacking a down file
	 * only ever ADDs schema or business data older code simply never
	 * references.
	 *
	 * up/ and down/ are separate directories under migrations/database/, not
	 * a `.down.sql`-suffixed sibling of the up file — glob($rPath . '*.sql')
	 * in run() would otherwise pick up a `<name>.down.sql` file too (it still
	 * ends in `.sql`) and try to run it forward as an ordinary pending
	 * migration. glob() doesn't recurse into subdirectories, so keeping down/
	 * out of up/'s directory entirely is what makes it invisible to run().
	 *
	 * @param Database $db                        Database handle.
	 * @param string[] $targetMigrationFilenames   Migration basenames the target version's migrations/database/up/ folder contains.
	 * @param string|null $databaseDir             Override for the migrations/database/ directory (tests); defaults to MAIN_HOME . 'migrations/database/'.
	 * @return array{reversed: string[], skipped: string[]}
	 * @throws \RuntimeException If a down migration's SQL fails — the caller must abort the rollback.
	 */
	public static function rollback(Database $db, array $targetMigrationFilenames, ?string $databaseDir = null): array {
		$rPath = $databaseDir ?? (MAIN_HOME . 'migrations/database/');

		self::ensureTable($db);
		$rApplied = self::appliedMigrations($db);

		$rToReverse = array_diff($rApplied, $targetMigrationFilenames);
		rsort($rToReverse);

		$rReversed = [];
		$rSkipped = [];

		foreach ($rToReverse as $rName) {
			$rDownFile = $rPath . 'down/' . $rName;

			if (!is_file($rDownFile)) {
				$rSkipped[] = $rName;
				continue;
			}

			if (!self::executeSqlFile($db, $rDownFile)) {
				throw new \RuntimeException('Down migration failed: ' . basename($rDownFile));
			}

			$db->query('DELETE FROM `migrations` WHERE `migration` = ?;', $rName);
			$rReversed[] = $rName;
		}

		return ['reversed' => $rReversed, 'skipped' => $rSkipped];
	}

	/**
	 * Ensure the `migrations` tracking table exists.
	 */
	private static function ensureTable(Database $db): void {
		$db->query("CREATE TABLE IF NOT EXISTS `migrations` (
			`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			`migration` VARCHAR(255) NOT NULL UNIQUE,
			`applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
		) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
	}

	/**
	 * Filenames of migrations already recorded as applied.
	 *
	 * @return string[]
	 */
	private static function appliedMigrations(Database $db): array {
		$db->query("SELECT `migration` FROM `migrations`;");
		$rApplied = [];
		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rApplied[] = $rRow['migration'];
			}
		}
		return $rApplied;
	}

	/**
	 * Execute every statement in a migration SQL file. Comment-strips (`--`
	 * full-line comments, which may themselves contain a `;`) before
	 * splitting on `;`, then runs each remaining statement.
	 *
	 * @param string $rError Set to the driver's message for the first statement that failed.
	 * @return bool True if every statement succeeded.
	 */
	private static function executeSqlFile(Database $db, string $file, string &$rError = ''): bool {
		$rSQL = trim((string) file_get_contents($file));
		if ($rSQL === '') {
			return true;
		}

		// Strip full-line `--` comments BEFORE splitting on `;` — a comment
		// may itself contain a semicolon (e.g. "no GeoIP data; on the old
		// schedule…"), which would otherwise corrupt statement parsing and
		// leak comment text into the next statement, failing forever.
		$rLines = array_filter(explode("\n", $rSQL), function ($l) {
			return strpos(ltrim($l), '--') !== 0;
		});
		$rClean = implode("\n", $rLines);

		$rStatements = array_filter(array_map('trim', explode(';', $rClean)));
		$rFailed = false;

		foreach ($rStatements as $rStatement) {
			if ($rStatement === '') {
				continue;
			}
			if (!$db->query($rStatement . ';')) {
				// The panel log leaves out some messages (duplicate entries,
				// timeouts): the output of the update is where the operator reads why.
				echo '  [ERR]  ' . $db->error() . "\n";
				if (!$rFailed) {
					$rError = $db->error();
				}
				$rFailed = true;
			}
		}

		return !$rFailed;
	}

	/**
	 * Delete files listed in migrations/deleted_files.txt during an update.
	 */
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
