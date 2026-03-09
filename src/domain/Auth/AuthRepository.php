<?php

/**
 * Консолидированный репозиторий аутентификации.
 * Объединяет: CodeRepository, HMACRepository.
 */
class AuthRepository {
	// ──────────────────────────────────────────────
	// Из CodeRepository
	// ──────────────────────────────────────────────

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

	public static function updateCodes() {
		$rMainHome = MAIN_HOME;
		$rServerId = SERVER_ID;
		$rTemplate = file_get_contents($rMainHome . 'bin/nginx/conf/codes/template');
		shell_exec('rm -f ' . $rMainHome . 'bin/nginx/conf/codes/*.conf');

		foreach (getcodes() as $rCode) {
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
				$rAlias = array('admin', 'reseller', 'ministra', 'includes/api/admin', 'includes/api/reseller', 'ministra/new', 'public/assets/player')[$rCode['type']];
				$rBurst = array(500, 50, 50, 1000, 1000, 50, 500)[$rCode['type']];

				if (strlen($rCode['code']) >= 4) {
					file_put_contents($rMainHome . 'bin/nginx/conf/codes/' . $rCode['code'] . '.conf', str_replace(array('#WHITELIST#', '#CODE#', '#TYPE#', '#BURST#', '#ALIAS#'), array(implode(' ', $rWhitelist), $rCode['code'], $rType, $rBurst, $rAlias), $rTemplate));
				} else {
					file_put_contents($rMainHome . 'bin/nginx/conf/codes/' . $rCode['code'] . '.conf', str_replace(array('#WHITELIST#', '#CODE#', '#TYPE#', '#BURST#', '#ALIAS#'), array(implode(' ', $rWhitelist), $rCode['code'] . '/', $rType . '/', $rBurst, $rAlias . '/'), $rTemplate));
				}
			}
		}

		if (count(self::getActiveCodes($rMainHome)) == 0) {
			if (!file_exists($rMainHome . 'bin/nginx/conf/codes/default.conf')) {
				file_put_contents($rMainHome . 'bin/nginx/conf/codes/default.conf', str_replace(array('alias ', '#WHITELIST#', '#CODE#', '#TYPE#', '#ALIAS#'), array('root ', '', '', 'admin', 'admin'), $rTemplate));
			}
		} else {
			if (file_exists($rMainHome . 'bin/nginx/conf/codes/default.conf')) {
				unlink($rMainHome . 'bin/nginx/conf/codes/default.conf');
			}
		}

		systemapirequest($rServerId, array('action' => 'reload_nginx'));
	}

	public static function getCurrentCode($rInfo = false) {
		global $db;
		// Front Controller передаёт XC_CODE через fastcgi_param.
		// Без FC — определяем из PHP_SELF (legacy поведение).
		$rCode = !empty($_SERVER['XC_CODE'])
			? $_SERVER['XC_CODE']
			: basename(dirname($_SERVER['PHP_SELF']));

		if ($rInfo) {
			$db->query('SELECT * FROM `access_codes` WHERE `code` = ?;', $rCode);
			if ($db->num_rows() == 1) {
				return $db->get_row();
			}
			return null;
		}

		return $rCode;
	}

	// ──────────────────────────────────────────────
	// Из HMACRepository
	// ──────────────────────────────────────────────

	public static function getAllHMAC() {
		global $db;
		$rReturn = array();
		$db->query('SELECT * FROM `hmac_keys` ORDER BY `id` ASC;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[intval($rRow['id'])] = $rRow;
			}
		}

		return $rReturn;
	}

	public static function getHMACById($rID) {
		global $db;
		$db->query('SELECT * FROM `hmac_keys` WHERE `id` = ?;', $rID);
		if ($db->num_rows() == 1) {
			return $db->get_row();
		}
	}
}
