<?php

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
	public $result = null;
	public $last_query = null;
	public $dbh = null;
	public $connected = false;

	protected $dbuser = null;
	protected $dbpassword = null;
	protected $dbname = null;
	protected $dbhost = null;
	protected $dbport = null;

	/**
	 * Constructor - Initializes database connection
	 *
	 * @param string $db_user Database username
	 * @param string $db_pass Database password
	 * @param string $db_name Database name
	 * @param string $host Database host
	 * @param int $db_port Database port number
	 */
	public function __construct($db_user = null, $db_pass = null, $db_name = null, $host = null, $db_port = 3306, $migrate = false) {
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
	 * @param string $rHost Host name.
	 * @return string '127.0.0.1' for 'localhost', otherwise the host unchanged.
	 */
	private function normalizeHost($rHost) {
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
	 */
	public function ping() {
		if (!$this->dbh) {
			return false;
		}

		try {
			$this->dbh->query('SELECT 1');
		} catch (Exception $e) {
			return false;
		}

		return true;
	}

	/**
	 * Connect using the panel's configured credentials.
	 *
	 * On failure exits with a JSON error unless running in migrate mode.
	 *
	 * @param bool $migrate When true, return false on failure instead of exiting.
	 * @return bool True on success.
	 */
	public function db_connect($migrate = false) {
		try {
			$this->dbh = XC_VM::db_connect($migrate);
			if (!$this->dbh) {
				if (!$migrate) {
					exit(json_encode(array('error' => 'MySQL: Cannot connect to database! Please check credentials.')));
				}

				return false;
			}
		} catch (PDOException $e) {
			if (!$migrate) {
				exit(json_encode(array('error' => 'MySQL: ' . $e->getMessage())));
			}
			return false;
		}

		$this->dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->connected = true;

		return true;
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
	public function db_explicit_connect($rHost, $rPort, $rDatabase, $rUsername, $rPassword) {
		try {
			$this->dbh = new PDO('mysql:host=' . $this->normalizeHost($rHost) . ';port=' . $rPort . ';dbname=' . $rDatabase, $rUsername, $rPassword);

			if (!$this->dbh) {
				return false;
			}
		} catch (PDOException $e) {
			return false;
		}

		$this->dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->connected = true;

		return true;
	}

	/**
	 * Capture a PDO statement's debugDumpParams() output as a string.
	 *
	 * @param PDOStatement $stmt Prepared statement.
	 * @return string The dumped parameter/SQL debug text.
	 */
	public function debugString($stmt) {
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
	 * @param bool   $buffered Disable buffered query mode when true.
	 * @return bool True on success, false on failure.
	 */
	public function query($query, $buffered = false) {
		if (!$this->dbh) {
			return false;
		}


		$numargs = func_num_args();
		$arg_list = func_get_args();
		$next_arg_list = array();
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
			$this->dbh->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
		}

		try {
			$this->result = $this->dbh->prepare($query);
			$this->result->execute($next_arg_list);
		} catch (Exception $e) {
			$rDebugParts = explode('Sent SQL:', $this->debugString($this->result));
			$actual_query = isset($rDebugParts[1]) ? trim(explode("\n", $rDebugParts[1])[0]) : '';

			if (strlen($actual_query) == 0) {
				$actual_query = $query;
			}

			FileLogger::log('pdo', $e->getMessage(), $actual_query, (int) $e->getLine());

			return false;
		}

		return true;
	}

	/**
	 * Run an unprepared query (no bound parameters).
	 *
	 * @param string $query Raw SQL.
	 * @return bool True on success, false on failure.
	 */
	public function simple_query($query) {
		try {
			$this->result = $this->dbh->query($query);
		} catch (Exception $e) {
			FileLogger::log('pdo', $e->getMessage(), $query, (int) $e->getLine());
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
	public function get_rows($use_id = false, $column_as_id = '', $unique_row = true, $sub_row_id = '') {
		if (!($this->dbh && $this->result)) {
			return false;
		}

		$rows = array();

		if (0 >= $this->result->rowCount()) {
		} else {
			foreach ($this->result->fetchAll(PDO::FETCH_ASSOC) as $row) {
				if ($use_id && array_key_exists($column_as_id, $row)) {
					if (!isset($rows[$row[$column_as_id]])) {
						$rows[$row[$column_as_id]] = array();
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
	 * @return array|false The row, or false if no active result.
	 */
	public function get_row() {
		if (!($this->dbh && $this->result)) {
			return false;
		}

		$row = array();

		if (0 >= $this->result->rowCount()) {
		} else {
			$row = $this->result->fetch(PDO::FETCH_ASSOC);
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
		if (!($this->dbh && $this->result)) {
			return false;
		}

		$row = false;

		if (0 >= $this->result->rowCount()) {
		} else {
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
			while ($val = $this->result->fetchColumn(0)) {
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
	 * @param string $string Value to quote.
	 * @return string|null Quoted string, or null if not connected.
	 */
	public function escape($string) {
		if ($this->dbh) {
			return $this->dbh->quote($string);
		}
	}

	/**
	 * Number of columns in the current result set.
	 *
	 * @return int Column count (0 if none).
	 */
	public function num_fields() {
		if (!($this->dbh && $this->result)) {
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
	}

	/**
	 * Number of rows in/affected by the current result set.
	 *
	 * @return int Row count (0 if none).
	 */
	public function num_rows() {
		if (!($this->dbh && $this->result)) {
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
	public static function parseCleanValue($rValue) {
		if ($rValue != '') {
			$rValue = str_replace(array("\r\n", "\n\r", "\r"), "\n", $rValue);
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
	 * @param array $row Associative row.
	 * @return array Row with sanitized values.
	 */
	public function clean_row($row) {
		foreach ($row as $key => $value) {
			if ($value) {
				$row[$key] = self::parseCleanValue($value);
			}
		}
		return $row;
	}
}
