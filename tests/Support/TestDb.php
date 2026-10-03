<?php

use XcVm\Core\Database\DatabaseHandler;
/**
 * TestDb — SQLite/MariaDB test double for the XC_VM Database wrapper.
 *
 * Mirrors the subset of Database's public API used by repositories/services
 * (query / get_rows / get_row / get_col / get_column / num_rows /
 * last_insert_id / escape). By default it is backed by `sqlite::memory:` so
 * DB-touching code can be unit-tested without a server; set the environment
 * variables below to run the SAME tests against a real MariaDB (e.g. on the
 * panel host, where SQLite is unavailable). Inject via the service's setDb()
 * (the DI seam present on every domain repository/service).
 *
 * Backend selection (constructor, when no PDO is injected):
 *   - XCVM_TEST_DB_DSN set  -> connect with that PDO DSN (MariaDB/MySQL),
 *     XCVM_TEST_DB_USER / XCVM_TEST_DB_PASS for credentials.
 *   - otherwise              -> sqlite::memory:
 *
 * Tests keep writing plain SQLite DDL. When the backend is MySQL/MariaDB the
 * DDL is translated on the fly (see translate()): `AUTOINCREMENT` becomes
 * `AUTO_INCREMENT`, a bare `INTEGER PRIMARY KEY` gains `AUTO_INCREMENT` so the
 * implicit-rowid behaviour matches, and every `CREATE TABLE x` is preceded by
 * `DROP TABLE IF EXISTS x` (a MariaDB schema persists across the per-test
 * connections that `:memory:` would otherwise start empty).
 *
 * Usage:
 *   $db = new TestDb();
 *   $db->exec('CREATE TABLE ...; INSERT INTO ...;');
 *   SomeRepository::setDb($db);
 *
 * Caveat: reserved-word identifiers must be back-quoted in the test DDL
 * (e.g. `key`, `order`, `lines`) so both engines accept them.
 *
 * Extends DatabaseHandler so it satisfies the setDb(DatabaseHandler) DI seam
 * as a real subtype; the parent constructor (which connects to MySQL) is
 * deliberately not invoked — our own constructor wires the backend instead.
 *
 * @package XC_VM_Tests_Support
 */
final class TestDb extends DatabaseHandler {

	public PDO $pdo;

	/** @var string PDO driver name ('sqlite' or 'mysql'). */
	private string $driver;

	/** @var array<int,array<string,mixed>> Buffered rows from the last SELECT. */
	private array $rows = [];

	/** @var int Rows the last statement returned (a SELECT) or changed (a write), as PDOStatement::rowCount() in Database::num_rows(). */
	private int $count = 0;

	private int $lastInsertId = 0;

	public function __construct(?PDO $pdo = null) {
		$this->pdo = $pdo ?? self::connect();
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

		if ($this->driver === 'mysql') {
			// Relax to match SQLite's leniency the tests were written against:
			// disabling ANSI_QUOTES lets double-quoted string literals work, and
			// dropping STRICT avoids type-coercion errors on the simplified DDL.
			$this->pdo->exec("SET SESSION sql_mode = ''");
		}
	}

	/**
	 * Build the backing PDO from the environment: a MariaDB/MySQL DSN when
	 * XCVM_TEST_DB_DSN is set, otherwise an in-memory SQLite database.
	 */
	private static function connect(): PDO {
		$dsn = getenv('XCVM_TEST_DB_DSN');
		if ($dsn !== false && $dsn !== '') {
			$user = getenv('XCVM_TEST_DB_USER');
			$pass = getenv('XCVM_TEST_DB_PASS');
			return new PDO($dsn, $user === false ? null : $user, $pass === false ? null : $pass);
		}
		return new PDO('sqlite::memory:');
	}

