<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Domain\Vod\VodItemImporter;

/**
 * VodImportBatchCommand — work through a Movies/Series import batch.
 *
 * Started once per import by VodItemImporter::queueBatch(); runs the batch's
 * files through `vod_import_item`, `thread_count` at a time.
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class VodImportBatchCommand implements CommandInterface {
	public function getName(): string {
		return 'vod_import_batch';
	}

	public function getDescription(): string {
		return 'Import a queued Movies/Series import batch, thread_count files at a time';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] != 'xc_vm') {
			echo "Please run as XC_VM!\n";
			return 1;
		}
		$rFile = self::batchFile((string) ($rArgs[0] ?? ''));
		if ($rFile === null) {
			echo "vod_import_batch: not an import batch file\n";
			return 1;
		}

		set_time_limit(0);
		// The .wpid file lets "Kill Running" stop the batch, not only its items.
		$rPidFile = WATCH_TMP_PATH . getmypid() . '.wpid';
		register_shutdown_function(static function () use ($rPidFile) {
			@unlink($rPidFile);
		});
		file_put_contents($rPidFile, time());

		VodItemImporter::runBatch($rFile);

		return 0;
	}

	/**
	 * The batch file, if the argument names one queueBatch() could have written.
	 *
	 * @param string $rArg
	 * @return string|null
	 */
	public static function batchFile(string $rArg) {
		$rPath = realpath($rArg);
		$rDir = realpath(WATCH_TMP_PATH);
		if ($rPath === false || $rDir === false || dirname($rPath) !== $rDir || !preg_match('/^import_[0-9a-f]{16}\.jsonl$/', basename($rPath))) {
			return null;
		}
		return $rPath;
	}
}
