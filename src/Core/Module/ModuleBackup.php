<?php

namespace XcVm\Core\Module;

/** Persistent, table-scoped module database backups. */
final class ModuleBackup {
	private const ID = '/^\d{14}-[a-f0-9]{16}$/D';

	private string $directory;

	public function __construct(private object $db, string $modulesPath) {
		$this->directory = dirname(rtrim($modulesPath, '/')) . '/module_backups';
	}

	/**
	 * Back up the module's currently existing owned tables.
	 *
	 * @param array{name:string,title:string,hash_id:string,version:string} $module
	 * @return array<string,mixed>|null null when the module owns no existing table
	 */
	public function create(string $modulePath, array $module, string $kind = 'uninstall'): ?array {
		$tables = array_values(array_filter(
			ModuleMigrator::ownedTables($modulePath),
			fn(string $table): bool => $this->tableExists($table)
		));
		return $tables === [] ? null : $this->createTables($tables, $module, $kind);
	}

	/** @return list<array<string,mixed>> */
	public function list(): array {
		$out = [];
		if (!is_dir($this->directory) || is_link($this->directory)) {
			return $out;
		}
		foreach (glob($this->directory . '/*.json') ?: [] as $file) {
			$id = basename($file, '.json');
			try {
				$entry = $this->load($id, false);
				$entry['verified'] = $this->verified($entry);
				unset($entry['_path']);
				$out[] = $entry;
			} catch (\Throwable) {
				// Invalid files are never exposed as selectable backup identifiers.
			}
		}
		usort($out, static fn(array $a, array $b): int => (int) $b['created_at'] <=> (int) $a['created_at']);
		return $out;
	}

	/** @return array<string,mixed> */
	public function metadata(string $id): array {
		$entry = $this->load($id, false);
		$entry['verified'] = $this->verified($entry);
		unset($entry['_path']);
		return $entry;
	}

	public function downloadPath(string $id): string {
		return (string) $this->load($id, true)['_path'];
	}

	/** Restore only the tables named by the verified backup. */
	public function restore(string $id): void {
		$entry = $this->load($id, true);
		$creates = $this->validatedStatements($entry['_path'], $entry['tables']);
		$created = [];
		if (!$this->db->query('SET FOREIGN_KEY_CHECKS = 0;')) {
			throw new \RuntimeException('Cannot prepare the module backup restore.');
		}
		try {
			foreach ($entry['tables'] as $table) {
				if (!$this->tableExists($table)) {
					if (!$this->db->query($creates[$table])) {
						throw new \RuntimeException("Cannot recreate {$table} for restore.");
					}
					$created[] = $table;
				}
			}
			if (!$this->db->query('SET autocommit = 0;')) {
				throw new \RuntimeException('Cannot start the module backup restore.');
			}
			$lock = 'LOCK TABLES ' . implode(', ', array_map(fn(string $table): string => $this->quoted($table) . ' WRITE', $entry['tables'])) . ';';
			if (!$this->db->query($lock)) {
				throw new \RuntimeException('Cannot lock the module tables for restore.');
			}
			$this->assertTransactional($entry['tables']);
			$this->createTables($entry['tables'], [
				'name' => (string) $entry['module_name'],
				'title' => (string) $entry['module_title'],
				'hash_id' => (string) $entry['hash_id'],
				'version' => (string) $entry['version'],
			], 'pre_restore', false);
			foreach ($entry['tables'] as $table) {
				if (!$this->db->query('DELETE FROM ' . $this->quoted($table) . ';')) {
					throw new \RuntimeException("Cannot clear {$table} for restore.");
				}
			}
			$this->restoreRows($entry['_path']);
			if (!$this->db->query('COMMIT;')) {
				throw new \RuntimeException('Cannot commit the module backup restore.');
			}
			$created = [];
		} catch (\Throwable $error) {
			$this->db->query('ROLLBACK;');
			throw $error;
		} finally {
			$this->db->query('UNLOCK TABLES;');
			$this->db->query('SET autocommit = 1;');
			foreach ($created as $table) {
				$this->db->query('DROP TABLE IF EXISTS ' . $this->quoted($table) . ';');
			}
			$this->db->query('SET FOREIGN_KEY_CHECKS = 1;');
		}
	}

