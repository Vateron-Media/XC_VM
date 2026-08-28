<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\ImageUtils;
use XcVm\Domain\Epg\EpgService;
use XcVm\Domain\Stream\CategoryService;

/**
 * Admin-ajax controller for the "EPG" group.
 *
 * Extracted from the legacy `admin/api.php`. Actions: epg, epglist, force_epg,
 * epg_auto_assign, epg_categories, provider_import_epg, get_epg, get_programme.
 * Block logic ported faithfully (scaffolding via gate/ok/fail from
 * {@see BaseAjaxController}; empty-then / nested if-else cascades flattened —
 * behaviour-preserving; comments English).
 *
 * A few actions answer with a `{"status": STATUS_*}` envelope (not the usual
 * `{"result": …}`), including on the permission-denied path, so those gate
 * inline via {@see Authorization::check()} instead of {@see self::gate()}.
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class EpgAjaxController extends BaseAjaxController {

    /** action=epg — delete or reload a single EPG source. */
    public function epg(): never {
        $this->requireXhr();
        $this->gate('adv', 'epg_edit');

        $rSub = RequestManager::get('sub');

        if ($rSub == 'delete') {
            EpgService::deleteEpgById(RequestManager::get('epg_id'));
            $this->ok();
        }

        if ($rSub == 'reload') {
            shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:epg "' . intval(RequestManager::get('epg_id')) . '" > /dev/null 2>/dev/null &');
            $this->ok();
        }

        $this->fail();
    }

    /** action=epglist — Select2 search over EPG channels grouped by source. */
    public function epglist(): never {
        $this->requireXhr();
        $this->gate('adv', 'import_streams');

        global $db;
        $rReturn = array('total_count' => 0, 'items' => array(), 'result' => true);

        if (RequestManager::has('search')) {
            $rEPGNames = $rEPGMap = array();
            $db->query('SELECT `epg_channels`.`epg_id`, `epg_channels`.`channel_id`, `epg_channels`.`name`, `epg_channels`.`langs`, `epg`.`epg_name` FROM `epg_channels` LEFT JOIN `epg` ON `epg_channels`.`epg_id` = `epg`.`id` WHERE (LOWER(`epg_channels`.`channel_id`) LIKE ? OR LOWER(`epg_channels`.`name`) LIKE ?) ORDER BY `epg_channels`.`name` ASC LIMIT 50;', strtolower(RequestManager::get('search')) . '%', strtolower(RequestManager::get('search')) . '%');

            foreach ($db->get_rows() as $rRow) {
                if (!isset($rEPGNames[$rRow['epg_id']])) {
                    $rEPGNames[$rRow['epg_id']] = $rRow['epg_name'];
                }

                $rLangs = json_decode($rRow['langs'], true);
                $rEPGMap[$rRow['epg_id']][] = array('id' => $rRow['channel_id'], 'text' => $rRow['name'], 'icon' => null, 'lang' => (isset($rLangs[0]) ? $rLangs[0] : ''), 'epg_id' => $rRow['epg_id'], 'type' => 0);
            }

            foreach ($rEPGMap as $rEPGID => $rResults) {
                $rReturn['items'][] = array('text' => $rEPGNames[$rEPGID], 'children' => $rResults);
                $rReturn['total_count'] += count($rResults);
            }
        }

        $this->json($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /** action=force_epg — trigger a full EPG cron run. */
    public function forceEpg(): never {
        $this->requireXhr();
        $this->gate('adv', 'epg');

        shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:epg > /dev/null 2>/dev/null &');

        $this->ok();
    }

    /** action=epg_auto_assign — fuzzy-match streams to EPG channels in batches. */
    public function epgAutoAssign(): never {
        $this->requireXhr();

        if (!Authorization::check('adv', 'stream_tools')) {
            $this->json(array('status' => STATUS_FAILURE));
        }

        global $db;
        set_time_limit(120);
        $rLastId    = max(0, intval(RequestManager::get('last_id') ?? 0));
        $rThreshold = min(100, max(50, intval(RequestManager::get('threshold') ?? 80)));
        $rCatId     = intval(RequestManager::get('category_id') ?? 0);
        $rLimit     = 300;

        $fnNormalize = function (string $name): string {
            $name = strtolower($name);
            $name = preg_replace('/\b(fhd|uhd|4k|hd|sd|hq|lq|\+1|plus)\b/i', '', $name);
            $name = preg_replace('/[^a-z0-9]/i', '', $name);
            return trim($name);
        };

        // Base WHERE clause — optionally filter by category
        $rCatWhere = $rCatId > 0
            ? ' AND JSON_CONTAINS(`category_id`, \'' . $rCatId . '\')'
            : '';

        $db->query('SELECT COUNT(*) AS `cnt` FROM `streams` WHERE `type` = 1' . $rCatWhere . ' AND (`epg_id` IS NULL OR `channel_id` IS NULL OR `channel_id` = ?);', '');
        $rTotal = intval($db->get_row()['cnt']);

        // --- Lookup 1: EPG channels by name ---
        $db->query('SELECT `epg_id`, `channel_id`, `name`, `langs` FROM `epg_channels`;');
        $rEpgLookup = array();
        // Also build channel_id -> epg info map for provider cross-reference
        $rEpgByChannelId = array();
        foreach ($db->get_rows() as $rEpgCh) {
            $rNorm = $fnNormalize($rEpgCh['name']);
            $rLang = json_decode($rEpgCh['langs'] ?? '[]', true)[0] ?? '';
            if ($rNorm !== '') {
                $rEpgLookup[] = array(
                    'epg_id'     => intval($rEpgCh['epg_id']),
                    'channel_id' => $rEpgCh['channel_id'],
                    'norm'       => $rNorm,
                    'lang'       => $rLang,
                );
            }
            if (!isset($rEpgByChannelId[$rEpgCh['channel_id']])) {
                $rEpgByChannelId[$rEpgCh['channel_id']] = array(
                    'epg_id' => intval($rEpgCh['epg_id']),
                    'lang'   => $rLang,
                );
            }
        }

        // --- Lookup 2: Provider streams whose channel_id exists in epg_channels ---
        $db->query(
            'SELECT DISTINCT `ps`.`stream_display_name`, `ps`.`channel_id` FROM `providers_streams` `ps`
             INNER JOIN (SELECT DISTINCT `channel_id` FROM `epg_channels`) `ec` ON `ps`.`channel_id` = `ec`.`channel_id`
             WHERE `ps`.`type` = ? AND `ps`.`channel_id` IS NOT NULL AND `ps`.`channel_id` != ?;',
            'live',
            ''
        );
        $rProviderLookup = array();
        foreach ($db->get_rows() as $rPs) {
            $rNorm = $fnNormalize($rPs['stream_display_name']);
            if ($rNorm === '') {
                continue;
            }
            // Keep first occurrence per normalised name
            if (!isset($rProviderLookup[$rNorm])) {
                $rProviderLookup[$rNorm] = $rPs['channel_id'];
            }
        }

        // --- Batch of streams to process ---
        $db->query(
            'SELECT `id`, `stream_display_name` FROM `streams` WHERE `type` = 1' . $rCatWhere . ' AND `id` > ? AND (`epg_id` IS NULL OR `channel_id` IS NULL OR `channel_id` = ?) ORDER BY `id` ASC LIMIT ' . $rLimit . ';',
            $rLastId,
            ''
        );
        $rStreams    = $db->get_rows();
        $rAssigned   = 0;
        $rSkipped    = 0;
        $rNextLastId = $rLastId;

        foreach ($rStreams as $rStream) {
            $rNextLastId = max($rNextLastId, intval($rStream['id']));
            $rNorm = $fnNormalize($rStream['stream_display_name']);
            if ($rNorm === '') {
                $rSkipped++;
                continue;
            }

            $rMatch = null;

            // --- Step 1: match against EPG channel names ---
            $rBestScore = 0;
            $rBestEpg   = null;
            foreach ($rEpgLookup as $rEpgCh) {
                similar_text($rNorm, $rEpgCh['norm'], $rPct);
                if ($rPct > $rBestScore) {
                    $rBestScore = $rPct;
                    $rBestEpg   = $rEpgCh;
                }
            }
            if ($rBestScore >= $rThreshold && $rBestEpg !== null) {
                $rMatch = $rBestEpg;
            }

            // --- Step 2: if no match, cross-reference via provider streams ---
            if ($rMatch === null) {
                $rBestScore = 0;
                $rBestChId  = null;
                foreach ($rProviderLookup as $rProvNorm => $rChId) {
                    similar_text($rNorm, $rProvNorm, $rPct);
                    if ($rPct > $rBestScore) {
                        $rBestScore = $rPct;
                        $rBestChId  = $rChId;
                    }
                }
                if ($rBestScore >= $rThreshold && $rBestChId !== null && isset($rEpgByChannelId[$rBestChId])) {
                    $rMatch = array(
                        'epg_id'     => $rEpgByChannelId[$rBestChId]['epg_id'],
                        'channel_id' => $rBestChId,
                        'lang'       => $rEpgByChannelId[$rBestChId]['lang'],
                    );
                }
            }

            if ($rMatch !== null) {
                $db->query(
                    'UPDATE `streams` SET `epg_id` = ?, `channel_id` = ?, `epg_lang` = ? WHERE `id` = ?;',
                    $rMatch['epg_id'],
                    $rMatch['channel_id'],
                    $rMatch['lang'],
                    intval($rStream['id'])
                );
                $rAssigned++;
            } else {
                $rSkipped++;
            }
        }

        $this->json(array(
            'status' => STATUS_SUCCESS,
            'data'   => array(
                'assigned'     => $rAssigned,
                'skipped'      => $rSkipped,
                'batch_size'   => count($rStreams),
                'total'        => $rTotal,
                'has_more'     => count($rStreams) === $rLimit,
                'next_last_id' => $rNextLastId,
            ),
        ));
    }

    /** action=epg_categories — live stream categories for the auto-assign UI. */
    public function epgCategories(): never {
        $this->requireXhr();

        if (!Authorization::check('adv', 'stream_tools')) {
            $this->json(array('status' => STATUS_FAILURE));
        }

        global $db;
        $db->query('SELECT `id`, `category_name` FROM `streams_categories` WHERE `category_type` = ? ORDER BY `cat_order` ASC;', 'live');

        $this->json(array('status' => STATUS_SUCCESS, 'data' => $db->get_rows()));
    }

    /** action=provider_import_epg — create (or find) an EPG source from a provider's xmltv. */
    public function providerImportEpg(): never {
        $this->requireXhr();

        if (!Authorization::check('adv', 'providers')) {
            $this->json(array('status' => STATUS_FAILURE));
        }

        global $db;
        $rProviderId = intval(RequestManager::get('provider_id') ?? 0);

        if ($rProviderId <= 0) {
            $this->json(array('status' => STATUS_FAILURE, 'data' => 'Invalid provider'));
        }

        $db->query('SELECT `id`, `name`, `ip`, `port`, `username`, `password`, `ssl`, `enabled` FROM `providers` WHERE `id` = ? LIMIT 1;', $rProviderId);
        $rProv = $db->get_row();

        if (!$rProv) {
            $this->json(array('status' => STATUS_FAILURE, 'data' => 'Provider not found'));
        }

        if (!$rProv['enabled']) {
            $this->json(array('status' => STATUS_FAILURE, 'data' => 'Provider is disabled'));
        }

        $rScheme = $rProv['ssl'] ? 'https' : 'http';
        $rEpgUrl = $rScheme . '://' . $rProv['ip'] . ':' . $rProv['port'] . '/xmltv.php?username=' . urlencode($rProv['username']) . '&password=' . urlencode($rProv['password']);
        $db->query('SELECT `id` FROM `epg` WHERE `epg_file` = ? LIMIT 1;', $rEpgUrl);
        $rExisting = $db->get_row();

        if ($rExisting) {
            $this->json(array('status' => 2, 'data' => array('id' => $rExisting['id'], 'url' => $rEpgUrl)));
        }

        $rRawName = $rProv['name'];
        $rEpgName = (filter_var($rRawName, FILTER_VALIDATE_URL) ? $rProv['ip'] : $rRawName) . ' (Provider EPG)';
        $db->query('INSERT INTO `epg` (`epg_name`, `epg_file`, `days_keep`, `last_updated`, `data`, `offset`) VALUES (?, ?, 7, 0, NULL, 0);', $rEpgName, $rEpgUrl);
        $rNewId = $db->last_insert_id();

        $this->json(array('status' => STATUS_SUCCESS, 'data' => array('id' => $rNewId, 'name' => $rEpgName, 'url' => $rEpgUrl)));
    }

    /** action=get_epg — TV-guide grid (channels + timed listings) for the given streams. */
    public function getEpg(): never {
        $this->requireXhr();
        $this->gate('adv', 'manage_streams');

        global $db;
        $rTimezone = (RequestManager::get('timezone') ?: 'Europe/London');
        date_default_timezone_set($rTimezone);
        $rReturn = array('Channels' => array());
        $rChannels = array_map('intval', explode(',', RequestManager::get('channels')));

        if (count($rChannels) != 0) {
            $rHours = (intval(RequestManager::get('hours')) ?: 3);
            $rStartDate = (intval(strtotime(RequestManager::get('startdate'))) ?: time());
            $rFinishDate = $rStartDate + $rHours * 3600;
            $rPerUnit = floatval(100 / ($rHours * 60));
            $rListings = array();

            $rArchiveInfo = array();
            $db->query('SELECT `id`, `tv_archive_server_id`, `tv_archive_duration` FROM `streams` WHERE `id` IN (' . implode(',', $rChannels) . ');');

            if (0 < $db->num_rows()) {
                foreach ($db->get_rows() as $rRow) {
                    $rArchiveInfo[$rRow['id']] = $rRow;
                }
            }

            $rEPG = EpgService::getStreamsEpg($rChannels, $rStartDate, $rFinishDate);

            foreach ($rEPG as $rChannelID => $rEPGData) {
                $rFullSize = 0;

                foreach ($rEPGData as $rEPGItem) {
                    $rCapStart = ($rEPGItem['start'] < $rStartDate ? $rStartDate : $rEPGItem['start']);
                    $rCapEnd = ($rFinishDate < $rEPGItem['end'] ? $rFinishDate : $rEPGItem['end']);
                    $rDuration = ($rCapEnd - $rCapStart) / 60;
                    $rArchive = null;

                    if (
                        isset($rArchiveInfo[$rChannelID])
                        && 0 < $rArchiveInfo[$rChannelID]['tv_archive_server_id']
                        && 0 < $rArchiveInfo[$rChannelID]['tv_archive_duration']
                        && time() - $rArchiveInfo[$rChannelID]['tv_archive_duration'] * 86400 <= $rEPGItem['start']
                    ) {
                        $rArchive = array($rEPGItem['start'], intval(($rEPGItem['end'] - $rEPGItem['start']) / 60));
                    }

                    $rRelativeSize = round($rDuration * $rPerUnit, 2);
                    $rFullSize += $rRelativeSize;

                    if (100 < $rFullSize) {
                        $rRelativeSize -= $rFullSize - 100;
                    }

                    $rListings[$rChannelID][] = array('ListingId' => $rEPGItem['id'], 'ChannelId' => $rChannelID, 'Title' => $rEPGItem['title'], 'RelativeSize' => $rRelativeSize, 'StartTime' => date('h:iA', $rCapStart), 'EndTime' => date('h:iA', $rCapEnd), 'Start' => $rEPGItem['start'], 'End' => $rEPGItem['end'], 'Specialisation' => 'tv', 'Archive' => $rArchive);
                }
            }

            $rDefaultEPG = array('ChannelId' => null, 'Title' => 'No Programme Information...', 'RelativeSize' => 100, 'StartTime' => 'Not Available', 'EndTime' => '', 'Specialisation' => 'tv', 'Archive' => null);
            $db->query('SELECT `id`, `stream_icon`, `stream_display_name`, `tv_archive_duration`, `tv_archive_server_id`, `category_id` FROM `streams` WHERE `id` IN (' . implode(',', $rChannels) . ') ORDER BY FIELD(`id`, ' . implode(',', $rChannels) . ') ASC;');

            foreach ($db->get_rows() as $rStream) {
                if (0 < $rStream['tv_archive_duration'] && 0 < $rStream['tv_archive_server_id']) {
                    $rArchive = $rStream['tv_archive_duration'];
                } else {
                    $rArchive = 0;
                }

                $rDefaultArray = $rDefaultEPG;
                $rDefaultArray['ChannelId'] = $rStream['id'];
                $rCategoryIDs = json_decode($rStream['category_id'], true);
                $rCategories = CategoryService::getAllByType('live');

                if (0 < strlen(RequestManager::get('category'))) {
                    $rCategory = ($rCategories[intval(RequestManager::get('category'))]['category_name'] ?: 'No Category');
                } else {
                    $rCategory = ($rCategories[$rCategoryIDs[0]]['category_name'] ?: 'No Category');
                }

                if (1 < count($rCategoryIDs)) {
                    $rCategory .= ' (+' . (count($rCategoryIDs) - 1) . ' others)';
                }

                $rReturn['Channels'][] = array('Id' => $rStream['id'], 'DisplayName' => $rStream['stream_display_name'], 'CategoryName' => $rCategory, 'Archive' => $rArchive, 'Image' => (ImageUtils::validateURL($rStream['stream_icon']) ?: ''), 'TvListings' => (($rListings[$rStream['id']] ?? array()) ?: array($rDefaultArray)));
            }
        }

        $this->json($rReturn);
    }

    /** action=get_programme — single programme details + live/archive availability. */
    public function getProgramme(): never {
        $this->requireXhr();
        $this->gate('adv', 'manage_streams');

        global $db;
        $rTimezone = (RequestManager::get('timezone') ?: 'Europe/London');
        date_default_timezone_set($rTimezone);

        if (RequestManager::has('id')) {
            $rRow = EpgService::getProgramme(RequestManager::get('stream_id'), RequestManager::get('id'));

            if ($rRow) {
                $rArchive = $rAvailable = false;

                if (time() < $rRow['end']) {
                    $db->query('SELECT `server_id`, `direct_source`, `monitor_pid`, `pid`, `stream_status`, `on_demand` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `streams`.`id` = ? AND `server_id` IS NOT NULL;', RequestManager::get('stream_id'));

                    if (0 < $db->num_rows()) {
                        foreach ($db->get_rows() as $rStreamRow) {
                            if ($rStreamRow['server_id'] && !$rStreamRow['direct_source']) {
                                $rAvailable = true;

                                break;
                            }
                        }
                    }
                }

                $rRow['date'] = date('H:i', $rRow['start']) . ' - ' . date('H:i', $rRow['end']);
                $this->ok(array('data' => $rRow, 'available' => $rAvailable, 'archive' => $rArchive));
            }
        }

        $this->fail();
    }
}
