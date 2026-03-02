<?php

class WatchService {
	public static function editWatchSettings($db, $rData, $clearSettingsCacheCallback = null) {
		foreach ($rData as $rKey => $rValue) {
			$rSplit = explode('_', $rKey);
			if ($rSplit[0] == 'genre') {
				$rBouquets = isset($rData['bouquet_' . $rSplit[1]]) ? '[' . implode(',', array_map('intval', $rData['bouquet_' . $rSplit[1]])) . ']' : '[]';
				$db->query('UPDATE `watch_categories` SET `category_id` = ?, `bouquets` = ? WHERE `genre_id` = ? AND `type` = 1;', $rValue, $rBouquets, $rSplit[1]);
			}
		}

		foreach ($rData as $rKey => $rValue) {
			$rSplit = explode('_', $rKey);
			if ($rSplit[0] == 'genretv') {
				$rBouquets = isset($rData['bouquettv_' . $rSplit[1]]) ? '[' . implode(',', array_map('intval', $rData['bouquettv_' . $rSplit[1]])) . ']' : '[]';
				$db->query('UPDATE `watch_categories` SET `category_id` = ?, `bouquets` = ? WHERE `genre_id` = ? AND `type` = 2;', $rValue, $rBouquets, $rSplit[1]);
			}
		}

		$altTitles = isset($rData['alternative_titles']);
		$fallbackParser = isset($rData['fallback_parser']);
		$db->query('UPDATE `settings` SET `percentage_match` = ?, `scan_seconds` = ?, `thread_count` = ?, `max_genres` = ?, `max_items` = ?, `alternative_titles` = ?, `fallback_parser` = ?;', $rData['percentage_match'], $rData['scan_seconds'], $rData['thread_count'], $rData['max_genres'], $rData['max_items'], $altTitles, $fallbackParser);

		if ($clearSettingsCacheCallback) {
			call_user_func($clearSettingsCacheCallback);
		}

		return array('status' => STATUS_SUCCESS);
	}

