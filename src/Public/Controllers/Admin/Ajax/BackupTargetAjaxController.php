<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Http\RequestManager;
use XcVm\Core\Process\ProcessRunner;
use XcVm\Domain\Backup\BackupTargets;
use XcVm\Domain\Backup\RecoveryBundle;

/**
 * The Backups page's off-site actions (Views/admin/backups_offsite.php): save,
 * delete and test a backup target, set the recovery bundle's passphrase, start
 * the restore test. Each asks for the `database` permission (the Backups page's)
 * and a POST.
 *
 * @package XC_VM_Public_Controllers_Admin_Ajax
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class BackupTargetAjaxController extends BaseAjaxController {
	/** action=backup_target_save */
	public function save(): never {
		$this->guard();
		$this->json(BackupTargets::save(RequestManager::getAll()));
	}

	/** action=backup_target_delete */
	public function delete(): never {
		$this->guard();
		$this->json(['result' => BackupTargets::delete((int) RequestManager::get('id'))]);
	}

	/** action=backup_target_test: write, list and delete a small file there. */
	public function test(): never {
		$this->guard();
		$rTarget = BackupTargets::find((int) RequestManager::get('id'));
		$rError = $rTarget === null ? 'no such target' : BackupTargets::test($rTarget);
		$this->json($rError === null ? ['result' => true] : ['result' => false, 'error' => $rError]);
	}

	/** action=backup_bundle_passphrase: set it (20+ characters) or, empty, clear it. */
	public function passphrase(): never {
		$this->guard();
		$this->json(['result' => RecoveryBundle::setPassphrase((string) RequestManager::get('passphrase'))]);
	}

	/** action=backup_verify_now: cron:backup_verify in the background (a restore outlasts a request). */
	public function verifyNow(): never {
		$this->guard();
		$this->json(['result' => ProcessRunner::start([PHP_BIN, MAIN_HOME . 'console.php', 'cron:backup_verify'])]);
	}

	private function guard(): void {
		$this->requireXhr();
		$this->gate('adv', 'database');
		if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
			$this->fail(['error' => 'method']);
		}
	}
}
