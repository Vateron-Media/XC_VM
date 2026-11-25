<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Http\RequestManager;
use XcVm\Core\Module\ModuleBackup;
use XcVm\Infrastructure\Database\DatabaseFactory;

/** Restore, delete and download a server-listed module backup. */
final class ModuleBackupAjaxController extends BaseAjaxController {
	public function restore(): never {
		$this->writeGuard();
		try {
			$this->backups()->restore((string) RequestManager::get('id'));
			$this->ok();
		} catch (\Throwable $error) {
			$this->fail(['message' => $error->getMessage()]);
		}
	}

	public function delete(): never {
		$this->writeGuard();
		try {
			$this->backups()->delete((string) RequestManager::get('id'));
			$this->ok();
		} catch (\Throwable $error) {
			$this->fail(['message' => $error->getMessage()]);
		}
	}

	public function download(): never {
		$this->gate('adv', 'database');
		try {
			$id = (string) RequestManager::get('id');
			$backup = $this->backups();
			$meta = $backup->metadata($id);
			$file = $backup->downloadPath($id);
			$name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $meta['module_name']);
			header('Content-Type: application/sql');
			header('Content-Disposition: attachment; filename="' . $name . '_' . $id . '.sql"');
			header('Content-Length: ' . filesize($file));
			readfile($file);
			exit;
		} catch (\Throwable) {
			http_response_code(404);
			exit;
		}
	}

	private function writeGuard(): void {
		$this->requireXhr();
		$this->gate('adv', 'database');
		if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
			$this->fail(['message' => 'POST required.']);
		}
	}

	private function backups(): ModuleBackup {
		$db = DatabaseFactory::get();
		if ($db === null) {
			throw new \RuntimeException('Database unavailable.');
		}
		return new ModuleBackup($db, MAIN_HOME . 'Modules');
	}
}
