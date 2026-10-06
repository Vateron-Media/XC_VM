<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Http\RequestManager;

/**
 * Контроллер редактирования MAG-устройства (admin/mag.php)
 *
 * @renders Views/admin/mag.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class MagController extends BaseAdminController {
	public function index() {
		$this->requirePermission();

		$rDevice = null;
		if (RequestManager::has('id')) {
			// The device and its lines as stored: the form escapes what it prints and
			// posts it back, so text read through the row cleaner ('&lt;' for '<')
			// would be saved escaped.
			$db = $GLOBALS['db'];
			$db->query('SELECT * FROM `mag_devices` WHERE `mag_id` = ?;', intval(RequestManager::get('id')));
			if ($rDevice = $db->get_raw_row()) {
				$rDevice['user'] = LineController::storedLine($rDevice['user_id']);
				$rDevice['paired'] = LineController::storedLine($rDevice['user']['pair_id'] ?? null);
			}
			if (!($rDevice['user_id'] ?? null)) {
				exit();
			}
		}

		if (isset($rDevice) && !isset($rDevice['user'])) {
			$rDevice['user'] = ['bouquet' => []];
		}

		$categoryTemplates = \XcVm\Domain\Stream\CategoryTemplateService::getTemplatesForUser(
			$GLOBALS['rAdminUserInfo'] ?? ($GLOBALS['rUserInfo'] ?? []),
			true
		);

		$this->setTitle('MAG Device');
		$this->render('mag', ['rDevice' => $rDevice, 'categoryTemplates' => $categoryTemplates]);
	}
}
