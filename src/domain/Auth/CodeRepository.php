<?php

class CodeRepository {
	public static function getActiveCodes($rMainHome) {
		$rCodes = array();
		$rFiles = scandir($rMainHome . 'bin/nginx/conf/codes/');

		foreach ($rFiles as $rFile) {
			$rPathInfo = pathinfo($rFile);
			$rExt = $rPathInfo['extension'] ?? null;

			if ($rExt == 'conf' && $rPathInfo['filename'] != 'default') {
				$rCodes[] = $rPathInfo['filename'];
			}
		}

		return $rCodes;
	}

	public static function updateCodes($db, $rMainHome, $rServerId, $rGetCodesCallback, $rReloadNginxCallback) {
		$rTemplate = file_get_contents($rMainHome . 'bin/nginx/conf/codes/template');
		shell_exec('rm -f ' . $rMainHome . 'bin/nginx/conf/codes/*.conf');

		foreach (call_user_func($rGetCodesCallback) as $rCode) {
			if ($rCode['enabled']) {
				$rWhitelist = array();

				foreach (json_decode($rCode['whitelist'], true) as $rIP) {
					if (filter_var($rIP, FILTER_VALIDATE_IP)) {
						$rWhitelist[] = 'allow ' . $rIP . ';';
					}
				}

				if (count($rWhitelist) > 0) {
					$rWhitelist[] = 'deny all;';
				}

				$rType = array('admin', 'reseller', 'ministra', 'includes/api/admin', 'includes/api/reseller', 'ministra/new', 'player')[$rCode['type']];
				$rBurst = array(500, 50, 50, 1000, 1000, 50, 500)[$rCode['type']];

				if (strlen($rCode['code']) >= 4) {
					file_put_contents($rMainHome . 'bin/nginx/conf/codes/' . $rCode['code'] . '.conf', str_replace(array('#WHITELIST#', '#CODE#', '#TYPE#', '#BURST#'), array(implode(' ', $rWhitelist), $rCode['code'], $rType, $rBurst), $rTemplate));
				} else {
					file_put_contents($rMainHome . 'bin/nginx/conf/codes/' . $rCode['code'] . '.conf', str_replace(array('#WHITELIST#', '#CODE#', '#TYPE#', '#BURST#'), array(implode(' ', $rWhitelist), $rCode['code'] . '/', $rType . '/', $rBurst), $rTemplate));
				}
			}
		}

		if (count(self::getActiveCodes($rMainHome)) == 0) {
			if (!file_exists($rMainHome . 'bin/nginx/conf/codes/default.conf')) {
				file_put_contents($rMainHome . 'bin/nginx/conf/codes/default.conf', str_replace(array('alias ', '#WHITELIST#', '#CODE#', '#TYPE#'), array('root ', '', '', 'admin'), $rTemplate));
			}
		} else {
			if (file_exists($rMainHome . 'bin/nginx/conf/codes/default.conf')) {
				unlink($rMainHome . 'bin/nginx/conf/codes/default.conf');
			}
		}

		call_user_func($rReloadNginxCallback, $rServerId);
	}

	public static function getCurrentCode($db, $rInfo = false) {
		if ($rInfo) {
			$db->query('SELECT * FROM `access_codes` WHERE `code` = ?;', basename(dirname($_SERVER['PHP_SELF'])));
			if ($db->num_rows() == 1) {
				return $db->get_row();
			}
			return null;
		}

		return basename(dirname($_SERVER['PHP_SELF']));
	}
}
