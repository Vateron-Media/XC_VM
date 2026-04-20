<?php

/**
 * AdminResizeController — Image resize proxy for admin panel.
 *
 * Migrated from public/Views/admin/resize.php.
 * Resizes remote/local images and caches the result as PNG.
 * Requires authenticated admin session (bootstrap handles this).
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class AdminResizeController extends BaseAdminController {
	public function index() {
		session_write_close();

		if (!isset($GLOBALS['rUserInfo']) || !$GLOBALS['rUserInfo']['id']) {
			exit();
		}

		ImageResizeService::serve(['cacheDir' => IMAGES_PATH . 'admin/']);
	}
}
