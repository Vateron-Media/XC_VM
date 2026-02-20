<?php

/**
 * XC_VM — Менеджер базы данных (PDO)
 *
 * Расширяет базовый класс Database:
 *   - Transaction support (begin/commit/rollback)
 *   - Named parameter binding
 *   - Reconnection on disconnect
 *   - Query counter for debugging
 *   - Fluent chaining for common patterns
 *   - ServiceContainer integration
 *
 * ---------------------------------------------------------------
 * Backward Compatibility:
 * ---------------------------------------------------------------
 *
 *   DatabaseHandler extends Database — all legacy code continues working.
 *   Existing usage patterns are fully supported:
 *
 *     $db->query("SELECT * FROM streams WHERE id = ?", $id);
 *     $rows = $db->get_rows();
 *     $row  = $db->get_row();
 *     $val  = $db->get_col();
 *
 *   New code can use improved methods:
 *
 *     $rows = $db->fetchAll("SELECT * FROM streams WHERE id = ?", $id);
 *     $row  = $db->fetchOne("SELECT * FROM streams WHERE id = ?", $id);
 *     $val  = $db->fetchValue("SELECT COUNT(*) FROM streams");
 *
 * ---------------------------------------------------------------
 * Transaction Support:
 * ---------------------------------------------------------------
 *
 *     $db->beginTransaction();
 *     try {
 *         $db->query("UPDATE ...", $val1);
 *         $db->query("INSERT ...", $val2);
 *         $db->commit();
 *     } catch (Exception $e) {
 *         $db->rollback();
 *     }
 *
 *     // Or use the transactional() helper:
 *     $db->transactional(function($db) {
 *         $db->query("UPDATE ...", $val1);
 *         $db->query("INSERT ...", $val2);
 *     });
 *
 * ---------------------------------------------------------------
 * ServiceContainer Registration:
 * ---------------------------------------------------------------
 *
 *     $container->set('db', function($c) {
 *         $cfg = $c->get('config');
 *         return DatabaseHandler::create($cfg);
 *     });
 *
 * ---------------------------------------------------------------
 * Migration Path:
 * ---------------------------------------------------------------
 *
 *   Phase 1: DatabaseHandler extends Database (this file).
 *            Database.php moved to core/Database/Database.php.
 *            bootstrap.php creates DatabaseHandler instead of Database.
 *            All legacy code works via inheritance.
 *
 *   Phase 6: Database.php merged into DatabaseHandler. DatabaseHandler becomes standalone.
 *            All `global $db` replaced with ServiceContainer.
 *
 * @see core/Database/Database.php  Base PDO wrapper class
 * @see core/Container/ServiceContainer.php  DI container
 */

require_once __DIR__ . '/Database.php';

class DatabaseHandler extends Database {

    /** @var int Total queries executed in this request */
    protected $queryCount = 0;

    /** @var float Total query execution time (seconds) */
    protected $queryTime = 0.0;

    /** @var bool Whether a transaction is currently active */
    protected $inTransaction = false;

    /** @var callable|null Optional logger callback: function(string $level, string $message, array $context) */
    protected $logger = null;

    /** @var int Maximum reconnection attempts */
    protected $maxReconnectAttempts = 3;

    /**
     * Factory method — creates DatabaseHandler from config array
     *
     * @param array $config Associative array with keys:
     *   'username', 'password', 'database', 'hostname', 'port' (optional, default 3306)
     * @param bool $migrate Whether this is a migration connection (no exit on failure)
     * @return DatabaseHandler|false
     */
    public static function create(array $config, $migrate = false) {
        $port = isset($config['port']) ? (int)$config['port'] : 3306;

        return new self(
            $config['username'],
            $config['password'],
            $config['database'],
            $config['hostname'],
            $port,
            $migrate
        );
    }

    /**
     * Set a logger callback for query and error logging
     *
     * @param callable $logger function(string $level, string $message, array $context = [])
     * @return $this
     */
    public function setLogger($logger) {
        $this->logger = $logger;
        return $this;
    }

    // ───────────────────────────────────────────────────────────
    //  Transaction Support
    // ───────────────────────────────────────────────────────────

    /**
     * Begin a database transaction
     *
     * @return bool
     */
    public function beginTransaction() {
        if (!$this->dbh) {
            return false;
        }

        if ($this->inTransaction) {
            $this->log('warning', 'beginTransaction called while already in transaction');
            return false;
        }

        $this->inTransaction = $this->dbh->beginTransaction();
        return $this->inTransaction;
    }

