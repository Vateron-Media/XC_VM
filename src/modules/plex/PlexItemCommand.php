<?php

/**
 * PlexItemCommand — plex item command
 *
 * @package XC_VM_Module_Plex
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class PlexItemCommand implements CommandInterface {

	public function getName(): string {
		return 'plex_item';
	}

	public function getDescription(): string {
		return 'Process single Plex item (movie/series)';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] != 'xc_vm') {
			echo "Please run as XC_VM!\n";
			return 1;
		}

		if (empty($rArgs[0])) {
			return 0;
		}

		setlocale(LC_ALL, 'en_US.UTF-8');
		putenv('LC_ALL=en_US.UTF-8');

		register_shutdown_function(function () {
			global $db;
			if (is_object($db)) {
				$db->close_mysql();
			}
			@unlink(WATCH_TMP_PATH . @getmypid() . '.ppid');
		});

		ini_set('display_errors', 1);
		ini_set('display_startup_errors', 1);
		error_reporting(30711);

		$rStreamDatabase = (json_decode(file_get_contents(WATCH_TMP_PATH . 'stream_database.pcache'), true) ?: array());
		$rThreadData = json_decode(base64_decode($rArgs[0]), true);

		if (!$rThreadData) {
			return 0;
		}

		file_put_contents(WATCH_TMP_PATH . getmypid() . '.ppid', time());

		if ($rThreadData['type'] == 'movie') {
			$rTimeout = 60;
		} else {
			$rTimeout = 600;
		}

		set_time_limit($rTimeout);
		ini_set('max_execution_time', $rTimeout);
		PlexItem::run();

		return 0;
	}
}
