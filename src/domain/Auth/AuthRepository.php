<?php

/**
 * Консолидированный репозиторий аутентификации.
 * Объединяет: CodeRepository, HMACRepository.
 *
 * @package XC_VM_Domain_Auth
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class AuthRepository {
	public static function getAllCodes($rType = null) {
		global $db;
		$rReturn = array();

		if (!is_null($rType)) {
			$db->query('SELECT * FROM `access_codes` WHERE `type` = ? ORDER BY `id` ASC;', $rType);
		} else {
			$db->query('SELECT * FROM `access_codes` ORDER BY `id` ASC;');
		}

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[intval($rRow['id'])] = $rRow;
			}
		}

		return $rReturn;
	}

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

		foreach (self::getAllCodes() as $rCode) {
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

				// NOTE: 'includes/api/admin' and 'includes/api/reseller' are legacy nginx route
				// identifiers baked into generated access-code configs — NOT filesystem paths.
				// Do not rename without regenerating all deployed nginx configs.
				$rType = array('admin', 'reseller', 'ministra', 'includes/api/admin', 'includes/api/reseller', 'ministra/new', 'player')[$rCode['type']];
				$rAlias = array('public/Views/admin', 'reseller', 'ministra', 'includes/api/admin', 'includes/api/reseller', 'ministra/new', 'public/assets/player')[$rCode['type']];
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
				file_put_contents($rMainHome . 'bin/nginx/conf/codes/default.conf', str_replace(array('alias ', '#WHITELIST#', '#CODE#', '#TYPE#', '#ALIAS#'), array('root ', '', '', 'admin', 'public/Views/admin'), $rTemplate));
			}
		} else {
			if (file_exists($rMainHome . 'bin/nginx/conf/codes/default.conf')) {
				unlink($rMainHome . 'bin/nginx/conf/codes/default.conf');
			}
		}

		ApiClient::systemRequest($rServerId, array('action' => 'reload_nginx'));
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

	// ──────────────────────────────────────────────
	// Permissions
	// ──────────────────────────────────────────────

	public static function getPermissions($rID) {
		global $db;
		$db->query('SELECT * FROM `users_groups` WHERE `group_id` = ?;', $rID);

		if ($db->num_rows() == 1) {
			$rRow = $db->get_row();
			$rRow['subresellers'] = !empty($rRow['subresellers']) ? json_decode($rRow['subresellers'], true) : [];

			if (count($rRow['subresellers'] ?? []) == 0) {
				$rRow['create_sub_resellers'] = 0;
			}

			return $rRow;
		}

		return [];
	}

	public static function getGroupPermissions($rUserID, $rStreams = true, $rUsers = true) {
		global $db;
		$rStart = round(microtime(true) * 1000);
		$rReturn = array('create_line' => false, 'create_mag' => false, 'create_enigma' => false, 'stream_ids' => array(), 'series_ids' => array(), 'category_ids' => array(), 'users' => array(), 'direct_reports' => array(), 'all_reports' => array(), 'report_map' => array());
		$rUser = UserRepository::getRegisteredUserById($rUserID);

		if (!$rUser) {
		} else {
			if (!file_exists(CACHE_TMP_PATH . 'permissions_' . intval($rUser['member_group_id']))) {
			} else {
				$rPermData = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'permissions_' . intval($rUser['member_group_id'])));
				if (is_array($rPermData)) {
					$rReturn = array_merge($rReturn, $rPermData);
				}
			}

			$db->query("SELECT * FROM `users_packages` WHERE JSON_CONTAINS(`groups`, ?, '\$');", $rUser['member_group_id']);

			foreach ($db->get_rows() as $rRow) {
				if (!$rRow['is_line']) {
				} else {
					$rReturn['create_line'] = true;
				}

				if (!$rRow['is_mag']) {
				} else {
					$rReturn['create_mag'] = true;
				}

				if (!$rRow['is_e2']) {
				} else {
					$rReturn['create_enigma'] = true;
				}
			}

			if (!$rUsers) {
			} else {
				$rReturn['users'] = UserRepository::getSubUsers($rUser['id']);

				foreach ($rReturn['users'] as $rUserID => $rUserData) {
					if ($rUser['id'] != $rUserData['parent']) {
					} else {
						$rReturn['direct_reports'][] = $rUserID;
					}

					$rReturn['all_reports'][] = $rUserID;
				}
			}
		}

		return $rReturn;
	}

	public static function getCodeById($rID) {
		global $db;
		$db->query('SELECT * FROM `access_codes` WHERE `id` = ?;', $rID);

		if ($db->num_rows() != 1) {
			return null;
		}

		return $db->get_row();
	}

	public static function deleteCode($rID) {
		global $db;
		$db->query('SELECT `id` FROM `access_codes` WHERE `id` = ?;', $rID);

		if (0 >= $db->num_rows()) {
			return false;
		}

		$db->query('DELETE FROM `access_codes` WHERE `id` = ?;', $rID);
		self::updateCodes();

		return true;
	}

	public static function deleteHMAC($rID) {
		global $db;
		$db->query('SELECT `id` FROM `hmac_keys` WHERE `id` = ?;', $rID);

		if (0 >= $db->num_rows()) {
			return false;
		}

		$db->query('DELETE FROM `hmac_keys` WHERE `id` = ?;', $rID);

		return true;
	}
}
