<?php

namespace XcVm\Core\Auth;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Util\NetworkUtils;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Two-factor sign-in for admins and resellers: a TOTP code from an
 * authenticator app, or one of ten one-time recovery codes.
 *
 * A password sign-in that needs a code is held (hold()): the session keys the
 * sign-in set are moved aside until confirm() takes a good code, so no request
 * sees a signed-in session without one. An account whose group requires two
 * factors and that has none sets one up in the same step. Secrets live in
 * `users_2fa`, apart from the `users` rows the pages and APIs read whole.
 *
 * @package XC_VM_Core_Auth
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class TwoFactor {
	use DatabaseAware;

	/** Seconds a held sign-in waits for its code. */
	public const PENDING_TTL = 300;

	/** Wrong codes an account takes within FAIL_WINDOW seconds before it refuses every code. */
	public const MAX_FAILS = 10;

	public const FAIL_WINDOW = 900;

	public const RECOVERY_CODES = 10;

	/** The session keys each panel's sign-in sets (Authenticator::login() and resellerLogin()). */
	private const SESSION_KEYS = ['admin' => ['hash', 'ip', 'code', 'verify'], 'reseller' => ['reseller', 'rip', 'rcode', 'rverify']];

	/** @return array{secret: string, recovery: list<string>, last_step: int}|null an account's second factor */
	public static function of(int $rUserID): ?array {
		$db = self::db();
		$db->query('SELECT `secret`, `recovery`, `last_step` FROM `users_2fa` WHERE `user_id` = ?;', $rUserID);
		$rRow = $db->num_rows() > 0 ? $db->get_raw_row() : null;
		if (!$rRow) {
			return null;
		}
		return ['secret' => (string) $rRow['secret'], 'recovery' => array_values(array_filter((array) json_decode((string) $rRow['recovery'], true), 'is_string')), 'last_step' => (int) $rRow['last_step']];
	}

	/** Whether an account's group makes two factors compulsory. */
	public static function required(array $rPermissions): bool {
		return !empty($rPermissions['require_2fa']);
	}

	/**
	 * Hold a sign-in that has just passed its password when the account has a
	 * second factor, or must set one up: its session keys wait in the session
	 * until confirm(). False when the account needs no code (signed in now).
	 *
	 * @param 'admin'|'reseller' $rType
	 */
	public static function hold(string $rType, array $rUserInfo, array $rPermissions, ?int $rAccessCode): bool {
		$rEnrolled = self::of((int) $rUserInfo['id']) !== null;
		if (!$rEnrolled && !self::required($rPermissions)) {
			return false;
		}
		$rSession = [];
		foreach (self::SESSION_KEYS[$rType] as $rKey) {
			if (array_key_exists($rKey, $_SESSION)) {
				$rSession[$rKey] = $_SESSION[$rKey];
				unset($_SESSION[$rKey]);
			}
		}
		$_SESSION['2fa'] = [
			'type' => $rType,
			'user' => (int) $rUserInfo['id'],
			'username' => (string) $rUserInfo['username'],
			'ip' => NetworkUtils::getUserIP(),
			'at' => time(),
			'access_code' => $rAccessCode ?? '',
			// The secret being set up, shown on the code page until confirmed; ''
			// when the account has one. The session holds strings only: every
			// request turns its values into strings (InputValidator::cleanGlobals()).
			'enrol' => $rEnrolled ? '' : Totp::newSecret(),
			'session' => $rSession,
		];
		return true;
	}

	/** @return array<string, mixed>|null the held sign-in of this panel, from this address and not expired */
	public static function pending(string $rType): ?array {
		$rPending = $_SESSION['2fa'] ?? null;
		if (!is_array($rPending) || $rPending['type'] !== $rType) {
			return null;
		}
		if ((string) $rPending['ip'] !== NetworkUtils::getUserIP() || (int) $rPending['at'] + self::PENDING_TTL < time()) {
			unset($_SESSION['2fa']);
			return null;
		}
		return $rPending;
	}

	public static function cancel(): void {
		unset($_SESSION['2fa']);
	}

	/**
	 * Take the code for the held sign-in. A good one signs the session in (a
	 * new session id, the held keys back); a first setup also returns the
	 * recovery codes to show once.
	 *
	 * @return array{status: int, recovery?: list<string>}
	 */
	public static function confirm(string $rType, string $rCode): array {
		$rPending = self::pending($rType);
		if ($rPending === null) {
			return ['status' => STATUS_FAILURE];
		}
		$rUserID = (int) $rPending['user'];
		$rNow = time();
		if (self::tooManyFails($rUserID, $rNow)) {
			return ['status' => STATUS_2FA_LOCKED];
		}
		$rCode = (string) preg_replace('/[\s-]/', '', $rCode);
		$rRecovery = null;
		if ((string) $rPending['enrol'] !== '') {
			$rOk = ($rRecovery = self::enable($rUserID, (string) $rPending['enrol'], $rCode, $rNow)) !== null;
		} else {
			$rOk = self::check($rUserID, $rCode, $rNow);
		}
		if (!$rOk) {
			// Always recorded: they are what tooManyFails() and the login flood limit count.
			self::log($rType, $rPending, 'INVALID_2FA', true);
			return ['status' => STATUS_2FA_INVALID];
		}
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_regenerate_id(true);
		}
		foreach ($rPending['session'] as $rKey => $rValue) {
			$_SESSION[$rKey] = $rValue;
		}
		unset($_SESSION['2fa']);
		self::log($rType, $rPending, 'SUCCESS', false);
		return $rRecovery === null ? ['status' => STATUS_SUCCESS] : ['status' => STATUS_SUCCESS, 'recovery' => $rRecovery];
	}

	/** A TOTP code (each time step once) or an unused recovery code, which is then spent. */
	public static function check(int $rUserID, string $rCode, int $rNow): bool {
		$rFactor = self::of($rUserID);
		if ($rFactor === null) {
			return false;
		}
		$rStep = Totp::match($rFactor['secret'], $rCode, $rNow, $rFactor['last_step']);
		if ($rStep !== null) {
			// Only a later step: two sign-ins racing on one code, one wins.
			self::db()->query('UPDATE `users_2fa` SET `last_step` = ? WHERE `user_id` = ? AND `last_step` < ?;', $rStep, $rUserID, $rStep);
			return self::db()->num_rows() === 1;
		}
		$rHash = self::recoveryHash($rCode);
		if (!in_array($rHash, $rFactor['recovery'], true)) {
			return false;
		}
		$rLeft = array_values(array_diff($rFactor['recovery'], [$rHash]));
		self::db()->query('UPDATE `users_2fa` SET `recovery` = ? WHERE `user_id` = ? AND `recovery` = ?;', json_encode($rLeft), $rUserID, json_encode($rFactor['recovery']));
		return self::db()->num_rows() === 1;
	}

	/**
	 * Turn two factors on with $rSecret once $rCode proves the app has it.
	 *
	 * @return list<string>|null the recovery codes, to show once; null when the code is wrong
	 */
	public static function enable(int $rUserID, string $rSecret, string $rCode, int $rNow): ?array {
		$rStep = Totp::match($rSecret, $rCode, $rNow);
		if ($rStep === null) {
			return null;
		}
		[$rCodes, $rHashes] = self::newRecovery();
		self::db()->query('REPLACE INTO `users_2fa` (`user_id`, `secret`, `recovery`, `last_step`, `created`) VALUES (?, ?, ?, ?, ?);', $rUserID, $rSecret, json_encode($rHashes), $rStep, $rNow);
		return $rCodes;
	}

	/** @return list<string> a new set of recovery codes, replacing the old ones */
	public static function renewRecovery(int $rUserID): array {
		[$rCodes, $rHashes] = self::newRecovery();
		self::db()->query('UPDATE `users_2fa` SET `recovery` = ? WHERE `user_id` = ?;', json_encode($rHashes), $rUserID);
		return $rCodes;
	}

	/** Turn an account's two factors off (its owner with a code, an admin, or `tools twofactor`). */
	public static function disable(int $rUserID): void {
		self::db()->query('DELETE FROM `users_2fa` WHERE `user_id` = ?;', $rUserID);
	}

	/**
	 * The profile page's actions on the signed-in account (post.php
	 * action=twofactor, admin and reseller): `enable` with the secret the page
	 * showed and a code from the app; `recovery` (new codes) and `disable`,
	 * each with a current code. A wrong code counts toward MAX_FAILS.
	 *
	 * @param array<string, mixed> $rData
	 * @return array{result: bool, status: int, recovery?: list<string>}
	 */
	public static function manage(int $rUserID, array $rData): array {
		$rNow = time();
		if (self::tooManyFails($rUserID, $rNow)) {
			return ['result' => false, 'status' => STATUS_2FA_LOCKED];
		}
		$rCode = (string) preg_replace('/[\s-]/', '', (string) ($rData['code'] ?? ''));
		$rSub = (string) ($rData['sub'] ?? '');
		if ($rSub === 'enable') {
			$rSecret = (string) ($rData['secret'] ?? '');
			$rRecovery = preg_match('/^[A-Z2-7]{32}\z/', $rSecret) && self::of($rUserID) === null ? self::enable($rUserID, $rSecret, $rCode, $rNow) : null;
			$rOk = $rRecovery !== null;
		} elseif ($rSub === 'recovery' || $rSub === 'disable') {
			$rOk = self::check($rUserID, $rCode, $rNow);
			$rRecovery = $rOk && $rSub === 'recovery' ? self::renewRecovery($rUserID) : null;
			if ($rOk && $rSub === 'disable') {
				self::disable($rUserID);
			}
		} else {
			return ['result' => false, 'status' => STATUS_FAILURE];
		}
		if (!$rOk) {
			self::db()->query("INSERT INTO `login_logs`(`type`, `access_code`, `user_id`, `status`, `login_ip`, `date`) VALUES('PROFILE', NULL, ?, 'INVALID_2FA', ?, ?);", $rUserID, NetworkUtils::getUserIP(), $rNow);
			return ['result' => false, 'status' => STATUS_2FA_INVALID];
		}
		return $rRecovery === null ? ['result' => true, 'status' => STATUS_SUCCESS] : ['result' => true, 'status' => STATUS_SUCCESS, 'recovery' => $rRecovery];
	}

	public static function tooManyFails(int $rUserID, int $rNow): bool {
		$db = self::db();
		$db->query("SELECT COUNT(*) AS `count` FROM `login_logs` WHERE `user_id` = ? AND `status` = 'INVALID_2FA' AND `date` >= ?;", $rUserID, $rNow - self::FAIL_WINDOW);
		return $db->num_rows() === 1 && (int) $db->get_raw_row()['count'] >= self::MAX_FAILS;
	}

	/** Ten codes of ten characters, as `abcde-fghij`; only their hashes are kept. */
	private static function newRecovery(): array {
		$rCodes = $rHashes = [];
		for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
			$rCode = substr(strtolower(Totp::base32Encode(random_bytes(7))), 0, 10);
			$rCodes[] = substr($rCode, 0, 5) . '-' . substr($rCode, 5);
			$rHashes[] = self::recoveryHash($rCode);
		}
		return [$rCodes, $rHashes];
	}

	/** A recovery code is random, so a plain SHA-256 of it (without the dash, any case) is enough. */
	private static function recoveryHash(string $rCode): string {
		return hash('sha256', strtolower((string) preg_replace('/[\s-]/', '', $rCode)));
	}

	private static function log(string $rType, array $rPending, string $rStatus, bool $rAlways): void {
		if (!$rAlways && empty(SettingsManager::get('save_login_logs'))) {
			return;
		}
		self::db()->query('INSERT INTO `login_logs`(`type`, `access_code`, `user_id`, `status`, `login_ip`, `date`) VALUES(?, ?, ?, ?, ?, ?);', strtoupper($rType), (string) $rPending['access_code'] === '' ? null : (int) $rPending['access_code'], (int) $rPending['user'], $rStatus, $rPending['ip'], time());
	}
}