	public function delete(string $id): void {
		$entry = $this->load($id, false);
		if (!@unlink($entry['_path']) || !@unlink($this->directory . '/' . $id . '.json')) {
			throw new \RuntimeException('Cannot delete the module backup.');
		}
	}

	/**
	 * @param string[] $tables
	 * @param array{name:string,title:string,hash_id:string,version:string} $module
	 * @return array<string,mixed>
	 */
	private function createTables(array $tables, array $module, string $kind, bool $lockTables = true): array {
		$this->ensureDirectory();
		$id = date('YmdHis') . '-' . bin2hex(random_bytes(8));
		$sql = $this->directory . '/' . $id . '.sql';
		$meta = $this->directory . '/' . $id . '.json';
		$sqlTemp = $sql . '.tmp';
		$metaTemp = $meta . '.tmp';
		$out = @fopen($sqlTemp, 'xb');
		if ($out === false) {
			throw new \RuntimeException('Cannot create the module backup.');
		}
		if (!$this->restrict($sqlTemp, 0600)) {
			fclose($out);
			@unlink($sqlTemp);
			throw new \RuntimeException('Cannot restrict the module backup file.');
		}
		$lock = 'LOCK TABLES ' . implode(', ', array_map(fn(string $table): string => $this->quoted($table) . ' READ', $tables)) . ';';
		if ($lockTables && !$this->db->query($lock)) {
			fclose($out);
			@unlink($sqlTemp);
			throw new \RuntimeException('Cannot lock the module tables for backup.');
		}
		$unlocked = !$lockTables;
		try {
			$this->write($out, "-- XC_VM module backup v1\n");
			foreach ($tables as $table) {
				$this->dumpTable($out, $table);
			}
			if (!fflush($out) || !fsync($out)) {
				throw new \RuntimeException('Cannot flush the module backup.');
			}
		} catch (\Throwable $error) {
			@unlink($sqlTemp);
			throw $error;
		} finally {
			fclose($out);
			if ($lockTables) {
				$unlocked = $this->db->query('UNLOCK TABLES;');
			}
		}
		if (!$unlocked) {
			@unlink($sqlTemp);
			throw new \RuntimeException('Cannot unlock the module tables after backup.');
		}

		$checksum = hash_file('sha256', $sqlTemp);
		$size = filesize($sqlTemp);
		if ($checksum === false || $size === false || $size === 0) {
			@unlink($sqlTemp);
			throw new \RuntimeException('Cannot verify the module backup.');
		}
		$entry = [
			'id' => $id,
			'kind' => $kind,
			'module_name' => $module['name'],
			'module_title' => $module['title'],
			'hash_id' => $module['hash_id'],
			'version' => $module['version'],
			'created_at' => time(),
			'tables' => array_values($tables),
			'size' => $size,
			'checksum' => $checksum,
		];
		try {
			$json = json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		} catch (\Throwable $error) {
			@unlink($sqlTemp);
			throw new \RuntimeException('Cannot encode the module backup metadata.', 0, $error);
		}
		$manifest = @fopen($metaTemp, 'xb');
		if ($manifest === false) {
			@unlink($sqlTemp);
			throw new \RuntimeException('Cannot create the module backup metadata.');
		}
		if (!$this->restrict($metaTemp, 0600)) {
			fclose($manifest);
			@unlink($sqlTemp);
			@unlink($metaTemp);
			throw new \RuntimeException('Cannot restrict the module backup metadata.');
		}
		try {
			$this->write($manifest, $json . "\n");
			if (!fflush($manifest) || !fsync($manifest)) {
				throw new \RuntimeException('Cannot flush the module backup metadata.');
			}
		} catch (\Throwable $error) {
			@unlink($sqlTemp);
			@unlink($metaTemp);
			throw $error;
		} finally {
			fclose($manifest);
		}
		if (!@rename($sqlTemp, $sql) || !@rename($metaTemp, $meta)) {
			@unlink($sqlTemp);
			@unlink($metaTemp);
			@unlink($sql);
			throw new \RuntimeException('Cannot publish the module backup.');
		}
		if (!$this->restrict($sql, 0600) || !$this->restrict($meta, 0600)) {
			@unlink($sql);
			@unlink($meta);
			throw new \RuntimeException('Cannot restrict the published module backup.');
		}
		return $entry;
	}

