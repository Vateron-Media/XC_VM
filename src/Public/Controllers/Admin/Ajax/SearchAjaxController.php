<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Util\TimeUtils;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\User\GroupService;

/**
 * Admin-ajax controller for the global "search" action.
 *
 * Extracted from the legacy `admin/api.php`. This is the single largest block
 * in the file: a fuzzy full-text search across lines, MAG/Enigma2 devices,
 * users, streams (live/VOD/created channels/radio/episodes) and series, each
 * rendered into a rich HTML result card.
 *
 * Given the size and the volume of literal HTML string-building, the body was
 * moved verbatim (not flattened) to guarantee identical output; only the
 * scaffolding around it changed. `$language` and `$rSearchStatusArray` are the
 * legacy bootstrap globals it relies on, alongside `$db` / `$rServers`.
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class SearchAjaxController extends BaseAjaxController {

    /** action=search — global fuzzy search rendered as HTML result cards. */
    public function search(): never {
        $this->requireXhr();

        /** @var class-string $language */
        global $db, $rServers, $language, $rSearchStatusArray;

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

            foreach ($rItems as $rItem) {
                $rTableInfo = $rTables[$rItem['table']];

                switch ($rItem['table']) {
                    case 'streams':
                        $rServerItem = ($rServerItems[$rItem['id']][0] ?: null);
                        $rCategoryIDs = json_decode($rItem['category_id'], true);
                        $rProperties = json_decode($rItem['movie_properties'], true) ?: array();

                        if ($rItem['type'] != 5) {
                            if (Authorization::check('adv', 'manage_streams')) {
                                $rTitle = "<span style='cursor: pointer;' onClick=\"navigate('stream_view?id=" . intval($rItem['id']) . "');\">" . $rItem['stream_display_name'] . '</span>';
                            } else {
                                $rTitle = $rItem['stream_display_name'];
                            }

                            $rCategory = ($rCategories[$rCategoryIDs[0]]['category_name'] ?: 'No Category');

                            if (1 < count($rCategoryIDs)) {
                                $rCategory .= ' (+' . (count($rCategoryIDs) - 1) . ')';
                            }
                        } else {
                            if (Authorization::check('adv', 'manage_streams')) {
                                $rTitle = ($rSeriesTitles[$rItem['id']] ? "<span style='cursor: pointer;' onClick=\"navigate('stream_view?id=" . intval($rItem['id']) . "');\">" . $rSeriesTitles[$rItem['id']] . '</span>' : 'No Series');
                            } else {
                                $rTitle = ($rSeriesTitles[$rItem['id']] ?: 'No Series');
                            }

                            if (stripos($rItem['stream_display_name'], $rTitle) === 0) {
                                $rCategory = ltrim(substr($rItem['stream_display_name'], strlen($rTitle), strlen($rItem['stream_display_name']) - strlen($rTitle)));

                                if (substr($rCategory, 0, 1) == '-') {
                                    $rCategory = trim(ltrim($rCategory, '-'));
                                }
                            }
                        }

                        if ($rItem['type'] == 2) {
                            $rRatingText = '';

                            if ($rProperties['rating']) {
                                $rStarRating = round($rProperties['rating']) / 2;
                                $rFullStars = floor($rStarRating);
                                $rHalfStar = 0 < $rStarRating - $rFullStars;
                                $rEmpty = 5 - ($rFullStars + (($rHalfStar ? 1 : 0)));

                                if (0 < $rFullStars) {
                                    foreach (range(1, $rFullStars) as $i) {
                                        $rRatingText .= "<i class='mdi mdi-star'></i>";
                                    }
                                }

                                if ($rHalfStar) {
                                    $rRatingText .= "<i class='mdi mdi-star-half'></i>";
                                }

                                if (0 < $rEmpty) {
                                    foreach (range(1, $rEmpty) as $i) {
                                        $rRatingText .= "<i class='mdi mdi-star-outline'></i>";
                                    }
                                }
                            }

                            $rYear = ($rItem['year'] ? '<strong>' . $rItem['year'] . '</strong> &nbsp;' : '');
                            $rTitle .= "<br><span style='font-size:11px;'>" . $rYear . $rRatingText . '</span></a>';
                        }

                        $rItem['server_id'] = ($rServerItem['server_id'] ?: null);

                        if ($rItem['server_id']) {
                            $rServerName = $rServers[$rItem['server_id']]['server_name'];

                            if (1 < $rServerCount[$rItem['id']]) {
                                $rServerName .= ' (+' . ($rServerCount[$rItem['id']] - 1) . ')';
                            }
                        } else {
                            $rServerName = '';
                        }

                        if ($rServerItem) {
                            $rUptime = time() - intval($rServerItem['stream_started']);

                            if ($rItem['type'] == 1 || $rItem['type'] == 4) {
                                if (intval($rItem['direct_source']) == 1) {
                                    $rActualStatus = 5;
                                } else {
                                    if ($rServerItem['monitor_pid']) {
                                        if ($rServerItem['pid'] && 0 < $rServerItem['pid']) {
                                            if (intval($rServerItem['stream_status']) == 2) {
                                                $rActualStatus = 2;
                                            } else {
                                                $rActualStatus = 1;
                                            }
                                        } else {
                                            if ($rServerItem['stream_status'] == 0) {
                                                $rActualStatus = 2;
                                            } else {
                                                $rActualStatus = 3;
                                            }
                                        }
                                    } else {
                                        if (intval($rServerItem['on_demand']) == 1) {
                                            $rActualStatus = 4;
                                        } else {
                                            $rActualStatus = 0;
                                        }
                                    }
                                }
                            } else {
                                if ($rItem['type'] == 2 || $rItem['type'] == 5) {
                                    if (intval($rItem['direct_source']) == 1) {
                                        $rActualStatus = 5;
                                    } else {
                                        if (!is_null($rServerItem['pid']) && 0 < $rServerItem['pid']) {
                                            if ($rServerItem['to_analyze'] == 1) {
                                                $rActualStatus = 7;
                                            } else {
                                                if ($rServerItem['stream_status'] == 1) {
                                                    $rActualStatus = 10;
                                                } else {
                                                    $rActualStatus = 9;
                                                }
                                            }
                                        } else {
                                            $rActualStatus = 8;
                                        }
                                    }
                                } else {
                                    if ($rItem['type'] == 3) {
                                        if ($rServerItem['monitor_pid']) {
                                            if ($rServerItem['pid'] && 0 < $rServerItem['pid']) {
                                                if (intval($rServerItem['stream_status']) == 2) {
                                                    $rActualStatus = 2;
                                                } else {
                                                    $rActualStatus = 1;
                                                }
                                            } else {
                                                if ($rServerItem['stream_status'] == 0) {
                                                    $rActualStatus = 2;
                                                } else {
                                                    $rActualStatus = 3;
                                                }
                                            }
                                        } else {
                                            $rActualStatus = 0;
                                        }

                                        if (!(count(json_decode($rServerItem['cchannel_rsources'], true)) == count(json_decode($rItem['stream_source'], true)) || $rServerItem['parent_id'])) {
                                            $rActualStatus = 6;
                                        }
                                    }
                                }
                            }
                        } else {
                            if (intval($rItem['direct_source']) == 1) {
                                $rActualStatus = 5;
                            } else {
                                $rActualStatus = -1;
                            }
                        }

                        if ($rActualStatus == 1) {
                            if (86400 <= $rUptime) {
                                $rUptime = sprintf('%02dd %02dh %02dm', $rUptime / 86400, ($rUptime / 3600) % 24, ($rUptime / 60) % 60);
                            } else {
                                $rUptime = sprintf('%02dh %02dm %02ds', $rUptime / 3600, ($rUptime / 60) % 60, $rUptime % 60);
                            }

                            $rUptime = "<button type='button' class='btn bg-animate-info btn-xs waves-effect waves-light no-border btn-fixed-xl'>" . $rUptime . '</button>';
                        } else {
                            if ($rActualStatus == 6) {
                                $rSources = json_decode($rItem['stream_source'], true);
                                $rLeft = count(array_diff($rSources, json_decode($rServerItem['cchannel_rsources'], true)));
                                $rPercent = (count($rSources) - $rLeft) / count($rSources) * 100;
                                $rEncodeInfo = json_decode($rServerItem['progress_info'] ?? '', true);
                                if (0 < $rLeft && isset($rEncodeInfo['cc_encode']['pct'])) {
                                    $rPercent += floatval($rEncodeInfo['cc_encode']['pct']) / count($rSources);
                                }
                                $rPercent = intval($rPercent);
                                $rUptime = "<button type='button' class='btn bg-animate-primary btn-xs waves-effect waves-light no-border btn-fixed-xl'>" . $rPercent . '% DONE</button>';
                            } else {
                                $rUptime = $rSearchStatusArray[$rActualStatus];
                            }
                        }

                        if ($rItem['type'] == 1) {
                            $rPageText = $rPage = 'stream';
                        } else {
                            if ($rItem['type'] == 2) {
                                $rPageText = $rPage = 'movie';
                            } else {
                                if ($rItem['type'] == 3) {
                                    $rPageText = 'channel';
                                    $rPage = 'created_channel';
                                } else {
                                    if ($rItem['type'] == 4) {
                                        $rPageText = $rPage = 'radio';
                                    } else {
                                        if ($rItem['type'] == 5) {
                                            $rPageText = $rPage = 'episode';
                                        }
                                    }
                                }
                            }
                        }

                        $rHasButtons = false;
                        $rButtons = '<div class="btn-group bg-animate-info">';

                        if (in_array($rItem['type'], array(1, 3, 4))) {
                            if (Authorization::check('adv', 'edit_stream')) {
                                $rHasButtons = true;
                                $rButtons .= "<button title=\"Edit\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onclick=\"navigate('" . $rPage . '?id=' . intval($rItem['id']) . "');\"><i class=\"mdi mdi-pencil\"></i></button>";

                                if (intval($rActualStatus) == 1 || intval($rActualStatus) == 2 || intval($rActualStatus) == 3 || $rItem['on_demand'] == 1 || $rActualStatus == 5 || $rActualStatus == 7) {
                                    $rButtons .= "<button title=\"Stop\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onclick=\"searchAPI('stream', " . intval($rItem['id']) . ", 'stop');\"><i class=\"mdi mdi-stop\"></i></button>";
                                    $rStatus = '';
                                } else {
                                    $rButtons .= "<button title=\"Start\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onclick=\"searchAPI('stream', " . intval($rItem['id']) . ", 'start');\"><i class=\"mdi mdi-play\"></i></button>";
                                    $rStatus = ' disabled';
                                }

                                $rButtons .= "<button title=\"Restart\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onclick=\"searchAPI('stream', " . intval($rItem['id']) . ", 'restart');\"" . $rStatus . '><i class="mdi mdi-refresh"></i></button>';
                                $rButtons .= "<button title=\"Purge\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onclick=\"searchAPI('stream', " . intval($rItem['id']) . ", 'purge');\"" . $rStatus . '><i class="mdi mdi-hammer"></i></button>';

                                if ($rItem['type'] == 1) {
                                    if (($rConnectionCount[$rItem['id']] ?: false)) {
                                        $rButtons .= '<button title="' . $language::get('fingerprint') . '" type="button" class="btn btn-xs waves-effect waves-light no-border tooltip" onClick="modalFingerprint(' . $rItem['id'] . ", 'stream');\"><i class=\"mdi mdi-fingerprint\"></i></button>";
                                    } else {
                                        $rButtons .= '<button type="button" disabled class="btn btn-xs waves-effect waves-light no-border tooltip"><i class="mdi mdi-fingerprint"></i></button>';
                                    }
                                }
                            }
                        } else {
                            if (Authorization::check('adv', 'edit_' . $rPage)) {
                                $rHasButtons = true;
                                $rButtons .= "<button title=\"Edit\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onclick=\"navigate('" . $rPage . '?id=' . intval($rItem['id']) . "');\"><i class=\"mdi mdi-pencil\"></i></button>";

                                if (intval($rActualStatus) == 9) {
                                    $rButtons .= "<button title=\"Re-Encode\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('" . $rPage . "', " . intval($rItem['id']) . ", 'start');\"><i class=\"mdi mdi-refresh\"></i></button>";
                                } else {
                                    if (intval($rActualStatus) == 5) {
                                        $rButtons .= '<button disabled type="button" class="btn btn-xs waves-effect waves-light no-border tooltip"><i class="mdi mdi-stop"></i></button>';
                                    } else {
                                        if (intval($rActualStatus) == 7) {
                                            $rButtons .= "<button title=\"Stop Encoding\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('" . $rPage . "', " . intval($rItem['id']) . ", 'stop');\"><i class=\"mdi mdi-stop\"></i></button>";
                                        } else {
                                            $rButtons .= "<button title=\"Start Encoding\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('" . $rPage . "', " . intval($rItem['id']) . ", 'start');\"><i class=\"mdi mdi-play\"></i></button>";
                                        }
                                    }
                                }

                                $rButtons .= "<button title=\"Purge\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onclick=\"searchAPI('" . $rPage . "', " . intval($rItem['id']) . ", 'purge');\"" . $rStatus . '><i class="mdi mdi-hammer"></i></button>';
                            }
                        }

                        $rButtons .= '</div>';

                        if (in_array($rItem['type'], array(1, 3, 4))) {
                            $rIcon = urlencode($rItem['stream_icon']);
                            $rHTML = '<div class="card-search text-white">' . "\n\t\t\t\t\t\t\t\t" . '<div class="card-body">' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="media align-items-center">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="col-9">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<h3 class="text-white my-1 text-truncate">' . $rTitle . '</h3>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<p class="text-white mb-1 text-truncate"><small>' . $rCategory . '<br/>' . $rServerName . '</small></p>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="col-3">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="float-right text-center search-icon">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<img src="resize?maxw=96&maxh=96&url=' . $rIcon . '" />' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '<div class="card-body action-block">' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="media align-items-center align-center">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-unstyled topnav-menu topnav-menu-left m-0" style="opacity: 80%; display: flex;">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<button type="button" class="btn bg-animate-success btn-xs waves-effect waves-light no-border">' . strtoupper($rPageText) . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . "<i class=\"fe-zap text-white\"></i> &nbsp; <button onClick=\"navigate('live_connections?stream_id=" . $rItem['id'] . "');\" type=\"button\" class=\"btn bg-animate-info btn-xs waves-effect waves-light no-border\">" . number_format($rConnectionCount[$rItem['id']] ?? 0, 0) . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-clock text-white"></i> &nbsp; ' . $rUptime . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>';

                            if ($rHasButtons) {
                                $rHTML .= '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-sliders text-white"></i> &nbsp; ' . $rButtons . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>';
                            }

                            $rHTML .= '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '</div>';
                        } else {
                            $rIcon = urlencode($rProperties['movie_image']);
                            $rHTML = '<div class="card-search text-white">' . "\n\t\t\t\t\t\t\t\t" . '<div class="search-fade">' . "\n\t\t\t\t\t\t\t\t\t" . "<div class=\"search-image\" style=\"background: url('resize?maxw=512&maxh=512&url=" . $rIcon . "');\"></div>" . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '<div class="card-body">' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="media align-items-center">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<h3 class="text-white my-1 text-truncate">' . $rTitle . '</h3>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<p class="text-white mb-1 text-truncate"><small>' . $rCategory . '<br/>' . $rServerName . '</small></p>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '<div class="card-body action-block">' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="media align-items-center align-center">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-unstyled topnav-menu topnav-menu-left m-0" style="opacity: 80%; display: flex;">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<button type="button" class="btn bg-animate-primary btn-xs waves-effect waves-light no-border">' . strtoupper($rPage) . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . "<i class=\"fe-zap text-white\"></i> &nbsp; <button onClick=\"navigate('live_connections?stream_id=" . $rItem['id'] . "');\" type=\"button\" class=\"btn bg-animate-info btn-xs waves-effect waves-light no-border\">" . number_format($rConnectionCount[$rItem['id']] ?? 0, 0) . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-clock text-white"></i> &nbsp; ' . $rUptime . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>';

                            if ($rHasButtons) {
                                $rHTML .= '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-sliders text-white"></i> &nbsp; ' . $rButtons . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>';
                            }

                            $rHTML .= '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '</div>';
                        }

                        break;

                    case 'streams_series':
                        $rSeriesItem = ($rSeriesInfo[$rItem['id']] ?: array());
                        $rCategoryIDs = json_decode($rItem['category_id'], true);
                        $rTitle = $rItem['title'];
                        $rRatingText = '';

                        if ($rItem['rating']) {
                            $rStarRating = round($rItem['rating']) / 2;
                            $rFullStars = floor($rStarRating);
                            $rHalfStar = 0 < $rStarRating - $rFullStars;
                            $rEmpty = 5 - ($rFullStars + (($rHalfStar ? 1 : 0)));

                            if (0 < $rFullStars) {
                                foreach (range(1, $rFullStars) as $i) {
                                    $rRatingText .= "<i class='mdi mdi-star'></i>";
                                }
                            }

                            if ($rHalfStar) {
                                $rRatingText .= "<i class='mdi mdi-star-half'></i>";
                            }

                            if (0 < $rEmpty) {
                                foreach (range(1, $rEmpty) as $i) {
                                    $rRatingText .= "<i class='mdi mdi-star-outline'></i>";
                                }
                            }
                        }

                        $rYear = ($rItem['year'] ? '<strong>' . $rItem['year'] . '</strong> &nbsp;' : '');
                        $rTitle .= "<br><span style='font-size:11px;'>" . $rYear . $rRatingText . '</span></a>';
                        $rCategory = ($rCategories[$rCategoryIDs[0]]['category_name'] ?: 'No Category');

                        if (1 < count($rCategoryIDs)) {
                            $rCategory .= ' (+' . (count($rCategoryIDs) - 1) . ')';
                        }

                        $rHasButtons = false;
                        $rButtons = '<div class="btn-group bg-animate-info">';

                        if (Authorization::check('adv', 'add_episode')) {
                            $rHasButtons = true;
                            $rButtons .= "<button title=\"Add Episode(s)\" onClick=\"navigate('episode?sid=" . $rItem['id'] . "');\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\"><i class=\"mdi mdi-plus-circle-outline\"></i></button>";
                        }

                        if (Authorization::check('adv', 'episodes')) {
                            $rHasButtons = true;
                            $rButtons .= "<button title=\"View Episodes\" onClick=\"navigate('episodes?series=" . $rItem['id'] . "');\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\"><i class=\"mdi mdi-eye\"></i></button>";
                        }

                        if (Authorization::check('adv', 'edit_series')) {
                            $rHasButtons = true;
                            $rButtons .= "<button title=\"Edit\" onClick=\"navigate('serie?id=" . $rItem['id'] . "');\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\"><i class=\"mdi mdi-pencil\"></i></button>";
                        }

                        $rButtons .= '</div>';
                        $rIcon = urlencode($rItem['cover']);
                        $rHTML = '<div class="card-search text-white">' . "\n\t\t\t\t\t\t\t" . '<div class="search-fade">' . "\n\t\t\t\t\t\t\t\t" . "<div class=\"search-image\" style=\"background: url('resize?maxw=512&maxh=512&url=" . $rIcon . "');\"></div>" . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '<div class="card-body">' . "\n\t\t\t\t\t\t\t\t" . '<div class="media align-items-center">' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<h3 class="text-white my-1 text-truncate">' . $rTitle . '</h3>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<p class="text-white mb-1 text-truncate"><small>' . $rCategory . '<br/>' . $rServerName . '</small></p>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '<div class="card-body action-block">' . "\n\t\t\t\t\t\t\t\t" . '<div class="media align-items-center align-center">' . "\n\t\t\t\t\t\t\t\t\t" . '<ul class="list-unstyled topnav-menu topnav-menu-left m-0" style="opacity: 80%; display: flex;">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<button type="button" class="btn bg-animate-danger btn-xs waves-effect waves-light no-border">' . $language::get('tv_series_btn') . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . 'S &nbsp; <button type="button" class="btn bg-animate-info btn-xs waves-effect waves-light no-border">' . number_format($rSeriesItem[0], 0) . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . 'E &nbsp; <button type="button" class="btn bg-animate-info btn-xs waves-effect waves-light no-border">' . number_format($rSeriesItem[1], 0) . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>';

                        if ($rHasButtons) {
                            $rHTML .= '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-sliders text-white"></i> &nbsp; ' . $rButtons . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>';
                        }

                        $rHTML .= '</ul>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t" . '</div>';

                        break;

                    case 'users':
                        $rUserCount = ($rUsersCount[$rItem['id']] ?: 0);
                        $rLineCount = ($rLinesCount[$rItem['id']] ?: 0);
                        $rOwnerName = ($rOwnerNames[$rItem['owner_id']] ?: null);
                        $rHasButtons = false;
                        $rButtons = '<div class="btn-group bg-animate-info">';

                        if (Authorization::check('adv', 'edit_reguser')) {
                            $rHasButtons = true;

                            if ($rGroups[$rItem['member_group_id']]['is_reseller']) {
                                $rButtons .= '<button title="' . $language::get('add_credits') . '" type="button" class="btn btn-xs waves-effect waves-light no-border tooltip" onClick="addCredits(' . $rItem['id'] . ');"><i class="mdi mdi-coin"></i></button>';
                            }

                            $rButtons .= "<button title=\"Edit\" onClick=\"navigate('user?id=" . $rItem['id'] . "');\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\"><i class=\"mdi mdi-pencil\"></i></button>";

                            if ($rItem['status'] == 1) {
                                $rButtons .= "<button title=\"Disable\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('user', " . $rItem['id'] . ", 'disable');\"><i class=\"mdi mdi-lock\"></i></button>";
                            } else {
                                $rButtons .= "<button title=\"Enable\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('user', " . $rItem['id'] . ", 'enable');\"><i class=\"mdi mdi-lock\"></i></button>";
                            }
                        }

                        $rButtons .= '</div>';

                        if ($rItem['status'] == 1) {
                            $rStatus = 'Active';
                            $rStatusColour = 'info';
                        } else {
                            $rStatus = 'Inactive';
                            $rStatusColour = 'warning';
                        }

                        $rHTML = '<div class="card-search text-white">' . "\n\t\t\t\t\t\t\t" . '<div class="card-body">' . "\n\t\t\t\t\t\t\t\t" . '<div class="media align-items-center">';

                        if ($rGroups[$rItem['member_group_id']]['is_reseller']) {
                            $rHTML .= '<div class="col-9">';
                        } else {
                            $rHTML .= '<div class="col-12">';
                        }

                        $rHTML .= '<div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<h3 class="text-white my-1 text-truncate">' . $rItem['username'] . '</h3>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<p class="text-lighter mb-1 text-truncate"><small>' . (($rGroups[$rItem['member_group_id']]['group_name'] ? '<span class="text-white">' . $rGroups[$rItem['member_group_id']]['group_name'] . '</span><br/>' : '')) . (($rOwnerName ? '<span class="text-white">owner:</span> ' . $rOwnerName : '')) . '</small></p>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>';

                        if ($rGroups[$rItem['member_group_id']]['is_reseller']) {
                            $rHTML .= '<div class="col-3">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="float-right text-center font-24 search-icon-xl">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-coin text-white"></i><br/>' . number_format($rItem['credits'], 0) . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>';
                        }

                        $rHTML .= '</div>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '<div class="card-body action-block">' . "\n\t\t\t\t\t\t\t\t" . '<div class="media align-items-center align-center">' . "\n\t\t\t\t\t\t\t\t\t" . '<ul class="list-unstyled topnav-menu topnav-menu-left m-0" style="opacity: 80%; display: flex;">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<button type="button" class="btn bg-animate-warning btn-xs waves-effect waves-light no-border">' . $language::get('user_btn') . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-user-check text-white"></i> &nbsp; <button type="button" class="btn bg-animate-' . $rStatusColour . ' btn-xs waves-effect waves-light no-border">' . $rStatus . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-users text-white"></i> &nbsp; <button type="button" class="btn bg-animate-info btn-xs waves-effect waves-light no-border">' . number_format($rUserCount, 0) . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-tv text-white"></i> &nbsp; <button type="button" class="btn bg-animate-info btn-xs waves-effect waves-light no-border">' . number_format($rLineCount, 0) . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>';

                        if ($rHasButtons) {
                            $rHTML .= '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-sliders text-white"></i> &nbsp; ' . $rButtons . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>';
                        }

                        $rHTML .= '</ul>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t" . '</div>';

                        break;

                    case 'lines':
                        $rOwnerName = ($rOwnerNames[$rItem['member_id']] ?: null);
                        $rHasButtons = false;
                        $rButtons = '<div class="btn-group bg-animate-info">';

                        if (Authorization::check('adv', 'edit_user')) {
                            $rHasButtons = true;
                            $rButtons .= "<button title=\"Edit\" onClick=\"navigate('line?id=" . $rItem['id'] . "');\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\"><i class=\"mdi mdi-pencil\"></i></button>";
                            $rButtons .= "<button title=\"Kill Connections\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('line', " . $rItem['id'] . ", 'kill');\"><i class=\"fas fa-hammer\"></i></button>";

                            if ($rItem['admin_enabled']) {
                                $rButtons .= "<button title=\"Ban Line\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('line', " . $rItem['id'] . ", 'ban');\"><i class=\"mdi mdi-power\"></i></button>";
                            } else {
                                $rButtons .= "<button title=\"Unban Line\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('line', " . $rItem['id'] . ", 'unban');\"><i class=\"mdi mdi-power\"></i></button>";
                            }

                            if ($rItem['enabled']) {
                                $rButtons .= "<button title=\"Disable Line\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('line', " . $rItem['id'] . ", 'disable');\"><i class=\"mdi mdi-lock\"></i></button>";
                            } else {
                                $rButtons .= "<button title=\"Enable Line\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('line', " . $rItem['id'] . ", 'enable');\"><i class=\"mdi mdi-lock\"></i></button>";
                            }

                            if (($rLineConnectionCount[$rItem['id']] ?: false)) {
                                $rButtons .= '<button title="' . $language::get('fingerprint') . '" type="button" class="btn btn-xs waves-effect waves-light no-border tooltip" onClick="modalFingerprint(' . $rItem['id'] . ", 'user');\"><i class=\"mdi mdi-fingerprint\"></i></button>";
                            } else {
                                $rButtons .= '<button type="button" disabled class="btn btn-xs waves-effect waves-light no-border tooltip"><i class="mdi mdi-fingerprint"></i></button>';
                            }
                        }

                        $rButtons .= '</div>';

                        if (!$rItem['admin_enabled']) {
                            $rStatus = 'Banned';
                            $rStatusColour = 'danger';
                        } else {
                            if (!$rItem['enabled']) {
                                $rStatus = 'Disabled';
                                $rStatusColour = 'warning';
                            } else {
                                $rStatus = 'Active';
                                $rStatusColour = 'info';
                            }
                        }

                        $rLastInfo = (isset($rLinesInfo[$rItem['id']]) ? $rLinesInfo[$rItem['id']] : json_decode($rItem['last_activity_array'], true) ?? []);

                        if (is_array($rLastInfo)) {
                            $rLastInfo['stream_display_name'] = $rStreamNames[$rLastInfo['stream_id']];

                            if ($rLastInfo['online']) {
                                $rLastInfoText = "<a class='text-white' href='javascript:void(0);' onClick=\"navigate('stream_view?id=" . intval($rLastInfo['stream_id']) . "');\">" . $rLastInfo['stream_display_name'] . "</a><br/><small class='text-lighter'>Online: " . TimeUtils::secondsToTime(time() - $rLastInfo['last_active']) . '</small>';
                            } else {
                                $rLastInfoText = "Last Active<br/><small class='text-lighter'>" . (($rLastInfo['date_end'] ? date(SettingsManager::get('date_format'), $rLastInfo['date_end']) . '<br/>' . date('H:i:s', $rLastInfo['date_end']) : 'Never')) . '</small>';
                            }
                        } else {
                            $rLastInfoText = "Last Active<br/><small class='text-lighter'>Never</small>";
                        }

                        $rExpires = ($rItem['exp_date'] ? date(SettingsManager::get('datetime_format'), $rItem['exp_date']) : null);
                        $rHTML = '<div class="card-search text-white">' . "\n\t\t\t\t\t\t\t" . '<div class="card-body">' . "\n\t\t\t\t\t\t\t\t" . '<div class="media align-items-center">' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="col-9">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<h3 class="text-white my-1 text-truncate">' . $rItem['username'] . '</h3>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<p class="text-lighter mb-1 text-truncate"><small>' . (($rExpires ? '<span class="text-white">expires:</span> ' . $rExpires . '<br/>' : '')) . (($rOwnerName ? '<span class="text-white">owner:</span> ' . $rOwnerName : '')) . '</small></p>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="col-3">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="float-right text-center search-icon-xl mt-1">' . $rLastInfoText . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '<div class="card-body action-block">' . "\n\t\t\t\t\t\t\t\t" . '<div class="media align-items-center align-center">' . "\n\t\t\t\t\t\t\t\t\t" . '<ul class="list-unstyled topnav-menu topnav-menu-left m-0" style="opacity: 80%; display: flex;">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<button type="button" class="btn bg-animate-pink btn-xs waves-effect waves-light no-border">' . (($rItem['is_restreamer'] ? "<i title='Restreamer' class='mdi mdi-swap-horizontal tooltip'></i> " : ($rItem['is_trial'] ? "<i title='Trial' class='mdi mdi-gavel tooltip'></i> " : ''))) . 'LINE</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-user-check text-white"></i> &nbsp; <button type="button" class="btn bg-animate-' . $rStatusColour . ' btn-xs waves-effect waves-light no-border">' . $rStatus . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-zap text-white"></i> &nbsp; <button type="button" class="btn bg-animate-info btn-xs waves-effect waves-light no-border">' . number_format(($rLineConnectionCount[$rItem['id']] ?: 0), 0) . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>';

                        if ($rHasButtons) {
                            $rHTML .= '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-sliders text-white"></i> &nbsp; ' . $rButtons . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>';
                        }

                        $rHTML .= '</ul>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t" . '</div>';

                        break;

                    case 'enigma_devices':
                    case 'mag_devices':
                        $rLineInfo = ($rDeviceLines[$rItem['user_id']] ?: null);

                        if ($rLineInfo) {
                            $rDeviceType = ($rItem['table'] == 'mag_devices' ? 'mag' : 'enigma');
                            $rOwnerName = ($rOwnerNames[$rLineInfo['member_id']] ?: null);
                            $rHasButtons = false;
                            $rButtons = '<div class="btn-group bg-animate-info">';

                            if (Authorization::check('adv', 'edit_user')) {
                                $rHasButtons = true;
                                $rButtons .= "<button title=\"Edit\" onClick=\"navigate('" . $rDeviceType . '?id=' . $rItem['id'] . "');\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\"><i class=\"mdi mdi-pencil\"></i></button>";
                                $rButtons .= "<button title=\"Kill Connection\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('line', " . $rLineInfo['id'] . ", 'kill');\"><i class=\"fas fa-hammer\"></i></button>";

                                if ($rItem['admin_enabled']) {
                                    $rButtons .= "<button title=\"Ban Device\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('line', " . $rLineInfo['id'] . ", 'ban');\"><i class=\"mdi mdi-power\"></i></button>";
                                } else {
                                    $rButtons .= "<button title=\"Unban Device\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('line', " . $rLineInfo['id'] . ", 'unban');\"><i class=\"mdi mdi-power\"></i></button>";
                                }

                                if ($rItem['enabled']) {
                                    $rButtons .= "<button title=\"Disable Device\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('line', " . $rLineInfo['id'] . ", 'disable');\"><i class=\"mdi mdi-lock\"></i></button>";
                                } else {
                                    $rButtons .= "<button title=\"Enable Device\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('line', " . $rLineInfo['id'] . ", 'enable');\"><i class=\"mdi mdi-lock\"></i></button>";
                                }

                                if (($rLineConnectionCount[$rLineInfo['id']] ?: false)) {
                                    $rButtons .= '<button title="' . $language::get('fingerprint') . '" type="button" class="btn btn-xs waves-effect waves-light no-border tooltip" onClick="modalFingerprint(' . $rLineInfo['id'] . ", 'user');\"><i class=\"mdi mdi-fingerprint\"></i></button>";
                                } else {
                                    $rButtons .= '<button type="button" disabled class="btn btn-xs waves-effect waves-light no-border tooltip"><i class="mdi mdi-fingerprint"></i></button>';
                                }
                            }

                            $rButtons .= '</div>';

                            if (!$rLineInfo['admin_enabled']) {
                                $rStatus = 'Banned';
                                $rStatusColour = 'danger';
                            } else {
                                if (!$rLineInfo['enabled']) {
                                    $rStatus = 'Disabled';
                                    $rStatusColour = 'warning';
                                } else {
                                    $rStatus = 'Active';
                                    $rStatusColour = 'info';
                                }
                            }

                            $rLastInfo = (isset($rLinesInfo[$rLineInfo['id']]) ? $rLinesInfo[$rLineInfo['id']] : json_decode($rLineInfo['last_activity_array'], true) ?? []);

                            if (is_array($rLastInfo)) {
                                $rLastInfo['stream_display_name'] = $rStreamNames[$rLastInfo['stream_id']];

                                if ($rLastInfo['online']) {
                                    $rLastInfoText = "<a class='text-white' href='javascript:void(0);' onClick=\"navigate('stream_view?id=" . intval($rLastInfo['stream_id']) . "');\">" . $rLastInfo['stream_display_name'] . "</a><br/><small class='text-lighter'>Online: " . TimeUtils::secondsToTime(time() - $rLastInfo['last_active']) . '</small>';
                                } else {
                                    $rLastInfoText = "Last Active<br/><small class='text-lighter'>" . (($rLastInfo['date_end'] ? date(SettingsManager::get('date_format'), $rLastInfo['date_end']) . '<br/>' . date('H:i:s', $rLastInfo['date_end']) : 'Never')) . '</small>';
                                }
                            } else {
                                $rLastInfoText = "Last Active<br/><small class='text-lighter'>Never</small>";
                            }

                            $rExpires = ($rLineInfo['exp_date'] ? date(SettingsManager::get('datetime_format'), $rLineInfo['exp_date']) : null);
                            $rHTML = '<div class="card-search text-white">' . "\n\t\t\t\t\t\t\t" . '<div class="card-body">' . "\n\t\t\t\t\t\t\t\t" . '<div class="media align-items-center">' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="col-9">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<h3 class="text-white my-1 text-truncate">' . $rItem['mac'] . '</h3>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<p class="text-lighter mb-1 text-truncate"><small>' . (($rExpires ? '<span class="text-white">expires:</span> ' . $rExpires . '<br/>' : '')) . (($rOwnerName ? '<span class="text-white">owner:</span> ' . $rOwnerName : '')) . '</small></p>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="col-3">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="float-right text-center search-icon-xl mt-1">' . $rLastInfoText . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '<div class="card-body action-block">' . "\n\t\t\t\t\t\t\t\t" . '<div class="media align-items-center align-center">' . "\n\t\t\t\t\t\t\t\t\t" . '<ul class="list-unstyled topnav-menu topnav-menu-left m-0" style="opacity: 80%; display: flex;">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<button type="button" class="btn bg-animate-pink btn-xs waves-effect waves-light no-border">' . (($rLineInfo['is_trial'] ? "<i class='mdi mdi-gavel'></i> " : '')) . strtoupper($rDeviceType) . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-user-check text-white"></i> &nbsp; <button type="button" class="btn bg-animate-' . $rStatusColour . ' btn-xs waves-effect waves-light no-border">' . $rStatus . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-zap text-white"></i> &nbsp; <button type="button" class="btn bg-animate-info btn-xs waves-effect waves-light no-border">' . number_format(($rLineConnectionCount[$rLineInfo['id']] ?: 0), 0) . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>';

                            if ($rHasButtons) {
                                $rHTML .= '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-sliders text-white"></i> &nbsp; ' . $rButtons . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>';
                            }

                            $rHTML .= '</ul>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t" . '</div>';

                            break;
                        }

                        break;
                }
                $rReturn['items'][] = array('id' => $rTable . '#' . $rItem[$rTableInfo[3]], 'url' => $rTableInfo[1] . $rItem[$rTableInfo[3]], 'text' => $rItem[$rTableInfo[4]], 'html' => $rHTML);
            }
        }

        $rReturn['total_count'] = count($rReturn['items']);

        if ($rReturn['total_count'] == 0) {
            $rHTML = '<div class="card-search text-white">' . "\n\t\t\t\t" . '<div class="card-body">' . "\n\t\t\t\t\t" . '<div class="media align-items-center">' . "\n\t\t\t\t\t\t" . '<div class="col-9">' . "\n\t\t\t\t\t\t\t" . '<div>' . "\n\t\t\t\t\t\t\t\t" . '<h3 class="text-white my-1 text-truncate">No Results Found</h3>' . "\n\t\t\t\t\t\t\t\t" . "<p class=\"text-lighter mb-1\"><small>Try refining your search or manually locating the content you're looking for.</small></p>" . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t" . '<div class="col-3">' . "\n\t\t\t\t\t\t\t" . '<div class="float-right text-center search-icon-xl mt-1" style="font-size: 72px;"><i class="fe-alert-circle"></i></div>' . "\n\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t" . '</div>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>';
            $rReturn['items'][] = array('id' => 'no_results', 'url' => null, 'text' => 'No Results', 'html' => $rHTML);
        }

        echo json_encode($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);

        exit();
    }
}
