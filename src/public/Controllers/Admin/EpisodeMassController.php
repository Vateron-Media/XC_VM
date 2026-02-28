<?php
/**
 * EpisodeMassController — массовое редактирование эпизодов (Phase 6.3 — Group B).
 */
class EpisodeMassController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        global $rServers;

        $rSeries = getSeries();
        $rServerTree = [
            ['id' => 'source', 'parent' => '#', 'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Active</strong>", 'icon' => 'mdi mdi-play', 'state' => ['opened' => true]],
            ['id' => 'offline', 'parent' => '#', 'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>", 'icon' => 'mdi mdi-stop', 'state' => ['opened' => true]],
        ];

        foreach ($rServers as $rServer) {
            $rServerTree[] = ['id' => $rServer['id'], 'parent' => 'offline', 'text' => $rServer['server_name'], 'icon' => 'mdi mdi-server-network', 'state' => ['opened' => true]];
        }

        $this->setTitle('Mass Edit Episodes');
        $this->render('episodes_mass', compact('rSeries', 'rServerTree'));
    }
}
