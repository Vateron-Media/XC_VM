<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Stream\StreamRepository;
use XcVm\Domain\Vod\SeriesService;

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
        if (!empty(RequestManager::get('id')) && empty(RequestManager::get('sid'))) {
            $db->query('SELECT `series_id` FROM `streams_episodes` WHERE `stream_id` = ?;', intval(RequestManager::get('id')));
            if ($db->num_rows() > 0) {
                RequestManager::update('sid', intval($db->get_row()['series_id']));
            }
        }

        if (!($rSeriesArr = SeriesService::getById(RequestManager::get('sid') ?? null))) {
            $this->redirect('series');
            return;
        }

        $rEpisode = null;
        $rStreamSys = [];

        if (RequestManager::has('id')) {
            $rEpisode = StreamRepository::getById(RequestManager::get('id'));
            if (!$rEpisode || $rEpisode['type'] != 5) {
                $this->redirect('episodes');
                return;
            }
        }

        $rServerTree = [
            ['id' => 'source', 'parent' => '#', 'text' => "<span class='badge bg-success'>Active</span>", 'icon' => 'icon-base ti tabler-player-play', 'state' => ['opened' => true]],
            ['id' => 'offline', 'parent' => '#', 'text' => "<span class='badge bg-secondary'>Offline</span>", 'icon' => 'icon-base ti tabler-player-stop', 'state' => ['opened' => true]],
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
            $rStreamSys = StreamRepository::getSystemRows(RequestManager::get('id'));

            foreach ($rServers as $rServer) {
                $rParent = isset($rStreamSys[intval($rServer['id'])]) ? 'source' : 'offline';
                $rServerTree[] = ['id' => $rServer['id'], 'parent' => $rParent, 'text' => $rServer['server_name'], 'icon' => 'icon-base ti tabler-server', 'state' => ['opened' => true]];
            }
        } else {
            if (!Authorization::check('adv', 'add_episode')) {
                exit();
            }
            foreach ($rServers as $rServer) {
                $rServerTree[] = ['id' => $rServer['id'], 'parent' => 'offline', 'text' => $rServer['server_name'], 'icon' => 'icon-base ti tabler-server', 'state' => ['opened' => true]];
            }
            if (RequestManager::has('multi') && Authorization::check('adv', 'import_episodes')) {
                $rMulti = true;
            }
        }

        // The load-balancer server tree on this form is driven by jstree.
        $GLOBALS['xmNewuiVendors'] = array_values(array_unique(array_merge(
            (array) ($GLOBALS['xmNewuiVendors'] ?? []),
            ['jstree']
        )));

        $this->setTitle('Episode');
        $this->render('episode', compact('rSeriesArr', 'rEpisode', 'rServerTree', 'rStreamSys', 'rMulti'));
    }
}
