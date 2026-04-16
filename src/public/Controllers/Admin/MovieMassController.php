<?php

/**
 * MovieMassController — массовое редактирование фильмов.
 *
 * @renders Views/admin/movie_mass.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class MovieMassController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        global $rServers;

        $rCategories = CategoryService::getAllByType('movie');

        if (isset(RequestManager::getAll()['submit_stream'])) {
            $rReturn = MovieService::massEdit(RequestManager::getAll());
            $_STATUS = $rReturn['status'];
            $GLOBALS['_STATUS'] = $_STATUS;

            if ($_STATUS == 0) {
                header('Location: ./movies_mass?status=0');
                exit();
            }
        }

        $rTranscodeProfiles = StreamConfigRepository::getTranscodeProfiles();
        $rServerTree = [
            ['id' => 'source', 'parent' => '#', 'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Active</strong>", 'icon' => 'mdi mdi-play', 'state' => ['opened' => true]],
            ['id' => 'offline', 'parent' => '#', 'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>", 'icon' => 'mdi mdi-stop', 'state' => ['opened' => true]],
        ];

        foreach ($rServers as $rServer) {
            $rServerTree[] = ['id' => $rServer['id'], 'parent' => 'offline', 'text' => $rServer['server_name'], 'icon' => 'mdi mdi-server-network', 'state' => ['opened' => true]];
        }

        $this->setTitle('Mass Edit Movies');
        $this->render('movie_mass', compact('rCategories', 'rTranscodeProfiles', 'rServerTree'));
    }
}
