<?php

namespace XcVm\Core\Util;

use XcVm\Core\Localization\Translator;

/**
 * LayoutRenderer — unified entry point for the admin/reseller/player header
 * and footer chrome.
 *
 * Every scope is fully migrated to the Bootstrap 5 shell; this class exists
 * to bridge the caller's page-scoped variables ($GLOBALS + an explicit $vars
 * map) into the local scope of the scope-specific header/footer view it
 * `require`s — a `require` inside a method only ever sees that method's own
 * local scope, not the caller's globals, so the legacy view templates (which
 * read $rUserInfo, $rSettings, etc. as plain local variables) need them
 * pulled in explicitly first.
 *
 * Neither method has a global-function wrapper anymore. The former
 * layouts/admin.php and layouts/footer.php (renderUnifiedLayoutHeader() /
 * renderUnifiedLayoutFooter()) were deleted once every caller — the 3
 * Base*Controller classes, admin/setup.php, and every Public/Views/*.php
 * page template — was converted to call this class directly.
 *
 * @package XC_VM_Core_Util
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class LayoutRenderer {
	/**
	 * Render the header/sidebar/topbar chrome for a scope.
	 *
	 * @param string               $scope 'admin' | 'reseller' | 'player' | 'player_v2'
	 * @param array<string, mixed> $vars  Page-scoped variables (optional)
	 */
	public static function renderHeader(string $scope = 'admin', array $vars = []): void {
		foreach ($vars as $key => $value) {
			if (!array_key_exists($key, $GLOBALS)) {
				$GLOBALS[$key] = $value;
			}
		}

		// The header views expect these variables in file scope; pull them
		// from $GLOBALS since a require here only sees this method's scope.
		foreach (
			[
				'rUserInfo',
				'rSettings',
				'rMobile',
				'rHues',
				'db',
				'allServersHealthy',
				'rServerError',
				'rServers',
				'allServers',
				'rUpdate',
				'_TITLE',
				'rModal',
				'rProxyServers',
				'rPermissions',
				'_PAGE',
				'_SETUP',
			] as $_g
		) {
			if (array_key_exists($_g, $GLOBALS)) {
				$$_g = $GLOBALS[$_g];
			}
		}
		unset($_g);

		// Translator FQCN for the header views' $language::get(...) calls.
		$language = Translator::class;

		if ($scope === 'player') {
			require MAIN_HOME . 'Public/Views/layouts/player/header.php';
			return;
		}

		if ($scope === 'player_v2') {
			require MAIN_HOME . 'Public/Views/layouts/player_v2/header.php';
			return;
		}

		if ($scope === 'reseller') {
			require MAIN_HOME . 'Public/Views/layouts/reseller/header.php';
			// header may set $rModal in local scope; propagate to $GLOBALS so the
			// footer/renderer can read it later.
			if (isset($rModal)) {
				$GLOBALS['rModal'] = $rModal;
			}
			return;
		}

		require MAIN_HOME . 'Public/Views/admin/header.php';

		// header.php sets $rModal in local scope; propagate to $GLOBALS so
		// that renderFooter() can read it later.
		if (isset($rModal)) {
			$GLOBALS['rModal'] = $rModal;
		}
	}

	/**
	 * Render the footer chrome for a scope.
	 *
	 * @param string               $scope 'admin' | 'reseller' | 'player' | 'player_v2'
	 * @param array<string, mixed> $vars  Page-scoped variables (optional)
	 */
	public static function renderFooter(string $scope = 'admin', array $vars = []): void {
		foreach ($vars as $key => $value) {
			if (!is_string($key) || $key === '') {
				continue;
			}

			// Explicit page data must win over stale globals and remain in the
			// local scope used by the footer view required below.
			$GLOBALS[$key] = $value;
			${$key} = $value;
		}

		// The footer views expect these variables in file scope.
		foreach ([
			'rUserInfo', 'rSettings', 'rMobile', 'rHues',
			'db', 'rServers', 'allServers', 'rUpdate',
			'_TITLE', 'rModal', 'rProxyServers', 'rPermissions',
			'rServerError', 'allServersHealthy', '_PAGE', '_SETUP',
			'rStreamIDs', 'rFilterBy', 'rSortArray', 'rFilterArray',
			'rSearchBy', 'rURLs', 'rSubtitles', 'rLegacy', 'rSeries',
			'rYearStart', 'rYearEnd', 'rRatingStart', 'rRatingEnd',
			'rRegisteredUsers', 'rLine',
		] as $_g) {
			if (array_key_exists($_g, $GLOBALS)) {
				$$_g = $GLOBALS[$_g];
			}
		}
		unset($_g);

		// Translator FQCN for the footer views' $language::get(...) calls.
		$language = Translator::class;

		if ($scope === 'player') {
			require MAIN_HOME . 'Public/Views/layouts/player/footer.php';
			return;
		}

		if ($scope === 'player_v2') {
			require MAIN_HOME . 'Public/Views/layouts/player_v2/footer.php';
			return;
		}

		if ($scope === 'reseller') {
			require MAIN_HOME . 'Public/Views/layouts/reseller/footer.php';
			return;
		}

		require MAIN_HOME . 'Public/Views/admin/footer.php';
	}
}