	/** @param resource $out */
	private function dumpTable($out, string $table): void {
		$name = $this->quoted($table);
		if (!$this->db->query('SHOW CREATE TABLE ' . $name . ';')) {
			throw new \RuntimeException("Cannot read the schema of {$table}.");
		}
		$row = $this->db->get_raw_row();
		$create = is_array($row) ? (string) (array_values($row)[1] ?? '') : '';
		if ($create === '') {
			throw new \RuntimeException("Cannot read the schema of {$table}.");
		}
		$this->write($out, 'DROP TABLE IF EXISTS ' . $name . ";\n");
		$this->write($out, str_replace(["\r", "\n"], ' ', $create) . ";\n");
		if (!$this->db->query("SELECT `column_name` FROM `information_schema`.`columns` WHERE `table_schema` = DATABASE() AND BINARY `table_name` = ? AND COALESCE(`generation_expression`, '') = '' ORDER BY `ordinal_position`;", $table)) {
			throw new \RuntimeException("Cannot read the columns of {$table}.");
		}
		$columns = array_column($this->db->get_raw_rows(), 'column_name');
		if ($columns === [] || array_filter($columns, static fn(mixed $column): bool => !is_string($column) || str_contains($column, "\r") || str_contains($column, "\n"))) {
			throw new \RuntimeException("Cannot read the columns of {$table}.");
		}
		$quotedColumns = implode(', ', array_map(fn(string $column): string => $this->quoted($column), $columns));
		if (!$this->db->query('SELECT ' . $quotedColumns . ' FROM ' . $name . ';', true)) {
			throw new \RuntimeException("Cannot read {$table}.");
		}
		while (($record = $this->db->fetch_raw_row()) !== null) {
			$values = implode(', ', array_map(fn(string $column): string => $this->value($record[$column] ?? null), $columns));
			$this->write($out, 'INSERT INTO ' . $name . ' (' . $quotedColumns . ') VALUES (' . $values . ");\n");
		}
	}

	private function tableExists(string $table): bool {
		if (!$this->db->query('SELECT `table_name` FROM `information_schema`.`tables` WHERE `table_schema` = DATABASE() AND BINARY `table_name` = ?;', $table)) {
			throw new \RuntimeException('Cannot determine the module tables.');
		}
		return $this->db->num_rows() > 0;
	}

