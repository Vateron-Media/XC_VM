<?php

namespace XcVm\Core\Auth;

use XcVm\Core\Config\SettingsManager;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Named API tokens, in place of an account's single `users.api_key`: an
 * account (admin or reseller) holds several, each with a scope, an optional
 * address list and expiry, generated on the server and stored as a SHA-256
 * hash. A token is sent where a key is, as `api_key`; the Admin, Reseller and
 * activation-code APIs take either (AdminAPIWrapper / ResellerAPIWrapper
 * ::createSession()). A token acts with its account's group permissions,
 * narrowed by its scope (allows()); raw SQL (mysql_query) needs a full-scope
 * admin token made with it. The `api_legacy_keys` setting turns the old keys off.
 *
 * @package XC_VM_Core_Auth
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class ApiTokens {
	use DatabaseAware;

	public const PREFIX = 'xct_';

	/** full: what the account's group may do; read: only reading actions; lines: lines, devices and activation codes. */
	public const SCOPES = ['full', 'read', 'lines'];

	public const MAX_PER_USER = 20;

	/** The API actions that only read, besides every `get_*` one. */
	private const READ = ['user_info', 'packages', 'activity_logs', 'live_connections', 'credit_logs', 'client_logs', 'user_logs', 'stream_errors', 'system_logs', 'login_logs', 'restream_logs', 'mag_events', 'check_active_code', 'export_active_code_batch'];

	/** The reads a line-managing integration needs beside the lines' own actions. */
	private const LINE_READS = ['user_info', 'packages', 'get_packages', 'get_package', 'get_bouquets', 'get_bouquet'];

	/** @var array<string, mixed>|null the token the current API request signed in with */
	private static ?array $rCurrent = null;

	/**
	 * The token a key is, if it is one that may be used now from $rIP: its row,
	 * which becomes the request's token. Null for a legacy key or a token that
	 * is unknown, expired or not for this address.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function resolve(string $rKey, string $rIP, ?int $rNow = null): ?array {
		self::$rCurrent = null;
		if (!str_starts_with($rKey, self::PREFIX)) {
			return null;
		}
		$rNow ??= time();
		$db = self::db();
		$db->query('SELECT * FROM `api_tokens` WHERE `hash` = ?;', hash('sha256', $rKey));
		$rToken = $db->num_rows() === 1 ? $db->get_raw_row() : null;
		if (!$rToken || (!empty($rToken['expires']) && (int) $rToken['expires'] <= $rNow)) {
			return null;
		}
		$rIPs = self::ipList((string) $rToken['ips']);
		if ($rIPs !== [] && !in_array($rIP, $rIPs, true)) {
			return null;
		}
		// Last use to the minute: a busy integration does not write on every call.
		$db->query('UPDATE `api_tokens` SET `last_used` = ?, `last_ip` = ? WHERE `id` = ? AND (`last_used` IS NULL OR `last_used` < ?);', $rNow, $rIP, $rToken['id'], $rNow - 60);
		return self::$rCurrent = $rToken;
	}

	/**
	 * The enabled admin or reseller account a key signs in: a token's, or a
	 * legacy `users.api_key`'s while api_legacy_keys allows them. Every API
	 * path that takes `api_key` asks here, so turning legacy keys off closes them all.
	 *
	 * @param 'admin'|'reseller' $rRole
	 */
	public static function userFor(string $rKey, string $rIP, string $rRole): ?int {
		$db = self::db();
		$rFlag = $rRole === 'admin' ? 'is_admin' : 'is_reseller';
		$rFrom = 'SELECT `users`.`id` FROM `users` LEFT JOIN `users_groups` ON `users_groups`.`group_id` = `users`.`member_group_id` WHERE ';
		$rToken = self::resolve($rKey, $rIP);
		if ($rToken !== null) {
			$db->query($rFrom . '`users`.`id` = ? AND `' . $rFlag . '` = 1 AND `status` = 1;', $rToken['user_id']);
		} elseif ($rKey !== '' && !str_starts_with($rKey, self::PREFIX) && self::legacyKeys()) {
			$db->query($rFrom . '`api_key` = ? AND LENGTH(`api_key`) > 0 AND `' . $rFlag . '` = 1 AND `status` = 1;', $rKey);
		} else {
			return null;
		}
		$rRow = $db->num_rows() > 0 ? $db->get_raw_row() : null;
		if (!$rRow) {
			self::$rCurrent = null;
			return null;
		}
		return (int) $rRow['id'];
	}

	/** Whether an account's legacy `api_key` still signs in (the `api_legacy_keys` setting, on unless turned off). */
	public static function legacyKeys(): bool {
		return (string) (SettingsManager::get('api_legacy_keys') ?? '1') !== '0';
	}

	/** Forget the request's token (a session that did not sign in with one). */
	public static function clear(): void {
		self::$rCurrent = null;
	}

	/** Whether the request's token, if it signed in with one, may run an API action. */
	public static function allows(string $rAction): bool {
		$rToken = self::$rCurrent;
		if ($rToken === null) {
			return true;
		}
		if ($rAction === 'mysql_query') {
			return $rToken['scope'] === 'full' && !empty($rToken['allow_sql']);
		}
		return match ($rToken['scope']) {
			'full' => true,
			'read' => self::isRead($rAction),
			'lines' => in_array($rAction, self::LINE_READS, true) || str_contains($rAction, 'active_code') || preg_match('/^[a-z]+_(line|lines|mag|mags|enigma|enigmas)$/', $rAction) === 1,
			default => false,
		};
	}

	public static function isRead(string $rAction): bool {
		return str_starts_with($rAction, 'get_') || in_array($rAction, self::READ, true);
	}

	/**
	 * Make a token. Returns the token itself, shown once: only its hash is kept.
	 *
	 * @param list<string> $rIPs
	 */
	public static function issue(int $rUserID, string $rName, string $rScope, bool $rAllowSql, array $rIPs, ?int $rExpires, ?int $rNow = null): string {
		$rToken = self::PREFIX . bin2hex(random_bytes(20));
		self::db()->query(
			'INSERT INTO `api_tokens` (`user_id`, `name`, `hash`, `prefix`, `scope`, `allow_sql`, `ips`, `expires`, `created`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?);',
			$rUserID,
			$rName,
			hash('sha256', $rToken),
			substr($rToken, 0, 12),
			$rScope,
			$rAllowSql ? 1 : 0,
			implode(',', $rIPs),
			$rExpires,
			$rNow ?? time()
		);
		return $rToken;
	}

	/** @return list<array<string, mixed>> an account's tokens, newest first, without their hashes */
	public static function forUser(int $rUserID): array {
		$db = self::db();
		$db->query('SELECT `id`, `name`, `prefix`, `scope`, `allow_sql`, `ips`, `expires`, `last_used`, `last_ip`, `created` FROM `api_tokens` WHERE `user_id` = ? ORDER BY `id` DESC;', $rUserID);
		return $db->get_raw_rows();
	}

	public static function revoke(int $rUserID, int $rID): bool {
		self::db()->query('DELETE FROM `api_tokens` WHERE `id` = ? AND `user_id` = ?;', $rID, $rUserID);
		return self::db()->num_rows() === 1;
	}

	/**
	 * The profile page's actions on the signed-in account's tokens (post.php
	 * action=api_tokens): `create` (name, scope, allow_sql, ips, days) answers
	 * the new token once; `revoke` (id).
	 *
	 * @param array<string, mixed> $rData
	 * @return array{result: bool, error?: string, token?: string}
	 */
	public static function manage(int $rUserID, array $rData, bool $rIsAdmin): array {
		$rSub = (string) ($rData['sub'] ?? '');
		if ($rSub === 'revoke') {
			return ['result' => self::revoke($rUserID, (int) ($rData['id'] ?? 0))];
		}
		if ($rSub !== 'create') {
			return ['result' => false, 'error' => 'action'];
		}
		$rName = trim((string) ($rData['name'] ?? ''));
		$rScope = (string) ($rData['scope'] ?? 'full');
		$rDays = (int) ($rData['days'] ?? 0);
		$rIPs = self::ipList((string) ($rData['ips'] ?? ''));
		if ($rName === '' || mb_strlen($rName) > 64) {
			return ['result' => false, 'error' => 'name'];
		}
		if (!in_array($rScope, self::SCOPES, true)) {
			return ['result' => false, 'error' => 'scope'];
		}
		if ($rDays < 0 || $rDays > 3650) {
			return ['result' => false, 'error' => 'days'];
		}
		if (count($rIPs) !== count(preg_split('/[\s,]+/', trim((string) ($rData['ips'] ?? '')), -1, PREG_SPLIT_NO_EMPTY))) {
			return ['result' => false, 'error' => 'ips'];
		}
		if (count(self::forUser($rUserID)) >= self::MAX_PER_USER) {
			return ['result' => false, 'error' => 'limit'];
		}
		$rAllowSql = $rIsAdmin && $rScope === 'full' && !empty($rData['allow_sql']);
		$rToken = self::issue($rUserID, $rName, $rScope, $rAllowSql, $rIPs, $rDays > 0 ? time() + $rDays * 86400 : null);
		return ['result' => true, 'token' => $rToken];
	}

	/** @return list<string> the valid addresses of a comma- or space-separated list */
	private static function ipList(string $rList): array {
		return array_values(array_filter(preg_split('/[\s,]+/', trim($rList), -1, PREG_SPLIT_NO_EMPTY) ?: [], static fn(string $rIP): bool => filter_var($rIP, FILTER_VALIDATE_IP) !== false));
	}
}
