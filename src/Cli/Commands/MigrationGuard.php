<?php

namespace XcVm\Cli\Commands;

use XcVm\Core\Database\DatabaseHandler;

/** Keep an import's source when a query or a caught conversion fails. */
final class MigrationGuard {
	private bool $failed = false;

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
