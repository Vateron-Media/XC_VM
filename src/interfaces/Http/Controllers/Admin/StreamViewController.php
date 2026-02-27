<?php
/**
 * StreamViewController — просмотр стрима (Phase 6.3 — Group A).
 */
class StreamViewController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        global $db;

        if (isset(CoreUtilities::$rRequest['id']) && ($rStream = StreamRepository::getById(CoreUtilities::$rRequest['id']))) {
        } else {
            goHome();
        }

        $rTypeString = array(1 => 'Stream', 2 => 'Movie', 3 => 'Channel', 4 => 'Station', 5 => 'Episode')[$rStream['type']];
        $rEPGData = null;
        $rImage = null;

        if ($rStream['type'] == 1) {
            $rEPGData = EpgService::getChannelEpg($rStream);

            if (0 >= $rStream['vframes_server_id']) {
            } else {
                $rExpires = time() + 3600;
                $rTokenData = array('session_id' => session_id(), 'expires' => $rExpires, 'stream_id' => intval(CoreUtilities::$rRequest['id']), 'ip' => CoreUtilities::getUserIP());
                $rUIToken = CoreUtilities::encryptData(json_encode($rTokenData), CoreUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);

                if (issecure()) {
                    $rImage = 'https://' . ((CoreUtilities::$rServers[$rStream['vframes_server_id']]['domain_name'] ? CoreUtilities::$rServers[$rStream['vframes_server_id']]['domain_name'] : CoreUtilities::$rServers[$rStream['vframes_server_id']]['server_ip'])) . ':' . intval(CoreUtilities::$rServers[$rStream['vframes_server_id']]['https_broadcast_port']) . '/admin/thumb?uitoken=' . $rUIToken;
                } else {
                    $rImage = 'http://' . ((CoreUtilities::$rServers[$rStream['vframes_server_id']]['domain_name'] ? CoreUtilities::$rServers[$rStream['vframes_server_id']]['domain_name'] : CoreUtilities::$rServers[$rStream['vframes_server_id']]['server_ip'])) . ':' . intval(CoreUtilities::$rServers[$rStream['vframes_server_id']]['http_broadcast_port']) . '/admin/thumb?uitoken=' . $rUIToken;
                }
            }

            $rAdaptiveLink = (json_decode($rStream['adaptive_link'], true) ?: array());
        } else {
            if ($rStream['type'] == 2 || $rStream['type'] == 5) {
                $rProperties = json_decode($rStream['movie_properties'], true);
                $rImage = (!empty($rProperties['backdrop_path'][0]) ? CoreUtilities::validateImage($rProperties['backdrop_path'][0], (issecure() ? 'https' : 'http')) : CoreUtilities::validateImage($rProperties['movie_image'], (issecure() ? 'https' : 'http')));

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
            'rStream', 'rTypeString', 'rEPGData', 'rImage', 'rUIToken',
            'rAdaptiveLink', 'rProperties', 'rSeries', 'rSeriesID',
            'rStreamStats', 'rCCInfo', 'rSeconds'
        ));
    }
}
