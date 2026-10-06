<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Http\RequestManager;

/**
 * StreamCategoryController — редактирование категории стрима.
 *
 * @renders Views/admin/stream_category.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class StreamCategoryController extends BaseAdminController {
	public function index() {
		$this->requirePermission();
		$rCategoryArr = null;

		if (RequestManager::has('id')) {
			// The category as stored: the form escapes what it prints and posts it back,
			// so text read through the row cleaner ('&lt;' for '<') would be saved escaped.
			$db = $GLOBALS['db'];
			$db->query('SELECT * FROM `streams_categories` WHERE `id` = ?;', intval(RequestManager::get('id')));
			$rCategoryArr = $db->get_raw_row();
			if (!$rCategoryArr || !Authorization::check('adv', 'edit_cat')) {
				exit();
			}
		}

		$this->setTitle('Stream Category');
		$this->render('stream_category', ['rCategoryArr' => $rCategoryArr]);
	}
}
