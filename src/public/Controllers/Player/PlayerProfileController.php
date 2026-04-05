<?php

/**
 * PlayerProfileController — player profile controller
 *
 * @package XC_VM_Public_Controllers_Player
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class PlayerProfileController extends BasePlayerController
{
    public function index()
    {
        global $db, $rUserInfo;

        if (!SettingsManager::getAll()['player_allow_bouquet']) {
        } else {
            $rBouquetNames = array();

            foreach (BouquetService::getAll() as $rBouquet) {
                if (isset($rBouquet['id'], $rBouquet['bouquet_name'])) {
                    $rBouquetNames[$rBouquet['id']] = $rBouquet['bouquet_name'];
                }
            }

            if (!isset(RequestManager::getAll()['bouquet_order'])) {
            } else {
                $rBouquetOrder = json_decode(RequestManager::getAll()['bouquet_order'], true);
                $rUserInfo['bouquet'] = array_map('intval', sortArrayByArray($rUserInfo['bouquet'], $rBouquetOrder));
                $db->query('UPDATE `lines` SET `bouquet` = ? WHERE `id` = ?;', '[' . implode(',', $rUserInfo['bouquet']) . ']', $rUserInfo['id']);

                if (!SettingsManager::getAll()['enable_cache']) {
                } else {
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
