<?php

namespace XcVm\Public\Controllers\Player;

use XcVm\Core\Util\ImageResizeService;

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
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_write_close();
		}

		if (!defined('IMAGES_PATH')) {
			define('IMAGES_PATH', (defined('MAIN_HOME') ? MAIN_HOME : '/home/xc_vm/') . 'Public/assets/player/images/thumbs/');
		}

		$placeholder = (defined('MAIN_HOME') ? MAIN_HOME : '/home/xc_vm/') . 'Public/assets/player/images/placeholder.png';

		ImageResizeService::serve([
			'cacheDir'    => IMAGES_PATH,
			'placeholder' => file_exists($placeholder) ? $placeholder : null,
			'extraParams' => true,
		]);
	}
}
