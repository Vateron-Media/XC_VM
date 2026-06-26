<?php

namespace XcVm\Module\Watch;

use XcVm\Cli\CommandInterface;

/**
 * WatchItemCommand — watch item command
 *
 * @package XC_VM_Module_Watch
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class WatchItemCommand implements CommandInterface {

	public function getName(): string {
		return 'watch_item';
	}

	public function getDescription(): string {
		return 'Process single Watch item (TMDB search/update)';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] != 'xc_vm') {
			echo "Please run as XC_VM!\n";
			return 1;
		}

		if (empty($rArgs[0])) {
			echo "watch_item: empty payload\n";
			return 0;
		}

		setlocale(LC_ALL, 'en_US.UTF-8');
		putenv('LC_ALL=en_US.UTF-8');

		$rTimeout = 60;
		set_time_limit($rTimeout);
		ini_set('max_execution_time', $rTimeout);

		register_shutdown_function(function () {
			global $db;
			global $rShowData;
			if (is_array($rShowData) && $rShowData['id'] && file_exists(WATCH_TMP_PATH . 'lock_' . intval($rShowData['id']))) {
				unlink(WATCH_TMP_PATH . 'lock_' . intval($rShowData['id']));
			}
			if (is_object($db)) {
				$db->close_mysql();
			}
			@unlink(WATCH_TMP_PATH . @getmypid() . '.wpid');
		});
		require_once MAIN_HOME . 'Modules/tmdb/lib/TmdbClient.php';
		require MAIN_HOME . 'Modules/tmdb/lib/Release.php';

		$rPayload = trim((string) $rArgs[0]);
		$rDecodedPayload = base64_decode($rPayload, true);
		if ($rDecodedPayload === false) {
			echo "watch_item: invalid payload base64\n";
			return 0;
		}

		$rThreadData = json_decode($rDecodedPayload, true);
		if (!is_array($rThreadData)) {
			echo "watch_item: payload must be JSON object\n";
			return 0;
		}

		file_put_contents(WATCH_TMP_PATH . getmypid() . '.wpid', time());
		WatchItem::run($rThreadData, $rTimeout);

		return 0;
	}
}
