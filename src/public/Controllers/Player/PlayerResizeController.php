<?php
/**
 * PlayerResizeController — Image resize proxy for player panel.
 *
 * Migrated from player/resize.php.
 * Resizes remote/local images and caches the result as PNG.
 * Requires authenticated player session (bootstrap handles this).
 *
 * @package XC_VM_Public_Controllers_Player
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class PlayerResizeController extends BasePlayerController
{
	public function index()
	{
		session_write_close();

		if (!isset($GLOBALS['rUserInfo']) || !$GLOBALS['rUserInfo']['id']) {
			exit();
		}

		if (!defined('IMAGES_PATH')) {
			define('IMAGES_PATH', MAIN_HOME . 'public/assets/player/images/thumbs/');
		}
		$rResizeCacheDir = IMAGES_PATH;
		$rResizePlaceholder = MAIN_HOME . 'public/assets/player/images/placeholder.png';
		$rResizeSupportExtras = true;
		require MAIN_HOME . 'infrastructure/legacy/resize_body.php';
	}
}
