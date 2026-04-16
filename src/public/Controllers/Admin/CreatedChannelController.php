<?php

/**
 * CreatedChannelController — редактирование/добавление канала.
 *
 * @renders Views/admin/created_channel.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class CreatedChannelController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        global $db, $rServers;

        $rCategories = CategoryService::getAllByType('live');
        $rTranscodeProfiles = StreamConfigRepository::getTranscodeProfiles();

        if (!isset(RequestManager::getAll()['id'])) {
            $rChannel = null;
        } else {
            $rChannel = StreamRepository::getById(RequestManager::getAll()['id']);

            if (!$rChannel || $rChannel['type'] != 3) {
                AdminHelpers::goHome();
            }
        }

        $rOnDemand = [];
        $rProperties = null;
        $rChannelSys = null;
        $rServerTree = [
            [
                'id' => 'source',
                'parent' => '#',
                'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Online</strong>",
                'icon' => 'mdi mdi-play',
                'state' => ['opened' => true]
            ],
            [
                'id' => 'offline',
                'parent' => '#',
                'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>",
                'icon' => 'mdi mdi-stop',
                'state' => ['opened' => true]
            ]
        ];

        if (isset($rChannel)) {
            $rProperties = json_decode($rChannel['movie_properties'], true);

            if (!$rProperties) {
                $rProperties = ['type' => $rChannel['series_no'] > 0 ? 0 : 1];
            }

            $rChannelSys = StreamRepository::getSystemRows(RequestManager::getAll()['id']);

            foreach ($rServers as $rServer) {
                if (isset($rChannelSys[intval($rServer['id'])])) {
                    $rParent = $rChannelSys[intval($rServer['id'])]['parent_id'] != 0
                        ? intval($rChannelSys[intval($rServer['id'])]['parent_id'])
                        : (!$rChannelSys[intval($rServer['id'])]['on_demand'] ? 'source' : null);
                } else {
                    $rParent = 'offline';
                }

                if ($rParent !== null) {
                    $rServerTree[] = [
                        'id' => $rServer['id'],
                        'parent' => $rParent,
                        'text' => $rServer['server_name'],
                        'icon' => 'mdi mdi-server-network',
                        'state' => ['opened' => true]
                    ];
                }
            }
        } else {
            foreach ($rServers as $rServer) {
                $rServerTree[] = [
                    'id' => $rServer['id'],
                    'parent' => 'offline',
                    'text' => $rServer['server_name'],
                    'icon' => 'mdi mdi-server-network',
                    'state' => ['opened' => true]
                ];
            }
        }

        $this->setTitle('Created Channel');
        $this->render('created_channel', compact(
            'rCategories',
            'rTranscodeProfiles',
            'rChannel',
            'rOnDemand',
            'rServerTree',
            'rProperties',
            'rChannelSys'
        ));
    }
}
