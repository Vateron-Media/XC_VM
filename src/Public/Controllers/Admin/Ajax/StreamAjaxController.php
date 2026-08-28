<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\ApiClient;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\Stream\StreamRepository;
use XcVm\Domain\Vod\SeriesService;

/**
 * Admin-ajax controller for the "Streams & VOD" content group.
 *
 * Extracted from the legacy `admin/api.php`. Actions: stream, movie, episode,
 * series. Block logic ported faithfully (scaffolding via gate/ok/fail from
 * {@see BaseAjaxController}; empty-then cascades flattened — behaviour-preserving;
 * comments English).
 *
 * The `movie` and `episode` blocks were byte-identical (bar the permission key),
 * and the delete/kill/purge sub-actions were identical across stream/movie/
 * episode, so those are factored into {@see self::vodAction()} and
 * {@see self::streamMutation()}. The start/stop/restart/force paths echo the
 * raw `ApiClient` response (not JSON), exactly as api.php did.
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class StreamAjaxController extends BaseAjaxController {

    /** action=stream — live stream control (start/stop/restart/force) + delete/kill/purge. */
    public function stream(): never {
        $this->requireXhr();
        $this->gate('adv', 'edit_stream');

        global $db;
        $rStreamID = intval(RequestManager::get('stream_id'));
        $rServerID = intval(RequestManager::get('server_id'));
        $rSub = RequestManager::get('sub');

        if (in_array($rSub, array('start', 'stop', 'restart'))) {
            if ($rSub == 'restart') {
                $rSub = 'start';
            }

            if ($rServerID == -1) {
                $rServerIDs = array();
                $db->query('SELECT `server_id` FROM `streams_servers` WHERE `stream_id` = ?;', $rStreamID);

                foreach ($db->get_rows() as $rRow) {
                    $rServerIDs[] = intval($rRow['server_id']);
                }

                if (count($rServerIDs) > 0) {
                    echo ApiClient::request(array('action' => 'stream', 'sub' => $rSub, 'stream_ids' => array($rStreamID), 'servers' => $rServerIDs));

                    exit();
                }
            } else {
                echo ApiClient::request(array('action' => 'stream', 'sub' => $rSub, 'stream_ids' => array($rStreamID), 'servers' => array($rServerID)));

                exit();
            }

            $this->fail();
        }

        if ($rSub == 'force') {
            $rForceID = intval(RequestManager::get('force_id'));
            $rServerIDs = array_keys(StreamRepository::getSystemRows($rStreamID));

            if (0 >= count($rServerIDs)) {
                $this->fail();
            }

            echo json_encode(ApiClient::asyncRequest($rServerIDs, array('action' => 'force_stream', 'stream_id' => $rStreamID, 'force_id' => $rForceID)));

            exit();
        }

        $this->streamMutation($rSub, $rStreamID, $rServerID, false);
    }

    /** action=movie — VOD movie control + delete/kill/purge. */
    public function movie(): never {
        $this->requireXhr();
        $this->vodAction('edit_movie');
    }

    /** action=episode — VOD episode control + delete/kill/purge. */
    public function episode(): never {
        $this->requireXhr();
        $this->vodAction('edit_episode');
    }

    /** action=series — delete a series. */
    public function series(): never {
        $this->requireXhr();
        $this->gate('adv', 'edit_series');

        if (RequestManager::get('sub') == 'delete') {
            SeriesService::deleteSeriesById(RequestManager::get('series_id'));
            $this->ok();
        }

        $this->fail();
    }

    /**
     * Shared movie/episode handler — identical in api.php bar the permission key.
     * start/stop dispatch a VOD request to the node(s); everything else is a
     * stream mutation.
     */
    private function vodAction(string $rGate): never {
        $this->gate('adv', $rGate);

        global $db;
        $rStreamID = intval(RequestManager::get('stream_id'));
        $rServerID = intval(RequestManager::get('server_id'));
        $rSub = RequestManager::get('sub');

        if (in_array($rSub, array('start', 'stop'))) {
            if ($rServerID == -1) {
                $rServerIDs = array();
                $db->query('SELECT `server_id` FROM `streams_servers` WHERE `stream_id` = ?;', $rStreamID);

                foreach ($db->get_rows() as $rRow) {
                    $rServerIDs[] = intval($rRow['server_id']);
                }

                if (0 < count($rServerIDs)) {
                    echo ApiClient::request(array('action' => 'vod', 'sub' => $rSub, 'stream_ids' => array($rStreamID), 'servers' => $rServerIDs, 'force' => true));

                    exit();
                }
            } else {
                echo ApiClient::request(array('action' => 'vod', 'sub' => $rSub, 'stream_ids' => array($rStreamID), 'servers' => array($rServerID), 'force' => true));

                exit();
            }

            $this->fail();
        }

        $this->streamMutation($rSub, $rStreamID, $rServerID, true);
    }

    /**
     * Shared delete/kill/purge sub-actions for stream/movie/episode. An
     * unhandled sub falls through to `{"result":false}`.
     */
    private function streamMutation(string $rSub, int $rStreamID, int $rServerID, bool $rIsVod): never {
        global $db;

        if ($rSub == 'delete') {
            StreamRepository::deleteStream($rStreamID, $rServerID, $rIsVod);
            $this->ok();
        }

        if ($rSub == 'kill') {
            ConnectionTracker::closeConnection(RequestManager::get('stream_id'));
            $this->ok();
        }

        if ($rSub == 'purge') {
            if (SettingsManager::get('redis_handler')) {
                foreach (ConnectionTracker::getRedisConnections(null, ($rServerID == -1 ? null : $rServerID), $rStreamID, true, false, false) as $rConnection) {
                    ConnectionTracker::closeConnection($rConnection);
                }
            } else {
                if ($rServerID == -1) {
                    $db->query('SELECT * FROM `lines_live` WHERE `stream_id` = ?;', $rStreamID);
                } else {
                    $db->query('SELECT * FROM `lines_live` WHERE `stream_id` = ? AND `server_id` = ?;', $rStreamID, $rServerID);
                }

                foreach ($db->get_rows() as $rRow) {
                    ConnectionTracker::closeConnection($rRow);
                }
            }

            $this->ok();
        }

        $this->fail();
    }
}
