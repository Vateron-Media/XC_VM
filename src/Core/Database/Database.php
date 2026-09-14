<?php

namespace XcVm\Core\Database;

use XcVm\Core\Logging\FileLogger;

/**
 * Database — database
 *
 * @package XC_VM_Core_Database
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class Database {
	public $result;

	public $last_query;

	public $dbh;

	public $connected = false;

	/** Last PDO error message (empty when the last query succeeded). */
	protected $lastError = '';

	protected $dbuser;

	protected $dbpassword;

	protected $dbname;

	protected $dbhost;

	protected $dbport;

	/**
	 * Constructor - Initializes database connection
	 *
	 * @param string $db_user Database username
	 * @param string $db_pass Database password
	 * @param string $db_name Database name
	 * @param string $host Database host
	 * @param int $db_port Database port number
	 */
	public function __construct(?string $db_user = null, ?string $db_pass = null, ?string $db_name = null, ?string $host = null, int $db_port = 3306, $migrate = false) {
		$this->dbh = false;
		$this->dbuser = $db_user;
		$this->dbpassword = $db_pass;
		$this->dbname = $db_name;
		$this->dbhost = $this->normalizeHost($host);
		$this->dbport = $db_port;
		$this->db_connect($migrate);
	}

	/**
	 * Normalize a DB host, forcing TCP for 'localhost'.
	 *
	 * @param string|null $rHost Host name (null when connecting via the bundled
	 *                           XC_VM extension, which resolves credentials itself).
	 * @return string|null '127.0.0.1' for 'localhost', otherwise the host unchanged.
	 */
	private function normalizeHost(?string $rHost): ?string {
		if ($rHost === 'localhost') {
			return '127.0.0.1';
		}

		return $rHost;
	}

	/**
	 * Close the connection and reset internal state.
	 *
	 * @return bool Always true.
	 */
	public function close_mysql() {
		$this->connected = false;
		$this->dbh = null;
		$this->result = null;
		$this->last_query = null;

		return true;
	}

	/**
	 * Close the connection when the instance is destroyed.
	 */
	public function __destruct() {
		$this->close_mysql();
	}

	/**
	 * Check whether the connection is alive.
	 *
	 * @return bool True if a `SELECT 1` succeeds.
	 * @phpstan-impure Return value reflects live connection state and can differ between calls.
	 */
	public function ping() {
		if (!$this->dbh) {
			return false;
		}

		try {
			$this->dbh->query('SELECT 1');
		} catch (\Exception $e) {
			return false;
		}

		return true;
	}

	/**
	 * Connect using the panel's configured credentials.
	 *
	 * IMPORTANT: `$migrate` selects the TARGET schema, not just the failure mode.
	 * The xcvm_core extension resolves `\XC_VM::db_connect(true)` to the
	 * `xc_vm_migrate` scratch database (that is how the migration flow reads a
	 * restored old-panel backup). Passing `true` here merely to get "return false
	 * instead of exit" therefore silently repoints the connection at
	 * `xc_vm_migrate`; once that DB exists (after a migration) every unqualified
	 * query resolves against it and fails with "Table 'xc_vm_migrate.<t>' doesn't
	 * exist". Callers that only want to fail gracefully on the MAIN database must
	 * pass $graceful, NOT $migrate.
	 *
	 * @param bool      $migrate  Connect to the `xc_vm_migrate` schema instead of
	 *                            the configured one. Use only for migration code.
	 * @param bool|null $graceful Return false on failure instead of exiting. When
	 *                            null it defaults to $migrate (legacy behaviour).
	 * @return bool True on success.
	 */
	public function db_connect(bool $migrate = false, ?bool $graceful = null) {
		if ($graceful === null) {
			$graceful = $migrate;
		}

		try {
			$this->dbh = \XC_VM::db_connect($migrate);
			if (!$this->dbh) {
				if (!$graceful) {
					exit(json_encode(['error' => 'MySQL: Cannot connect to database! Please check credentials.']));
				}

				return false;
			}
		} catch (\PDOException $e) {
			if (!$graceful) {
				exit(json_encode(['error' => 'MySQL: ' . $e->getMessage()]));
			}
			return false;
		}

		$this->dbh->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$this->connected = true;
		$this->applySessionTimeouts();

		return true;
	}

	/**
	 * Force a short session idle-timeout so orphaned connections self-expire.
	 *
	 * A worker killed with SIGKILL (or OOM) cannot close its socket, leaving the
	 * connection idle on MASTER for the global wait_timeout (8h). Capping the
	 * *session* wait_timeout at 60s means any orphan is reaped within a minute.
	 * This only counts idle time between queries — long-running queries are not
	 * affected. A daemon idle > 60s gets 'gone away' on its next query, which
	 * DatabaseHandler::query() transparently recovers via reconnect-on-failure.
	 *
	 * @return void
	 */
	protected function applySessionTimeouts() {
		if (!$this->dbh) {
			return;
		}

		try {
			$this->dbh->exec('SET NAMES utf8mb4; SET SESSION wait_timeout=60, interactive_timeout=60');
		} catch (\Exception $e) {
			// Non-fatal: a missing idle cap is a degradation, not a failure.
		}
	}

	/**
	 * Connect using explicitly supplied credentials (e.g. remote/LB databases).
	 *
	 * @param string $rHost     Database host.
	 * @param int    $rPort     Database port.
	 * @param string $rDatabase Database name.
	 * @param string $rUsername Username.
	 * @param string $rPassword Password.
	 * @return bool True on success, false on failure.
	 */
	public function db_explicit_connect(string $rHost, int $rPort, string $rDatabase, string $rUsername, string $rPassword) {
		try {
			$this->dbh = new \PDO('mysql:host=' . $this->normalizeHost($rHost) . ';port=' . $rPort . ';dbname=' . $rDatabase, $rUsername, $rPassword);
		} catch (\PDOException $e) {
			return false;
		}

		$this->dbh->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$this->connected = true;
		$this->applySessionTimeouts();

		return true;
	}

	/**
	 * Capture a \PDO statement's debugDumpParams() output as a string.
	 *
	 * @param \PDOStatement $stmt Prepared statement.
	 * @return string The dumped parameter/SQL debug text.
	 */
	public function debugString(\PDOStatement $stmt) {
		ob_start();
		$stmt->debugDumpParams();
		$r = ob_get_contents();
		ob_end_clean();

		return $r;
	}

	/**
	 * Run a prepared, parameterized query.
	 *
	 * Bind values are passed as additional arguments after $query (the `?`
	 * placeholders); the literal string 'null' and PHP null bind as SQL NULL.
	 * Errors are logged via FileLogger and return false.
	 *
	 * @param string $query    SQL with `?` placeholders.
	 * @param mixed  $buffered First bind value, or boolean true to disable
	 *                         buffered query mode (legacy positional overload;
	 *                         all args from index 1 are collected as binds).
	 *                         NOTE: because `=== true` toggles unbuffered mode,
	 *                         a literal boolean true cannot be bound as the first
	 *                         parameter. This ambiguous overload is a candidate
	 *                         for extraction into a dedicated unbuffered_query().
	 * @return bool True on success, false on failure.
	 */
	public function query(string $query, mixed $buffered = false) {
		if (!$this->dbh) {
			return false;
		}


		$numargs = func_num_args();
		$arg_list = func_get_args();
		$next_arg_list = [];
		$i = 1;

		while ($i < $numargs) {
			if (is_null($arg_list[$i]) || strtolower($arg_list[$i]) == 'null') {
				$next_arg_list[] = null;
			} else {
				$next_arg_list[] = $arg_list[$i];
			}

			$i++;
		}

		if ($buffered === true) {
			$this->dbh->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
		}

		try {
			$this->result = $this->dbh->prepare($query);
			$this->result->execute($next_arg_list);
		} catch (\Exception $e) {
			$rDebugParts = explode('Sent SQL:', $this->debugString($this->result));
			$actual_query = isset($rDebugParts[1]) ? trim(explode("\n", $rDebugParts[1])[0]) : '';

			if (strlen($actual_query) == 0) {
				$actual_query = $query;
			}

			// Keep the raw driver message so callers can surface the real cause
			// of a failed write (e.g. LINE_CREATE_FAIL) even when FileLogger's
			// noise filter drops the 'pdo' entry (duplicate entry / timeouts).
			$this->lastError = $e->getMessage();

			FileLogger::log('pdo', $e->getMessage(), $actual_query, $e->getLine());

			return false;
		} finally {
			// Restore buffered mode so the next query on this connection is not
			// left in a sticky unbuffered state ('Commands out of sync').
			if ($buffered === true) {
				$this->dbh->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
			}
		}

		$this->lastError = '';
		return true;
	}

	/**
	 * Return the driver message from the most recent failed query.
	 *
	 * @return string Empty string when the last query succeeded.
	 */
	public function error(): string {
		return $this->lastError;
	}

	/**
	 * Run an unprepared query (no bound parameters).
	 *
	 * @param string $query Raw SQL.
	 * @return bool True on success, false on failure.
	 */
	public function simple_query(string $query) {
		try {
			$this->result = $this->dbh->query($query);
		} catch (\Exception $e) {
			FileLogger::log('pdo', $e->getMessage(), $query, $e->getLine());
			return false;
		}

		return true;
	}

	/**
	 * Fetch all rows from the last query, optionally keyed by a column.
	 *
	 * @param bool   $use_id       Key the result by $column_as_id when true.
	 * @param string $column_as_id Column to use as the top-level key.
	 * @param bool   $unique_row   When false, group multiple rows under each key.
	 * @param string $sub_row_id   Optional column used as the sub-key when grouping.
	 * @return array|false Rows (cleaned), or false if no active result.
	 */
	public function get_rows(bool $use_id = false, string $column_as_id = '', bool $unique_row = true, string $sub_row_id = '') {
		if (!$this->dbh || !$this->result) {
			return false;
		}

		$rows = [];

		if (0 < $this->result->rowCount()) {
			foreach ($this->result->fetchAll(\PDO::FETCH_ASSOC) as $row) {
				if ($use_id && array_key_exists($column_as_id, $row)) {
					if (!isset($rows[$row[$column_as_id]])) {
						$rows[$row[$column_as_id]] = [];
					}

					if (!$unique_row) {
						if (!empty($sub_row_id) && array_key_exists($sub_row_id, $row)) {
							$rows[$row[$column_as_id]][$row[$sub_row_id]] = $this->clean_row($row);
						} else {
							$rows[$row[$column_as_id]][] = $this->clean_row($row);
						}
					} else {
						$rows[$row[$column_as_id]] = $this->clean_row($row);
					}
				} else {
					$rows[] = $this->clean_row($row);
				}
			}
		}

		$this->result = null;

		return $rows;
	}

	/**
	 * Fetch a single (cleaned) associative row from the last query.
	 *
	 * @return array<string, mixed>|false The row, or false if no active result.
	 */
	public function get_row() {
		if (!$this->dbh || !$this->result) {
			return false;
		}

		$row = [];

		if (0 < $this->result->rowCount()) {
			$row = $this->result->fetch(\PDO::FETCH_ASSOC);
		}

		$this->result = null;

		return $this->clean_row($row);
	}

	/**
	 * Fetch the first column of the first row.
	 *
	 * @return mixed The scalar value, or false if no active result/row.
	 */
	public function get_col() {
		if (!$this->dbh || !$this->result) {
			return false;
		}

		$row = false;

		if (0 < $this->result->rowCount()) {
			$row = $this->result->fetch();
			$row = $row[0];
		}

		$this->result = null;

		return $row;
	}

	/**
	 * Fetch the first column from every row.
	 *
	 * @return array List of first-column values.
	 */
	public function get_column() {
		$col = [];
		if ($this->result) {
			// fetchColumn() returns false only when the rowset is exhausted;
			// compare strictly so falsy values (0, '', '0') are not truncated.
			while (($val = $this->result->fetchColumn(0)) !== false) {
				$col[] = $val;
			}
			$this->result->closeCursor();
			$this->result = null;
		}
		return $col;
	}

	/**
	 * Quote a string for safe inclusion in SQL (prefer parameterized queries).
	 *
	 * @param string|null $string Value to quote (null coerced to empty string).
	 * @return string|null Quoted string, or null if not connected.
	 */
	public function escape(?string $string) {
		if ($this->dbh) {
			return $this->dbh->quote((string) $string);
		}
		return null;
	}

	/**
	 * Number of columns in the current result set.
	 *
	 * @return int Column count (0 if none).
	 */
	public function num_fields() {
		if (!$this->dbh || !$this->result) {
			return 0;
		}

		$mysqli_num_fields = $this->result->columnCount();

		return (empty($mysqli_num_fields) ? 0 : $mysqli_num_fields);
	}

	/**
	 * Id generated by the last INSERT.
	 *
	 * @return int|null Insert id (0 if none), or null if not connected.
	 */
	public function last_insert_id() {
		if ($this->dbh) {
			$mysql_insert_id = $this->dbh->lastInsertId();
			return (empty($mysql_insert_id) ? 0 : $mysql_insert_id);
		}
		return null;
	}

	/**
	 * Number of rows in/affected by the current result set.
	 *
	 * @return int Row count (0 if none).
	 * @phpstan-impure Return value reflects the current result set and changes as queries run.
	 */
	public function num_rows() {
		if (!$this->dbh || !$this->result) {
			return 0;
		}

		$mysqli_num_rows = $this->result->rowCount();

		return (empty($mysqli_num_rows) ? 0 : $mysqli_num_rows);
	}

	/**
	 * Sanitize a single value: normalize newlines and HTML-escape angle brackets/scripts.
	 *
	 * @param string $rValue Raw value.
	 * @return string Cleaned value ('' for empty input).
	 */
	public static function parseCleanValue(string $rValue) {
		if ($rValue != '') {
			$rValue = str_replace(["\r\n", "\n\r", "\r"], "\n", $rValue);
			$rValue = str_replace('<', '&lt;', str_replace('>', '&gt;', $rValue));
			$rValue = str_replace('<!--', '&#60;&#33;--', $rValue);
			$rValue = str_replace('-->', '--&#62;', $rValue);
			$rValue = str_ireplace('<script', '&#60;script', $rValue);
			$rValue = preg_replace('/&amp;#([0-9]+);/s', '&#\\1;', $rValue);
			$rValue = preg_replace('/&#(\\d+?)([^\\d;])/i', '&#\\1;\\2', $rValue);

			return trim($rValue);
		}
		return '';
	}

	/**
	 * Apply parseCleanValue() to every column of a row.
	 *
	 * @param array<string, mixed> $row Associative row.
	 * @return array<string, mixed> Row with sanitized values.
	 */
	public function clean_row(array $row) {
		foreach ($row as $key => $value) {
			if ($value) {
				$row[$key] = self::parseCleanValue($value);
			}
		}
		return $row;
	}
}
