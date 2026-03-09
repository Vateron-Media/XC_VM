<?php
/**
 * Player Utility Functions
 *
 * Extracted from player/functions.php — only the utility functions,
 * without the legacy standalone bootstrap.
 *
 * Functions: sortArrayStreamName, getStream,
 * getSubtitles, getOrderedCategories,
 * getUserStreams, getUserSeries, mapContentTypesToNumbers
 *
 * Shared with includes/admin.php: sortArrayByArray, getSerie,
 * getMovieTMDB, getSeriesTMDB, getSeasonTMDB.
 */

function sortArrayStreamName($a, $b) {
	$rColumn = (isset($a['stream_display_name']) ? 'stream_display_name' : 'title');

	return strcmp($a[$rColumn], $b[$rColumn]);
}

function getStream($rID) {
	global $db;
	$db->query('SELECT * FROM `streams` WHERE `id` = ?;', $rID);
	if ($db->num_rows() == 1) {
		$rRow = $db->get_row();

		if (!empty($rRow['title'])) {
			$rRow['stream_display_name'] = $rRow['title'];
		}

		return $rRow;
	}
}

function getSubtitles($rStreamID, $rSubtitles) {
	global $rUserInfo;
	$rDomainName = DomainResolver::resolve(SERVER_ID, !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443);
	$rReturn = array();

	if (is_array($rSubtitles)) {
		$i = 0;

		foreach ($rSubtitles as $rSubtitle) {
			$rLanguage = null;

			foreach (array_keys($rSubtitle['tags']) as $rKey) {
				if (!in_array(strtoupper(explode('-', $rKey)[0]), array('BPS', 'DURATION', 'NUMBER_OF_FRAMES', 'NUMBER_OF_BYTES'))) {
					if ($rKey == 'language') {
						$rLanguage = $rSubtitle['tags'][$rKey];
						break;
					}
				} else {
					list(, $rLanguage) = explode('-', $rKey, 2);
					break;
				}
			}

			if (!$rLanguage) {
				$rLanguage = 'Subtitle #' . ($i + 1);
			}

			$rReturn[] = array('label' => $rLanguage, 'file' => $rDomainName . 'subtitle/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rStreamID . '?sub_id=' . $i . '&webvtt=1', 'kind' => 'subtitles');
			$i++;
		}
	}

	return $rReturn;
}

function getOrderedCategories($rCategories, $rType = 'movie') {
	$rReturn = array();

	foreach (CategoryService::getFromDatabase($rType) as $rCategory) {
		if (in_array($rCategory['id'], $rCategories)) {
			$rReturn[] = array('title' => $rCategory['category_name'], 'id' => $rCategory['id'], 'cat_order' => $rCategory['cat_order']);
		}
	}
	$rTitle = array_column($rReturn, 'cat_order');
	array_multisort($rTitle, SORT_ASC, $rReturn);

	if ($rType != 'live') {
		array_unshift($rReturn, array('id' => '0', 'cat_order' => 0, 'title' => 'All Genres'));
	} else {
		array_unshift($rReturn, array('id' => '0', 'cat_order' => 0, 'title' => 'Most Popular'));
	}

	return $rReturn;
}

function getUserStreams($rUserInfo, $rTypes = array(), $rCategoryID = null, $rFav = null, $rOrderBy = null, $rSearchBy = null, $rPicking = array(), $rStart = 0, $rLimit = 10, $rIDs = false) {
	global $db;
	$rPicking = $rPicking ?? [];
	$rAdded = false;
	$rChannels = array();

	foreach ($rTypes as $rType) {
		switch ($rType) {
			case 'live':
			case 'created_live':
				if (!$rAdded) {
					$rChannels = array_merge($rChannels, $rUserInfo['live_ids']);
					$rAdded = true;
					break;
				}
				break;

			case 'movie':
				$rChannels = array_merge($rChannels, $rUserInfo['vod_ids']);
				break;

			case 'radio_streams':
				$rChannels = array_merge($rChannels, $rUserInfo['radio_ids']);
				break;

			case 'series':
				$rChannels = array_merge($rChannels, $rUserInfo['episode_ids']);
				break;
		}
	}
	$rStreams = array('count' => 0, 'streams' => array());
	$rKey = $rStart + 1;
	$rWhereV = $rWhere = array();

	if (SettingsManager::getAll()['player_hide_incompatible']) {
		$rWhere[] = '(SELECT MAX(`compatible`) FROM `streams_servers` WHERE `streams_servers`.`stream_id` = `streams`.`id` LIMIT 1) = 1';
	}

	if (count($rTypes) > 0) {
		$rWhere[] = '`type` IN (' . implode(',', mapContentTypesToNumbers($rTypes)) . ')';
	}

	if (!empty($rCategoryID)) {
		$rWhere[] = "JSON_CONTAINS(`category_id`, ?, '\$')";
		$rWhereV[] = $rCategoryID;
	} else {
		if (in_array('live', $rTypes) && empty($rSearchBy)) {
			$rStart = 0;
			$rLimit = 200;
			$rLiveIDs = igbinary_unserialize(file_get_contents(CONTENT_PATH . 'live_popular'));

			if ($rLiveIDs && 0 < count($rLiveIDs)) {
				$rWhere[] = '`id` IN (' . implode(',', array_map('intval', $rLiveIDs)) . ')';
			}
		}
	}

	if (!empty($rPicking['filter'])) {
		switch ($rPicking['filter']) {
			case 'all':
				break;

			case 'timeshift':
				$rWhere[] = '`tv_archive_duration` > 0 AND `tv_archive_server_id` > 0';
				break;
		}
	}
	$rChannels = StreamSorter::sortChannels($rChannels);

	if (!empty($rFav)) {
		$favoriteChannelIds = array();

		foreach ($rTypes as $rType) {
			foreach ($rUserInfo['fav_channels'][$rType] as $rStreamID) {
				$favoriteChannelIds[] = intval($rStreamID);
			}
		}
		$rChannels = array_intersect($favoriteChannelIds, $rChannels);
	}

	if (!empty($rSearchBy)) {
		$rWhere[] = '`stream_display_name` LIKE ?';
		$rWhereV[] = '%' . $rSearchBy . '%';
	}

	if (!empty($rPicking['year_range']) && is_array($rPicking['year_range'])) {
		$rWhere[] = '(`year` >= ? AND `year` <= ?)';
		$rWhereV[] = $rPicking['year_range'][0];
		$rWhereV[] = $rPicking['year_range'][1];
	}

	if (!empty($rPicking['rating_range']) && is_array($rPicking['rating_range'])) {
		$rWhere[] = '(`rating` >= ? AND `rating` <= ?)';
		$rWhereV[] = $rPicking['rating_range'][0];
		$rWhereV[] = $rPicking['rating_range'][1];
	}

	$rChannels = InputValidator::confirmIDs($rChannels);

	if (count($rChannels) != 0) {
		$rWhere[] = '`id` IN (' . implode(',', array_map('intval', $rChannels)) . ')';
		$rWhereString = 'WHERE ' . implode(' AND ', $rWhere);

		switch ($rOrderBy) {
			case 'name':
				uasort($rStreams['streams'], 'sortArrayStreamName');
				$rOrder = '`stream_display_name` ASC';
				break;

			case 'top':
			case 'rating':
				$rOrder = '`rating` DESC';
				break;

			case 'added':
				$rOrder = '`added` DESC';
				break;

			case 'release':
				$rOrder = '`year` DESC, `stream_display_name` ASC';
				break;

			case 'number':
			default:
				if (SettingsManager::getAll()['channel_number_type'] != 'manual' && 0 < count($rChannels)) {
					$rOrder = 'FIELD(id,' . implode(',', $rChannels) . ')';
				} else {
					$rOrder = '`order` ASC';
				}
				break;
		}

		if (0 < count($rChannels)) {
			$db->query('SELECT COUNT(`id`) AS `count` FROM `streams` ' . $rWhereString . ';', ...$rWhereV);

			$rStreams['count'] = $db->get_row()['count'];

			if ($rLimit) {
				if ($rIDs) {
					$rQuery = 'SELECT `id` FROM `streams` ' . $rWhereString . ' ORDER BY ' . $rOrder . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
				} else {
					$rQuery = 'SELECT (SELECT `stream_info` FROM `streams_servers` WHERE `streams_servers`.`pid` IS NOT NULL AND `streams_servers`.`stream_id` = `streams`.`id` LIMIT 1) AS `stream_info`, `id`, `stream_display_name`, `movie_properties`, `target_container`, `added`, `year`, `category_id`, `channel_id`, `epg_id`, `tv_archive_duration`, `stream_icon`, `allow_record`, `type` FROM `streams` ' . $rWhereString . ' ORDER BY ' . $rOrder . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
				}
			} else {
				if ($rIDs) {
					$rQuery = 'SELECT `id` FROM `streams` ' . $rWhereString . ' ORDER BY ' . $rOrder . ';';
				} else {
					$rQuery = 'SELECT (SELECT `stream_info` FROM `streams_servers` WHERE `streams_servers`.`pid` IS NOT NULL AND `streams_servers`.`stream_id` = `streams`.`id` LIMIT 1) AS `stream_info`, `id`, `stream_display_name`, `movie_properties`, `target_container`, `added`, `year`, `category_id`, `channel_id`, `epg_id`, `tv_archive_duration`, `stream_icon`, `allow_record`, `type` FROM `streams` ' . $rWhereString . ' ORDER BY ' . $rOrder . ';';
				}
			}

			$db->query($rQuery, ...$rWhereV);
			$rRows = $db->get_rows();
		} else {
			$rRows = array();
		}

		if ($rIDs) {
			return $rRows;
		}

		foreach ($rRows as $rStream) {
			$rStream['number'] = $rKey;

			if (in_array($rCategoryID, json_decode($rStream['category_id'], true))) {
				$rStream['category_id'] = $rCategoryID;
			} else {
				list($rStream['category_id']) = json_decode($rStream['category_id'], true);
			}

			$rStream['stream_info'] = json_decode($rStream['stream_info'], true);
			$rStreams['streams'][$rStream['id']] = $rStream;
			$rKey++;
		}

		return $rStreams;
	} else {
		return $rStreams;
	}
}

function getUserSeries($rUserInfo, $rCategoryID = null, $rFav = null, $rOrderBy = null, $rSearchBy = null, $rPicking = array(), $rStart = 0, $rLimit = 10, $additionalOptions = null) {
	global $db;
	$rPicking = $rPicking ?? [];
	$rSeries = $rUserInfo['series_ids'];
	$rStreams = array('count' => 0, 'streams' => array());
	$rKey = $rStart + 1;
	$rWhereV = $rWhere = array();

	if (SettingsManager::getAll()['player_hide_incompatible']) {
		$rWhere[] = '(SELECT MAX(`compatible`) FROM `streams_servers` LEFT JOIN `streams_episodes` ON `streams_episodes`.`stream_id` = `streams_servers`.`stream_id` WHERE `streams_episodes`.`series_id` = `streams_series`.`id`) = 1';
	}

	if (!empty($rCategoryID)) {
		$rWhere[] = "JSON_CONTAINS(`category_id`, ?, '\$')";

		$rWhereV[] = $rCategoryID;
	}

	if (!empty($rPicking['year_range']) && is_array($rPicking['year_range'])) {
		$rWhere[] = '(`year` >= ? AND `year` <= ?)';

		$rWhereV[] = $rPicking['year_range'][0];
		$rWhereV[] = $rPicking['year_range'][1];
	}

	if (!empty($rPicking['rating_range']) && is_array($rPicking['rating_range'])) {
		$rWhere[] = '(`rating` >= ? AND `rating` <= ?)';
		$rWhereV[] = $rPicking['rating_range'][0];
		$rWhereV[] = $rPicking['rating_range'][1];
	}

	if (!empty($rSearchBy)) {
		$rWhere[] = '`title` LIKE ?';
		$rWhereV[] = '%' . $rSearchBy . '%';
	}

	$rSeries = InputValidator::confirmIDs($rSeries);

	if (count($rSeries) != 0) {
		$rWhere[] = '`id` IN (' . implode(',', array_map('intval', $rSeries)) . ')';
		$rWhereString = 'WHERE ' . implode(' AND ', $rWhere);

		switch ($rOrderBy) {
			case 'name':
				uasort($rStreams['streams'], 'sortArrayStreamName');
				$rOrder = '`title` ASC';

				break;

			case 'top':
			case 'rating':
				$rOrder = '`rating` DESC';

				break;

			case 'added':
				$rOrder = '`last_modified` DESC';
				break;

			case 'release':
				$rOrder = '`release_date` DESC';
				break;

			case 'number':
			default:
				if (SettingsManager::getAll()['vod_sort_newest']) {
					$rOrder = '`last_modified` DESC';
				} else {
					$rOrder = 'FIELD(id,' . implode(',', $rSeries) . ')';
				}

				break;
		}

		if (0 < count($rSeries)) {
			$db->query('SELECT COUNT(`id`) AS `count` FROM `streams_series` ' . $rWhereString . ';', ...$rWhereV);

			$rStreams['count'] = $db->get_row()['count'];

			if ($rLimit) {
				$rQuery = 'SELECT `id`, `title`, `category_id`, `cover`, `rating`, `release_date`, `last_modified`, `tmdb_id`, `seasons`, `backdrop_path`, `year` FROM `streams_series` ' . $rWhereString . ' ORDER BY ' . $rOrder . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
			} else {
				$rQuery = 'SELECT `id`, `title`, `category_id`, `cover`, `rating`, `release_date`, `last_modified`, `tmdb_id`, `seasons`, `backdrop_path`, `year` FROM `streams_series` ' . $rWhereString . ' ORDER BY ' . $rOrder . ';';
			}

			$db->query($rQuery, ...$rWhereV);
			$rRows = $db->get_rows();
		} else {
			if ($additionalOptions) {
				return null;
			}

			$rRows = array();
		}

		foreach ($rRows as $rStream) {
			$rStream['number'] = $rKey;

			if (in_array($rCategoryID, json_decode($rStream['category_id'], true))) {
				$rStream['category_id'] = $rCategoryID;
			} else {
				list($rStream['category_id']) = json_decode($rStream['category_id'], true);
			}

			$rStreams['streams'][$rStream['id']] = $rStream;
			$rKey++;
		}

		return $rStreams;
	} else {
		return $rStreams;
	}
}

function mapContentTypesToNumbers($rTypes) {
	$rReturn = array();
	$rTypeInt = array('live' => 1, 'movie' => 2, 'created_live' => 3, 'radio_streams' => 4, 'series' => 5);

	foreach ($rTypes as $rType) {
		$rReturn[] = $rTypeInt[$rType];
	}

	return $rReturn;
}
