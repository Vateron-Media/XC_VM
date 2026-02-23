<?php

class PlexService {
	public static function editPlexSettings($db, $rData, $clearSettingsCacheCallback = null) {
		foreach ($rData as $rKey => $rValue) {
			$rSplit = explode('_', $rKey);
			if ($rSplit[0] == 'genre') {
				if (isset($rData['bouquet_' . $rSplit[1]])) {
					$rBouquets = '[' . implode(',', array_map('intval', $rData['bouquet_' . $rSplit[1]])) . ']';
				} else {
					$rBouquets = '[]';
				}
				$db->query('UPDATE `watch_categories` SET `category_id` = ?, `bouquets` = ? WHERE `genre_id` = ? AND `type` = 3;', $rValue, $rBouquets, $rSplit[1]);
			}
		}

		foreach ($rData as $rKey => $rValue) {
			$rSplit = explode('_', $rKey);
			if ($rSplit[0] == 'genretv') {
				if (isset($rData['bouquettv_' . $rSplit[1]])) {
					$rBouquets = '[' . implode(',', array_map('intval', $rData['bouquettv_' . $rSplit[1]])) . ']';
				} else {
					$rBouquets = '[]';
				}
				$db->query('UPDATE `watch_categories` SET `category_id` = ?, `bouquets` = ? WHERE `genre_id` = ? AND `type` = 4;', $rValue, $rBouquets, $rSplit[1]);
			}
		}

		$db->query('UPDATE `settings` SET `scan_seconds` = ?, `max_genres` = ?, `thread_count_movie` = ?, `thread_count_show` = ?;', $rData['scan_seconds'], $rData['max_genres'], $rData['thread_count_movie'], $rData['thread_count_show']);
		call_user_func($clearSettingsCacheCallback);
		return array('status' => STATUS_SUCCESS);
	}

	public static function processPlexSync($db, $rData, $getWatchFolderCallback = null) {
		if (isset($rData['edit'])) {
			$rArray = overwriteData(call_user_func($getWatchFolderCallback, $rData['edit']), $rData);
		} else {
			$rArray = verifyPostTable('watch_folders', $rData);
			unset($rArray['id']);
		}

		if (is_array($rData['server_id'])) {
			$rServers = $rData['server_id'];
			$rArray['server_id'] = intval(array_shift($rServers));
			$rArray['server_add'] = '[' . implode(',', array_map('intval', $rServers)) . ']';
		} else {
			$rArray['server_id'] = intval($rData['server_id']);
			$rArray['server_add'] = null;
		}

		if (isset($rData['edit'])) {
			$db->query('SELECT COUNT(*) AS `count` FROM `watch_folders` WHERE `directory` = ? AND `server_id` = ? AND `plex_ip` = ? AND `id` <> ?;', $rData['library_id'], $rArray['server_id'], $rData['plex_ip'], $rArray['id']);
		} else {
			$db->query('SELECT COUNT(*) AS `count` FROM `watch_folders` WHERE `directory` = ? AND `server_id` = ? AND `plex_ip` = ?;', $rData['library_id'], $rArray['server_id'], $rData['plex_ip']);
		}

		if (0 < $db->get_row()['count']) {
			return array('status' => STATUS_EXISTS_DIR, 'data' => $rData);
		}

		$rArray['type'] = 'plex';
		$rArray['directory'] = $rData['library_id'];
		$rArray['plex_ip'] = $rData['plex_ip'];
		$rArray['plex_port'] = $rData['plex_port'];
		$rArray['plex_libraries'] = $rData['libraries'];
		$rArray['plex_username'] = $rData['username'];
		$rArray['direct_proxy'] = isset($rData['direct_proxy']) ? 1 : 0;
		if (0 < strlen($rData['password'])) {
			$rArray['plex_password'] = $rData['password'];
		}

		foreach (array('remove_subtitles', 'check_tmdb', 'store_categories', 'scan_missing', 'auto_upgrade', 'read_native', 'movie_symlink', 'auto_encode', 'active') as $rKey) {
			$rArray[$rKey] = isset($rData[$rKey]) ? 1 : 0;
		}

		$overrideBouquets = $rData['override_bouquets'] ?? [];
		$fallbackBouquets = $rData['fallback_bouquets'] ?? [];
		$rArray['category_id'] = intval($rData['override_category']);
		$rArray['fb_category_id'] = intval($rData['fallback_category']);
		$rArray['bouquets'] = '[' . implode(',', array_map('intval', $overrideBouquets)) . ']';
		$rArray['fb_bouquets'] = '[' . implode(',', array_map('intval', $fallbackBouquets)) . ']';
		$rArray['target_container'] = ($rData['target_container'] == 'auto' ? null : $rData['target_container']);
		$rPrepare = prepareArray($rArray);
		$rQuery = 'REPLACE INTO `watch_folders`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
		}
		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}

	public static function forcePlex($rServerID, $rPlexID, $systemApiRequestCallback = null) {
		call_user_func($systemApiRequestCallback, $rServerID, array('action' => 'plex_force', 'id' => $rPlexID));
	}
}