    /**
     * Commit the current transaction
     *
     * @return bool
     */
    public function commit() {
        if (!$this->dbh || !$this->inTransaction) {
            return false;
        }

        $result = $this->dbh->commit();
        $this->inTransaction = false;
        return $result;
    }

    /**
     * Rollback the current transaction
     *
     * @return bool
     */
    public function rollback() {
        if (!$this->dbh || !$this->inTransaction) {
            return false;
        }

        $result = $this->dbh->rollBack();
        $this->inTransaction = false;
        return $result;
    }

    /**
     * Execute a callback within a transaction
     *
     * Automatically commits on success, rolls back on exception.
     *
     * @param callable $callback function(DatabaseHandler $db)
     * @return mixed The return value of the callback
     * @throws Exception Re-throws after rollback
     */
    public function transactional($callback) {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Check if currently inside a transaction
     *
     * @return bool
     */
    public function isInTransaction() {
        return $this->inTransaction;
    }

    // ───────────────────────────────────────────────────────────
    //  Improved Query Methods (shorthand)
    // ───────────────────────────────────────────────────────────

    /**
     * Execute query and return all rows
     *
     * Shorthand for: $db->query(...); return $db->get_rows();
     *
     * @param string $sql SQL query with ? placeholders
     * @param mixed ...$params Bound parameter values
     * @return array|false
     */
    public function fetchAll($sql) {
        $args = func_get_args();
        if (!call_user_func_array(array($this, 'query'), $args)) {
            return false;
        }
        return $this->get_rows();
    }

    /**
     * Execute query and return all rows keyed by a column
     *
     * Shorthand for: $db->query(...); return $db->get_rows(true, $column);
     *
     * @param string $keyColumn Column name to use as array key
     * @param string $sql SQL query with ? placeholders
     * @param mixed ...$params Bound parameter values
     * @return array|false
     */
    public function fetchAllKeyed($keyColumn, $sql) {
        $args = func_get_args();
        array_shift($args); // remove $keyColumn
        if (!call_user_func_array(array($this, 'query'), $args)) {
            return false;
        }
        return $this->get_rows(true, $keyColumn);
    }

    /**
     * Execute query and return single row
     *
     * Shorthand for: $db->query(...); return $db->get_row();
     *
     * @param string $sql SQL query with ? placeholders
     * @param mixed ...$params Bound parameter values
     * @return array|false
     */
    public function fetchOne($sql) {
        $args = func_get_args();
        if (!call_user_func_array(array($this, 'query'), $args)) {
            return false;
        }
        return $this->get_row();
    }

    /**
     * Execute query and return a single scalar value
     *
     * Shorthand for: $db->query(...); return $db->get_col();
     *
     * @param string $sql SQL query with ? placeholders
     * @param mixed ...$params Bound parameter values
     * @return mixed|false
     */
    public function fetchValue($sql) {
        $args = func_get_args();
        if (!call_user_func_array(array($this, 'query'), $args)) {
            return false;
        }
        return $this->get_col();
    }

    /**
     * Execute query and return a single column as flat array
     *
     * Shorthand for: $db->query(...); return $db->get_column();
     *
     * @param string $sql SQL query with ? placeholders
     * @param mixed ...$params Bound parameter values
     * @return array|false
     */
    public function fetchColumn($sql) {
        $args = func_get_args();
        if (!call_user_func_array(array($this, 'query'), $args)) {
            return false;
        }
        return $this->get_column();
    }

    // ───────────────────────────────────────────────────────────
    //  Query Override with Timing and Reconnect
    // ───────────────────────────────────────────────────────────

    /**
     * Execute a prepared query with automatic reconnection and timing
     *
     * Overrides Database::query() to add:
     * - Query counting
     * - Execution time tracking
     * - Automatic reconnection on "MySQL server has gone away"
     *
     * @param string $query SQL with ? placeholders
     * @param bool $buffered Use unbuffered query
     * @return bool
     */
    public function query($query, $buffered = false) {
        $start = microtime(true);
        $this->queryCount++;

        $result = call_user_func_array('parent::query', func_get_args());

        // If query failed — try reconnection (only if not in transaction)
        if ($result === false && !$this->inTransaction && $this->shouldReconnect()) {
            $this->log('warning', 'Query failed, attempting reconnect', ['query' => $query]);

            if ($this->reconnect()) {
                $result = call_user_func_array('parent::query', func_get_args());
            }
        }

        $elapsed = microtime(true) - $start;
        $this->queryTime += $elapsed;

        return $result;
    }

    // ───────────────────────────────────────────────────────────
    //  Connection Management
    // ───────────────────────────────────────────────────────────

    /**
     * Attempt to reconnect to the database
     *
     * @return bool
     */
    public function reconnect() {
        $attempts = 0;

        while ($attempts < $this->maxReconnectAttempts) {
            $attempts++;
            $this->log('info', "Reconnect attempt {$attempts}/{$this->maxReconnectAttempts}");

            $this->close_mysql();

            if ($this->db_connect(true)) {
                $this->log('info', 'Reconnected successfully');
                return true;
            }

            // Exponential backoff: 100ms, 200ms, 400ms
            usleep(100000 * pow(2, $attempts - 1));
        }

        $this->log('error', 'Failed to reconnect after ' . $this->maxReconnectAttempts . ' attempts');
        return false;
    }

    /**
     * Check if a reconnection should be attempted
     *
     * @return bool
     */
    protected function shouldReconnect() {
        if (!$this->dbh) {
            return true;
        }

        return !$this->ping();
    }

    // ───────────────────────────────────────────────────────────
    //  Diagnostics
    // ───────────────────────────────────────────────────────────

    /**
     * Get total number of queries executed
     *
     * @return int
     */
    public function getQueryCount() {
        return $this->queryCount;
    }

    /**
     * Get total query execution time in seconds
     *
     * @return float
     */
    public function getQueryTime() {
        return $this->queryTime;
    }

    /**
     * Get diagnostic summary
     *
     * @return array
     */
    public function getDiagnostics() {
        return [
            'connected'   => $this->connected,
            'queryCount'  => $this->queryCount,
            'queryTime'   => round($this->queryTime, 4),
            'inTransaction' => $this->inTransaction,
            'database'    => $this->dbname,
            'host'        => $this->dbhost,
            'port'        => $this->dbport,
        ];
    }

    // ───────────────────────────────────────────────────────────
    //  Bulk Operations
    // ───────────────────────────────────────────────────────────

    /**
     * Insert a row from an associative array
     *
     * @param string $table Table name
     * @param array $data Associative array of column => value
     * @return int|false Last insert ID on success, false on failure
     */
    public function insert($table, array $data) {
        if (empty($data)) {
            return false;
        }

        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`, `', $columns),
            implode(', ', $placeholders)
        );

        $args = array_merge([$sql], array_values($data));

        if (call_user_func_array([$this, 'query'], $args)) {
            return $this->last_insert_id();
        }

        return false;
    }