	/**
	 * Translate SQLite test DDL to the active backend. No-op for SQLite.
	 */
	private function translate(string $sql): string {
		if ($this->driver !== 'mysql') {
			// SQLite doesn't understand MySQL table-option clauses (real
			// production DDL, e.g. MigrationRunner's CREATE TABLE, carries
			// `ENGINE=InnoDB DEFAULT CHARSET=utf8[mb4] [COLLATE=...]`) — strip
			// them so such DDL can run unmodified against the default backend.
			return preg_replace(
				'/\s*ENGINE\s*=\s*\w+(\s+DEFAULT)?(\s+CHARSET\s*=\s*\w+)?(\s+COLLATE\s*=?\s*\w+)?/i',
				'',
				$sql
			);
		}

		// SQLite `AUTOINCREMENT` -> MySQL `AUTO_INCREMENT`.
		$sql = preg_replace('/\bAUTOINCREMENT\b/i', 'AUTO_INCREMENT', $sql);

		// A bare `INTEGER PRIMARY KEY` auto-increments in SQLite but not in
		// MySQL; add AUTO_INCREMENT so INSERTs without an id behave the same.
		$sql = preg_replace(
			'/\bINTEGER\s+PRIMARY\s+KEY\b(?!\s+AUTO_INCREMENT)/i',
			'INTEGER PRIMARY KEY AUTO_INCREMENT',
			$sql
		);

		// MariaDB keeps a table between the per-test connections, so make each
		// CREATE idempotent by dropping first.
		$sql = preg_replace_callback(
			'/\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(`?)(\w+)\1/i',
			static fn(array $m): string => 'DROP TABLE IF EXISTS ' . $m[1] . $m[2] . $m[1] . '; ' . $m[0],
			$sql
		);

		return $sql;
	}

	/** True when the statement is schema DDL (needs the multi-statement exec path). */
	private static function isDdl(string $sql): bool {
		return (bool) preg_match('/^\s*(CREATE|ALTER|DROP)\s+TABLE/i', $sql);
	}

	/**
	 * Execute raw schema/seed SQL (one or more `;`-separated statements).
	 */
	public function exec(string $sql): void {
		$this->pdo->exec($this->translate($sql));
	}

	/**
	 * Run a prepared query. Bind values follow $query (as in Database::query()).
	 * SELECT/PRAGMA/WITH results are buffered for get_rows()/get_row()/num_rows().
	 */
	public function query($query, ...$args): bool {
		// DDL cannot be run through prepare()/execute() on MySQL (the DROP+CREATE
		// pair is multi-statement); route it through exec() on both backends.
		if (self::isDdl($query)) {
			$this->pdo->exec($this->translate($query));
			$this->rows = array();
			$this->count = 0;
			return true;
		}

		// Mirror Database: the literal string 'null' and PHP null bind as SQL NULL.
		$binds = array();
		foreach ($args as $a) {
			$binds[] = (is_string($a) && strtolower($a) === 'null') ? null : $a;
		}

		if ($this->driver !== 'mysql') {
			// SQLite has no row locks and no such clause: a locking read is a plain one there.
			$query = preg_replace('/\s+FOR UPDATE\s*;?\s*$/i', '', (string) $query);
		}
		$stmt = $this->pdo->prepare($query);
		$stmt->execute($binds);

		if (preg_match('/^\s*(SELECT|PRAGMA|WITH)/i', $query)) {
			$this->rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
			$this->count = count($this->rows);
		} else {
			$this->rows = array();
			$this->count = $stmt->rowCount();
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
		$rows = array_map(fn($row) => $this->clean_row($row), $this->rows);
		if (!$use_id) {
			return $rows;
		}

		$out = array();
		foreach ($rows as $row) {
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
		return $this->clean_row($this->rows[0] ?? array());
	}

	public function get_raw_rows(): array {
		return $this->rows;
	}

	public function get_raw_row(): ?array {
		return $this->rows[0] ?? null;
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

	/** Rows of the last SELECT, or rows the last write changed (Database::num_rows(), PDOStatement::rowCount()). */
	public function num_rows(): int {
		return $this->count;
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

	/** The in-memory connection is always up (DatabaseFactory::connectLazy() asks). */
	public function ping(): bool {
		return true;
	}

	/** Database's escaping, on the strings a MySQL fetch would return; get_raw_rows() skips it as Database's does. */
	public function clean_row($row) {
		foreach ($row as $key => $value) {
			if (is_string($value) && $value !== '') {
				$row[$key] = self::parseCleanValue($value);
			}
		}
		return $row;
	}

	/**
	 * Transactions over the backing PDO, with DatabaseHandler's semantics: one
	 * at a time (a nested begin is refused, false), commit and rollback false
	 * outside one. SQLite and MariaDB both roll back what ran inside.
	 */
	public function beginTransaction() {
		if ($this->inTransaction) {
			return false;
		}
		$this->inTransaction = $this->pdo->beginTransaction();
		return $this->inTransaction;
	}

	public function commit() {
		if (!$this->inTransaction) {
			return false;
		}
		$this->inTransaction = false;
		return $this->pdo->commit();
	}

	public function rollback() {
		if (!$this->inTransaction) {
			return false;
		}
		$this->inTransaction = false;
		return $this->pdo->rollBack();
	}
}
