<?php

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

		require_once INCLUDES_PATH . 'libs/tmdb.php';
		require INCLUDES_PATH . 'libs/tmdb_release.php';

		$rThreadData = json_decode(base64_decode($rArgs[0]), true);
		if (!$rThreadData) {
			return 0;
		}

		file_put_contents(WATCH_TMP_PATH . getmypid() . '.wpid', time());
		WatchItem::run();

		return 0;
	}
}
