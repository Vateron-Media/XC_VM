<?php

/**
 * StreamViewController — просмотр стрима.
 *
 * @renders Views/admin/stream_view.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class StreamViewController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        global $db;

        if (isset(RequestManager::getAll()['id']) && ($rStream = StreamRepository::getById(RequestManager::getAll()['id']))) {
        } else {
            AdminHelpers::goHome();
        }

        $rTypeString = array(1 => 'Stream', 2 => 'Movie', 3 => 'Channel', 4 => 'Station', 5 => 'Episode')[$rStream['type']];
        $rEPGData = null;
        $rImage = null;
        $rUIToken = null;
        $rAdaptiveLink = null;
        $rProperties = null;
        $rCCInfo = null;
        $rSeconds = null;
        $rSeries = null;
        $rSeriesID = null;

        if ($rStream['type'] == 1) {
            $rEPGData = EpgService::getChannelEpg($rStream);

            if (0 >= $rStream['vframes_server_id']) {
            } else {
                $rExpires = time() + 3600;
                $rTokenData = array('session_id' => session_id(), 'expires' => $rExpires, 'stream_id' => intval(RequestManager::getAll()['id']), 'ip' => NetworkUtils::getUserIP());
                $rUIToken = Encryption::encrypt(json_encode($rTokenData), SettingsManager::getAll()['live_streaming_pass'], OPENSSL_EXTRA);

                if (AdminHelpers::issecure()) {
                    $rVServer = ServerRepository::getAll()[$rStream['vframes_server_id']];
                    $rImage = 'https://' . (($rVServer['domain_name'] ? $rVServer['domain_name'] : $rVServer['server_ip'])) . ':' . intval($rVServer['https_broadcast_port']) . '/admin/thumb?uitoken=' . $rUIToken;
                } else {
                    $rVServer = ServerRepository::getAll()[$rStream['vframes_server_id']];
                    $rImage = 'http://' . (($rVServer['domain_name'] ? $rVServer['domain_name'] : $rVServer['server_ip'])) . ':' . intval($rVServer['http_broadcast_port']) . '/admin/thumb?uitoken=' . $rUIToken;
                }
            }

            $rAdaptiveLink = (json_decode($rStream['adaptive_link'], true) ?: array());
        } else {
            if ($rStream['type'] == 2 || $rStream['type'] == 5) {
                $rProperties = json_decode($rStream['movie_properties'], true);
                $rImage = (!empty($rProperties['backdrop_path'][0]) ? ImageUtils::validateURL($rProperties['backdrop_path'][0], (AdminHelpers::issecure() ? 'https' : 'http')) : ImageUtils::validateURL($rProperties['movie_image'], (AdminHelpers::issecure() ? 'https' : 'http')));

                if (empty($rImage)) {
                } else {
                    if (@getimagesize($rImage)) {
                    } else {
                        $rImage = null;
                    }
                }
            } else {
                if ($rStream['type'] != 3) {
                } else {
                    $rCCInfo = null;
                    $db->query('SELECT `streams_servers`.`stream_started`, `streams_servers`.`cc_info` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` AND `streams_servers`.`parent_id` IS NULL WHERE `streams`.`id` = ? GROUP BY `streams`.`id`;', $rStream['id']);

                    if (0 >= $db->num_rows()) {
                    } else {
                        $rServerRow = $db->get_row();
                        $rCCInfo = json_decode($rServerRow['cc_info'], true);
                        $rSeconds = time() - intval($rServerRow['stream_started']);
                    }
                }
            }
        }

        if ($rStream['type'] != 5) {
        } else {
            $rSeries = null;
            $db->query('SELECT * FROM `streams_series` WHERE `id` = (SELECT `series_id` FROM `streams_episodes` WHERE `stream_id` = ?);', $rStream['id']);

            if (0 >= $db->num_rows()) {
            } else {
                $rSeries = $db->get_row();
            }

            $rSeriesID = $rSeries['id'];
        }

        $rStreamStats = StreamRepository::getStats($rStream['id']);

        $this->setTitle('View ' . $rTypeString);
        $this->render('stream_view', compact(
            'rStream',
            'rTypeString',
            'rEPGData',
            'rImage',
            'rUIToken',
            'rAdaptiveLink',
            'rProperties',
            'rSeries',
            'rSeriesID',
            'rStreamStats',
            'rCCInfo',
            'rSeconds'
        ));
    }
}
