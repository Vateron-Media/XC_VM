<?php

declare(strict_types=1);

namespace XcVm\Core\Auth;

use XcVm\Core\Logging\Logger;

/**
 * Authenticator — authenticator
 *
 * @package XC_VM_Core_Auth
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class Authenticator {
	/**
	 * Verifies a Google reCAPTCHA v2 token.
	 *
	 * Fails closed (returns false) on any transport error — unreachable host,
	 * timeout, non-string body or malformed JSON — instead of crashing, so a
	 * blocked outbound route to google.com can never white-screen the login
	 * page. Admins locked out this way can still sign in via a 'setup'/'rescue'
	 * access code, which bypasses reCAPTCHA entirely.
	 */
	private static function verifyRecaptcha(string $rToken): bool {
		global $rSettings;

		$rSecret = trim((string) ($rSettings['recaptcha_v2_secret_key'] ?? ''));
		if ($rSecret === '' || $rToken === '') {
			self::logRecaptcha($rSecret === '' ? 'empty secret key in settings' : 'empty g-recaptcha-response token (checkbox not solved)');
			return false;
		}

		$rPost = http_build_query(array('secret' => $rSecret, 'response' => $rToken));
		$rUrl  = 'https://www.google.com/recaptcha/api/siteverify';
		$rRaw  = false;
		$rErr  = '';

		$rCurl = curl_init($rUrl);
		curl_setopt($rCurl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($rCurl, CURLOPT_POST, true);
		curl_setopt($rCurl, CURLOPT_POSTFIELDS, $rPost);
		curl_setopt($rCurl, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($rCurl, CURLOPT_TIMEOUT, 10);
		curl_setopt($rCurl, CURLOPT_SSL_VERIFYPEER, false);
		$rRaw = curl_exec($rCurl);
		if ($rRaw === false) {
			$rErr = 'curl error: ' . curl_error($rCurl);
		}
		curl_close($rCurl);

		if ($rRaw === false) {
			self::logRecaptcha('could not reach siteverify endpoint — ' . $rErr);
			return false;
		}

		$rResponse = json_decode($rRaw, true);
		if (!is_array($rResponse) || empty($rResponse['success'])) {
			$rCodes = (is_array($rResponse) && !empty($rResponse['error-codes']))
				? implode(', ', (array) $rResponse['error-codes'])
				: 'unknown';
			self::logRecaptcha('verification rejected by Google (error-codes: ' . $rCodes . '); secret prefix=' . substr($rSecret, 0, 10));
			return false;
		}

		return true;
	}

	/** Writes a reCAPTCHA diagnostic line to the panel log (visible on-screen in DEV_MODE). */
	private static function logRecaptcha(string $rReason): void {
		// Logger is always autoloadable (Composer PSR-4); no defensive guard needed.
		Logger::log('WARNING', '[reCAPTCHA] ' . $rReason, '', __FILE__, __LINE__);
	}

	/**
	 * Authenticate an admin/reseller user via the login form.
	 *
	 * Verifies reCAPTCHA (unless bypassed), credentials, and that the user's
	 * group is allowed for the current access code; records login attempts when
	 * logging is enabled.
	 *
	 * @param array $rData            Login payload (username, password, captcha token).
	 * @param bool  $rBypassRecaptcha Skip the reCAPTCHA check (e.g. internal flows).
	 * @return array ['status' => STATUS_* constant, ...] describing the result.
	 */
	public static function login(array $rData, bool $rBypassRecaptcha = false): array {
		global $db, $rSettings;
		if (!empty($rSettings['recaptcha_enable']) && !$rBypassRecaptcha) {
			if (!self::verifyRecaptcha($rData['g-recaptcha-response'] ?? '')) {
				return array('status' => STATUS_INVALID_CAPTCHA);
			}
		}

		$rIP = \XcVm\Core\Util\NetworkUtils::getUserIP();
		$rUserInfo = \XcVm\Domain\User\UserRepository::getAuthUserByCredentials($rData['username'], $rData['password']);
		$rAccessCode = AuthRepository::getCurrentCode(true);

		if (!isset($rUserInfo)) {
			if (!empty($rSettings['save_login_logs'])) {
				$db->query("INSERT INTO `login_logs`(`type`, `access_code`, `user_id`, `status`, `login_ip`, `date`) VALUES('ADMIN', ?, 0, ?, ?, ?);", $rAccessCode['id'], 'INVALID_LOGIN', $rIP, time());
			}
			return array('status' => STATUS_FAILURE);
		}

		$db->query('SELECT COUNT(*) AS `count` FROM `access_codes`;');
		$rCodeCount = $db->get_row()['count'];

		$rCodeGroups = ($rAccessCode && isset($rAccessCode['groups']))
			? json_decode($rAccessCode['groups'], true)
			: null;

		if (!($rCodeCount == 0 || (is_array($rCodeGroups) && in_array($rUserInfo['member_group_id'], $rCodeGroups)))) {
			if (!empty($rSettings['save_login_logs'])) {
				$db->query("INSERT INTO `login_logs`(`type`, `access_code`, `user_id`, `status`, `login_ip`, `date`) VALUES('ADMIN', ?, ?, ?, ?, ?);", $rAccessCode['id'], $rUserInfo['id'], 'INVALID_CODE', $rIP, time());
			}
			return array('status' => STATUS_INVALID_CODE);
		}

		$rPermissions = AuthRepository::getPermissions($rUserInfo['member_group_id']);
		if (!$rPermissions['is_admin']) {
			if (!empty($rSettings['save_login_logs'])) {
				$db->query("INSERT INTO `login_logs`(`type`, `access_code`, `user_id`, `status`, `login_ip`, `date`) VALUES('ADMIN', ?, ?, ?, ?, ?);", $rAccessCode['id'], $rUserInfo['id'], 'NOT_ADMIN', $rIP, time());
			}
			return array('status' => STATUS_NOT_ADMIN);
		}

		if ($rUserInfo['status'] == 1) {
			$rCrypt = self::hashPassword($rData['password']);
			$db->query('UPDATE `users` SET `password` = ?, `last_login` = UNIX_TIMESTAMP(), `ip` = ? WHERE `id` = ?;', $rCrypt, $rIP, $rUserInfo['id']);

			$_SESSION['hash'] = $rUserInfo['id'];
			$_SESSION['ip'] = $rIP;
			$_SESSION['code'] = AuthRepository::getCurrentCode();
			$_SESSION['verify'] = md5($rUserInfo['username'] . '||' . $rCrypt);

			if (!empty($rSettings['save_login_logs'])) {
				$db->query("INSERT INTO `login_logs`(`type`, `access_code`, `user_id`, `status`, `login_ip`, `date`) VALUES('ADMIN', ?, ?, ?, ?, ?);", $rAccessCode['id'], $rUserInfo['id'], 'SUCCESS', $rIP, time());
			}
			return array('status' => STATUS_SUCCESS);
		}

		if (!$rUserInfo['status']) {
			if (!empty($rSettings['save_login_logs'])) {
				$db->query("INSERT INTO `login_logs`(`type`, `access_code`, `user_id`, `status`, `login_ip`, `date`) VALUES('ADMIN', ?, ?, ?, ?, ?);", $rAccessCode['id'], $rUserInfo['id'], 'DISABLED', $rIP, time());
			}
			return array('status' => STATUS_DISABLED);
		}

		return array('status' => STATUS_FAILURE);
	}

	/**
	 * Authenticate a reseller (reseller login flow).
	 *
	 * @param array $rData Login payload (username, password).
	 * @return array ['status' => STATUS_* constant, ...] describing the result.
	 */
	public static function resellerLogin(array $rData): array {
		global $db, $rSettings;
		if (!empty($rSettings['recaptcha_enable'])) {
			if (!self::verifyRecaptcha($rData['g-recaptcha-response'] ?? '')) {
				return array('status' => STATUS_INVALID_CAPTCHA);
			}
		}

		$rIP = \XcVm\Core\Util\NetworkUtils::getUserIP();
		$rUserInfo = \XcVm\Domain\User\UserRepository::getAuthUserByCredentials($rData['username'], $rData['password']);
		$rAccessCode = AuthRepository::getCurrentCode(true);

		if (!isset($rUserInfo)) {
			if (!empty($rSettings['save_login_logs'])) {
				$db->query("INSERT INTO `login_logs`(`type`, `access_code`, `user_id`, `status`, `login_ip`, `date`) VALUES('RESELLER', ?, 0, ?, ?, ?);", $rAccessCode['id'], 'INVALID_LOGIN', $rIP, time());
			}
			return array('status' => STATUS_FAILURE);
		}

		if (!(in_array($rUserInfo['member_group_id'], ($rAccessCode && isset($rAccessCode['groups'])) ? (json_decode($rAccessCode['groups'], true) ?: []) : []) || count(AuthRepository::getActiveCodes(MAIN_HOME)) == 0)) {
			if (!empty($rSettings['save_login_logs'])) {
				$db->query("INSERT INTO `login_logs`(`type`, `access_code`, `user_id`, `status`, `login_ip`, `date`) VALUES('RESELLER', ?, ?, ?, ?, ?);", $rAccessCode['id'], $rUserInfo['id'], 'INVALID_CODE', $rIP, time());
			}
			return array('status' => STATUS_INVALID_CODE);
		}

		$rPermissions = AuthRepository::getPermissions($rUserInfo['member_group_id']);
		if (!$rPermissions['is_reseller']) {
			if (!empty($rSettings['save_login_logs'])) {
				$db->query("INSERT INTO `login_logs`(`type`, `access_code`, `user_id`, `status`, `login_ip`, `date`) VALUES('RESELLER', ?, ?, ?, ?, ?);", $rAccessCode['id'], $rUserInfo['id'], 'NOT_ADMIN', $rIP, time());
			}
			return array('status' => STATUS_NOT_RESELLER);
		}

		if ($rUserInfo['status'] == 1) {
			$rCrypt = self::hashPassword($rData['password']);
			$db->query('UPDATE `users` SET `password` = ?, `last_login` = UNIX_TIMESTAMP(), `ip` = ? WHERE `id` = ?;', $rCrypt, $rIP, $rUserInfo['id']);

			$_SESSION['reseller'] = $rUserInfo['id'];
			$_SESSION['rip'] = $rIP;
			$_SESSION['rcode'] = AuthRepository::getCurrentCode();
			$_SESSION['rverify'] = md5($rUserInfo['username'] . '||' . $rCrypt);

			if (!empty($rSettings['save_login_logs'])) {
				$db->query("INSERT INTO `login_logs`(`type`, `access_code`, `user_id`, `status`, `login_ip`, `date`) VALUES('RESELLER', ?, ?, ?, ?, ?);", $rAccessCode['id'], $rUserInfo['id'], 'SUCCESS', $rIP, time());
			}
			return array('status' => STATUS_SUCCESS);
		}

		if (!$rUserInfo['status']) {
			if (!empty($rSettings['save_login_logs'])) {
				$db->query("INSERT INTO `login_logs`(`type`, `access_code`, `user_id`, `status`, `login_ip`, `date`) VALUES('RESELLER', ?, ?, ?, ?, ?);", $rAccessCode['id'], $rUserInfo['id'], 'DISABLED', $rIP, time());
			}
			return array('status' => STATUS_DISABLED);
		}

		return array('status' => STATUS_FAILURE);
	}

	/**
	 * Hash a password with a salted, multi-round digest.
	 *
	 * @param string      $password Plain-text password.
	 * @param string|null $salt     Explicit salt, or null to generate one.
	 * @param int         $rounds   Number of hashing rounds.
	 * @return string The encoded password hash (salt embedded).
	 */
	public static function hashPassword(string $password, ?string $salt = null, int $rounds = 20000): string {
		if ($salt === null || $salt === '') {
			$salt = substr(bin2hex(openssl_random_pseudo_bytes(16)), 0, 16);
		}
		if (strpos($salt, 'rounds=') === false) {
			$salt = sprintf('$6$rounds=%d$%s$', $rounds, $salt);
		}
		return crypt($password, $salt);
	}

	/**
	 * Verify a plain-text password against a stored hash.
	 *
	 * @param string $password   Plain-text password to check.
	 * @param string $storedHash Previously stored hash (from hashPassword()).
	 * @return bool True if the password matches.
	 */
	public static function checkPassword(string $password, string $storedHash): bool {
		return hash_equals($storedHash, crypt($password, $storedHash));
	}
}
