<?php

namespace XcVm\Public\Controllers\Player;

use XcVm\Core\Auth\SessionManager;

/**
 * PlayerLogoutController — player logout controller
 *
 * @package XC_VM_Public_Controllers_Player
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class PlayerLogoutController extends BasePlayerController {
	public function index() {
		// Signing out runs only on a POST: the player's cookie also comes with a
		// link from another site (a GET), which leads home instead. Not to the
		// sign-in form: that ends the sign-in it finds.
		if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
			header('Location: index');
			exit();
		}

		SessionManager::clearContext('player');
		header('Location: login');
		exit();
	}
}
