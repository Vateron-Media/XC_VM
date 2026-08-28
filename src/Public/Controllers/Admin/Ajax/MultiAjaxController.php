<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\ApiClient;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Device\EnigmaService;
use XcVm\Domain\Device\MagService;
use XcVm\Domain\Line\LineRepository;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\Stream\StreamRepository;
use XcVm\Domain\User\UserService;
use XcVm\Domain\Vod\SeriesService;

/**
 * Admin-ajax controller for the "multi" bulk-operations action.
 *
 * Extracted from the legacy `admin/api.php` (the last block that remained).
 * Dispatches a bulk sub-action (delete/enable/disable/ban/unban/purge/start/
 * stop/restart/convert/…) over a set of ids for a given entity `type` — one
 * private handler per entity, each answering `{"result":true}` on success or
 * `{"result":false}` when the permission gate fails.
 *
 * The api.php block was a deep if/else pyramid; the sub-action dispatch is now a
 * flat if/elseif chain, and the repeated `implode(',', array_map('intval', …))`
 * / connection-purge idioms are factored into {@see self::inList()} and
 * {@see self::purgeUserConnections()}. Behaviour and SQL are otherwise unchanged.
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class MultiAjaxController extends BaseAjaxController {

    /** action=multi — bulk operation over ids of a given entity type. */
    public function multi(): never {
        $this->requireXhr();

        $rType = RequestManager::get('type') ?? '';
        $rRequestIDs = json_decode(RequestManager::get('ids') ?? '[]', true) ?: array();
        $rSub = RequestManager::get('sub') ?? '';

        if (count($rRequestIDs) != 0) {
            switch ($rType) {
                case 'line':
                    $this->handleLine($rRequestIDs, $rSub);
                    // no break — handler terminates the request
                case 'mag':
                case 'enigma':
                    $this->handleDevices($rType, $rRequestIDs, $rSub);
                    // no break
                case 'user':
                    $this->handleUser($rRequestIDs, $rSub);
                    // no break
                case 'server':
                case 'proxy':
                    $this->handleServers($rType, $rRequestIDs, $rSub);
                    // no break
                case 'series':
                    $this->handleSeries($rRequestIDs, $rSub);
                    // no break
                case 'stream':
                case 'movie':
                case 'episode':
                case 'cchannel':
                case 'radio':
                    $this->handleStreams($rType, $rRequestIDs, $rSub);
                    // no break
            }
        }

        // Empty id set or an unknown type falls through to a failure response.
        $this->fail();
    }

    /** Bulk operations on lines. */
    private function handleLine(array $rRequestIDs, string $rSub): never {
        $this->gate('adv', 'edit_line');

        global $db;

        if ($rSub == 'delete') {
            LineRepository::deleteMany($rRequestIDs);
        } elseif ($rSub == 'enable') {
            $db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id`IN (' . $this->inList($rRequestIDs) . ');');
            LineService::updateLinesSignal($rRequestIDs);
        } elseif ($rSub == 'disable') {
            $db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` IN (' . $this->inList($rRequestIDs) . ');');
            LineService::updateLinesSignal($rRequestIDs);
        } elseif ($rSub == 'ban') {
            $db->query('UPDATE `lines` SET `admin_enabled` = 0 WHERE `id` IN (' . $this->inList($rRequestIDs) . ');');
            LineService::updateLinesSignal($rRequestIDs);
        } elseif ($rSub == 'unban') {
            $db->query('UPDATE `lines` SET `admin_enabled` = 1 WHERE `id` IN (' . $this->inList($rRequestIDs) . ');');
            LineService::updateLinesSignal($rRequestIDs);
        } elseif ($rSub == 'purge') {
            $this->purgeUserConnections($rRequestIDs);
        }

        $this->ok();
    }

    /** Bulk operations on MAG / Enigma2 devices (acting on their owning lines). */
    private function handleDevices(string $rType, array $rRequestIDs, string $rSub): never {
        // NB: api.php keyed this map by 'enigma2' while $rType is 'enigma', so the
        // enigma branch resolves to a null permission (a latent bug — behaviour
        // preserved here, flagged for a separate fix).
        $rPermission = array('mag' => 'edit_mag', 'enigma2' => 'edit_e2')[$rType] ?? null;

        if (!Authorization::check('adv', $rPermission)) {
            $this->fail();
        }

        global $db;
        $rUserIDs = array();

        if ($rType == 'mag') {
            $db->query('SELECT `user_id` FROM `mag_devices` WHERE `mag_id` IN (' . $this->inList($rRequestIDs) . ');');
        } else {
            $db->query('SELECT `user_id` FROM `enigma2_devices` WHERE `device_id` IN (' . $this->inList($rRequestIDs) . ');');
        }

        foreach ($db->get_Rows() as $rRow) {
            $rUserIDs[] = $rRow['user_id'];
        }

        if (0 < count($rUserIDs)) {
            if ($rSub == 'delete') {
                if ($rType == 'mag') {
                    MagService::deleteDevices($rRequestIDs);
                } else {
                    EnigmaService::deleteDevices($rRequestIDs);
                }
            } elseif ($rSub == 'enable') {
                $db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` IN (' . $this->inList($rUserIDs) . ');');
            } elseif ($rSub == 'disable') {
                $db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` IN (' . $this->inList($rUserIDs) . ');');
            } elseif ($rSub == 'ban') {
                $db->query('UPDATE `lines` SET `admin_enabled` = 0 WHERE `id` IN (' . $this->inList($rUserIDs) . ');');
            } elseif ($rSub == 'unban') {
                $db->query('UPDATE `lines` SET `admin_enabled` = 1 WHERE `id` IN (' . $this->inList($rUserIDs) . ');');
            } elseif ($rSub == 'purge') {
                $this->purgeUserConnections($rUserIDs);
            } elseif ($rSub == 'convert' && in_array($rType, array('mag', 'enigma'))) {
                foreach ($rRequestIDs as $rDeviceID) {
                    if ($rType == 'mag') {
                        MagService::deleteDevice($rDeviceID, false, false, true);
                    } else {
                        EnigmaService::deleteDevice($rDeviceID, false, false, true);
                    }
                }
            }

            LineService::updateLinesSignal($rUserIDs);
        }

        $this->ok();
    }

    /** Bulk operations on registered (reseller) users. */
    private function handleUser(array $rRequestIDs, string $rSub): never {
        $this->gate('adv', 'edit_reguser');

        global $db;

        if ($rSub == 'enable') {
            $db->query('UPDATE `users` SET `status` = 1 WHERE `id` IN (' . $this->inList($rRequestIDs) . ');');
        } elseif ($rSub == 'disable') {
            $db->query('UPDATE `users` SET `status` = 0 WHERE `id` IN (' . $this->inList($rRequestIDs) . ');');
        } elseif ($rSub == 'delete') {
            UserService::deleteRegisteredUsers($rRequestIDs);
        }

        $this->ok();
    }

    /** Bulk operations on servers / proxies. */
    private function handleServers(string $rType, array $rRequestIDs, string $rSub): never {
        $this->gate('adv', 'edit_server');

        global $db, $rServers;

        if ($rType == 'server' && in_array($rSub, array('restart', 'start', 'stop'))) {
            $rStreamMap = array();

            if ($rSub == 'start') {
                $db->query('SELECT `server_id`, `stream_id` FROM `streams_servers` WHERE `server_id` IN (' . $this->inList($rRequestIDs) . ') AND `on_demand` = 0;');
            } else {
                $db->query('SELECT `server_id`, `stream_id` FROM `streams_servers` WHERE `server_id` IN (' . $this->inList($rRequestIDs) . ') AND `on_demand` = 0 AND `monitor_pid` IS NOT NULL AND `monitor_pid` > 0;');
            }

            if (0 < $db->num_rows()) {
                foreach ($db->get_rows() as $rRow) {
                    $rStreamMap[intval($rRow['server_id'])][] = intval($rRow['stream_id']);
                }
            }

            foreach ($rStreamMap as $rServerID => $rStreamIDs) {
                $rStreamSub = ($rSub == 'stop') ? 'stop' : 'start';
                ApiClient::request(array('action' => 'stream', 'sub' => $rStreamSub, 'stream_ids' => $rStreamIDs, 'servers' => array($rServerID)));
            }
        } elseif ($rSub == 'purge') {
            foreach ($rRequestIDs as $rServerID) {
                $this->purgeServerConnections($rType, $rServerID);
            }
        } elseif ($rSub == 'enable') {
            $db->query('UPDATE `servers` SET `enabled` = 1 WHERE `id` IN (' . $this->inList($rRequestIDs) . ');');
        } elseif ($rSub == 'disable') {
            $db->query('UPDATE `servers` SET `enabled` = 0 WHERE `is_main` = 0 AND `id` IN (' . $this->inList($rRequestIDs) . ');');
        } elseif ($rSub == 'enable_proxy' && $rType == 'server') {
            $db->query('UPDATE `servers` SET `enable_proxy` = 1 WHERE `id` IN (' . $this->inList($rRequestIDs) . ');');
        } elseif ($rSub == 'disable_proxy' && $rType == 'server') {
            $db->query('UPDATE `servers` SET `enable_proxy` = 0 WHERE `id` IN (' . $this->inList($rRequestIDs) . ');');
        } else {
            foreach ($rRequestIDs as $rServerID) {
                if ($rServers[$rServerID]['is_main'] == 0) {
                    ServerRepository::deleteById($rServerID);
                }
            }
        }

        $this->ok();
    }

    /** Bulk delete of series. */
    private function handleSeries(array $rRequestIDs, string $rSub): never {
        if ($rSub == 'delete') {
            SeriesService::deleteSeriesByIds($rRequestIDs);
        }

        $this->ok();
    }

    /** Bulk operations on streams / movies / episodes / created channels / radios. */
    private function handleStreams(string $rType, array $rRequestIDs, string $rSub): never {
        $this->gate('adv', 'edit_' . $rType);

        global $db;
        $rNoServer = $rStreamMap = array();

        foreach ($rRequestIDs as $rStream) {
            list($rStreamID, $rServerID) = explode('-', $rStream);

            if (!$rServerID) {
                $rNoServer[] = $rStreamID;
            } else {
                $rStreamMap[$rServerID][] = $rStreamID;
            }
        }
        $rUnallocated = $rAllocated = array();

        if (0 < count($rNoServer)) {
            $db->query('SELECT `stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` IN (' . $this->inList($rNoServer) . ');');

            foreach ($db->get_rows() as $rRow) {
                $rStreamMap[intval($rRow['server_id'])][] = intval($rRow['stream_id']);

                if (!in_array(intval($rRow['stream_id']), $rAllocated)) {
                    $rAllocated[] = intval($rRow['stream_id']);
                }
            }
        }

        foreach ($rNoServer as $rStreamID) {
            if (!in_array($rStreamID, $rAllocated)) {
                $rUnallocated[] = $rStreamID;
            }
        }

        if (0 < count($rStreamMap) || $rSub == 'delete' && 0 < count($rUnallocated)) {
            if (in_array($rSub, array('start', 'stop', 'restart'))) {
                if ($rSub == 'restart') {
                    $rSub = 'start';
                }

                foreach ($rStreamMap as $rServerID => $rStreamIDs) {
                    $rAction = in_array($rType, array('stream', 'radio', 'cchannel')) ? 'stream' : 'vod';
                    ApiClient::request(array('action' => $rAction, 'sub' => $rSub, 'stream_ids' => $rStreamIDs, 'servers' => array($rServerID)));
                }
            } elseif ($rSub == 'delete') {
                foreach ($rStreamMap as $rServerID => $rStreamIDs) {
                    StreamRepository::deleteStreamsByServer($rStreamIDs, $rServerID, true);
                }

                if (0 < count($rUnallocated)) {
                    StreamRepository::deleteStreams($rUnallocated, true);
                }
            } elseif ($rSub == 'purge') {
                foreach ($rStreamMap as $rServerID => $rStreamIDs) {
                    if (SettingsManager::get('redis_handler')) {
                        foreach ($rStreamIDs as $rStreamID) {
                            foreach (ConnectionTracker::getRedisConnections(null, $rServerID, $rStreamID, true, false, false) as $rConnection) {
                                ConnectionTracker::closeConnection($rConnection);
                            }
                        }
                    } else {
                        $db->query('SELECT * FROM `lines_live` WHERE `server_id` = ? AND `stream_id` IN (' . $this->inList($rStreamIDs) . ');', $rServerID);

                        foreach ($db->get_rows() as $rRow) {
                            ConnectionTracker::closeConnection($rRow);
                        }
                    }
                }
            }
        }

        $this->ok();
    }

    /** Close all live connections for a set of line ids (redis or lines_live). */
    private function purgeUserConnections(array $rUserIDs): void {
        global $db;

        if (SettingsManager::get('redis_handler')) {
            foreach ($rUserIDs as $rUserID) {
                foreach (ConnectionTracker::getRedisConnections($rUserID, null, null, true, false, false) as $rConnection) {
                    ConnectionTracker::closeConnection($rConnection);
                }
            }
        } else {
            $db->query('SELECT * FROM `lines_live` WHERE `user_id` IN (' . $this->inList($rUserIDs) . ');');

            foreach ($db->get_rows() as $rRow) {
                ConnectionTracker::closeConnection($rRow);
            }
        }
    }

    /** Close all live connections served by one server / proxy. */
    private function purgeServerConnections(string $rType, $rServerID): void {
        global $db;

        if (SettingsManager::get('redis_handler')) {
            if ($rType == 'proxy') {
                foreach (ServerRepository::getAll()[$rServerID]['parent_id'] as $rParentID) {
                    foreach (ConnectionTracker::getRedisConnections(null, $rParentID, null, true, false, false) as $rConnection) {
                        if ($rConnection['proxy_id'] == $rServerID) {
                            ConnectionTracker::closeConnection($rConnection);
                        }
                    }
                }
            } else {
                foreach (ConnectionTracker::getRedisConnections(null, $rServerID, null, true, false, false) as $rConnection) {
                    ConnectionTracker::closeConnection($rConnection);
                }
            }
        } else {
            if ($rType == 'proxy') {
                $db->query('SELECT * FROM `lines_live` WHERE `proxy_id` = ?;', $rServerID);
            } else {
                $db->query('SELECT * FROM `lines_live` WHERE `server_id` = ?;', $rServerID);
            }

            foreach ($db->get_rows() as $rRow) {
                ConnectionTracker::closeConnection($rRow);
            }
        }
    }

    /** `implode(',', array_map('intval', …))` — the recurring `IN (…)` id list. */
    private function inList(array $rIDs): string {
        return implode(',', array_map('intval', $rIDs));
    }
}
