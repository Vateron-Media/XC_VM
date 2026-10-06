<?php

namespace XcVm\Core\Auth;

use XcVm\Core\Cluster\NodeRpc;
use XcVm\Domain\User\UserCredits;
use XcVm\Domain\User\UserRepository;

/**
 * Консолидированный репозиторий аутентификации.
 * Объединяет: \CodeRepository, HMACRepository.
 *
 * @package XC_VM_Domain_Auth
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class AuthRepository {
	/**
	 * Fetch all access codes, optionally filtered by type.
	 *
	 * @param int|null $rType Access-code type to filter by, or null for all.
	 * @return array Rows keyed by access-code id.
	 */
	public static function getAllCodes(?int $rType = null) {
		global $db;
		$rReturn = [];

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

	/**
	 * List active access-code names from the generated nginx code configs.
	 *
	 * @param string $rMainHome Panel home path (trailing slash).
	 * @return string[] Access-code names (config filenames without extension, excluding 'default').
	 */
	public static function getActiveCodes(string $rMainHome) {
		$rCodes = [];
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

	/**
	 * Get the active code string for Web Player (type 6), if configured and enabled.
	 */
	public static function getWebPlayerCode(): ?string {
		foreach (self::getAllCodes(6) as $code) {
			if (!empty($code['enabled'])) {
				return (string) $code['code'];
			}
		}
		return null;
	}

	/**
	 * Get the active code string for Active Code Portal (type 7), if configured and enabled.
	 */
	public static function getActiveCodePortalCode(): ?string {
		foreach (self::getAllCodes(7) as $code) {
			if (!empty($code['enabled'])) {
				return (string) $code['code'];
			}
		}
		return null;
	}

	/**
	 * Get the active code string for Web Player V2 (type 8), if configured and enabled.
	 */
	public static function getWebPlayerV2Code(): ?string {
		foreach (self::getAllCodes(8) as $code) {
			if (!empty($code['enabled'])) {
				return (string) $code['code'];
			}
		}
		return null;
	}

	/**
	 * An access-code type's nginx location: its XC_SCOPE (#TYPE#), the
	 * directory under MAIN_HOME it aliases (#ALIAS#) and its burst. An
	 * unknown type is an admin code.
	 *
	 * Type 5, the legacy "Ministra (new)" code, serves the one Ministra
	 * portal there is (src/Ministra/, the type-2 directory): its `new`
	 * subdirectory never existed, so such a code served no portal files.
	 *
	 * @return array{0: string, 1: string, 2: int} [type, alias, burst]
	 */
	public static function codeLocation(int $rCodeType): array {
		// NOTE: 'includes/api/admin' and 'includes/api/reseller' are legacy nginx route
		// identifiers baked into generated access-code configs — NOT filesystem paths.
		// Do not rename without regenerating all deployed nginx configs.
		$rTypeMap = [0 => 'admin', 1 => 'reseller', 2 => 'ministra', 3 => 'includes/api/admin', 4 => 'includes/api/reseller', 5 => 'ministra/new', 6 => 'player', 7 => 'portal', 8 => 'player_v2'];
		$rAliasMap = [0 => 'Public/Views/admin', 1 => 'reseller', 2 => 'Ministra', 3 => 'includes/api/admin', 4 => 'includes/api/reseller', 5 => 'Ministra', 6 => 'Public/assets/player', 7 => 'Public/Views/portal', 8 => 'Public/Views/player_v2'];
		// The burst counts every request the code hands to PHP. A panel's list
		// page asks at once for itself, its table data and a thumbnail per row
		// in view, the reseller's as the admin's.
		$rBurstMap = [0 => 500, 1 => 500, 2 => 50, 3 => 1000, 4 => 1000, 5 => 50, 6 => 500, 7 => 500, 8 => 500];

		return [$rTypeMap[$rCodeType] ?? 'admin', $rAliasMap[$rCodeType] ?? 'Public/Views/admin', $rBurstMap[$rCodeType] ?? 500];
	}

	/**
	 * Regenerate per-code nginx config files from the database and reload nginx.
	 *
	 * Rebuilds `bin/nginx/conf/codes/*.conf` for every enabled access code,
	 * manages the fallback `default.conf`, and triggers an nginx reload.
	 *
	 * @return void
	 */
	public static function updateCodes() {
		$rMainHome = MAIN_HOME;
		$rServerId = SERVER_ID;
		$rTemplate = file_get_contents($rMainHome . 'bin/nginx/conf/codes/template');
		// An update keeps the installed bin/nginx/conf/codes, the template with
		// it: one from before the sign-in and the resizer were limited gets
		// their location here.
		// ponytail: drop once the updater replaces the templates.
		if (!str_contains($rTemplate, '(login|resize)')) {
			$rTemplate = preg_replace('/^\s*#WHITELIST#\s*$/m', '$0' . "\n    location ~ /(login|resize)$ {\n        limit_req zone=#ZONE# burst=#BURST# nodelay;\n        try_files \$uri @fc_#CODE#;\n    }", $rTemplate, 1);
		}
		// The zone is declared in nginx.conf; one restored from before it (the
		// update's own nginx -t fallback) has only the zone the streams use.
		$rZone = str_contains((string) @file_get_contents($rMainHome . 'bin/nginx/conf/nginx.conf'), 'zone=panel:') ? 'panel' : 'one';
		$rMinistraTemplate = file_get_contents($rMainHome . 'bin/nginx/conf/codes/template_ministra');
		shell_exec('rm -f ' . $rMainHome . 'bin/nginx/conf/codes/*.conf');

		foreach (self::getAllCodes() as $rCode) {
			if ($rCode['enabled']) {
				$rWhitelist = [];

				foreach ((array) json_decode($rCode['whitelist'], true) as $rIP) {
					if (filter_var($rIP, FILTER_VALIDATE_IP)) {
						$rWhitelist[] = 'allow ' . $rIP . ';';
					}
				}

				if (count($rWhitelist) > 0) {
					$rWhitelist[] = 'deny all;';
				}

				[$rType, $rAlias, $rBurst] = self::codeLocation((int) $rCode['type']);
				$rCurrentTemplate = in_array($rType, ['ministra', 'ministra/new']) ? $rMinistraTemplate : $rTemplate;

				if (in_array($rType, ['ministra', 'ministra/new']) || strlen($rCode['code']) >= 4) {
					file_put_contents($rMainHome . 'bin/nginx/conf/codes/' . $rCode['code'] . '.conf', str_replace(['#WHITELIST#', '#CODE#', '#TYPE#', '#BURST#', '#ALIAS#', '#ZONE#'], [implode(' ', $rWhitelist), (string) $rCode['code'], $rType, (string) $rBurst, $rAlias, $rZone], $rCurrentTemplate));
				} else {
					file_put_contents($rMainHome . 'bin/nginx/conf/codes/' . $rCode['code'] . '.conf', str_replace(['#WHITELIST#', '#CODE#', '#TYPE#', '#BURST#', '#ALIAS#', '#ZONE#'], [implode(' ', $rWhitelist), $rCode['code'] . '/', $rType . '/', (string) $rBurst, $rAlias . '/', $rZone], $rCurrentTemplate));
				}
			}
		}

		if (count(self::getActiveCodes($rMainHome)) == 0) {
			if (!file_exists($rMainHome . 'bin/nginx/conf/codes/default.conf')) {
				// No code: the admin panel at the root. Its XC_CODE is empty, which
				// nginx takes only quoted.
				file_put_contents($rMainHome . 'bin/nginx/conf/codes/default.conf', str_replace(['alias ', 'XC_CODE  #CODE#;', '#WHITELIST#', '#CODE#', '#TYPE#', '#ALIAS#', '#BURST#', '#ZONE#'], ['root ', 'XC_CODE "";', '', '', 'admin', 'Public/Views/admin', '500', $rZone], $rTemplate));
			}
		} else {
			if (file_exists($rMainHome . 'bin/nginx/conf/codes/default.conf')) {
				unlink($rMainHome . 'bin/nginx/conf/codes/default.conf');
			}
		}

		NodeRpc::request($rServerId, ['action' => 'reload_nginx']);
	}

	/**
	 * Resolve the access code for the current request.
	 *
	 * Reads `XC_CODE` (set by the Front Controller via fastcgi_param), falling
	 * back to the PHP_SELF directory name for legacy setups.
	 *
	 * @param bool $rInfo When true, return the full access-code DB row instead of the code string.
	 * @return string|array|null Code string, or the DB row when $rInfo is true (null if not found).
	 */
	public static function getCurrentCode(bool $rInfo = false) {
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

	/**
	 * Fetch all HMAC keys.
	 *
	 * @return array Rows keyed by HMAC key id.
	 */
	public static function getAllHMAC() {
		global $db;
		$rReturn = [];
		$db->query('SELECT * FROM `hmac_keys` ORDER BY `id` ASC;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[intval($rRow['id'])] = $rRow;
			}
		}

		return $rReturn;
	}

	/**
	 * Fetch a single HMAC key by id.
	 *
	 * @param int $rID HMAC key id.
	 * @return array|null The key row, or null if not found.
	 */
	public static function getHMACById(int $rID) {
		global $db;
		$db->query('SELECT * FROM `hmac_keys` WHERE `id` = ?;', $rID);
		if ($db->num_rows() == 1) {
			return $db->get_row();
		}
		return null;
	}

	// ──────────────────────────────────────────────
	// Permissions
	// ──────────────────────────────────────────────

	/**
	 * Fetch the permissions row for a user group.
	 *
	 * Decodes the `subresellers` JSON and disables sub-reseller creation when empty.
	 *
	 * @param int $rID User group id.
	 * @return array The group permissions row, or [] if not found.
	 */
	public static function getPermissions(int $rID) {
		global $db;
		$db->query('SELECT * FROM `users_groups` WHERE `group_id` = ?;', $rID);

		if ($db->num_rows() == 1) {
			// Domain\User is not in the load balancer build: a node reads the row as it is.
			$rRow = class_exists(UserCredits::class) ? UserCredits::amounts($db->get_row()) : $db->get_row();
			$rRow['subresellers'] = !empty($rRow['subresellers']) ? json_decode($rRow['subresellers'], true) : [];

			if (count($rRow['subresellers'] ?? []) == 0) {
				$rRow['create_sub_resellers'] = 0;
			}

			return $rRow;
		}

		return [];
	}

	/**
	 * Build the effective permission set for a user.
	 *
	 * Merges cached per-group permissions with package capability flags
	 * (create line/mag/enigma) and, optionally, the user's sub-user reports.
	 *
	 * @param int  $rUserID  Registered user id.
	 * @param bool $rStreams Reserved flag for stream-scope expansion.
	 * @param bool $rUsers   When true, include sub-users and report maps.
	 * @return array Effective permissions (create flags, stream/series/category ids, users, reports).
	 */
	public static function getGroupPermissions(int $rUserID, bool $rStreams = true, bool $rUsers = true) {
		global $db;
		$rReturn = ['create_line' => false, 'create_mag' => false, 'create_enigma' => false, 'stream_ids' => [], 'series_ids' => [], 'category_ids' => [], 'users' => [], 'direct_reports' => [], 'all_reports' => [], 'report_map' => []];
		$rUser = UserRepository::getRegisteredUserById($rUserID);

		if ($rUser) {
			if (file_exists(CACHE_TMP_PATH . 'permissions_' . intval($rUser['member_group_id']))) {
				$rPermData = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'permissions_' . intval($rUser['member_group_id'])));
				if (is_array($rPermData)) {
					$rReturn = array_merge($rReturn, $rPermData);
				}
			}
			$db->query("SELECT * FROM `users_packages` WHERE JSON_CONTAINS(`groups`, ?, '\$');", $rUser['member_group_id']);
			if ($db->num_rows() > 0) {
				foreach ($db->get_rows() as $rRow) {
					if ($rRow['is_line']) {
						$rReturn['create_line'] = true;
					}

					if ($rRow['is_mag']) {
						$rReturn['create_mag'] = true;
					}

					if ($rRow['is_e2']) {
						$rReturn['create_enigma'] = true;
					}
				}
			}
			if ($rUsers) {
				$rReturn['users'] = UserRepository::getSubUsers($rUser['id']);
				foreach ($rReturn['users'] as $rUserID => $rUserData) {
					if ($rUser['id'] == $rUserData['parent']) {
						$rReturn['direct_reports'][] = $rUserID;
					}

					$rReturn['all_reports'][] = $rUserID;
				}
			}
		}

		return $rReturn;
	}

	/**
	 * Fetch a single access code by id.
	 *
	 * @param int $rID Access-code id.
	 * @return array|null The access-code row, or null if not found.
	 */
	public static function getCodeById(int $rID) {
		global $db;
		$db->query('SELECT * FROM `access_codes` WHERE `id` = ?;', $rID);

		if ($db->num_rows() != 1) {
			return null;
		}

		return $db->get_row();
	}

	/**
	 * Delete an access code and regenerate the nginx code configs.
	 *
	 * @param int $rID Access-code id.
	 * @return bool True on deletion, false if the code does not exist.
	 */
	public static function deleteCode(int $rID) {
		global $db;
		$db->query('SELECT `id` FROM `access_codes` WHERE `id` = ?;', $rID);

		if (0 >= $db->num_rows()) {
			return false;
		}

		$db->query('DELETE FROM `access_codes` WHERE `id` = ?;', $rID);
		self::updateCodes();

		return true;
	}

	/**
	 * Delete an HMAC key.
	 *
	 * @param int $rID HMAC key id.
	 * @return bool True on deletion, false if the key does not exist.
	 */
	public static function deleteHMAC(int $rID) {
		global $db;
		$db->query('SELECT `id` FROM `hmac_keys` WHERE `id` = ?;', $rID);

		if (0 >= $db->num_rows()) {
			return false;
		}

		$db->query('DELETE FROM `hmac_keys` WHERE `id` = ?;', $rID);

		return true;
	}
}
