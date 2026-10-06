<?php

namespace XcVm\Core\Bootstrap\Stage;

use XcVm\Core\Bootstrap\BootState;
use XcVm\Core\Bootstrap\BootStageInterface;

/**
 * Start the PHP session with secure cookie parameters. HTTP contexts only —
 * self-skips on CLI.
 *
 * @package XC_VM_Core_Bootstrap_Stage
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class SessionStage implements BootStageInterface {
	public function run(BootState $state): void {
		if ($state->sessionStarted || $state->isCli()) {
			return;
		}

		if (session_status() === PHP_SESSION_NONE) {
			self::startSession();
		}

		$state->sessionStarted = true;
	}

	/**
	 * Start the session with the panel's cookie parameters. The scope
	 * bootstraps call it too: they start the session before the framework boot.
	 */
	public static function startSession(): void {
		$rParams = session_get_cookie_params();
		$rParams['samesite'] = 'Strict';
		// The panel's scripts never read the session cookie, so an XSS must
		// not be able to either.
		$rParams['httponly'] = true;
		session_set_cookie_params($rParams);
		// Refuse session ids this server never issued, so a visitor cannot
		// arrive carrying one an attacker chose.
		ini_set('session.use_strict_mode', '1');
		session_start();
	}
}
