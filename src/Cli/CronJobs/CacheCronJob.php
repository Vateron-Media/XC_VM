<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\ReplicaApply;
use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Config\SettingsRepository;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Security\BlocklistService;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * CacheCronJob — cache cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class CacheCronJob implements CommandInterface {
	use DatabaseAware;
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

		$this->loadCron($rStartup);

		return 0;
	}

	private function loadCron(bool $rStartup): void {
		$db = self::db();
		if (!defined('CACHE_TMP_PATH')) {
			exit();
		}

		// Atomic tmp+rename writes: a direct file_put_contents cannot replace a
		// cache file left behind by a root-context run (Permission denied spam),
		// while rename() only needs a writable directory.
		$rCache = new FileCache(CACHE_TMP_PATH);

		if ($rStartup && file_exists(CACHE_TMP_PATH . 'settings')) {
			echo 'Checking cache readability...' . "\n";
			$rSerialize = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'settings'));
			if (!is_array($rSerialize) || !isset($rSerialize['server_name'])) {
				echo 'Clearing cache...' . "\n\n";
				foreach ([STREAMS_TMP_PATH, LINES_TMP_PATH, SERIES_TMP_PATH] as $rTmpPath) {
					foreach (scandir($rTmpPath) ?: [] as $rFile) {
						if (is_file($rTmpPath . $rFile)) {
							unlink($rTmpPath . $rFile);
						}
					}
				}
				// No sudo: this runs as xc_vm (service boot chowns tmp/ first),
				// and xc_vm has no sudoers entry — sudo here would fail silently.
				exec('rm -rf ' . TMP_PATH . '*');
				exec('rm -rf ' . SIGNALS_PATH . '*');
			}
		}

		foreach ([EPG_PATH, VOD_PATH, ARCHIVE_PATH, CREATED_PATH, DELAY_PATH, VIDEO_PATH, PLAYLIST_PATH, CONS_TMP_PATH, CRONS_TMP_PATH, PLAYER_TMP_PATH, CACHE_TMP_PATH, DIVERGENCE_TMP_PATH, FLOOD_TMP_PATH, MINISTRA_TMP_PATH, SIGNALS_TMP_PATH, LOGS_TMP_PATH, WATCH_TMP_PATH, CIDR_TMP_PATH, STREAMS_TMP_PATH, LINES_TMP_PATH, SERIES_TMP_PATH] as $rPath) {
			if (!file_exists($rPath)) {
				@mkdir($rPath, 0755, true);
				if (is_dir($rPath) && function_exists('posix_getpwnam')) {
					$rUser = posix_getpwnam('xc_vm');
					if ($rUser) {
						chown($rPath, $rUser['uid']);
						chgrp($rPath, $rUser['gid']);
					}
				}
			}
		}

		// The settings cache is the replica's once it owns it; getAll() then
		// returns that cache and never writes MAIN's row over it.
		SettingsRepository::getAll(true);
		// The node replica: with the CONFIG flow on, its caches are rebuilt from
		// the verified copy on disk every minute, whether or not the agent ran
		// cluster:apply since the flow changed; with it off they are MAIN's
		// database's again. The servers cache is written here only while the
		// replica does not own it. The streams section follows the STREAMS
		// flow the same way, CONFIG or not; the minute never compares a shadow
		// section with MAIN's database (the agent's cluster:apply does).
		ReplicaApply::minute();
		// The bouquets and categories are the replica's too once it owns them
		// (its `bouquets` and `categories` sections); a process booted from the
		// replica keeps them as they are (their readers never read MAIN's
		// database there).
		if (!ReplicaApply::owns(ReplicaSections::BOUQUETS)) {
			FileCache::setCache('bouquets', BouquetService::getAll(true));
		}
		if (!ReplicaApply::owns(ReplicaSections::SERVERS)) {
			FileCache::setCache('servers', ServerRepository::getAll(true));
		}
		// The proxies and the allowed IPs are built from the servers and the
		// settings this process booted with (whitelist_ips, server and private
		// IPs, domains, allowed_ips_admin): the replica's, once it owns them.
		FileCache::setCache('proxy_servers', BlocklistService::getProxyIPs(true));
		// With the CONFIG flow on, the node's replica writes these (cluster:apply).
		if (!NodeFlows::on(NodeFlows::CONFIG)) {
			FileCache::setCache('blocked_servers', BlocklistService::getBlockedServers(true));
			FileCache::setCache('blocked_isp', BlocklistService::getBlockedISP(true));
			FileCache::setCache('blocked_ua', BlocklistService::getBlockedUA(true));
			FileCache::setCache('blocked_ips', BlocklistService::getBlockedIPs(true));
		}
		FileCache::setCache('allowed_ips', ServerRepository::getAllowedIPs(true));
		// verify_host's list (HostVerificationStage, the streaming bootstrap):
		// the servers' names and addresses and the resellers' DNS. Nothing had
		// written it since the move off CoreUtilities, so hosts went unchecked.
		// While the replica owns the servers, it writes the list from its
		// `servers` section (ReplicaApply). Without it a node in mode 2 reads
		// no reseller of MAIN's, and a list without them would refuse a
		// reseller's viewers: it keeps no list, and hosts stay unchecked there.
		if (!ReplicaApply::owns(ReplicaSections::SERVERS)) {
			if (NodeRole::refusesConnects()) {
				FileCache::delCache('allowed_domains');
			} else {
				ServerRepository::getAllowedDomains(true);
			}
		}
		if (!ReplicaApply::owns(ReplicaSections::CATEGORIES)) {
			FileCache::setCache('categories', CategoryService::getFromDatabase(null, true));
		}

		$rAllServers = ServerRepository::getAll();
		if (!isset($rAllServers[SERVER_ID]) || !$rAllServers[SERVER_ID]['is_main']) {
			return;
		}

		// Skip expensive heavy cache rebuild for 5 minutes; lightweight caches above still refresh every run.
		$rHeavyMarker = CACHE_TMP_PATH . 'heavy_cache_built';
		if (!$rStartup && file_exists($rHeavyMarker) && (time() - filemtime($rHeavyMarker)) < 300) {
			return;
		}

		$rOutputFormats = [];
		$db->query('SELECT `access_output_id`, `output_key` FROM `output_formats`;');
		foreach ($db->get_rows() as $rRow) {
			$rOutputFormats[] = $rRow;
		}
		$rCache->set('output_formats', $rOutputFormats);

		$rHMACKeys = [];
		$db->query('SELECT `id`, `key` FROM `hmac_keys` WHERE `enabled` = 1;');
		foreach ($db->get_rows() as $rRow) {
			$rHMACKeys[] = $rRow;
		}
		$rCache->set('hmac_keys', $rHMACKeys);

		$rRTMPIPs = [];
		$db->query('SELECT `ip`, `password`, `push`, `pull` FROM `rtmp_ips`');
		foreach ($db->get_rows() as $rRow) {
			$rRTMPIPs[gethostbyname($rRow['ip'])] = ['password' => $rRow['password'], 'push' => boolval($rRow['push']), 'pull' => boolval($rRow['pull'])];
		}
		$rCache->set('rtmp_ips', $rRTMPIPs);

		if (file_exists(BIN_PATH . 'maxmind/cidr.db')) {
			exec('ls ' . CIDR_TMP_PATH . ' | wc -l', $rOutput);
			if (intval($rOutput[0]) == 0) {
				$rDatabase = json_decode(file_get_contents(BIN_PATH . 'maxmind/cidr.db'), true);
				foreach ($rDatabase as $rASN => $rData) {
					file_put_contents(CIDR_TMP_PATH . $rASN, json_encode($rData));
				}
			}
		}

		$rChannelOrder = [];
		if (SettingsManager::get('channel_number_type') == 'manual') {
			$db->query('SELECT `id`, `order` FROM `streams` ORDER BY `order` ASC;');
			foreach ($db->get_rows() as $rRow) {
				$rChannelOrder[] = intval($rRow['id']);
			}
		}

		$rCategoryMap = [];
		$rBouquetMap = [];
		$rStreamIDs = ['channels' => [], 'radios' => [], 'movies' => [], 'episodes' => [], 'series' => []];

		$db->query('SELECT *, IF(`bouquet_order` > 0, `bouquet_order`, 999) AS `order` FROM `bouquets` ORDER BY `order` ASC;');
		foreach ($db->get_rows(true, 'id') as $rID => $rChannels) {
			$rAllowedCategories = [];

			foreach ((json_decode($rChannels['bouquet_channels'], true) ?: []) as $rStreamID) {
				if (0 < intval($rStreamID) && !in_array($rStreamID, $rStreamIDs['channels'])) {
					$rStreamIDs['channels'][] = $rStreamID;
				}
				if (!isset($rBouquetMap[intval($rStreamID)])) {
					$rBouquetMap[intval($rStreamID)] = [];
				}
				$rBouquetMap[intval($rStreamID)][] = $rID;
			}

			foreach ((json_decode($rChannels['bouquet_radios'], true) ?: []) as $rStreamID) {
				if (0 < intval($rStreamID) && !in_array($rStreamID, $rStreamIDs['radios'])) {
					$rStreamIDs['radios'][] = $rStreamID;
				}
				if (!isset($rBouquetMap[intval($rStreamID)])) {
					$rBouquetMap[intval($rStreamID)] = [];
				}
				$rBouquetMap[intval($rStreamID)][] = $rID;
			}

			foreach ((json_decode($rChannels['bouquet_movies'], true) ?: []) as $rStreamID) {
				if (0 < intval($rStreamID) && !in_array($rStreamID, $rStreamIDs['movies'])) {
					$rStreamIDs['movies'][] = $rStreamID;
				}
				if (!isset($rBouquetMap[intval($rStreamID)])) {
					$rBouquetMap[intval($rStreamID)] = [];
				}
				$rBouquetMap[intval($rStreamID)][] = $rID;
			}

			foreach ((json_decode($rChannels['bouquet_series'], true) ?: []) as $rSeriesID) {
				if (0 < intval($rSeriesID) && !in_array($rSeriesID, $rStreamIDs['series'])) {
					$db->query('SELECT `stream_id` FROM `streams_episodes` WHERE `series_id` = ? ORDER BY `season_num` ASC, `episode_num` ASC;', $rSeriesID);
					foreach ($db->get_rows() as $rEpisode) {
						if (0 < intval($rEpisode['stream_id'])) {
							$rStreamIDs['episodes'][] = $rEpisode['stream_id'];
						}
						if (!isset($rBouquetMap[intval($rEpisode['stream_id'])])) {
							$rBouquetMap[intval($rEpisode['stream_id'])] = [];
						}
						$rBouquetMap[intval($rEpisode['stream_id'])][] = $rID;
					}
				}
			}

			$rAllChannels = array_map('intval', array_unique(array_merge((json_decode($rChannels['bouquet_channels'], true) ?: []), (json_decode($rChannels['bouquet_radios'], true) ?: []), (json_decode($rChannels['bouquet_movies'], true) ?: []))));
			$rAllSeries = array_map('intval', array_unique((json_decode($rChannels['bouquet_series'], true) ?: [])));

			if (count($rAllChannels) > 0) {
				$db->query('SELECT DISTINCT(`category_id`) AS `category_id` FROM `streams` WHERE `id` IN (' . implode(',', $rAllChannels) . ');');
				foreach ($db->get_rows() as $rRow) {
					$rAllowedCategories = array_merge($rAllowedCategories, (json_decode($rRow['category_id'], true) ?: []));
				}
			}

			if (count($rAllSeries) > 0) {
				$db->query('SELECT DISTINCT(`category_id`) AS `category_id` FROM `streams_series` WHERE `id` IN (' . implode(',', $rAllSeries) . ');');
				foreach ($db->get_rows() as $rRow) {
					$rAllowedCategories = array_merge($rAllowedCategories, (json_decode($rRow['category_id'], true) ?: []));
				}
			}

			$rCategoryMap[$rID] = array_unique($rAllowedCategories);
		}

		if (SettingsManager::get('channel_number_type') != 'manual') {
			foreach (['channels', 'radios', 'movies', 'episodes'] as $rKey) {
				if (0 < count($rStreamIDs[$rKey])) {
					$rWhere = 'AND `id` NOT IN (' . implode(',', array_map('intval', $rStreamIDs[$rKey])) . ')';
				} else {
					$rWhere = '';
				}
				switch ($rKey) {
					case 'channels':
						$rType = [1, 3];
						break;
					case 'radios':
						$rType = [4];
						break;
					case 'movies':
						$rType = [2];
						break;
					case 'episodes':
						$rType = [5];
						break;
				}
				if (count($rType) > 0) {
					$db->query('SELECT `id` FROM `streams` WHERE `type` IN (' . implode(',', $rType) . ') ' . $rWhere . ' ORDER BY `order` ASC;');
					foreach ($db->get_rows() as $rRow) {
						$rStreamIDs[$rKey][] = $rRow['id'];
					}
				}
			}

			if (SettingsManager::get('vod_sort_newest')) {
				$rStreamIDs['movies'] = [];
				$rStreamIDs['episodes'] = [];
				$db->query('SELECT `type`, `id` FROM `streams` WHERE `type` IN (2,5) ORDER BY `added` DESC, `id` DESC;');
				foreach ($db->get_rows() as $rRow) {
					$rStreamIDs[[2 => 'movies', 5 => 'episodes'][$rRow['type']]][] = $rRow['id'];
				}
				$rSeriesOrder = [];
				$db->query('SELECT `id`, (SELECT MAX(`streams`.`added`) FROM `streams_episodes` LEFT JOIN `streams` ON `streams`.`id` = `streams_episodes`.`stream_id` WHERE `streams_episodes`.`series_id` = `streams_series`.`id`) AS `last_modified_stream` FROM `streams_series` ORDER BY `last_modified_stream` DESC, `last_modified` DESC, `id` DESC;');
				foreach ($db->get_rows() as $rRow) {
					$rSeriesOrder[] = intval($rRow['id']);
				}
				$rCache->set('series_order', $rSeriesOrder);
			}

			foreach (['channels', 'radios', 'movies', 'episodes'] as $rKey) {
				foreach ($rStreamIDs[$rKey] as $rStreamID) {
					$rChannelOrder[] = intval($rStreamID);
				}
			}
			$rChannelOrder = array_unique($rChannelOrder);
		}

		$rCategoryChannels = [];
		$db->query('SELECT `id`, `category_id` FROM `streams`;');
		if ($db->dbh && $db->result) {
			if ($db->result->rowCount() > 0) {
				foreach ($db->result->fetchAll(\PDO::FETCH_ASSOC) as $rStreamInfo) {
					$rCategoryChannels[$rStreamInfo['id']] = json_decode($rStreamInfo['category_id'] ?? '[]', true);
				}
			}
		}

		$rResellerDomains = [];
		$db->query('SELECT `reseller_dns` FROM `users` WHERE `status` = 1 AND `reseller_dns` IS NOT NULL;');
		foreach ($db->get_rows() as $rRow) {
			$rResellerDomains[] = strtolower($rRow['reseller_dns']);
		}

		$rCache->set('reseller_domains', $rResellerDomains);
		$rCache->set('channel_order', $rChannelOrder);
		$rCache->set('bouquet_map', $rBouquetMap);
		$rCache->set('category_map', $rCategoryMap);
		(new FileCache(STREAMS_TMP_PATH))->set('channels_categories', $rCategoryChannels);
		@touch($rHeavyMarker);
	}
}
