<?php

namespace XcVm\Tests\Support;

use XcVm\Core\Database\DatabaseHandler;

/**
 * A database that records every statement and hands it to another one (a
 * TestDb): what a code path asked MySQL for, with the real answers. A
 * statement matching $rRefuse fails as MySQL's do in production (false).
 */
final class QueryLogDb extends DatabaseHandler {
	/** @var list<string> */
	public array $rQueries = [];

	/** A regex: statements it matches are refused (query() returns false). */
	public ?string $rRefuse = null;

	/**
	 * Runs before each statement with it and its arguments: to change the
	 * database in the middle of a code path, as another request would.
	 */
	public ?\Closure $rBefore = null;

	public function __construct(private DatabaseHandler $rInner) {
		$this->dbh = true;
	}

	public function query($query, ...$args): bool {
		if ($this->rBefore !== null) {
			($this->rBefore)((string) $query, $args);
		}
		$this->rQueries[] = (string) $query;
		if ($this->rRefuse !== null && preg_match($this->rRefuse, (string) $query)) {
			return false;
		}
		return $this->rInner->query($query, ...$args);
	}

	/** @return list<string> the statements that change something */
	public function writes(): array {
		return array_values(array_filter($this->rQueries, static fn(string $rQuery): bool => (bool) preg_match('/^\s*(INSERT|REPLACE|UPDATE|DELETE)\b/i', $rQuery)));
	}

	public function get_rows($use_id = false, $column_as_id = '', $unique_row = true, $sub_row_id = '') {
		return $this->rInner->get_rows($use_id, $column_as_id, $unique_row, $sub_row_id);
	}

	public function get_row() {
		return $this->rInner->get_row();
	}

	public function get_raw_rows(): array {
		return $this->rInner->get_raw_rows();
	}

	public function get_raw_row(): ?array {
		return $this->rInner->get_raw_row();
	}

	public function get_col() {
		return $this->rInner->get_col();
	}

	public function get_column() {
		return $this->rInner->get_column();
	}

	public function num_rows() {
		return $this->rInner->num_rows();
	}

	public function last_insert_id() {
		return $this->rInner->last_insert_id();
	}

	public function escape($string) {
		return $this->rInner->escape($string);
	}

	public function close_mysql() {
		return true;
	}

	public function ping(): bool {
		return true;
	}

	/** Transactions are the inner database's: what it rolls back, nothing kept. */
	public function beginTransaction() {
		return $this->rInner->beginTransaction();
	}

	public function commit() {
		return $this->rInner->commit();
	}

	public function rollback() {
		return $this->rInner->rollback();
	}

	public function isInTransaction() {
		return $this->rInner->isInTransaction();
	}
}
