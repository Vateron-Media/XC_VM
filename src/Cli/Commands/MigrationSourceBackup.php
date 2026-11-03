<?php

declare(strict_types=1);

namespace XcVm\Cli\Commands;

use XcVm\Core\Database\DatabaseHandler;
use XcVm\Core\Events\Migration\LegacyTableMigrationEvent;

/** A complete recovery copy before non-transactional source cleanup. */
final class MigrationSourceBackup {
	/** @param array<string, array{rows: int, sha256: string}> $tables */
	private function __construct(public readonly string $directory, private array $tables) {
	}

	public static function create(DatabaseHandler $source, MigrationGuard $guard, string $root): self {
		if (!is_dir($root) && !@mkdir($root, 0750, true) && !is_dir($root)) {
			throw new \RuntimeException('Cannot create the migration recovery directory.');
		}
		$guard->query($source, 'SELECT COALESCE(SUM(`data_length` + `index_length`), 0) AS `bytes` FROM `information_schema`.`tables` WHERE `table_schema` = DATABASE();');
		$bytes = (int) $source->get_col();
		$available = disk_free_space($root);
		if ($available === false || $available < $bytes * 2 + 64 * 1024 * 1024) {
			throw new \RuntimeException('Not enough space for a complete migration recovery copy; the source is kept.');
		}
		$directory = rtrim($root, '/') . '/migration-source-' . date('Ymd-His') . '-' . bin2hex(random_bytes(6));
		if (!mkdir($directory, 0700)) {
			throw new \RuntimeException('Cannot create ' . $directory);
		}
		try {
			$guard->query($source, 'SHOW FULL TABLES;');
			$rows = $source->get_rows();
			$format = LegacyTableMigrationEvent::formatOf($source);
			$tables = [];
			foreach ($rows as $row) {
				[$table, $type] = array_values($row);
				if ($type !== 'BASE TABLE') {
					throw new \RuntimeException('Source views need manual recovery preservation; the source is kept.');
				}
				$event = LegacyTableMigrationEvent::dump($source, $table, $format, $directory . '/' . $table . '.sql', true);
				if ($event === null) {
					throw new \RuntimeException('A source table disappeared while making its recovery copy.');
				}
				$hash = hash_file('sha256', $directory . '/' . $table . '.sql');
				if ($hash === false) {
					throw new \RuntimeException('Cannot verify the source recovery copy.');
				}
				$tables[$table] = ['rows' => $event->exportedRows, 'sha256' => $hash];
			}
			$manifest = fopen($directory . '/manifest.json', 'w');
			if ($manifest === false) {
				throw new \RuntimeException('Cannot write the migration recovery manifest.');
			}
			try {
				chmod($directory . '/manifest.json', 0600);
				$json = json_encode($tables, JSON_THROW_ON_ERROR);
				if (fwrite($manifest, $json) !== strlen($json) || !fflush($manifest) || !fsync($manifest)) {
					throw new \RuntimeException('Cannot flush the migration recovery manifest.');
				}
			} finally {
				fclose($manifest);
			}
		} catch (\Throwable $error) {
			// No source table has been dropped yet. Remove only this incomplete copy.
			foreach (glob($directory . '/*') ?: [] as $file) {
				@unlink($file);
			}
			@rmdir($directory);
			throw $error;
		}
		return new self($directory, $tables);
	}

	/** Tables which survived a failed DROP are still the unchanged source. */
	public function restoreMissing(DatabaseHandler $source, MigrationGuard $guard): void {
		foreach ($this->tables as $table => $meta) {
			if (hash_file('sha256', $this->directory . '/' . $table . '.sql') !== $meta['sha256']) {
				throw new \RuntimeException('The migration recovery copy is incomplete: ' . $this->directory);
			}
		}
		$guard->query($source, 'SET FOREIGN_KEY_CHECKS = 0;');
		try {
			foreach ($this->tables as $table => $meta) {
				$guard->query($source, 'SELECT `table_name` FROM `information_schema`.`tables` WHERE `table_schema` = DATABASE() AND BINARY `table_name` = ?;', $table);
				if ($source->num_rows() === 0) {
					$file = fopen($this->directory . '/' . $table . '.sql', 'r');
					if ($file === false) {
						throw new \RuntimeException('Cannot open a migration recovery file.');
					}
					try {
						while (($sql = fgets($file)) !== false) {
							$sql = trim($sql);
							if ($sql !== '' && !str_starts_with($sql, '--')) {
								$guard->query($source, $sql);
							}
						}
					} finally {
						fclose($file);
					}
				}
				$guard->query($source, 'SELECT COUNT(*) FROM `' . $table . '`;');
				if ((int) $source->get_col() !== $meta['rows']) {
					throw new \RuntimeException('Source recovery needs inspection: ' . $this->directory);
				}
			}
		} finally {
			$guard->query($source, 'SET FOREIGN_KEY_CHECKS = 1;');
		}
	}

	public function discard(): void {
		foreach (array_keys($this->tables) as $table) {
			@unlink($this->directory . '/' . $table . '.sql');
		}
		@unlink($this->directory . '/manifest.json');
		@rmdir($this->directory);
	}
}
