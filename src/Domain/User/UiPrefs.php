<?php

namespace XcVm\Domain\User;

use XcVm\Core\Enum\Theme;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Per-user Bootstrap 5 customizer state (users.ui_prefs), shared by the admin
 * and reseller panels. Only whitelisted keys with valid values are stored.
 *
 * @package XC_VM_Domain_User
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class UiPrefs {
	use DatabaseAware;

	/**
	 * Prefs for the shell's first paint and the customizer: the stored prefs, with
	 * the theme seeded from the profile's System Theme when none is saved (config.js
	 * would otherwise prime 'light'), and the RTL flag bound to the language.
	 *
	 * @param array<string, mixed> $rUserInfo
	 * @return array{0: array<string, mixed>, 1: string} [prefs, data-bs-theme value]
	 */
	public static function pageState(array $rUserInfo, bool $rIsRtl): array {
		$rIsDark = Theme::fromId($rUserInfo['theme'] ?? 0)->isDark();
		$rPrefs  = json_decode($rUserInfo['ui_prefs'] ?? '', true) ?: [];
		$rPrefs['theme'] ??= $rIsDark ? 'dark' : 'light';
		$rPrefs['rtl'] = $rIsRtl;

		// 'system' (or any other value) follows the profile's theme column.
		$rTheme   = $rPrefs['theme'];
		$rBsTheme = $rTheme === 'dark' ? 'dark' : ($rTheme === 'light' ? 'light' : ($rIsDark ? 'dark' : 'light'));

		return [$rPrefs, $rBsTheme];
	}

	/**
	 * Keep only the whitelisted keys whose values pass validation.
	 *
	 * @param array<string, mixed> $rIn
	 * @return array<string, mixed>
	 */
	public static function sanitize(array $rIn): array {
		$rEnum = static fn($rVal, array $rAllowed) => in_array($rVal, $rAllowed, true) ? $rVal : null;
		$rBool = static fn($rVal) => ($rVal === true || $rVal === 'true') ? true : (($rVal === false || $rVal === 'false') ? false : null);
		$rMap = [
			'theme'           => static fn($v) => $rEnum($v, ['light', 'dark', 'system']),
			'skin'            => static fn($v) => $rEnum($v, ['default', 'bordered']),
			'semiDark'        => $rBool,
			'layoutCollapsed' => $rBool,
			'rtl'             => $rBool,
			'navbar'          => static fn($v) => $rEnum($v, ['sticky', 'static', 'hidden']),
			'headerType'      => static fn($v) => $rEnum($v, ['static', 'fixed']),
			'contentLayout'   => static fn($v) => $rEnum($v, ['compact', 'wide']),
			'color'           => static fn($v) => (is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v)) ? $v : null,
			'lang'            => static fn($v) => (is_string($v) && preg_match('/^[a-z]{2,8}$/', $v)) ? $v : null,
		];

		$rClean = [];
		foreach ($rMap as $rKey => $rValidator) {
			if (array_key_exists($rKey, $rIn)) {
				$rVal = $rValidator($rIn[$rKey]);
				if ($rVal !== null) {
					$rClean[$rKey] = $rVal;
				}
			}
		}

		return $rClean;
	}

	/**
	 * Store the sanitized prefs for one user. The customizer's "Reset" sends
	 * `__reset` and clears them, so the panel falls back to the project defaults.
	 *
	 * @param array<string, mixed> $rIn
	 */
	public static function save(int $rUserId, array $rIn): void {
		if (!empty($rIn['__reset'])) {
			self::db()->query('UPDATE `users` SET `ui_prefs` = NULL WHERE `id` = ?;', $rUserId);

			return;
		}

		self::db()->query('UPDATE `users` SET `ui_prefs` = ? WHERE `id` = ?;', json_encode(self::sanitize($rIn)), $rUserId);
	}
}
