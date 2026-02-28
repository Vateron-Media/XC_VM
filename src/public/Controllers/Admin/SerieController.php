<?php
/**
 * SerieController — редактирование/добавление сериала (Phase 6.3 — Group B).
 */
class SerieController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        global $rServers;

        $rSeriesArr = null;
        if (isset(CoreUtilities::$rRequest['id']) && !($rSeriesArr = getSerie(CoreUtilities::$rRequest['id']))) {
            $this->redirect('series');
            return;
        }

        if (isset($rSeriesArr) && isset(CoreUtilities::$rRequest['import'])) {
            unset(CoreUtilities::$rRequest['import']);
        }

        $rTranscodeProfiles = StreamConfigRepository::getTranscodeProfiles();

        $rServerTree = [
            ['id' => 'source', 'parent' => '#', 'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Active</strong>", 'icon' => 'mdi mdi-play', 'state' => ['opened' => true]],
            ['id' => 'offline', 'parent' => '#', 'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>", 'icon' => 'mdi mdi-stop', 'state' => ['opened' => true]],
        ];

        foreach ($rServers as $rServer) {
            $rServerTree[] = ['id' => $rServer['id'], 'parent' => 'offline', 'text' => $rServer['server_name'], 'icon' => 'mdi mdi-server-network', 'state' => ['opened' => true]];
        }

        $this->setTitle('TV Series');
        $this->render('serie', compact('rSeriesArr', 'rTranscodeProfiles', 'rServerTree'));
    }
}
