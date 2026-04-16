<?php

/**
 * EpisodeController — редактирование/добавление эпизода.
 *
 * @renders Views/admin/episode.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class EpisodeController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        global $db, $rServers;

        // Resolve series_id from episode if not provided
        if (!empty(RequestManager::getAll()['id']) && empty(RequestManager::getAll()['sid'])) {
            $db->query('SELECT `series_id` FROM `streams_episodes` WHERE `stream_id` = ?;', intval(RequestManager::getAll()['id']));
            if ($db->num_rows() > 0) {
                RequestManager::update('sid', intval($db->get_row()['series_id']));
            }
        }

        if (!($rSeriesArr = SeriesService::getById(RequestManager::getAll()['sid'] ?? null))) {
            $this->redirect('series');
            return;
        }

        if (isset(RequestManager::getAll()['id'])) {
            $rEpisode = StreamRepository::getById(RequestManager::getAll()['id']);
            if (!$rEpisode || $rEpisode['type'] != 5) {
                $this->redirect('episodes');
                return;
            }
        }

        $rServerTree = [
            ['id' => 'source', 'parent' => '#', 'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Active</strong>", 'icon' => 'mdi mdi-play', 'state' => ['opened' => true]],
            ['id' => 'offline', 'parent' => '#', 'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>", 'icon' => 'mdi mdi-stop', 'state' => ['opened' => true]],
        ];
        $rMulti = false;

        if (isset($rEpisode)) {
            $db->query('SELECT `season_num`, `episode_num` FROM `streams_episodes` WHERE `stream_id` = ?;', $rEpisode['id']);
            if ($db->num_rows() > 0) {
                $rRow = $db->get_row();
                $rEpisode['episode'] = intval($rRow['episode_num']);
                $rEpisode['season'] = intval($rRow['season_num']);
            } else {
                $rEpisode['episode'] = 0;
                $rEpisode['season'] = 0;
            }

            $rEpisode['properties'] = json_decode($rEpisode['movie_properties'], true);
            $rStreamSys = StreamRepository::getSystemRows(RequestManager::getAll()['id']);

            foreach ($rServers as $rServer) {
                $rParent = isset($rStreamSys[intval($rServer['id'])]) ? 'source' : 'offline';
                $rServerTree[] = ['id' => $rServer['id'], 'parent' => $rParent, 'text' => $rServer['server_name'], 'icon' => 'mdi mdi-server-network', 'state' => ['opened' => true]];
            }
        } else {
            if (!Authorization::check('adv', 'add_episode')) {
                exit();
            }
            foreach ($rServers as $rServer) {
                $rServerTree[] = ['id' => $rServer['id'], 'parent' => 'offline', 'text' => $rServer['server_name'], 'icon' => 'mdi mdi-server-network', 'state' => ['opened' => true]];
            }
            if (isset(RequestManager::getAll()['multi']) && Authorization::check('adv', 'import_episodes')) {
                $rMulti = true;
            }
        }

        $this->setTitle('Episode');
        $this->render('episode', compact('rSeriesArr', 'rEpisode', 'rServerTree', 'rStreamSys', 'rMulti'));
    }
}
