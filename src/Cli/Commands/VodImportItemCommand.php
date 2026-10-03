<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Domain\Vod\VodItemImporter;

/**
 * VodImportItemCommand — import one movie/episode file (TMDb match + insert).
 *
 * Spawned once per file by the Movies/Series import and by the watch module's
 * folder scan. The payload is the base64-encoded JSON thread data.
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class VodImportItemCommand implements CommandInterface {
	public function getName(): string {
		return 'vod_import_item';
	}

	public function getDescription(): string {
		return 'Import a single movie/episode file (TMDB search/insert)';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] != 'xc_vm') {
			echo "Please run as XC_VM!\n";
			return 1;
		}

		$rThreadData = json_decode((string) base64_decode(trim((string) ($rArgs[0] ?? '')), true), true);
		if (!is_array($rThreadData)) {
			echo "vod_import_item: payload must be base64-encoded JSON object\n";
			return 0;
		}

		setlocale(LC_ALL, 'en_US.UTF-8');
		putenv('LC_ALL=en_US.UTF-8');

		$rTimeout = 60;
		set_time_limit($rTimeout);
		ini_set('max_execution_time', $rTimeout);

		// The .wpid file lets the watch module's "Kill Running" find this process.
		$rPidFile = WATCH_TMP_PATH . getmypid() . '.wpid';
		register_shutdown_function(static function () use ($rPidFile) {
			@unlink($rPidFile);
		});
		file_put_contents($rPidFile, time());

		VodItemImporter::run($rThreadData, $rTimeout);

		return 0;
	}
}
