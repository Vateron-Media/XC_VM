<?php

/**
 * CacheCronJob — cache cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

require_once __DIR__ . '/../CronTrait.php';

class CacheCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string {
        return 'cron:cache';
    }

    public function getDescription(): string {
        return 'Cron: generate file cache (settings, bouquets, servers, blocked, categories, etc.)';
    }

    public function execute(array $rArgs): int {
        if (!$this->assertRunAsXcVm()) {
            return 1;
        }

        ini_set('memory_limit', -1);

        $rStartup = false;
        if (!empty($rArgs[0])) {
            $rStartup = true;
        }

        $this->setProcessTitle('XC_VM[Cache Builder]');
        $this->acquireCronLock();

        global $db;

        $this->loadCron($db, $rStartup);

        return 0;
    }

    private function loadCron($db, bool $rStartup): void {
        if (!defined('CACHE_TMP_PATH')) {
            exit();
        }

        if ($rStartup && file_exists(CACHE_TMP_PATH . 'settings')) {
            echo 'Checking cache readability...' . "\n";
            $rSerialize = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'settings'));
            if (!(is_array($rSerialize) && isset($rSerialize['server_name']))) {
                echo 'Clearing cache...' . "\n\n";
                foreach (array(STREAMS_TMP_PATH, LINES_TMP_PATH, SERIES_TMP_PATH) as $rTmpPath) {
                    foreach (scandir($rTmpPath) as $rFile) {
                        unlink($rTmpPath . $rFile);
                    }
                }
                exec('sudo rm -rf ' . TMP_PATH . '*');
                exec('sudo rm -rf ' . SIGNALS_PATH . '*');
            }
        }

        foreach (array(EPG_PATH, VOD_PATH, ARCHIVE_PATH, CREATED_PATH, DELAY_PATH, VIDEO_PATH, PLAYLIST_PATH, CONS_TMP_PATH, CRONS_TMP_PATH, PLAYER_TMP_PATH, CACHE_TMP_PATH, DIVERGENCE_TMP_PATH, FLOOD_TMP_PATH, MINISTRA_TMP_PATH, SIGNALS_TMP_PATH, LOGS_TMP_PATH, WATCH_TMP_PATH, CIDR_TMP_PATH, STREAMS_TMP_PATH, LINES_TMP_PATH, SERIES_TMP_PATH) as $rPath) {
            if (!file_exists($rPath)) {
                mkdir($rPath, 0755, true);
                if (function_exists('posix_getpwnam')) {
                    $rUser = posix_getpwnam('xc_vm');
                    if ($rUser) {
                        chown($rPath, $rUser['uid']);
                        chgrp($rPath, $rUser['gid']);
                    }
                }
            }
        }

        FileCache::setCache('settings', SettingsRepository::getAll(true));
        FileCache::setCache('bouquets', BouquetService::getAll(true));
        $rServers = ServerRepository::getAll(true);
        unset($rServers['php_pids']);
        FileCache::setCache('servers', $rServers);
        FileCache::setCache('proxy_servers', BlocklistService::getProxyIPs(true));
        FileCache::setCache('blocked_servers', BlocklistService::getBlockedServers(true));
        FileCache::setCache('blocked_isp', BlocklistService::getBlockedISP(true));
        FileCache::setCache('blocked_ua', BlocklistService::getBlockedUA(true));
        FileCache::setCache('blocked_ips', BlocklistService::getBlockedIPs(true));
        FileCache::setCache('allowed_ips', ServerRepository::getAllowedIPs(true));
        FileCache::setCache('categories', CategoryService::getFromDatabase(null, true));

        if (!ServerRepository::getAll()[SERVER_ID]['is_main']) {
            return;
        }

        $rOutputFormats = array();
        $db->query('SELECT `access_output_id`, `output_key` FROM `output_formats`;');
        foreach ($db->get_rows() as $rRow) {
            $rOutputFormats[] = $rRow;
        }
        file_put_contents(CACHE_TMP_PATH . 'output_formats', igbinary_serialize($rOutputFormats));

        $rHMACKeys = array();
        $db->query('SELECT `id`, `key` FROM `hmac_keys` WHERE `enabled` = 1;');
        foreach ($db->get_rows() as $rRow) {
            $rHMACKeys[] = $rRow;
        }
        file_put_contents(CACHE_TMP_PATH . 'hmac_keys', igbinary_serialize($rHMACKeys));

        $rRTMPIPs = array();
        $db->query('SELECT `ip`, `password`, `push`, `pull` FROM `rtmp_ips`');
        foreach ($db->get_rows() as $rRow) {
            $rRTMPIPs[gethostbyname($rRow['ip'])] = array('password' => $rRow['password'], 'push' => boolval($rRow['push']), 'pull' => boolval($rRow['pull']));
        }
        file_put_contents(CACHE_TMP_PATH . 'rtmp_ips', igbinary_serialize($rRTMPIPs));

        if (file_exists(BIN_PATH . 'maxmind/cidr.db')) {
            exec('ls ' . CIDR_TMP_PATH . ' | wc -l', $rOutput);
            if (intval($rOutput[0]) == 0) {
                $rDatabase = json_decode(file_get_contents(BIN_PATH . 'maxmind/cidr.db'), true);
                foreach ($rDatabase as $rASN => $rData) {
                    file_put_contents(CIDR_TMP_PATH . $rASN, json_encode($rData));
                }
            }
        }

        $rChannelOrder = array();
        if (SettingsManager::getAll()['channel_number_type'] == 'manual') {
            $db->query('SELECT `id`, `order` FROM `streams` ORDER BY `order` ASC;');
            foreach ($db->get_rows() as $rRow) {
                $rChannelOrder[] = intval($rRow['id']);
            }
        }

        $rCategoryMap = array();
        $rBouquetMap = array();
        $rStreamIDs = array('channels' => array(), 'radios' => array(), 'movies' => array(), 'episodes' => array(), 'series' => array());

        $db->query('SELECT *, IF(`bouquet_order` > 0, `bouquet_order`, 999) AS `order` FROM `bouquets` ORDER BY `order` ASC;');
        foreach ($db->get_rows(true, 'id') as $rID => $rChannels) {
            $rAllowedCategories = array();

            foreach (json_decode($rChannels['bouquet_channels'], true) as $rStreamID) {
                if (!(0 >= intval($rStreamID) || in_array($rStreamID, $rStreamIDs['channels']))) {
                    $rStreamIDs['channels'][] = $rStreamID;
                }
                if (!isset($rBouquetMap[intval($rStreamID)])) {
                    $rBouquetMap[intval($rStreamID)] = array();
                }
                $rBouquetMap[intval($rStreamID)][] = $rID;
            }

            foreach (json_decode($rChannels['bouquet_radios'], true) as $rStreamID) {
                if (!(0 >= intval($rStreamID) || in_array($rStreamID, $rStreamIDs['radios']))) {
                    $rStreamIDs['radios'][] = $rStreamID;
                }
                if (!isset($rBouquetMap[intval($rStreamID)])) {
                    $rBouquetMap[intval($rStreamID)] = array();
                }
                $rBouquetMap[intval($rStreamID)][] = $rID;
            }

            foreach (json_decode($rChannels['bouquet_movies'], true) as $rStreamID) {
                if (!(0 >= intval($rStreamID) || in_array($rStreamID, $rStreamIDs['movies']))) {
                    $rStreamIDs['movies'][] = $rStreamID;
                }
                if (!isset($rBouquetMap[intval($rStreamID)])) {
                    $rBouquetMap[intval($rStreamID)] = array();
                }
                $rBouquetMap[intval($rStreamID)][] = $rID;
            }

            foreach (json_decode($rChannels['bouquet_series'], true) as $rSeriesID) {
                if (!(0 >= intval($rSeriesID) || in_array($rSeriesID, $rStreamIDs['series']))) {
                    $db->query('SELECT `stream_id` FROM `streams_episodes` WHERE `series_id` = ? ORDER BY `season_num` ASC, `episode_num` ASC;', $rSeriesID);
                    foreach ($db->get_rows() as $rEpisode) {
                        if (0 < intval($rEpisode['stream_id'])) {
                            $rStreamIDs['episodes'][] = $rEpisode['stream_id'];
                        }
                        if (!isset($rBouquetMap[intval($rEpisode['stream_id'])])) {
                            $rBouquetMap[intval($rEpisode['stream_id'])] = array();
                        }
                        $rBouquetMap[intval($rEpisode['stream_id'])][] = $rID;
                    }
                }
            }

            $rAllChannels = array_map('intval', array_unique(array_merge((json_decode($rChannels['bouquet_channels'], true) ?: array()), (json_decode($rChannels['bouquet_radios'], true) ?: array()), (json_decode($rChannels['bouquet_movies'], true) ?: array()))));
            $rAllSeries = array_map('intval', array_unique((json_decode($rChannels['bouquet_series'], true) ?: array())));

            if (count($rAllChannels) > 0) {
                $db->query('SELECT DISTINCT(`category_id`) AS `category_id` FROM `streams` WHERE `id` IN (' . implode(',', $rAllChannels) . ');');
                foreach ($db->get_rows() as $rRow) {
                    $rAllowedCategories = array_merge($rAllowedCategories, (json_decode($rRow['category_id'], true) ?: array()));
                }
            }

            if (count($rAllSeries) > 0) {
                $db->query('SELECT DISTINCT(`category_id`) AS `category_id` FROM `streams_series` WHERE `id` IN (' . implode(',', $rAllSeries) . ');');
                foreach ($db->get_rows() as $rRow) {
                    $rAllowedCategories = array_merge($rAllowedCategories, (json_decode($rRow['category_id'], true) ?: array()));
                }
            }

            $rCategoryMap[$rID] = array_unique($rAllowedCategories);
        }

        if (SettingsManager::getAll()['channel_number_type'] != 'manual') {
            foreach (array('channels', 'radios', 'movies', 'episodes') as $rKey) {
                if (0 < count($rStreamIDs[$rKey])) {
                    $rWhere = 'AND `id` NOT IN (' . implode(',', array_map('intval', $rStreamIDs[$rKey])) . ')';
                } else {
                    $rWhere = '';
                }
                switch ($rKey) {
                    case 'channels': $rType = array(1, 3); break;
                    case 'radios': $rType = array(4); break;
                    case 'movies': $rType = array(2); break;
                    case 'episodes': $rType = array(5); break;
                }
                if (count($rType) > 0) {
                    $db->query('SELECT `id` FROM `streams` WHERE `type` IN (' . implode(',', $rType) . ') ' . $rWhere . ' ORDER BY `order` ASC;');
                    foreach ($db->get_rows() as $rRow) {
                        $rStreamIDs[$rKey][] = $rRow['id'];
                    }
                }
            }

            if (SettingsManager::getAll()['vod_sort_newest']) {
                $rStreamIDs['movies'] = array();
                $rStreamIDs['episodes'] = array();
                $db->query('SELECT `type`, `id` FROM `streams` WHERE `type` IN (2,5) ORDER BY `added` DESC, `id` DESC;');
                foreach ($db->get_rows() as $rRow) {
                    $rStreamIDs[array(2 => 'movies', 5 => 'episodes')[$rRow['type']]][] = $rRow['id'];
                }
                $rSeriesOrder = array();
                $db->query('SELECT `id`, (SELECT MAX(`streams`.`added`) FROM `streams_episodes` LEFT JOIN `streams` ON `streams`.`id` = `streams_episodes`.`stream_id` WHERE `streams_episodes`.`series_id` = `streams_series`.`id`) AS `last_modified_stream` FROM `streams_series` ORDER BY `last_modified_stream` DESC, `last_modified` DESC, `id` DESC;');
                foreach ($db->get_rows() as $rRow) {
                    $rSeriesOrder[] = intval($rRow['id']);
                }
                file_put_contents(CACHE_TMP_PATH . 'series_order', igbinary_serialize($rSeriesOrder));
            }

            foreach (array('channels', 'radios', 'movies', 'episodes') as $rKey) {
                foreach ($rStreamIDs[$rKey] as $rStreamID) {
                    $rChannelOrder[] = intval($rStreamID);
                }
            }
            $rChannelOrder = array_unique($rChannelOrder);
        }

        $rCategoryChannels = array();
        $db->query('SELECT `id`, `category_id` FROM `streams`;');
        if ($db->dbh && $db->result) {
            if ($db->result->rowCount() > 0) {
                foreach ($db->result->fetchAll(PDO::FETCH_ASSOC) as $rStreamInfo) {
                    $rCategoryChannels[$rStreamInfo['id']] = json_decode($rStreamInfo['category_id'], true);
                }
            }
        }

        $rResellerDomains = array();
        $db->query('SELECT `reseller_dns` FROM `users` WHERE `status` = 1 AND `reseller_dns` IS NOT NULL;');
        foreach ($db->get_rows() as $rRow) {
            $rResellerDomains[] = strtolower($rRow['reseller_dns']);
        }

        file_put_contents(CACHE_TMP_PATH . 'reseller_domains', igbinary_serialize($rResellerDomains));
        file_put_contents(CACHE_TMP_PATH . 'channel_order', igbinary_serialize($rChannelOrder));
        file_put_contents(CACHE_TMP_PATH . 'bouquet_map', igbinary_serialize($rBouquetMap));
        file_put_contents(CACHE_TMP_PATH . 'category_map', igbinary_serialize($rCategoryMap));
        file_put_contents(STREAMS_TMP_PATH . 'channels_categories', igbinary_serialize($rCategoryChannels));
    }
}
