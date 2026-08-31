<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Localization\Translator;
use XcVm\Core\Reference\StatusBadge;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Util\TimeUtils;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\User\GroupService;

/**
 * Admin-ajax controller for the global "search" action.
 *
 * A fuzzy full-text search across lines, MAG/Enigma2 devices, users, streams
 * (live/VOD/created channels/radio/episodes) and series. Each result item
 * carries structured `data` that the client renders into a card (see
 * docs/adr/search-json-contract.md); permission checks, status resolution and
 * category/server lookups stay server-side. Status badges resolve via
 * {@see \XcVm\Core\Reference\StatusBadge}; `$language`, `$db` and `$rServers`
 * remain bootstrap globals it relies on.
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class SearchAjaxController extends BaseAjaxController {

    /** action=search — global fuzzy search as structured JSON (no HTML). */
    public function search(): never {
        $this->requireXhr();

        /** @var class-string $language */
        global $db, $rServers;

        $rReturn = array('total_count' => 0, 'items' => array(), 'result' => true);
        $rTables = array('lines' => array('Lines', 'line?id=', '`username`, `admin_notes`, `reseller_notes`, `last_ip`, `contact`', 'id', 'username'), 'mag_devices' => array('MAG Devices', 'mag?id=', '`mac_filter`, `ip`', 'mag_id', 'mac'), 'enigma2_devices' => array('Enigma2 Devices', 'enigma?id=', '`mac_filter`, `public_ip`', 'device_id', 'mac'), 'users' => array('Users', 'user?id=', '`username`, `email`, `ip`, `notes`, `reseller_dns`', 'id', 'username'), 'streams' => array('Streams, Movies & Episodes', 'stream_view?id=', '`stream_display_name`, `stream_source`, `notes`, `channel_id`', 'id', 'stream_display_name'), 'streams_series' => array('TV Series', 'serie?id=', '`title`, `plot`, `cast`, `director`', 'id', 'title'));
        $rLimit = 100;
        $rTerm = strtolower(preg_replace('/[^[:alnum:][:space:]]/u', '', RequestManager::get('search')));
        $rTermSP = strtolower(preg_replace('/[^[:alnum:][:space:]]/u', ' ', RequestManager::get('search')));

        if (!empty($rTermSP)) {
            $rItems = array();

            foreach ($rTables as $rTable => $rTableInfo) {
                if ($rTable == 'streams') {
                    $db->query('SELECT `' . $rTable . '`.*, MATCH(' . $rTableInfo[2] . ') AGAINST (? IN BOOLEAN MODE) AS `score1`, MATCH(' . $rTableInfo[2] . ') AGAINST (? IN BOOLEAN MODE) AS `score2` FROM `' . $rTable . '` WHERE MATCH(' . $rTableInfo[2] . ') AGAINST (? IN BOOLEAN MODE) OR `id` = ? ORDER BY `score1` + `score2` DESC LIMIT ' . $rLimit . ';', $rTermSP, $rTermSP . '*', $rTermSP . '*', intval($rTerm));
                } else {
                    $db->query('SELECT `' . $rTable . '`.*, MATCH(' . $rTableInfo[2] . ') AGAINST (? IN BOOLEAN MODE) AS `score1`, MATCH(' . $rTableInfo[2] . ') AGAINST (? IN BOOLEAN MODE) AS `score2` FROM `' . $rTable . '` WHERE MATCH(' . $rTableInfo[2] . ') AGAINST (? IN BOOLEAN MODE) ORDER BY `score1` + `score2` DESC LIMIT ' . $rLimit . ';', $rTermSP, $rTermSP . '*', $rTermSP . '*');
                }

                foreach ($db->get_rows() as $rRow) {
                    similar_text($rTerm, strtolower(preg_replace('/[^[:alnum:][:space:]]/u', '', $rRow[$rTableInfo[4]])), $rPerc);

                    if ($rTable == 'streams' && $rRow['id'] == intval($rTerm)) {
                        $rPerc = 1000;
                    }

                    if ($rTerm == strtolower(preg_replace('/[^[:alnum:][:space:]]/u', '', $rRow[$rTableInfo[4]]))) {
                        $rPerc = 1000;
                    }

                    $rRow['score'] = $rRow['score1'] + $rRow['score2'] + $rPerc;
                    $rRow['table'] = $rTable;
                    $rItems[] = $rRow;
                }
            }
            array_multisort(array_column($rItems, 'score'), SORT_DESC, $rItems);
            $rItems = array_slice($rItems, 0, (intval(SettingsManager::get('search_items')) ?: 50));
            $rStreamNameIDs = $rDeviceIDs = $rLineIDs = $rOwnerIDs = $rUserIDs = $rSeriesIDs = $rStreamIDs = array();

            foreach ($rItems as $rItem) {
                if ($rItem['table'] == 'streams') {
                    if (0 < intval($rItem['id'])) {
                        $rStreamIDs[] = intval($rItem['id']);
                    }
                } else {
                    if ($rItem['table'] == 'streams_series') {
                        if (0 < intval($rItem['id'])) {
                            $rSeriesIDs[] = intval($rItem['id']);
                        }
                    } else {
                        if ($rItem['table'] == 'users') {
                            if (0 < intval($rItem['id'])) {
                                $rUserIDs[] = intval($rItem['id']);
                            }

                            if (0 < intval($rItem['owner_id'])) {
                                $rOwnerIDs[] = intval($rItem['owner_id']);
                            }
                        } else {
                            if ($rItem['table'] == 'lines') {
                                if (0 < intval($rItem['id'])) {
                                    $rLineIDs[] = intval($rItem['id']);
                                }

                                if (0 < intval($rItem['member_id'])) {
                                    $rOwnerIDs[] = intval($rItem['member_id']);
                                }

                                $rActivityArray = json_decode($rItem['last_activity_array'], true);

                                if (is_array($rActivityArray) && 0 < intval($rActivityArray['stream_id'])) {
                                    $rStreamNameIDs[] = intval($rActivityArray['stream_id']);
                                }
                            } else {
                                if ($rItem['table'] == 'mag_devices' || $rItem['table'] == 'enigma2_devices') {
                                    if (0 < intval($rItem['user_id'])) {
                                        $rDeviceIDs[] = intval($rItem['user_id']);
                                        $rLineIDs[] = intval($rItem['user_id']);
                                    }
                                }
                            }
                        }
                    }
                }
            }
            $rDeviceLines = $rStreamNames = $rLinesInfo = $rOwnerNames = $rUsersCount = $rLinesCount = $rSeriesInfo = $rSeriesTitles = $rServerItems = $rServerCount = $rConnectionCount = $rLineConnectionCount = array();
            $rDeviceIDs = AdminHelpers::confirmIDs($rDeviceIDs);

            if (0 < count($rDeviceIDs)) {
                $db->query('SELECT * FROM `lines` WHERE `id` IN (' . implode(',', $rDeviceIDs) . ');');

                foreach ($db->get_rows() as $rRow) {
                    $rDeviceLines[$rRow['id']] = $rRow;

                    if (0 < intval($rRow['member_id'])) {
                        $rOwnerIDs[] = $rRow['member_id'];
                    }
                }
            }

            $rStreamIDs = AdminHelpers::confirmIDs($rStreamIDs);

            if (0 < count($rStreamIDs)) {
                $db->query('SELECT `streams_episodes`.`stream_id`, `streams_series`.`id`, `streams_series`.`title` FROM `streams_episodes` LEFT JOIN `streams_series` ON `streams_series`.`id` = `streams_episodes`.`series_id` WHERE `streams_episodes`.`stream_id` IN (' . implode(',', $rStreamIDs) . ');');

                foreach ($db->get_rows() as $rRow) {
                    $rSeriesTitles[$rRow['stream_id']] = $rRow['title'];
                }
                $db->query('SELECT * FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', $rStreamIDs) . ');');

                foreach ($db->get_rows() as $rRow) {
                    $rServerCount[$rRow['stream_id']] = ($rServerCount[$rRow['stream_id']] ?? 0) + 1;

                    if ($rServers[$rRow['server_id']]['server_online']) {
                        $rRow['priority'] = (0 < $rRow['pid'] ? 1 : 0);
                    } else {
                        $rRow['priority'] = 0;
                    }

                    $rServerItems[$rRow['stream_id']][] = $rRow;
                }

                foreach (array_keys($rServerItems) as $rStreamID) {
                    array_multisort(array_column($rServerItems[$rStreamID], 'priority'), SORT_DESC, $rServerItems[$rStreamID]);
                }

                if (SettingsManager::get('redis_handler')) {
                    $rConnectionCount = ConnectionTracker::getStreamConnections($rStreamIDs, true, true);
                } else {
                    $db->query('SELECT `stream_id`, COUNT(*) AS `count` FROM `lines_live` WHERE `stream_id` IN (' . implode(',', $rStreamIDs) . ') AND `hls_end` = 0;');

                    foreach ($db->get_rows() as $rRow) {
                        $rConnectionCount[$rRow['stream_id']] = $rRow['count'];
                    }
                }
            }

            $rSeriesIDs = AdminHelpers::confirmIDs($rSeriesIDs);

            if (0 < count($rSeriesIDs)) {
                $db->query('SELECT `series_id`, MAX(`season_num`) AS `latest_season`, COUNT(*) AS `episodes` FROM `streams_episodes` WHERE `series_id` IN (' . implode(',', $rSeriesIDs) . ') GROUP BY `series_id`;');

                foreach ($db->get_rows() as $rRow) {
                    $rSeriesInfo[$rRow['series_id']] = array($rRow['latest_season'], $rRow['episodes']);
                }
            }

            $rUserIDs = AdminHelpers::confirmIDs($rUserIDs);

            if (0 < count($rUserIDs)) {
                $db->query('SELECT `owner_id`, COUNT(*) AS `count` FROM `users` WHERE `owner_id` IN (' . implode(',', $rUserIDs) . ') GROUP BY `owner_id`;');

                foreach ($db->get_rows() as $rRow) {
                    $rUsersCount[$rRow['owner_id']] = $rRow['count'];
                }
                $db->query('SELECT `member_id`, COUNT(*) AS `count` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserIDs) . ') GROUP BY `member_id`;');

                foreach ($db->get_rows() as $rRow) {
                    $rLinesCount[$rRow['member_id']] = $rRow['count'];
                }
            }

            $rOwnerIDs = AdminHelpers::confirmIDs($rOwnerIDs);

            if (0 < count($rOwnerIDs)) {
                $db->query('SELECT `id`, `username` FROM `users` WHERE `id` IN (' . implode(',', $rOwnerIDs) . ');');

                foreach ($db->get_rows() as $rRow) {
                    $rOwnerNames[$rRow['id']] = $rRow['username'];
                }
            }

            $rLineIDs = AdminHelpers::confirmIDs($rLineIDs);

            if (0 < count($rLineIDs)) {
                if (SettingsManager::get('redis_handler')) {
                    $rLineConnectionCount = ConnectionTracker::getUserConnections($rLineIDs, true);
                    $rConnectionMap = ConnectionTracker::getFirstConnection($rLineIDs);
                    $rLStreamIDs = array();

                    foreach ($rConnectionMap as $rUserID => $rConnection) {
                        if (!(in_array($rConnection['stream_id'], $rStreamIDs))) {
                            $rLStreamIDs[] = intval($rConnection['stream_id']);
                        }
                    }
                    $rStreamMap = array();

                    if (0 < count($rLStreamIDs)) {
                        $db->query('SELECT `id`, `stream_display_name` FROM `streams` WHERE `id` IN (' . implode(',', $rLStreamIDs) . ');');

                        foreach ($db->get_rows() as $rRow) {
                            $rStreamMap[$rRow['id']] = $rRow['stream_display_name'];
                        }
                    }

                    foreach ($rConnectionMap as $rUserID => $rConnection) {
                        $rLinesInfo[$rUserID]['stream_id'] = $rConnection['stream_id'];
                        $rLinesInfo[$rUserID]['last_active'] = $rConnection['date_start'];
                        $rLinesInfo[$rUserID]['online'] = true;
                        $rStreamNameIDs[] = intval($rConnection['stream_id']);
                    }
                    unset($rConnectionMap);
                } else {
                    $db->query('SELECT `lines_live`.`user_id`, `lines_live`.`stream_id`, `lines_live`.`date_start` AS `last_active`, `streams`.`stream_display_name` FROM `lines_live` LEFT JOIN `streams` ON `streams`.`id` = `lines_live`.`stream_id` INNER JOIN (SELECT `user_id`, MAX(`date_start`) AS `ts` FROM `lines_live` GROUP BY `user_id`) `maxt` ON (`lines_live`.`user_id` = `maxt`.`user_id` AND `lines_live`.`date_start` = `maxt`.`ts`) WHERE `lines_live`.`hls_end` = 0 AND `lines_live`.`user_id` IN (' . implode(',', $rLineIDs) . ');');

                    foreach ($db->get_rows() as $rRow) {
                        $rLinesInfo[$rRow['user_id']]['stream_id'] = $rRow['stream_id'];
                        $rLinesInfo[$rRow['user_id']]['last_active'] = $rRow['last_active'];
                        $rLinesInfo[$rRow['user_id']]['online'] = true;
                        $rStreamNameIDs[] = intval($rRow['stream_id']);
                    }
                    $db->query('SELECT `user_id`, COUNT(*) AS `count` FROM `lines_live` WHERE `user_id` IN (' . implode(',', array_map('intval', $rLineIDs)) . ') AND `hls_end` = 0;');

                    foreach ($db->get_rows() as $rRow) {
                        $rLineConnectionCount[$rRow['user_id']] = $rRow['count'];
                    }
                }
            }

            $rStreamNameIDs = AdminHelpers::confirmIDs($rStreamNameIDs);

            if (0 < count($rStreamNameIDs)) {
                $db->query('SELECT `id`, `stream_display_name` FROM `streams` WHERE `id` IN (' . implode(',', array_unique($rStreamNameIDs)) . ');');

                foreach ($db->get_rows() as $rRow) {
                    $rStreamNames[$rRow['id']] = $rRow['stream_display_name'];
                }
            }

            $rCategories = CategoryService::getAllByType(null);
            $rGroups = GroupService::getAll();

            $rCtx = compact(
                'rServerItems', 'rServerCount', 'rSeriesTitles', 'rConnectionCount', 'rSeriesInfo',
                'rUsersCount', 'rLinesCount', 'rOwnerNames', 'rLinesInfo', 'rLineConnectionCount',
                'rStreamNames', 'rDeviceLines', 'rCategories', 'rGroups', 'rTables'
            );

            foreach ($rItems as $rItem) {
                $rReturn['items'][] = $this->buildItem($rItem, $rCtx);
            }
        }

        $rReturn['total_count'] = count($rReturn['items']);

        if ($rReturn['total_count'] == 0) {
            $rReturn['items'][] = array('id' => 'no_results', 'url' => null, 'text' => 'No Results', 'entity' => 'no_results', 'data' => null);
        }

        $this->json($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /** Dispatch one search row to its per-entity structured payload. */
    private function buildItem(array $rItem, array $rCtx): array {
        $rTableInfo = $rCtx['rTables'][$rItem['table']];
        $rBase = array(
            'id' => $rItem['table'] . '#' . $rItem[$rTableInfo[3]],
            'url' => $rTableInfo[1] . $rItem[$rTableInfo[3]],
            'text' => $rItem[$rTableInfo[4]],
        );

        switch ($rItem['table']) {
            case 'streams':
                return $rBase + array('entity' => $this->streamPage(intval($rItem['type']))['entity'], 'data' => $this->buildStreamItem($rItem, $rCtx));
            case 'streams_series':
                return $rBase + array('entity' => 'series', 'data' => $this->buildSeriesItem($rItem, $rCtx));
            case 'users':
                return $rBase + array('entity' => 'user', 'data' => $this->buildUserItem($rItem, $rCtx));
            case 'lines':
                return $rBase + array('entity' => 'line', 'data' => $this->buildLineItem($rItem, $rCtx));
            case 'enigma_devices':
            case 'mag_devices':
                return $rBase + array('entity' => ($rItem['table'] == 'mag_devices' ? 'mag' : 'enigma'), 'data' => $this->buildDeviceItem($rItem, $rCtx));
        }

        return $rBase + array('entity' => 'unknown', 'data' => null);
    }

    /** streams row -> stream/movie/channel/radio/episode payload. */
    private function buildStreamItem(array $rItem, array $rCtx): array {
        global $rServers;
        $rServerItem = ($rCtx['rServerItems'][$rItem['id']][0] ?? null) ?: null;
        $rCategoryIDs = json_decode($rItem['category_id'], true) ?: array();
        $rProperties = json_decode($rItem['movie_properties'], true) ?: array();
        $rLive = in_array(intval($rItem['type']), array(1, 3, 4), true);
        $rPage = $this->streamPage(intval($rItem['type']));

        if ($rItem['type'] != 5) {
            $rTitle = $rItem['stream_display_name'];
            $rCategory = ($rCtx['rCategories'][$rCategoryIDs[0] ?? null]['category_name'] ?? '') ?: 'No Category';

            if (1 < count($rCategoryIDs)) {
                $rCategory .= ' (+' . (count($rCategoryIDs) - 1) . ')';
            }
        } else {
            $rTitle = ($rCtx['rSeriesTitles'][$rItem['id']] ?? '') ?: 'No Series';
            $rCategory = '';

            if (stripos($rItem['stream_display_name'], $rTitle) === 0) {
                $rCategory = ltrim(substr($rItem['stream_display_name'], strlen($rTitle)));

                if (substr($rCategory, 0, 1) == '-') {
                    $rCategory = trim(ltrim($rCategory, '-'));
                }
            }
        }

        $rServerID = ($rServerItem['server_id'] ?? null) ?: null;
        $rServerName = '';

        if ($rServerID) {
            $rServerName = $rServers[$rServerID]['server_name'] ?? '';

            if (1 < ($rCtx['rServerCount'][$rItem['id']] ?? 0)) {
                $rServerName .= ' (+' . ($rCtx['rServerCount'][$rItem['id']] - 1) . ')';
            }
        }

        $rActualStatus = $this->resolveStreamStatus($rItem, $rServerItem);
        $rConnections = intval($rCtx['rConnectionCount'][$rItem['id']] ?? 0);

        return array(
            'layout' => $rLive ? 'live' : 'vod',
            'title' => $rTitle,
            'title_link' => Authorization::check('adv', 'manage_streams') ? ('stream_view?id=' . intval($rItem['id'])) : null,
            'category' => $rCategory,
            'server' => $rServerName,
            'image' => $rLive
                ? array('url' => $rItem['stream_icon'], 'size' => 96)
                : array('url' => ($rProperties['movie_image'] ?? ''), 'size' => 512),
            'badge' => $rLive
                ? array('text' => strtoupper($rPage['text']), 'variant' => 'success')
                : array('text' => strtoupper($rPage['page']), 'variant' => 'primary'),
            'connections' => $rConnections,
            'connections_link' => 'live_connections?stream_id=' . $rItem['id'],
            'status' => $this->streamStatusPayload($rActualStatus, $rItem, $rServerItem),
            'rating' => ($rItem['type'] == 2) ? $this->ratingData($rProperties['rating'] ?? 0, $rItem['year'] ?? '') : null,
            'actions' => $this->streamActions($rItem, $rActualStatus, $rPage['page'], $rConnections),
        );
    }

    /** streams_series row -> series payload. */
    private function buildSeriesItem(array $rItem, array $rCtx): array {
        $rSeriesItem = ($rCtx['rSeriesInfo'][$rItem['id']] ?? array()) ?: array();
        $rCategoryIDs = json_decode($rItem['category_id'], true) ?: array();
        $rCategory = ($rCtx['rCategories'][$rCategoryIDs[0] ?? null]['category_name'] ?? '') ?: 'No Category';

        if (1 < count($rCategoryIDs)) {
            $rCategory .= ' (+' . (count($rCategoryIDs) - 1) . ')';
        }

        $rActions = array();

        if (Authorization::check('adv', 'add_episode')) {
            $rActions[] = array('kind' => 'navigate', 'target' => 'episode?sid=' . $rItem['id'], 'icon' => 'mdi-plus-circle-outline', 'title' => 'Add Episode(s)');
        }

        if (Authorization::check('adv', 'episodes')) {
            $rActions[] = array('kind' => 'navigate', 'target' => 'episodes?series=' . $rItem['id'], 'icon' => 'mdi-eye', 'title' => 'View Episodes');
        }

        if (Authorization::check('adv', 'edit_series')) {
            $rActions[] = array('kind' => 'navigate', 'target' => 'serie?id=' . $rItem['id'], 'icon' => 'mdi-pencil', 'title' => 'Edit');
        }

        return array(
            'title' => $rItem['title'],
            'category' => $rCategory,
            'image' => array('url' => $rItem['cover'], 'size' => 512),
            'rating' => $this->ratingData($rItem['rating'] ?? 0, $rItem['year'] ?? ''),
            'badge' => array('text' => Translator::get('tv_series_btn'), 'variant' => 'danger'),
            'seasons' => intval($rSeriesItem[0] ?? 0),
            'episodes' => intval($rSeriesItem[1] ?? 0),
            'actions' => $rActions,
        );
    }

    /** users row -> registered-user payload. */
    private function buildUserItem(array $rItem, array $rCtx): array {
        $rGroup = $rCtx['rGroups'][$rItem['member_group_id']] ?? array();
        $rIsReseller = (bool) ($rGroup['is_reseller'] ?? false);
        $rActive = $rItem['status'] == 1;
        $rActions = array();

        if (Authorization::check('adv', 'edit_reguser')) {
            if ($rIsReseller) {
                $rActions[] = array('kind' => 'credits', 'id' => intval($rItem['id']), 'icon' => 'mdi-coin', 'title' => Translator::get('add_credits'));
            }

            $rActions[] = array('kind' => 'navigate', 'target' => 'user?id=' . $rItem['id'], 'icon' => 'mdi-pencil', 'title' => 'Edit');
            $rActions[] = $rActive
                ? array('kind' => 'api', 'entity' => 'user', 'id' => intval($rItem['id']), 'sub' => 'disable', 'icon' => 'mdi-lock', 'title' => 'Disable', 'enabled' => true)
                : array('kind' => 'api', 'entity' => 'user', 'id' => intval($rItem['id']), 'sub' => 'enable', 'icon' => 'mdi-lock', 'title' => 'Enable', 'enabled' => true);
        }

        return array(
            'username' => $rItem['username'],
            'group' => $rGroup['group_name'] ?? '',
            'owner' => ($rCtx['rOwnerNames'][$rItem['owner_id']] ?? null) ?: null,
            'is_reseller' => $rIsReseller,
            'credits' => $rIsReseller ? intval($rItem['credits']) : null,
            'status' => array('label' => $rActive ? 'Active' : 'Inactive', 'variant' => $rActive ? 'info' : 'warning'),
            'users_count' => intval($rCtx['rUsersCount'][$rItem['id']] ?? 0),
            'lines_count' => intval($rCtx['rLinesCount'][$rItem['id']] ?? 0),
            'badge' => array('text' => Translator::get('user_btn'), 'variant' => 'warning'),
            'actions' => $rActions,
        );
    }

    /** lines row -> line payload. */
    private function buildLineItem(array $rItem, array $rCtx): array {
        $rConn = intval($rCtx['rLineConnectionCount'][$rItem['id']] ?? 0);

        return array(
            'title' => $rItem['username'],
            'device_type' => null,
            'status' => $this->lineStatus($rItem),
            'owner' => ($rCtx['rOwnerNames'][$rItem['member_id']] ?? null) ?: null,
            'expires' => $rItem['exp_date'] ? date(SettingsManager::get('datetime_format'), $rItem['exp_date']) : null,
            'last_active' => $this->lastActive($rItem, $rCtx),
            'connections' => $rConn,
            'flags' => array('restreamer' => (bool) $rItem['is_restreamer'], 'trial' => (bool) $rItem['is_trial']),
            'badge' => array('variant' => 'pink'),
            'actions' => $this->lineActions(intval($rItem['id']), 'line?id=' . $rItem['id'], $rItem['admin_enabled'], $rItem['enabled'], $rConn),
        );
    }

    /** mag/enigma row -> device payload (null when the owning line is missing). */
    private function buildDeviceItem(array $rItem, array $rCtx): ?array {
        $rLineInfo = ($rCtx['rDeviceLines'][$rItem['user_id']] ?? null) ?: null;

        if (!$rLineInfo) {
            return null;
        }

        $rType = ($rItem['table'] == 'mag_devices' ? 'mag' : 'enigma');
        $rConn = intval($rCtx['rLineConnectionCount'][$rLineInfo['id']] ?? 0);

        return array(
            'title' => $rItem['mac'],
            'device_type' => $rType,
            'status' => $this->lineStatus($rLineInfo),
            'owner' => ($rCtx['rOwnerNames'][$rLineInfo['member_id']] ?? null) ?: null,
            'expires' => $rLineInfo['exp_date'] ? date(SettingsManager::get('datetime_format'), $rLineInfo['exp_date']) : null,
            'last_active' => $this->lastActive($rLineInfo, $rCtx),
            'connections' => $rConn,
            'flags' => array('trial' => (bool) $rLineInfo['is_trial']),
            'badge' => array('variant' => 'pink'),
            'actions' => $this->lineActions(intval($rLineInfo['id']), $rType . '?id=' . $rItem['id'], $rItem['admin_enabled'], $rItem['enabled'], $rConn),
        );
    }

    /** Map a stream type to its {entity, page, text} descriptors. */
    private function streamPage(int $rType): array {
        switch ($rType) {
            case 1: return array('entity' => 'stream', 'page' => 'stream', 'text' => 'stream');
            case 2: return array('entity' => 'movie', 'page' => 'movie', 'text' => 'movie');
            case 3: return array('entity' => 'channel', 'page' => 'created_channel', 'text' => 'channel');
            case 4: return array('entity' => 'radio', 'page' => 'radio', 'text' => 'radio');
            case 5: return array('entity' => 'episode', 'page' => 'episode', 'text' => 'episode');
        }

        return array('entity' => 'stream', 'page' => '', 'text' => '');
    }

    /** Resolve the stream status code (-1…10) — mirrors the legacy cascade. */
    private function resolveStreamStatus(array $rItem, ?array $rServerItem): int {
        if (!$rServerItem) {
            return intval($rItem['direct_source']) == 1 ? 5 : -1;
        }

        if ($rItem['type'] == 1 || $rItem['type'] == 4) {
            if (intval($rItem['direct_source']) == 1) {
                return 5;
            }

            if ($rServerItem['monitor_pid']) {
                if ($rServerItem['pid'] && 0 < $rServerItem['pid']) {
                    return intval($rServerItem['stream_status']) == 2 ? 2 : 1;
                }

                return $rServerItem['stream_status'] == 0 ? 2 : 3;
            }

            return intval($rServerItem['on_demand']) == 1 ? 4 : 0;
        }

        if ($rItem['type'] == 2 || $rItem['type'] == 5) {
            if (intval($rItem['direct_source']) == 1) {
                return 5;
            }

            if (!is_null($rServerItem['pid']) && 0 < $rServerItem['pid']) {
                if ($rServerItem['to_analyze'] == 1) {
                    return 7;
                }

                return $rServerItem['stream_status'] == 1 ? 10 : 9;
            }

            return 8;
        }

        if ($rItem['type'] == 3) {
            $rStatus = 0;

            if ($rServerItem['monitor_pid']) {
                if ($rServerItem['pid'] && 0 < $rServerItem['pid']) {
                    $rStatus = intval($rServerItem['stream_status']) == 2 ? 2 : 1;
                } else {
                    $rStatus = $rServerItem['stream_status'] == 0 ? 2 : 3;
                }
            }

            if (!(count(json_decode($rServerItem['cchannel_rsources'], true)) == count(json_decode($rItem['stream_source'], true)) || $rServerItem['parent_id'])) {
                $rStatus = 6;
            }

            return $rStatus;
        }

        return 0;
    }

    /** Status cell payload: running uptime, encode progress, or a labelled badge. */
    private function streamStatusPayload(int $rCode, array $rItem, ?array $rServerItem): array {
        if ($rCode == 1 && $rServerItem) {
            $rUptime = time() - intval($rServerItem['stream_started']);
            $rText = (86400 <= $rUptime)
                ? sprintf('%02dd %02dh %02dm', $rUptime / 86400, ($rUptime / 3600) % 24, ($rUptime / 60) % 60)
                : sprintf('%02dh %02dm %02ds', $rUptime / 3600, ($rUptime / 60) % 60, $rUptime % 60);

            return array('kind' => 'uptime', 'text' => $rText);
        }

        if ($rCode == 6 && $rServerItem) {
            $rSources = json_decode($rItem['stream_source'], true) ?: array();
            $rLeft = count(array_diff($rSources, json_decode($rServerItem['cchannel_rsources'], true) ?: array()));
            $rPercent = count($rSources) ? (count($rSources) - $rLeft) / count($rSources) * 100 : 0;
            $rEncodeInfo = json_decode($rServerItem['progress_info'] ?? '', true);

            if (0 < $rLeft && isset($rEncodeInfo['cc_encode']['pct'])) {
                $rPercent += floatval($rEncodeInfo['cc_encode']['pct']) / count($rSources);
            }

            return array('kind' => 'progress', 'percent' => intval($rPercent));
        }

        return array('kind' => 'status') + $this->statusMeta($rCode);
    }

    /** Derive {code, label, variant} for a status code from StatusBadge::search(). */
    private function statusMeta(int $rCode): array {
        $rHtml = StatusBadge::search($rCode);
        preg_match('/bg-animate-(\w+)/', $rHtml, $rVariant);
        preg_match('/>([^<]+)</', $rHtml, $rLabel);

        return array('code' => $rCode, 'label' => trim($rLabel[1] ?? ''), 'variant' => $rVariant[1] ?? 'secondary');
    }

    /** Star-rating + year payload for VOD items. */
    private function ratingData($rRating, $rYear): array {
        $rStars = round(floatval($rRating)) / 2;
        $rFull = (int) floor($rStars);
        $rHalf = 0 < $rStars - $rFull;

        return array(
            'stars_full' => $rRating ? $rFull : 0,
            'half' => $rRating ? $rHalf : false,
            'empty' => $rRating ? 5 - ($rFull + ($rHalf ? 1 : 0)) : 0,
            'year' => $rYear ? (string) $rYear : '',
        );
    }

    /** Status badge for a line/device (banned/disabled/active). */
    private function lineStatus(array $rSource): array {
        if (!$rSource['admin_enabled']) {
            return array('label' => 'Banned', 'variant' => 'danger');
        }

        if (!$rSource['enabled']) {
            return array('label' => 'Disabled', 'variant' => 'warning');
        }

        return array('label' => 'Active', 'variant' => 'info');
    }

    /** Last-activity payload for a line/device. */
    private function lastActive(array $rSource, array $rCtx): array {
        $rInfo = $rCtx['rLinesInfo'][$rSource['id']] ?? (json_decode($rSource['last_activity_array'], true) ?? array());

        if (!is_array($rInfo)) {
            return array('online' => false, 'date' => null);
        }

        if (!empty($rInfo['online'])) {
            return array(
                'online' => true,
                'stream_id' => intval($rInfo['stream_id'] ?? 0),
                'stream_name' => $rCtx['rStreamNames'][$rInfo['stream_id'] ?? null] ?? '',
                'online_for' => TimeUtils::secondsToTime(time() - intval($rInfo['last_active'] ?? 0)),
            );
        }

        return array(
            'online' => false,
            'date' => !empty($rInfo['date_end']) ? date(SettingsManager::get('datetime_format'), $rInfo['date_end']) : null,
        );
    }

    /**
     * Action list for a line/device. Buttons target $rTargetId (the owning line);
     * ban/unban + enable/disable state comes from the row's own flags.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lineActions(int $rTargetId, string $rEditTarget, $rAdminEnabled, $rEnabled, int $rConnections): array {
        if (!Authorization::check('adv', 'edit_user')) {
            return array();
        }

        return array(
            array('kind' => 'navigate', 'target' => $rEditTarget, 'icon' => 'mdi-pencil', 'title' => 'Edit'),
            array('kind' => 'api', 'entity' => 'line', 'id' => $rTargetId, 'sub' => 'kill', 'icon' => 'fa-hammer', 'title' => 'Kill Connections', 'enabled' => true),
            $rAdminEnabled
                ? array('kind' => 'api', 'entity' => 'line', 'id' => $rTargetId, 'sub' => 'ban', 'icon' => 'mdi-power', 'title' => 'Ban', 'enabled' => true)
                : array('kind' => 'api', 'entity' => 'line', 'id' => $rTargetId, 'sub' => 'unban', 'icon' => 'mdi-power', 'title' => 'Unban', 'enabled' => true),
            $rEnabled
                ? array('kind' => 'api', 'entity' => 'line', 'id' => $rTargetId, 'sub' => 'disable', 'icon' => 'mdi-lock', 'title' => 'Disable', 'enabled' => true)
                : array('kind' => 'api', 'entity' => 'line', 'id' => $rTargetId, 'sub' => 'enable', 'icon' => 'mdi-lock', 'title' => 'Enable', 'enabled' => true),
            array('kind' => 'fingerprint', 'id' => $rTargetId, 'context' => 'user', 'icon' => 'mdi-fingerprint', 'enabled' => (bool) $rConnections),
        );
    }

    /** Action list for a stream/movie/etc. row. */
    private function streamActions(array $rItem, int $rActualStatus, string $rPage, int $rConnections): array {
        $rId = intval($rItem['id']);
        $rActions = array();

        if (in_array(intval($rItem['type']), array(1, 3, 4), true)) {
            if (!Authorization::check('adv', 'edit_stream')) {
                return $rActions;
            }

            $rActions[] = array('kind' => 'navigate', 'target' => $rPage . '?id=' . $rId, 'icon' => 'mdi-pencil', 'title' => 'Edit');
            $rRunning = in_array(intval($rActualStatus), array(1, 2, 3), true) || $rItem['on_demand'] == 1 || $rActualStatus == 5 || $rActualStatus == 7;

            $rActions[] = $rRunning
                ? array('kind' => 'api', 'entity' => 'stream', 'id' => $rId, 'sub' => 'stop', 'icon' => 'mdi-stop', 'title' => 'Stop', 'enabled' => true)
                : array('kind' => 'api', 'entity' => 'stream', 'id' => $rId, 'sub' => 'start', 'icon' => 'mdi-play', 'title' => 'Start', 'enabled' => true);
            $rActions[] = array('kind' => 'api', 'entity' => 'stream', 'id' => $rId, 'sub' => 'restart', 'icon' => 'mdi-refresh', 'title' => 'Restart', 'enabled' => $rRunning);
            $rActions[] = array('kind' => 'api', 'entity' => 'stream', 'id' => $rId, 'sub' => 'purge', 'icon' => 'mdi-hammer', 'title' => 'Purge', 'enabled' => $rRunning);

            if ($rItem['type'] == 1) {
                $rActions[] = array('kind' => 'fingerprint', 'id' => $rId, 'context' => 'stream', 'icon' => 'mdi-fingerprint', 'enabled' => (bool) $rConnections);
            }

            return $rActions;
        }

        if (!Authorization::check('adv', 'edit_' . $rPage)) {
            return $rActions;
        }

        $rActions[] = array('kind' => 'navigate', 'target' => $rPage . '?id=' . $rId, 'icon' => 'mdi-pencil', 'title' => 'Edit');

        if (intval($rActualStatus) == 9) {
            $rActions[] = array('kind' => 'api', 'entity' => $rPage, 'id' => $rId, 'sub' => 'start', 'icon' => 'mdi-refresh', 'title' => 'Re-Encode', 'enabled' => true);
        } elseif (intval($rActualStatus) == 5) {
            $rActions[] = array('kind' => 'api', 'entity' => $rPage, 'id' => $rId, 'sub' => 'stop', 'icon' => 'mdi-stop', 'title' => 'Stop', 'enabled' => false);
        } elseif (intval($rActualStatus) == 7) {
            $rActions[] = array('kind' => 'api', 'entity' => $rPage, 'id' => $rId, 'sub' => 'stop', 'icon' => 'mdi-stop', 'title' => 'Stop Encoding', 'enabled' => true);
        } else {
            $rActions[] = array('kind' => 'api', 'entity' => $rPage, 'id' => $rId, 'sub' => 'start', 'icon' => 'mdi-play', 'title' => 'Start Encoding', 'enabled' => true);
        }

        $rActions[] = array('kind' => 'api', 'entity' => $rPage, 'id' => $rId, 'sub' => 'purge', 'icon' => 'mdi-hammer', 'title' => 'Purge', 'enabled' => true);

        return $rActions;
    }
}