	/** @return array<string,mixed> */
	private function load(string $id, bool $verify): array {
		if (!preg_match(self::ID, $id) || !is_dir($this->directory) || is_link($this->directory)) {
			throw new \RuntimeException('Invalid module backup identifier.');
		}
		$sql = $this->directory . '/' . $id . '.sql';
		$meta = $this->directory . '/' . $id . '.json';
		if (
			is_link($sql) || is_link($meta) || !is_file($sql) || !is_file($meta)
			|| dirname((string) realpath($sql)) !== (string) realpath($this->directory)
			|| dirname((string) realpath($meta)) !== (string) realpath($this->directory)
		) {
			throw new \RuntimeException('Invalid module backup files.');
		}
		$entry = json_decode((string) file_get_contents($meta), true);
		$strings = ['kind', 'module_name', 'module_title', 'hash_id', 'version', 'checksum'];
		if (
			!is_array($entry) || ($entry['id'] ?? null) !== $id || !is_array($entry['tables'] ?? null) || $entry['tables'] === []
			|| array_filter($strings, static fn(string $key): bool => !is_string($entry[$key] ?? null))
			|| !is_int($entry['created_at'] ?? null) || !is_int($entry['size'] ?? null)
			|| !preg_match('/^[a-f0-9]{64}$/D', (string) ($entry['checksum'] ?? ''))
			|| array_filter($entry['tables'], static fn(mixed $table): bool => !is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/D', $table))
		) {
			throw new \RuntimeException('Invalid module backup metadata.');
		}
		$entry['_path'] = $sql;
		if ($verify && !$this->verified($entry)) {
			throw new \RuntimeException('Module backup checksum mismatch.');
		}
		return $entry;
	}

	/** @param string[] $tables @return array<string,string> */
	private function validatedStatements(string $file, array $tables): array {
		$allowed = array_fill_keys($tables, true);
		$seen = [];
		$creates = [];
		$in = fopen($file, 'rb');
		if ($in === false) {
			throw new \RuntimeException('Cannot open the module backup.');
		}
		try {
			while (($line = fgets($in)) !== false) {
				$line = trim($line);
				if ($line === '' || str_starts_with($line, '--')) {
					continue;
				}
				if (
					!preg_match('/^(?:DROP TABLE IF EXISTS|CREATE TABLE(?: IF NOT EXISTS)?|INSERT INTO) `([A-Za-z0-9_]+)`/i', $line, $match)
					|| !isset($allowed[$match[1]]) || !str_ends_with($line, ';')
				) {
					throw new \RuntimeException('Module backup contains an unexpected statement.');
				}
				$seen[$match[1]][strtoupper(strtok($line, ' '))] = true;
				if (str_starts_with(strtoupper($line), 'CREATE TABLE')) {
					$creates[$match[1]] = $line;
				}
			}
		} finally {
			fclose($in);
		}
		foreach ($tables as $table) {
			if (empty($seen[$table]['DROP']) || empty($seen[$table]['CREATE'])) {
				throw new \RuntimeException('Module backup is incomplete.');
			}
		}
		return $creates;
	}

	/** Execute the already validated data statements without retaining the dump in memory. */
	private function restoreRows(string $file): void {
		$in = fopen($file, 'rb');
		if ($in === false) {
			throw new \RuntimeException('Cannot open the module backup.');
		}
		try {
			while (($line = fgets($in)) !== false) {
				$line = trim($line);
				if (str_starts_with(strtoupper($line), 'INSERT INTO ') && !$this->db->query($line)) {
					throw new \RuntimeException('Cannot restore the module backup.');
				}
			}
		} finally {
			fclose($in);
		}
	}

	/** @param string[] $tables */
	private function assertTransactional(array $tables): void {
		foreach ($tables as $table) {
			if (!$this->db->query('SELECT `engine` FROM `information_schema`.`tables` WHERE `table_schema` = DATABASE() AND BINARY `table_name` = ?;', $table)) {
				throw new \RuntimeException('Cannot determine the module table engines.');
			}
			$row = $this->db->get_raw_row();
			if (strtoupper((string) ($row['engine'] ?? '')) !== 'INNODB') {
				throw new \RuntimeException("Cannot safely restore non-transactional table {$table}.");
			}
		}
	}

	/** @param array<string,mixed> $entry */
	private function verified(array $entry): bool {
		$size = filesize((string) $entry['_path']);
		$checksum = hash_file('sha256', (string) $entry['_path']);
		return $size !== false && $checksum !== false && $size === $entry['size'] && hash_equals($entry['checksum'], $checksum);
	}

	private function ensureDirectory(): void {
		if (
			is_link($this->directory)
			|| (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory))
		) {
			throw new \RuntimeException('Cannot create the module backup directory.');
		}
		if (!$this->restrict($this->directory, 0700)) {
			throw new \RuntimeException('Cannot restrict the module backup directory.');
		}
	}

	private function restrict(string $path, int $mode): bool {
		if (!@chmod($path, $mode)) {
			return false;
		}
		clearstatcache(true, $path);
		$permissions = @fileperms($path);
		return $permissions !== false && ($permissions & 0777) === $mode;
	}

	private function quoted(string $table): string {
		return '`' . str_replace('`', '``', $table) . '`';
	}

	private function value(mixed $value): string {
		if ($value === null) {
			return 'NULL';
		}
		$quoted = $this->db->escape((string) $value);
		if (!is_string($quoted)) {
			throw new \RuntimeException('Cannot quote a module table value.');
		}
		return $quoted;
	}

	/** @param resource $stream */
	private function write($stream, string $data): void {
		if (fwrite($stream, $data) !== strlen($data)) {
			throw new \RuntimeException('Cannot write the module backup.');
		}
	}
}
