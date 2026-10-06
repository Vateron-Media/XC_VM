<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Http\RequestManager;

/**
 * Контроллер редактирования EPG (admin/epg.php)
 *
 * @renders Views/admin/epg.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class EpgController extends BaseAdminController {
	public function index() {
		$this->requirePermission();

		$rEPGArr = null;
		if (RequestManager::has('id')) {
			// The source as stored: the form escapes what it prints and posts it back,
			// so text read through the row cleaner ('&lt;' for '<') would be saved escaped.
			$db = $GLOBALS['db'];
			$db->query('SELECT * FROM `epg` WHERE `id` = ?;', intval(RequestManager::get('id')));
			$rEPGArr = $db->get_raw_row();
			if (!$rEPGArr) {
				exit();
			}
		}

		$this->setTitle('EPG');
		$this->render('epg', ['rEPGArr' => $rEPGArr]);
	}
}