	public static function processWatchFolder($db, $rData, $getWatchFolderCallback = null) {
		if (isset($rData['edit'])) {
			$rArray = overwriteData(call_user_func($getWatchFolderCallback, $rData['edit']), $rData);
		} else {
			$rArray = verifyPostTable('watch_folders', $rData);
			unset($rArray['id']);
		}

		$rPath = $rData['selected_path'];
		if (!(0 < strlen($rPath) && $rPath != '/')) {
			return array('status' => STATUS_INVALID_DIR, 'data' => $rData);
		}

		if (isset($rData['edit'])) {
			$db->query('SELECT COUNT(*) AS `count` FROM `watch_folders` WHERE `directory` = ? AND `server_id` = ? AND `type` = ? AND `id` <> ?;', $rPath, $rArray['server_id'], $rData['folder_type'], $rArray['id']);
		} else {
			$db->query('SELECT COUNT(*) AS `count` FROM `watch_folders` WHERE `directory` = ? AND `server_id` = ? AND `type` = ?;', $rPath, $rArray['server_id'], $rData['folder_type']);
		}

		if (0 < $db->get_row()['count']) {
			return array('status' => STATUS_EXISTS_DIR, 'data' => $rData);
		}

		$bouquets = is_array($rData['bouquets'] ?? null) ? $rData['bouquets'] : array();
		$fbBouquets = is_array($rData['fb_bouquets'] ?? null) ? $rData['fb_bouquets'] : array();

		$rArray['type'] = $rData['folder_type'];
		$rArray['directory'] = $rPath;
		$rArray['bouquets'] = '[' . implode(',', array_map('intval', $bouquets)) . ']';
		$rArray['fb_bouquets'] = '[' . implode(',', array_map('intval', $fbBouquets)) . ']';
		$rArray['allowed_extensions'] = is_array($rData['allowed_extensions'] ?? null) && count($rData['allowed_extensions']) > 0 ? json_encode($rData['allowed_extensions']) : '[]';
		$rArray['target_container'] = ($rData['target_container'] == 'auto' ? null : $rData['target_container']);
		$rArray['category_id'] = intval($rData['category_id_' . $rData['folder_type']]);
		$rArray['fb_category_id'] = intval($rData['fb_category_id_' . $rData['folder_type']]);

		foreach (array('remove_subtitles', 'duplicate_tmdb', 'extract_metadata', 'fallback_title', 'disable_tmdb', 'ignore_no_match', 'auto_subtitles', 'auto_upgrade', 'read_native', 'movie_symlink', 'auto_encode', 'ffprobe_input', 'active') as $rKey) {
			$rArray[$rKey] = isset($rData[$rKey]) ? 1 : 0;
		}

		$rPrepare = prepareArray($rArray);
		$rQuery = 'REPLACE INTO `watch_folders`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
		}

		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}

	public static function getWatchFolders($rType = null) {
		global $db;
		if ($rType) {
			$db->query("SELECT * FROM `watch_folders` WHERE `type` = ? AND `type` <> 'plex' ORDER BY `id` ASC;", $rType);
		} else {
			$db->query("SELECT * FROM `watch_folders` WHERE `type` <> 'plex' ORDER BY `id` ASC;");
		}

		return $db->get_rows();
	}

	public static function getWatchCategories($rType = null) {
		global $db;
		$rReturn = array();
		if ($rType) {
			$db->query('SELECT * FROM `watch_categories` WHERE `type` = ? ORDER BY `genre_id` ASC;', $rType);
		} else {
			$db->query('SELECT * FROM `watch_categories` ORDER BY `genre_id` ASC;');
		}

		foreach ($db->get_rows() as $rRow) {
			$rReturn[$rRow['genre_id']] = $rRow;
		}

		return $rReturn;
	}

	public static function forceWatch($rServerID, $rWatchID, $requestCallback = 'systemapirequest') {
		return call_user_func($requestCallback, $rServerID, array('action' => 'watch_force', 'id' => $rWatchID));
	}

	public static function enableWatch($db) {
		return $db->query("UPDATE `watch_folders` SET `active` = 1 WHERE `type` <> 'plex';");
	}

	public static function disableWatch($db) {
		return $db->query("UPDATE `watch_folders` SET `active` = 0 WHERE `type` <> 'plex';");
	}

	public static function killWatch($db, $requestCallback = 'systemapirequest') {
		$db->query("SELECT DISTINCT(`server_id`) AS `server_id` FROM `watch_folders` WHERE `active` = 11 AND `type` <> 'plex';");
		foreach ($db->get_rows() as $rRow) {
			if (CoreUtilities::$rServers[$rRow['server_id']]['server_online']) {
				call_user_func($requestCallback, $rRow['server_id'], array('action' => 'kill_watch'));
			}
		}
		return true;
	}

	public static function getRecordings($db) {
		$rRecordings = array();
		$db->query('SELECT * FROM `recordings` ORDER BY `id` DESC;');
		foreach ($db->get_rows() as $rRow) {
			$rRecordings[] = $rRow;
		}
		return $rRecordings;
	}

	public static function deleteRecording($db, $rID, $deleteStreamCallback = 'deleteStream') {
		$db->query('SELECT `created_id`, `source_id` FROM `recordings` WHERE `id` = ?;', $rID);
		if ($db->num_rows() > 0) {
			$rRecording = $db->get_row();
			if ($rRecording['created_id']) {
				call_user_func($deleteStreamCallback, $rRecording['created_id'], $rRecording['source_id'], true, true);
			}
			shell_exec("kill -9 `ps -ef | grep 'Record[" . intval($rID) . "]' | grep -v grep | awk '{print $2}'`");
			$db->query('DELETE FROM `recordings` WHERE `id` = ?;', $rID);
		}
		return true;
	}
}
