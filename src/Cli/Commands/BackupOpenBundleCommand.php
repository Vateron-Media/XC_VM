<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Domain\Backup\RecoveryBundle;

/**
 * backup:open-bundle <bundle> <directory> — open a recovery bundle
 * (Domain\Backup\RecoveryBundle) into a new directory: config.ini,
 * modules.php and, when it holds one, the cluster key
 * export (for `cluster:import-keys`, with the same passphrase). The passphrase
 * is read from standard input, so it is in no process list or shell history:
 *
 *   console.php backup:open-bundle recovery_2026-10-07.bundle /root/restore < passphrase.txt
 *
 * @package XC_VM_Cli_Commands
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class BackupOpenBundleCommand implements CommandInterface {
	public function getName(): string {
		return 'backup:open-bundle';
	}

	public function getDescription(): string {
		return 'Open a recovery bundle into a directory (passphrase on standard input)';
	}

	public function execute(array $rArgs): int {
		[$rBundle, $rDir] = [(string) ($rArgs[0] ?? ''), rtrim((string) ($rArgs[1] ?? ''), '/')];
		if ($rBundle === '' || $rDir === '' || !is_readable($rBundle)) {
			echo "Usage: console.php backup:open-bundle <bundle> <new directory>  (passphrase on standard input)\n";
			return 1;
		}
		if (file_exists($rDir)) {
			echo "{$rDir} exists; choose a new directory.\n";
			return 1;
		}
		$rPass = trim((string) stream_get_contents(STDIN));
		$rOpened = RecoveryBundle::open((string) file_get_contents($rBundle), $rPass);
		if ($rOpened === null) {
			echo "Not a recovery bundle, or the wrong passphrase.\n";
			return 1;
		}
		if (!mkdir($rDir, 0700, true)) {
			echo "Cannot create {$rDir}.\n";
			return 1;
		}
		foreach ($rOpened['files'] as $rName => $rContent) {
			$rFile = $rDir . '/' . basename($rName);
			file_put_contents($rFile, $rContent);
			chmod($rFile, 0600);
			echo "  {$rFile}\n";
		}
		echo 'Made ' . date('Y-m-d H:i', $rOpened['made']) . ". Restore the newest database backup, then put these files back in /home/xc_vm/config/.\n";
		return 0;
	}
}
