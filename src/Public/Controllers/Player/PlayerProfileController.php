<?php

namespace XcVm\Public\Controllers\Player;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Line\LineService;

/**
 * PlayerProfileController — player profile controller
 *
 * @package XC_VM_Public_Controllers_Player
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class PlayerProfileController extends BasePlayerController {
	public function index() {
		global $db, $rUserInfo;

		if (SettingsManager::get('player_allow_bouquet')) {
			$rBouquetNames = [];
			foreach (BouquetService::getAll() as $rBouquet) {
				if (isset($rBouquet['id'], $rBouquet['bouquet_name'])) {
					$rBouquetNames[$rBouquet['id']] = $rBouquet['bouquet_name'];
				}
			}
			// An external-server session carries a placeholder id, not a line of this panel.
			// The order is saved only from a POST: a GET (a link from another site
			// comes with the player's cookie) only shows the page.
			if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && RequestManager::has('bouquet_order') && empty($rUserInfo['is_external_xc'])) {
				$rBouquetOrder = json_decode(RequestManager::get('bouquet_order'), true);
				// The order only rearranges the bouquets stored for the line: the ones it
				// names come first, every other one stays behind them, and none is added.
				$db->query('SELECT `bouquet` FROM `lines` WHERE `id` = ?;', $rUserInfo['id']);
				$rStored = array_map('intval', json_decode((string) ($db->get_row()['bouquet'] ?? ''), true) ?: []);
				$rUserInfo['bouquet'] = array_values(array_unique(array_merge(array_intersect(array_map('intval', $rBouquetOrder), $rStored), $rStored)));
				$db->query('UPDATE `lines` SET `bouquet` = ? WHERE `id` = ?;', '[' . implode(',', $rUserInfo['bouquet']) . ']', $rUserInfo['id']);
				if (SettingsManager::get('enable_cache')) {
					LineService::updateLineSignal($rUserInfo['id']);
				}
			}
		}

		$GLOBALS['_TITLE'] = 'Profile';

		$this->render('profile', [
			'rBouquetNames' => (isset($rBouquetNames) ? $rBouquetNames : []),
		]);
	}
}
