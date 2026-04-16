<?php

/**
 * StreamController — редактирование/добавление стрима.
 *
 * @renders Views/admin/stream.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class StreamController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        global $db, $rServers;

        $rStream = null;

        if (isset(RequestManager::getAll()['id'])) {
            if (!isset(RequestManager::getAll()['import']) && Authorization::check('adv', 'edit_stream')) {
                $rStream = StreamRepository::getById(RequestManager::getAll()['id']);
                if (!$rStream || $rStream['type'] != 1) {
                    $this->redirect('streams');
                    return;
                }
            } else {
                exit();
            }
        }

        $rEPGSources = EpgService::getAll();
        $rStreamArguments = StreamConfigRepository::getStreamArguments();
        $rTranscodeProfiles = StreamConfigRepository::getTranscodeProfiles();
        $rOnDemand = [];
        $rStreamOptions = null;
        $rStreamSys = null;
        $rEPGJS = [[]];

        foreach ($rEPGSources as $rEPG) {
            $rEPGJS[$rEPG['id']] = json_decode($rEPG['data'], true);
        }

        $rServerTree = [
            ['id' => 'source', 'parent' => '#', 'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Online</strong>", 'icon' => 'mdi mdi-play', 'state' => ['opened' => true]],
            ['id' => 'offline', 'parent' => '#', 'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>", 'icon' => 'mdi mdi-stop', 'state' => ['opened' => true]],
        ];

        $rAudioDevices = $rVideoDevices = [];
        foreach ($rServers as $rServer) {
            $rVideoDevices[$rServer['id']] = $rServer['video_devices'];
            $rAudioDevices[$rServer['id']] = $rServer['audio_devices'];
        }

        if (isset($rStream)) {
            $rStreamOptions = StreamRepository::getOptions(RequestManager::getAll()['id']);
            $rStreamSys = StreamRepository::getSystemRows(RequestManager::getAll()['id']);

            foreach ($rServers as $rServer) {
                if (isset($rStreamSys[intval($rServer['id'])])) {
                    $rParent = $rStreamSys[intval($rServer['id'])]['parent_id'] != 0
                        ? intval($rStreamSys[intval($rServer['id'])]['parent_id'])
                        : 'source';

                    if ($rStreamSys[intval($rServer['id'])]['on_demand']) {
                        $rOnDemand[] = intval($rServer['id']);
                    }
                } else {
                    $rParent = 'offline';
                }
                $rServerTree[] = ['id' => $rServer['id'], 'parent' => $rParent, 'text' => $rServer['server_name'], 'icon' => 'mdi mdi-server-network', 'state' => ['opened' => true]];
            }
        } else {
            if (Authorization::check('adv', 'add_stream')) {
                foreach ($rServers as $rServer) {
                    $rServerTree[] = ['id' => $rServer['id'], 'parent' => 'offline', 'text' => $rServer['server_name'], 'icon' => 'mdi mdi-server-network', 'state' => ['opened' => true]];
                }
            } else {
                exit();
            }
        }

        $this->setTitle('Stream');
        $this->render('stream', compact(
            'rStream',
            'rEPGSources',
            'rStreamArguments',
            'rTranscodeProfiles',
            'rOnDemand',
            'rEPGJS',
            'rServerTree',
            'rAudioDevices',
            'rVideoDevices',
            'rStreamOptions',
            'rStreamSys'
        ));
    }
}
