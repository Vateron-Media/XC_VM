<?php

/**
 * ProvidersCronJob — providers cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

require_once __DIR__ . '/../CronTrait.php';

class ProvidersCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string {
        return 'cron:providers';
    }

    public function getDescription(): string {
        return 'Cron: sync providers (channels, VOD, series)';
    }

    public function execute(array $rArgs): int {
        if (!$this->assertRunAsXcVm()) {
            return 1;
        }

        $this->initCron('XC_VM[Providers]');

        $rTimeout = 300;
        set_time_limit($rTimeout);
        ini_set('max_execution_time', $rTimeout);

        $rProviderID = null;
        if (!empty($rArgs[0])) {
            $rProviderID = intval($rArgs[0]);
        }

        $this->loadCron($rProviderID);

        return 0;
    }

    private function readURL(string $rURL): ?array {
        $rContext = stream_context_create(array('http' => array('timeout' => 30)));
        return json_decode(file_get_contents($rURL, false, $rContext), true);
    }

    private function loadCron(?int $rProviderID): void {
        global $db;

        if ($rProviderID) {
            $db->query('SELECT `id`, `stream_display_name`, `title_sync` FROM `streams` WHERE `title_sync` LIKE ?;', $rProviderID . '_%');
        } else {
            $db->query('SELECT `id`, `stream_display_name`, `title_sync` FROM `streams` WHERE `title_sync` IS NOT NULL;');
        }

        $rSyncTitle = array();
        foreach ($db->get_rows() as $rRow) {
            list($rSyncID, $rSyncStream) = array_map('intval', explode('_', $rRow['title_sync']));
            if (!isset($rSyncTitle[$rSyncID])) {
                $rSyncTitle[$rSyncID] = array();
            }
            $rSyncTitle[$rSyncID][$rSyncStream] = array($rRow['id'], $rRow['stream_display_name']);
        }

        if ($rProviderID) {
            $db->query('SELECT * FROM `providers` WHERE `id` = ?;', $rProviderID);
        } else {
            $db->query('SELECT * FROM `providers` WHERE `enabled` = 1;');
        }

        foreach ($db->get_rows() as $rRow) {
            $rArray = array();
            $rURL = (($rRow['ssl'] ? 'https' : 'http')) . '://' . $rRow['ip'] . ':' . $rRow['port'] . '/';
            if ($rRow['legacy']) {
                $rURL .= 'player_api.php?username=' . $rRow['username'] . '&password=' . $rRow['password'];
            } else {
                $rURL .= 'player_api/' . $rRow['username'] . '/' . $rRow['password'] . '?connections=1';
            }

            $rInfo = $this->readURL($rURL);
            if ($rInfo) {
                $rStatus = 1;
                $rUserInfo = $rInfo['user_info'] ?? [];
                $rArray['max_connections'] = $rUserInfo['max_connections'] ?? null;
                $rArray['active_connections'] = $rUserInfo['active_cons'] ?? 0;
                $rArray['exp_date'] = $rUserInfo['exp_date'] ?? null;
            } else {
                $rStatus = 0;
                $rArray['exp_date'] = ($rRow['exp_date'] ?: -1);
            }

            $rCategories = array();
            $rCategoriesURL = $rURL . '&action=get_live_categories';
            foreach ($this->readURL($rCategoriesURL) as $rCategory) {
                $rCategories[$rCategory['category_id']] = $rCategory['category_name'];
            }
            $rCategoriesURL = $rURL . '&action=get_vod_categories';
            foreach ($this->readURL($rCategoriesURL) as $rCategory) {
                $rCategories[$rCategory['category_id']] = $rCategory['category_name'];
            }

            $rStreamsURL = $rURL . '&action=get_live_streams';
            $rStreams = $this->readURL($rStreamsURL);
            if (!is_array($rStreams)) $rStreams = [];
            $rArray['streams'] = count($rStreams);

            $rVODURL = $rURL . '&action=get_vod_streams';
            $rVOD = $this->readURL($rVODURL);
            if (!is_array($rVOD)) $rVOD = [];
            $rArray['movies'] = count($rVOD);

            $rSeriesURL = $rURL . '&action=get_series';
            $rSeries = $this->readURL($rSeriesURL);
            if (!is_array($rSeries)) $rSeries = [];
            $rArray['series'] = count($rSeries);

            $rLastChanged = time();
            $db->query('UPDATE `providers` SET `data` = ?, `last_changed` = ?, `status` = ? WHERE `id` = ?;', json_encode($rArray), $rLastChanged, $rStatus, $rRow['id']);

            $db->query('SELECT `type`, `stream_id`, `category_id`, `stream_display_name`, `stream_icon`, `channel_id` FROM `providers_streams` WHERE `provider_id` = ?;', $rRow['id']);
            $rNewIDs = $rExistingIDs = array();
            foreach ($db->get_rows() as $rStream) {
                $rExistingIDs[$rStream['stream_id']] = md5($rStream['category_id'] . '_' . (($rStream['stream_display_name'] ?: '')) . '_' . (($rStream['stream_icon'] ?: '')) . '_' . (($rStream['channel_id'] ?: '')));
            }

            $rTime = time();
            foreach (array('live' => $rStreams, 'movie' => $rVOD) as $rType => $rSelection) {
                foreach ($rSelection as $rStream) {
                    $rNewIDs[] = $rStream['stream_id'];
                    $rCategoryIDs = (isset($rStream['category_ids']) ? (is_array($rStream['category_ids']) ? $rStream['category_ids'] : array()) : array($rStream['category_id']));
                    $rCategoryArray = array();
                    foreach ($rCategoryIDs as $rCategoryID) {
                        $rCategoryArray[] = $rCategories[$rCategoryID];
                    }
                    $rCategoryIDs = '[' . implode(',', array_map('intval', $rCategoryIDs)) . ']';
                    if (isset($rExistingIDs[$rStream['stream_id']])) {
                        $rUUID = $rExistingIDs[$rStream['stream_id']];
                        if (md5($rCategoryIDs . '_' . (($rStream['name'] ?: '')) . '_' . (($rStream['stream_icon'] ?: '')) . '_' . ((($rType == 'live' ? $rStream['epg_channel_id'] : $rStream['container_extension']) ?: ''))) != $rUUID) {
                            $db->query('UPDATE `providers_streams` SET `category_id` = ?, `category_array` = ?, `stream_display_name` = ?, `stream_icon` = ?, `channel_id` = ?, `modified` = ? WHERE `provider_id` = ? AND `stream_id` = ?;', $rCategoryIDs, json_encode($rCategoryArray), $rStream['name'], $rStream['stream_icon'], ($rType == 'live' ? $rStream['epg_channel_id'] : $rStream['container_extension']), $rTime, $rRow['id'], $rStream['stream_id']);
                        }
                    } else {
                        $db->query('INSERT INTO `providers_streams`(`provider_id`, `type`, `stream_id`, `category_id`, `category_array`, `stream_display_name`, `stream_icon`, `channel_id`, `added`, `modified`) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?);', $rRow['id'], $rType, $rStream['stream_id'], $rCategoryIDs, json_encode($rCategoryArray), $rStream['name'], $rStream['stream_icon'], ($rType == 'live' ? $rStream['epg_channel_id'] : $rStream['container_extension']), $rTime, $rTime);
                    }
                    if ($rType == 'live' && isset($rSyncTitle[$rRow['id']][$rStream['stream_id']])) {
                        if ($rStream['name'] != $rSyncTitle[$rRow['id']][$rStream['stream_id']][1]) {
                            $db->query('UPDATE `streams` SET `stream_display_name` = ? WHERE `id` = ?;', $rStream['name'], $rSyncTitle[$rRow['id']][$rStream['stream_id']][0]);
                            StreamProcess::updateStream($rSyncTitle[$rRow['id']][$rStream['stream_id']][0]);
                        }
                    }
                }
            }

            $rDelete = array();
            foreach (array_keys($rExistingIDs) as $rStreamID) {
                if (!in_array($rStreamID, $rNewIDs)) {
                    $rDelete[] = $rStreamID;
                }
            }
            if (count($rDelete) > 0) {
                $db->query('DELETE FROM `providers_streams` WHERE `provider_id` = ? AND `stream_id` IN (' . implode(',', array_map('intval', $rDelete)) . ');', $rRow['id']);
            }
        }
    }
}
