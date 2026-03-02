<?php
/**
 * StreamController — редактирование/добавление стрима (Phase 6.3 — Group A).
 */
class StreamController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        global $db, $rServers;

        if (isset(CoreUtilities::$rRequest['id'])) {
            if (!isset(CoreUtilities::$rRequest['import']) && Authorization::check('adv', 'edit_stream')) {
                $rStream = StreamRepository::getById(CoreUtilities::$rRequest['id']);
                if (!$rStream || $rStream['type'] != 1) {
                    $this->redirect('streams');
                    return;
                }
            } else {
                exit();
            }
        }

        $rEPGSources = getEPGSources();
        $rStreamArguments = StreamConfigRepository::getStreamArguments();
        $rTranscodeProfiles = StreamConfigRepository::getTranscodeProfiles();
        $rOnDemand = [];
        $rStream = null;
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
            $rStreamOptions = StreamRepository::getOptions(CoreUtilities::$rRequest['id']);
            $rStreamSys = StreamRepository::getSystemRows(CoreUtilities::$rRequest['id']);

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
            'rStream', 'rEPGSources', 'rStreamArguments', 'rTranscodeProfiles',
            'rOnDemand', 'rEPGJS', 'rServerTree', 'rAudioDevices', 'rVideoDevices',
            'rStreamOptions', 'rStreamSys'
        ));
    }
}
