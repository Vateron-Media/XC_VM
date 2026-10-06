<?php

namespace XcVm\Public\Controllers\Reseller;

use XcVm\Core\Auth\Authenticator;
use XcVm\Core\Auth\TwoFactor;
use XcVm\Core\Config\Maintenance;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Localization\Translator;
use XcVm\Core\Util\NetworkUtils;
use XcVm\Domain\Security\BlocklistService;
use XcVm\Domain\User\ResellerAPI;

/**
 * ResellerLoginController — Login page for reseller panel.
 *
 * Migrated from reseller/login.php.
 * This controller handles its own bootstrap since the login page
 * must work without an active session.
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ResellerLoginController {
	public function index() {
		// Bootstrap (login page is in noBootstrapPages, so FC skips bootstrap)
		require_once MAIN_HOME . 'bootstrap.php';
		\XC_Bootstrap::boot(\XC_Bootstrap::CONTEXT_ADMIN);

		// Already logged in → dashboard
		if (isset($_SESSION['reseller'])) {
			header('Location: dashboard');
			exit();
		}

		$rIP = NetworkUtils::getUserIP();

		// Flood protection
		$rSettings = SettingsManager::getAll();
		global $db;

		// Translator FQCN for reseller/login.php's `$language::get(...)` calls.
        // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- consumed by required view reseller/login.php
		$language = Translator::class;

		if (Authenticator::loginFloodExceeded($rIP, intval($rSettings['login_flood']))) {
			BlocklistService::blockIP(['ip' => $rIP, 'notes' => 'LOGIN FLOOD ATTACK']);
			exit();
		}

		// Maintenance mode: no sign-in, a second factor's code included; the page says why.
		// phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- consumed by required view reseller/login.php
		$rMaintenance = Maintenance::active($rSettings) ? Maintenance::message($rSettings) : null;

		// Process login POST; a sign-in held for its second factor (TwoFactor)
		// takes its code here too.
		$_STATUS = null;
		$rContinue = AdminHelpers::loginRedirectTarget(RequestManager::get('referrer'));
		$rReferrer = (string) (RequestManager::get('referrer') ?? '');
		$rTwoFactorStatus = null;
		$rRecovery = null;
		if (RequestManager::has('cancel_2fa')) {
			TwoFactor::cancel();
		}
		if ($rMaintenance === null && RequestManager::has('verify_2fa')) {
			$rConfirm = TwoFactor::confirm('reseller', (string) RequestManager::get('twofactor_code'));
			if ($rConfirm['status'] !== STATUS_SUCCESS) {
				$rTwoFactorStatus = $rConfirm['status'];
			} elseif (!empty($rConfirm['recovery'])) {
				$rRecovery = $rConfirm['recovery'];
			} else {
				header('Location: ' . $rContinue);
				exit();
			}
		} elseif ($rMaintenance === null && RequestManager::has('login')) {
			$rReturn = ResellerAPI::processLogin(RequestManager::getAll());
			$_STATUS = $rReturn['status'];

			if ($_STATUS === STATUS_SUCCESS) {
				header('Location: ' . $rContinue);
				exit();
			}
		}
		// phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- consumed by required view reseller/login.php
		$rTwoFactor = $rRecovery === null ? TwoFactor::pending('reseller') : null;

		// Render login view
		$__viewFile = MAIN_HOME . 'Public/Views/reseller/login.php';
        // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- consumed by required view reseller/login.php
		$referrer = htmlspecialchars(RequestManager::get('referrer') ?? '');

		if (file_exists($__viewFile)) {
			require $__viewFile;
		} else {
			http_response_code(500);
			echo 'Login view not found';
		}
	}
}
