<?php

use XcVm\Core\Database\DatabaseHandler;
/**
 * TestDb — MariaDB/MySQL test double for the XC_VM Database wrapper.
 *
 * Mirrors the subset of Database's public API used by repositories/services
 * (query / get_rows / get_row / get_col / get_column / num_rows /
 * last_insert_id / escape), over the database engine production runs.
 * Inject via the service's setDb() (the DI seam present on every domain
 * repository/service) or DatabaseFactory::set().
 *
 * Connection: XCVM_TEST_DB_DSN (a PDO MySQL DSN), XCVM_TEST_DB_USER and
 * XCVM_TEST_DB_PASS; unset, root over the local server's socket (a panel
 * host), or without one over 127.0.0.1:3306 (`make test-db`). CI sets its own.
 *
 * Every TestDb gets an empty database of its own: a throwaway schema
 * (`xcvm_t<pid>_<n>`), dropped with the instance. The session runs the
 * sql_mode the installer configures (install: NO_ENGINE_SUBSTITUTION), so
 * tests see the coercions and truncations a live panel sees.
 *
 * Extends DatabaseHandler so it satisfies the setDb(DatabaseHandler) DI seam
 * as a real subtype; the parent constructor is deliberately not invoked —
 * our own constructor wires the connection instead.
 *
 * @package XC_VM_Tests_Support
 */
final class TestDb extends DatabaseHandler {

	/** sql_mode of a live panel: the installer writes it to mariadb.cnf. */
	public const SQL_MODE = 'NO_ENGINE_SUBSTITUTION';

	/** Without the variables: root over the local server's socket (a panel host), else `make test-db`'s port. */
	private const SOCKET = '/run/mysqld/mysqld.sock';

	public PDO $pdo;

	/** @var array<int,array<string,mixed>> Buffered rows from the last SELECT. */
	private array $rows = [];

	/** @var int Rows the last statement returned (a SELECT) or changed (a write), as PDOStatement::rowCount() in Database::num_rows(). */
	private int $count = 0;

	private int $lastInsertId = 0;

	/** This instance's throwaway schema. */
	private string $schema;

	private static int $schemas = 0;

	public function __construct() {
		$this->pdo = self::connect();
		if (self::$schemas === 0) {
			self::dropOrphanSchemas($this->pdo);
		}
		$this->schema = 'xcvm_t' . getmypid() . '_' . ++self::$schemas;
		$this->pdo->exec('CREATE DATABASE `' . $this->schema . '`');
		$this->pdo->exec('USE `' . $this->schema . '`');
	}

	public function __destruct() {
		$this->pdo->exec('DROP DATABASE IF EXISTS `' . $this->schema . '`');
	}

	/** A new connection to the test server, in production's sql_mode; $rSchema selects a database. */
	public static function connect(?string $rSchema = null): PDO {
		['XCVM_TEST_DB_DSN' => $rDsn, 'XCVM_TEST_DB_USER' => $rUser] = self::env();
		if ($rSchema !== null) {
			$rDsn = preg_replace('/;?dbname=[^;]*/', '', $rDsn) . ';dbname=' . $rSchema;
		}
		// An explicit connect timeout: a boot under test (AdminGlobalsStage) can leave
		// default_socket_timeout at 0, which mysqlnd takes for a TCP connect's.
		try {
			$rPdo = new PDO($rDsn, $rUser, self::env()['XCVM_TEST_DB_PASS'] ?? null, [PDO::ATTR_TIMEOUT => 10]);
		} catch (PDOException $e) {
			throw new PDOException($e->getMessage() . ' (no test database at ' . $rDsn . ' as ' . $rUser . ': without a local server `make test-db` starts one; a server that refuses this user needs XCVM_TEST_DB_DSN, XCVM_TEST_DB_USER and XCVM_TEST_DB_PASS, see docs/en/guides/phpunit-phar.md section 2)', (int) $e->getCode(), $e);
		}
		$rPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$rPdo->exec("SET SESSION sql_mode = '" . self::SQL_MODE . "'");
		return $rPdo;
	}

	/**
	 * The connection variables, defaults filled in: also for a child process
	 * started with an environment of its own (proc_open's $env replaces the parent's).
	 *
	 * @return array{XCVM_TEST_DB_DSN: string, XCVM_TEST_DB_USER: string, XCVM_TEST_DB_PASS?: string}
	 */
	public static function env(): array {
		$rOut = ['XCVM_TEST_DB_DSN' => file_exists(self::SOCKET) ? 'mysql:unix_socket=' . self::SOCKET : 'mysql:host=127.0.0.1;port=3306', 'XCVM_TEST_DB_USER' => 'root'];
		foreach (['XCVM_TEST_DB_DSN', 'XCVM_TEST_DB_USER', 'XCVM_TEST_DB_PASS'] as $rName) {
			if (($rValue = getenv($rName)) !== false && $rValue !== '') {
				$rOut[$rName] = $rValue;
			}
		}
		return $rOut;
	}

	/** This instance's schema, for a child process that opens its own connection to it (connect()). */
	public function schema(): string {
		return $this->schema;
	}

	/** Schemas a run left behind when its process died (a segfault skips __destruct). */
	private static function dropOrphanSchemas(PDO $pdo): void {
		foreach ($pdo->query("SHOW DATABASES LIKE 'xcvm\\_t%'")->fetchAll(PDO::FETCH_COLUMN) as $name) {
			if (preg_match('/^xcvm_t(\d+)_\d+$/', $name, $m) && !posix_kill((int) $m[1], 0)) {
				$pdo->exec('DROP DATABASE IF EXISTS `' . $name . '`');
			}
		}
	}

	/** True when the statement is schema DDL (a test may send several in one string). */
	private static function isDdl(string $sql): bool {
		return (bool) preg_match('/^\s*(CREATE|ALTER|DROP)\s+TABLE/i', $sql);
	}

	/**
	 * Execute raw schema/seed SQL (one or more `;`-separated statements).
	 */
	public function exec(string $sql): void {
		$this->pdo->exec($sql);
	}

	/**
	 * Run a prepared query. Bind values follow $query (as in Database::query()).
	 * SELECT/WITH/SHOW results are buffered for get_rows()/get_row()/num_rows().
	 */
	public function query($query, ...$args): bool {
		if (self::isDdl($query)) {
			$this->pdo->exec($query);
			$this->rows = array();
			$this->count = 0;
			return true;
		}

		// Mirror Database: the literal string 'null' and PHP null bind as SQL NULL.
		$binds = array();
		foreach ($args as $a) {
			$binds[] = (is_string($a) && strtolower($a) === 'null') ? null : $a;
		}

		$stmt = $this->pdo->prepare($query);
		$stmt->execute($binds);

		if (preg_match('/^\s*(SELECT|WITH|SHOW)/i', $query)) {
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

	/** The test connection is always up (DatabaseFactory::connectLazy() asks). */
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
	 * outside one.
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
