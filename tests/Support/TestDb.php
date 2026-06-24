<?php

use XcVm\Core\Database\Database;
/**
 * TestDb — in-memory SQLite test double for the XC_VM Database wrapper.
 *
 * Mirrors the subset of Database's public API used by repositories/services
 * (query / get_rows / get_row / get_col / get_column / num_rows /
 * last_insert_id / escape), but backed by `sqlite::memory:` so DB-touching
 * code can be unit-tested without a MySQL server. Inject via the service's
 * setDb() (the DI seam present on every domain repository/service).
 *
 * Usage:
 *   $db = new TestDb();
 *   $db->exec('CREATE TABLE ...; INSERT INTO ...;');
 *   SomeRepository::setDb($db);
 *
 * Caveat: SQLite is NOT MySQL. Code using MySQL-specific SQL — `JSON_CONTAINS`,
 * `information_schema`, MySQL-only functions — cannot be exercised here and
 * needs a real MySQL (CI service) or a query-level mock instead.
 *
 * @package XC_VM_Tests_Support
 */
final class TestDb {

	public PDO $pdo;

	/** @var array<int,array<string,mixed>> Buffered rows from the last SELECT. */
	private array $rows = [];

	private int $lastInsertId = 0;

	public function __construct(?PDO $pdo = null) {
		$this->pdo = $pdo ?? new PDO('sqlite::memory:');
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	}

	/**
	 * Execute raw schema/seed SQL (one or more `;`-separated statements).
	 */
	public function exec(string $sql): void {
		$this->pdo->exec($sql);
	}

	/**
	 * Run a prepared query. Bind values follow $query (as in Database::query()).
	 * SELECT/PRAGMA/WITH results are buffered for get_rows()/get_row()/num_rows().
	 */
	public function query($query, ...$args): bool {
		// Mirror Database: the literal string 'null' and PHP null bind as SQL NULL.
		$binds = array();
		foreach ($args as $a) {
			$binds[] = (is_string($a) && strtolower($a) === 'null') ? null : $a;
		}

		$stmt = $this->pdo->prepare($query);
		$stmt->execute($binds);

		if (preg_match('/^\s*(SELECT|PRAGMA|WITH)/i', $query)) {
			$this->rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
		} else {
			$this->rows = array();
			$id = $this->pdo->lastInsertId();
			if ($id) {
				$this->lastInsertId = (int) $id;
			}
		}

		return true;
	}

	/**
	 * Return buffered rows, optionally keyed by a column (mirrors Database).
	 */
	public function get_rows($use_id = false, $column_as_id = '', $unique_row = true, $sub_row_id = '') {
		if (!$use_id) {
			return $this->rows;
		}

		$out = array();
		foreach ($this->rows as $row) {
			if ($column_as_id !== '' && array_key_exists($column_as_id, $row)) {
				if ($unique_row) {
					$out[$row[$column_as_id]] = $row;
				} elseif (!empty($sub_row_id) && array_key_exists($sub_row_id, $row)) {
					$out[$row[$column_as_id]][$row[$sub_row_id]] = $row;
				} else {
					$out[$row[$column_as_id]][] = $row;
				}
			} else {
				$out[] = $row;
			}
		}
		return $out;
	}

	public function get_row() {
		return $this->rows[0] ?? array();
	}

	public function get_col() {
		$row = $this->rows[0] ?? null;
		return $row ? array_values($row)[0] : false;
	}

	public function get_column(): array {
		$col = array();
		foreach ($this->rows as $row) {
			$col[] = array_values($row)[0] ?? null;
		}
		return $col;
	}

	public function num_rows(): int {
		return count($this->rows);
	}

	public function last_insert_id() {
		return $this->lastInsertId;
	}

	public function escape($string) {
		return $this->pdo->quote((string) $string);
	}

	public function close_mysql(): bool {
		return true;
	}

	public function clean_row($row) {
		return $row;
	}
}
