<?php

/**
 * RadioController — редактирование/добавление радиостанции.
 *
 * @renders Views/admin/radio.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class RadioController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        global $db, $rServers;

        if (isset(RequestManager::getAll()['id'])) {
            $rStation = StreamRepository::getById(RequestManager::getAll()['id']);
            if (!$rStation || $rStation['type'] != 4) {
                AdminHelpers::goHome();
            }
        }

        $rStation = null;
        $rStationOptions = null;
        $rStationSys = null;
        $rOnDemand = array();
        $rStationArguments = StreamConfigRepository::getStreamArguments();
        $rServerTree = array(
            array(
                'id' => 'source',
                'parent' => '#',
                'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Online</strong>",
                'icon' => 'mdi mdi-play',
                'state' => array('opened' => true)
            ),
            array(
                'id' => 'offline',
                'parent' => '#',
                'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>",
                'icon' => 'mdi mdi-stop',
                'state' => array('opened' => true)
            )
        );

        if (isset($rStation)) {
            $rStationOptions = StreamRepository::getOptions(RequestManager::getAll()['id']);
            $rStationSys = StreamRepository::getSystemRows(RequestManager::getAll()['id']);

            foreach ($rServers as $rServer) {
                if (isset($rStationSys[intval($rServer['id'])])) {
                    $rParent = ($rStationSys[intval($rServer['id'])]['parent_id'] != 0) ? intval($rStationSys[intval($rServer['id'])]['parent_id']) : 'source';
                    if ($rStationSys[intval($rServer['id'])]['on_demand']) {
                        $rOnDemand[] = intval($rServer['id']);
                    }
                } else {
                    $rParent = 'offline';
                }

                $rServerTree[] = array(
                    'id' => $rServer['id'],
                    'parent' => $rParent,
                    'text' => $rServer['server_name'],
                    'icon' => 'mdi mdi-server-network',
                    'state' => array('opened' => true)
                );
            }
        } else {
            foreach ($rServers as $rServer) {
                $rServerTree[] = array(
                    'id' => $rServer['id'],
                    'parent' => 'offline',
                    'text' => $rServer['server_name'],
                    'icon' => 'mdi mdi-server-network',
                    'state' => array('opened' => true)
                );
            }
        }

        $this->setTitle('Radio Stations');
        $this->render('radio', compact(
            'rStation',
            'rOnDemand',
            'rStationArguments',
            'rServerTree',
            'rStationOptions',
            'rStationSys'
        ));
    }
}
