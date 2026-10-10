<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Auth\BruteforceGuard;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;

/**
 * MagscanSettingsController — MAGSCAN Settings (admin/magscan_settings.php).
 *
 * GET /magscan_settings
 * 3-tab form for MAC whitelist/blacklist and IP whitelist.
 *
 * @renders Views/admin/magscan_settings.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class MagscanSettingsController extends BaseAdminController {
	public function index(): void {
		$this->requirePermission();

		global $db;

		// The three lists, kept in the `magscan_settings` setting and read by
		// the guard that counts MAC guesses (BruteforceGuard::magscanVerdict()).
		// The page posted them to a route that took no POST, and they were
		// saved nowhere.
		if (RequestManager::has('submit_magscan')) {
			$db->query('UPDATE `settings` SET `magscan_settings` = ?;', json_encode(BruteforceGuard::magscanClean(RequestManager::getAll())));
			SettingsManager::clearCache();
			$this->redirect('./magscan_settings?status=' . STATUS_SUCCESS);
		}

		$this->setTitle('MAGSCAN Settings');
		$this->render('magscan_settings', ['rLists' => BruteforceGuard::magscanLists(SettingsManager::getAll())]);
	}
}
