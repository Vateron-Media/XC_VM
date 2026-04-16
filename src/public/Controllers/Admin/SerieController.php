<?php

/**
 * SerieController — редактирование/добавление сериала.
 *
 * @renders Views/admin/serie.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class SerieController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        global $rServers;

        $rSeriesArr = null;
        if (isset(RequestManager::getAll()['id']) && !($rSeriesArr = SeriesService::getById(RequestManager::getAll()['id']))) {
            $this->redirect('series');
            return;
        }

        if (isset($rSeriesArr) && isset(RequestManager::getAll()['import'])) {
            unset(RequestManager::getAll()['import']);
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
