<?php

class PlayerProfileController extends BasePlayerController
{
    public function index()
    {
        global $db, $rUserInfo;

        if (!SettingsManager::getAll()['player_allow_bouquet']) {
        } else {
            $rBouquetNames = array();

            foreach (BouquetService::getAll() as $rBouquet) {
                $rBouquetNames[$rBouquet['id']] = $rBouquet['bouquet_name'];
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
