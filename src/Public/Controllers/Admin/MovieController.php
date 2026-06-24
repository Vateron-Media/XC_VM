<?php

/**
 * MovieController — редактирование/добавление фильма.
 *
 * @renders Views/admin/movie.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class MovieController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        global $db, $rServers;

        $rCategories = CategoryService::getAllByType('movie');
        $rTranscodeProfiles = StreamConfigRepository::getTranscodeProfiles();

        if (isset(RequestManager::getAll()['id'])) {
            $rMovie = StreamRepository::getById(RequestManager::getAll()['id']);
            if (!$rMovie || $rMovie['type'] != 2) {
                $this->redirect('movies');
                return;
            }
        }

        $rServerTree = [
            ['id' => 'source', 'parent' => '#', 'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Active</strong>", 'icon' => 'mdi mdi-play', 'state' => ['opened' => true]],
            ['id' => 'offline', 'parent' => '#', 'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>", 'icon' => 'mdi mdi-stop', 'state' => ['opened' => true]],
        ];
        $activeStreamingServers = [];
        $rMovie = null;
        $rStreamSys = [];
        $rMovieSource = [''];
        $rSource = '';
        $rPathSources = '';

        if (isset(RequestManager::getAll()['id'])) {
            $rMovie = StreamRepository::getById(RequestManager::getAll()['id']);
            if (!$rMovie || $rMovie['type'] != 2) {
                $this->redirect('movies');
                return;
            }
            $rMovie['properties'] = json_decode($rMovie['movie_properties'], true);
            $rStreamSys = StreamRepository::getSystemRows(RequestManager::getAll()['id']);

            $streamSourceJson = $rMovie['stream_source'] ?? '';
            $rMovieSource = json_decode($streamSourceJson, true);
            if (!is_array($rMovieSource)) {
                $rMovieSource = [''];
            }
            $rSource = $rMovieSource[0] ?? '';
            if (str_starts_with($rSource, 's:')) {
                $parts = explode(':', $rSource, 3);
                $rPathSources = (count($parts) >= 3) ? urldecode($parts[2]) : '';
            } else {
                $rPathSources = $rSource;
            }

            foreach ($rServers as $rServer) {
                if (($rServer['direct_source'] ?? 0) == 0 && ($rServer['stream_status'] ?? 0) == 1) {
                    $activeStreamingServers[] = intval($rServer['id']);
                }
                if (isset($rStreamSys[intval($rServer['id'])])) {
                    $rParent = 'source';
                } else {
                    $rParent = 'offline';
                }
                $rServerTree[] = ['id' => $rServer['id'], 'parent' => $rParent, 'text' => $rServer['server_name'], 'icon' => 'mdi mdi-server-network', 'state' => ['opened' => true]];
            }
        } else {
            foreach ($rServers as $rServer) {
                $rServerTree[] = ['id' => $rServer['id'], 'parent' => 'offline', 'text' => $rServer['server_name'], 'icon' => 'mdi mdi-server-network', 'state' => ['opened' => true]];
            }
        }

        $this->setTitle('Movie');
        $this->render('movie', compact(
            'rCategories',
            'rTranscodeProfiles',
            'rMovie',
            'rServerTree',
            'activeStreamingServers',
            'rStreamSys',
            'rMovieSource',
            'rSource',
            'rPathSources'
        ));
    }
}
