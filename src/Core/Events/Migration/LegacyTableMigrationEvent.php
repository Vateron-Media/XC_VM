<?php

namespace XcVm\Core\Events\Migration;

use XcVm\Core\Database\DatabaseHandler;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * A table of a restored backup that belongs to a module, handed to the module
 * to copy into its own table.
 *
 * Core no longer writes module tables, and keeps no list of them. After the
 * core tables, the migration (`console.php migrate`) dumps every backup table
 * the core does not own (MigrateCommand::moduleTables()) to an SQL file of its
 * own in Modules/migration/, dispatches this, drops the table, and at the end
 * empties the backup database (`xc_vm_migrate`): a backup can weigh gigabytes.
 * The module that owns the table copies the rows and says how many in
 * `copied`; a table nobody took (its module is not installed) stays in its
 * file. A module installed later calls fromBackup() in its install(): the file
 * is loaded into a staging table `legacy_<table>` of the panel's database, the
 * module copies its rows() while its own table is still empty, and discard()
 * drops the staging table and the file.
 *
 * @package XC_VM_Core_Events
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class LegacyTableMigrationEvent {
	/** Rows read (and written per INSERT) at a time: a table can be large. */
	private const CHUNK = 1000;

	/** First line of a dump: the table and the backup's format, as JSON. */
	private const HEADER = '-- xcvm-legacy-table ';

	/** Tests: another directory than Modules/migration/. */
	private static ?string $rDir = null;

	/** Rows the module copied; null while no module has taken the table. */
	public ?int $copied = null;

	/**
	 * @param string          $table        The backup's table, e.g. `watch_folders`.
	 * @param string          $format       `xui` (an XUI.one backup) or `xc` (an Xtream Codes one).
	 * @param DatabaseHandler $rSource      Where the rows are now: the backup, or the panel's database.
	 * @param string          $rSourceTable The table they are in there (the staging table once loaded).
	 */
	public function __construct(
		public readonly string $table,
		public readonly string $format,
		private readonly DatabaseHandler $rSource,
		private readonly string $rSourceTable,
	) {
	}

	/** Where the migration leaves module tables for their modules. */
	public static function dir(): string {
		return self::$rDir ?? MAIN_HOME . 'Modules/migration/';
	}

	/** Tests: keep the files in $rDir (with a trailing slash); null restores Modules/migration/. */
	public static function useDir(?string $rDir): void {
		self::$rDir = $rDir;
	}

	/** The SQL file a table is saved to. */
	public static function sqlFile(string $table): string {
		return self::dir() . self::name($table) . '.sql';
	}

	/** The table its SQL file creates and fills in the panel's database. */
	public static function stagingTable(string $table): string {
		return 'legacy_' . self::name($table);
	}

	/**
	 * The format of the backup in $source: an XUI.one backup has `access_codes`.
	 */
	public static function formatOf(DatabaseHandler $source): string {
		$source->query("SHOW TABLES LIKE 'access_codes';");
		return $source->num_rows() > 0 ? 'xui' : 'xc';
	}

	/**
	 * Write a backup table to its SQL file and return the event for it; null
	 * when the table is not in the backup or is empty. The file creates the
	 * staging table (the backup's own definition under that name) and fills it,
	 * one statement per line.
	 *
	 * @throws \RuntimeException when the file could not be written: the backup
	 *         must then not be emptied
	 */
	public static function dump(DatabaseHandler $source, string $table, string $format): ?self {
		$source->query('SHOW TABLES LIKE ?;', $table);
		if ($source->num_rows() === 0) {
			return null;
		}
		$rName = '`' . self::name($table) . '`';
		$source->query('SHOW CREATE TABLE ' . $rName . ';');
		$rCreate = (string) (array_values((array) $source->get_row())[1] ?? '');
		if ($rCreate === '') {
			throw new \RuntimeException('Cannot read the definition of ' . $table);
		}
		if (!is_dir(self::dir()) && !@mkdir(self::dir(), 0750, true) && !is_dir(self::dir())) {
			throw new \RuntimeException('Cannot create ' . self::dir());
		}
		$rStaging = '`' . self::stagingTable($table) . '`';
		$rTemp = self::sqlFile($table) . '.tmp';
		$rOut = @fopen($rTemp, 'w');
		if ($rOut === false) {
			throw new \RuntimeException('Cannot write ' . $rTemp);
		}
		$rWrite = static function (string $rLine) use ($rOut, $rTemp): void {
			if (fwrite($rOut, $rLine . "\n") === false) {
				fclose($rOut);
				@unlink($rTemp);
				throw new \RuntimeException('Cannot write ' . $rTemp . ' (disk full?)');
			}
		};
		$rWrite(self::HEADER . json_encode(['table' => $table, 'format' => $format]));
		$rWrite('DROP TABLE IF EXISTS ' . $rStaging . ';');
		// The backup's definition, on one line, under the staging name, without
		// its foreign keys: they name the backup's other tables, which the
		// panel's database may not have.
		$rCreate = preg_replace('/,\s*CONSTRAINT `[^`]+` FOREIGN KEY \([^)]*\) REFERENCES `[^`]+` \([^)]*\)(\s+ON (DELETE|UPDATE) (CASCADE|SET NULL|SET DEFAULT|RESTRICT|NO ACTION))*/i', '', str_replace(["\r", "\n"], ' ', $rCreate));
		$rWrite(preg_replace('/^CREATE TABLE `[^`]+`/', 'CREATE TABLE ' . $rStaging, $rCreate) . ';');
		$rRows = 0;
		for ($rOffset = 0;; $rOffset += self::CHUNK) {
			// ORDER BY 1: the same order for every chunk, whatever the table's key.
			$source->query('SELECT * FROM ' . $rName . ' ORDER BY 1 LIMIT ' . self::CHUNK . ' OFFSET ' . $rOffset . ';');
			$rChunk = $source->get_rows();
			if ($rChunk !== []) {
				$rColumns = '`' . implode('`, `', array_map(static fn($c): string => str_replace('`', '', (string) $c), array_keys($rChunk[0]))) . '`';
				$rValues = [];
				foreach ($rChunk as $rRow) {
					$rValues[] = '(' . implode(', ', array_map(static fn($v): string => $v === null ? 'NULL' : (string) $source->escape((string) $v), $rRow)) . ')';
				}
				$rWrite('INSERT INTO ' . $rStaging . ' (' . $rColumns . ') VALUES ' . implode(', ', $rValues) . ';');
				$rRows += count($rChunk);
			}
			if (count($rChunk) < self::CHUNK) {
				break;
			}
		}
		fclose($rOut);
		if ($rRows === 0) {
			@unlink($rTemp);
			return null;
		}
		if (!rename($rTemp, self::sqlFile($table))) {
			throw new \RuntimeException('Cannot save ' . self::sqlFile($table));
		}
		return new self($table, $format, $source, self::name($table));
	}

	/**
	 * The table the migration left for its module, for a module installed
	 * after it: its SQL file loaded into the staging table of the panel's
	 * database. Null when there is none.
	 *
	 * @throws \RuntimeException when the file cannot be loaded
	 */
	public static function fromBackup(string $table, ?DatabaseHandler $db = null): ?self {
		$rIn = @fopen(self::sqlFile($table), 'r');
		if ($rIn === false) {
			return null;
		}
		$db ??= DatabaseFactory::get();
		try {
			$rMeta = json_decode(substr((string) fgets($rIn), strlen(self::HEADER)), true);
			if ($db === null || !is_array($rMeta)) {
				throw new \RuntimeException('Not a saved table: ' . self::sqlFile($table));
			}
			while (($rLine = fgets($rIn)) !== false) {
				$rLine = trim($rLine);
				if ($rLine !== '' && !str_starts_with($rLine, '--') && !$db->query($rLine)) {
					throw new \RuntimeException('Cannot load ' . self::sqlFile($table) . ' into ' . self::stagingTable($table));
				}
			}
		} finally {
			fclose($rIn);
		}
		return new self($table, (string) ($rMeta['format'] ?? 'xui'), $db, self::stagingTable($table));
	}

	/**
	 * The table's rows, one at a time.
	 *
	 * @return \Generator<int, array<string, mixed>>
	 */
	public function rows(): \Generator {
		$rName = '`' . self::name($this->rSourceTable) . '`';
		for ($rOffset = 0;; $rOffset += self::CHUNK) {
			$this->rSource->query('SELECT * FROM ' . $rName . ' ORDER BY 1 LIMIT ' . self::CHUNK . ' OFFSET ' . $rOffset . ';');
			$rChunk = $this->rSource->get_rows();
			foreach ($rChunk as $rRow) {
				yield $rRow;
			}
			if (count($rChunk) < self::CHUNK) {
				return;
			}
		}
	}

	/** Remove the table's file, and its staging table, once its module has taken it. */
	public function discard(): void {
		@unlink(self::sqlFile($this->table));
		if ($this->rSourceTable === self::stagingTable($this->table)) {
			$this->rSource->query('DROP TABLE IF EXISTS `' . $this->rSourceTable . '`;');
		}
	}

	private static function name(string $table): string {
		return preg_replace('/[^a-z0-9_]/', '', strtolower($table));
	}
}
