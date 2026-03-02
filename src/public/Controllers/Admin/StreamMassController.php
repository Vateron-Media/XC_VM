<?php
/**
 * StreamMassController — массовое редактирование стримов (Phase 6.3 — Group A).
 */
class StreamMassController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        global $rServers;

        $rCategories = getCategories('live');
        $rStreamArguments = StreamConfigRepository::getStreamArguments();
        $rTranscodeProfiles = StreamConfigRepository::getTranscodeProfiles();

        $rServerTree = [
            ['id' => 'source', 'parent' => '#', 'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Online</strong>", 'icon' => 'mdi mdi-play', 'state' => ['opened' => true]],
            ['id' => 'offline', 'parent' => '#', 'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>", 'icon' => 'mdi mdi-stop', 'state' => ['opened' => true]],
        ];

        foreach ($rServers as $rServer) {
            $rServerTree[] = ['id' => intval($rServer['id']), 'parent' => 'offline', 'text' => htmlspecialchars($rServer['server_name']), 'icon' => 'mdi mdi-server-network', 'state' => ['opened' => true]];
        }

        $this->setTitle('Mass Edit Streams');
        $this->render('stream_mass', compact('rCategories', 'rStreamArguments', 'rTranscodeProfiles', 'rServerTree'));
    }
}
