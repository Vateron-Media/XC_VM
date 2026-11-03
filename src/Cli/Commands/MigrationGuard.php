<?php

declare(strict_types=1);

namespace XcVm\Cli\Commands;

use XcVm\Core\Database\DatabaseHandler;
use XcVm\Core\Database\QueryHelper;

/** Keep an import's source when a query or a caught conversion fails. */
final class MigrationGuard {
	private bool $failed = false;

	/** @var \WeakMap<DatabaseHandler, array<string, array>> */
	private \WeakMap $columns;

	public function __construct() {
		$this->columns = new \WeakMap();
	}

	/** The target schema is fixed during one import; read each table once. */
	public function prepareRow(DatabaseHandler $db, string $table, array $data): array {
		$tables = $this->columns[$db] ?? [];
		if (!array_key_exists($table, $tables)) {
			$this->query($db, 'SELECT `column_name`, `column_default`, `is_nullable`, `data_type` FROM `information_schema`.`columns` WHERE `table_schema` = (SELECT DATABASE()) AND `table_name` = ? ORDER BY `ordinal_position`;', $table);
			$columns = $db->get_rows();
			if (!is_array($columns) || $columns === []) {
				$this->fail();
				throw new \RuntimeException('The migration target table has no columns: ' . $table);
			}
			$tables[$table] = $columns;
			$this->columns[$db] = $tables;
		}
		return QueryHelper::verifyPostColumns($tables[$table], $data);
	}

	public function fail(): void {
		$this->failed = true;
	}

	public function hasFailed(): bool {
		return $this->failed;
	}

	public function query(DatabaseHandler $db, string $sql, mixed ...$args): bool {
		try {
			if ($db->query($sql, ...$args) === false) {
				throw new \RuntimeException('A migration database query failed.');
			}
		} catch (\Throwable $error) {
			$this->fail();
			throw $error;
		}
		return true;
	}
}