    /**
     * Update rows from an associative array
     *
     * @param string $table Table name
     * @param array $data Associative array of column => value
     * @param string $where WHERE clause (with ? placeholders)
     * @param mixed ...$whereParams Values for WHERE placeholders
     * @return bool
     */
    public function update($table, array $data, $where) {
        if (empty($data)) {
            return false;
        }

        $setParts = [];
        $values = [];

        foreach ($data as $column => $value) {
            $setParts[] = "`{$column}` = ?";
            $values[] = $value;
        }

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $table,
            implode(', ', $setParts),
            $where
        );

        // Add WHERE params from extra func_get_args
        $allArgs = func_get_args();
        $whereParams = array_slice($allArgs, 3);
        $values = array_merge($values, $whereParams);

        $args = array_merge([$sql], $values);

        return call_user_func_array([$this, 'query'], $args);
    }

    /**
     * Delete rows with a WHERE clause
     *
     * @param string $table Table name
     * @param string $where WHERE clause with ? placeholders
     * @param mixed ...$params Values for WHERE placeholders
     * @return bool
     */
    public function delete($table, $where) {
        $sql = sprintf('DELETE FROM `%s` WHERE %s', $table, $where);

        $allArgs = func_get_args();
        $whereParams = array_slice($allArgs, 2);
        $args = array_merge([$sql], $whereParams);

        return call_user_func_array([$this, 'query'], $args);
    }

    // ───────────────────────────────────────────────────────────
    //  Internal Logging
    // ───────────────────────────────────────────────────────────

    /**
     * Log a message through the configured logger
     *
     * @param string $level 'info', 'warning', 'error'
     * @param string $message Log message
     * @param array $context Additional context
     */
    protected function log($level, $message, array $context = []) {
        if ($this->logger) {
            call_user_func($this->logger, $level, '[DatabaseHandler] ' . $message, $context);
        }
    }
}
