<?php

// Импорт глобальных переменных — нужен когда файл включается
// из метода контроллера (не из глобального scope).
global $db, $rSettings, $rMobile, $rServers, $rProxyServers, $rDetect,
       $rTimeout, $rProtocol, $allServers, $rPermissions, $language, $allowedLangs, $_INFO;

require_once __DIR__ . '/../bootstrap.php';
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_ADMIN);

if (is_array($rServers)) {
	uasort(
		$rServers,
		function ($a, $b) {
			return $a['order'] - $b['order'];
		}
	);
}

function secondsToTime($inputSeconds) {
	$secondsInAMinute = 60;
	$secondsInAnHour = 60 * $secondsInAMinute;
	$secondsInADay = 24 * $secondsInAnHour;
	$days = floor($inputSeconds / $secondsInADay);
	$hourSeconds = $inputSeconds % $secondsInADay;
	$hours = floor($hourSeconds / $secondsInAnHour);
	$minuteSeconds = $hourSeconds % $secondsInAnHour;
	$minutes = floor($minuteSeconds / $secondsInAMinute);
	$remainingSeconds = $minuteSeconds % $secondsInAMinute;
	$seconds = ceil($remainingSeconds);

	return array('d' => (int) $days, 'h' => (int) $hours, 'm' => (int) $minutes, 's' => (int) $seconds);
}

function validateCIDR($rCIDR) {
	$rParts = explode('/', $rCIDR);
	$rIP = $rParts[0];
	$rNetmask = null;

	if (count($rParts) != 2) {
	} else {
		$rNetmask = intval($rParts[1]);

		if ($rNetmask >= 0) {
		} else {
			return false;
		}
	}

	if (!filter_var($rIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
		if (!filter_var($rIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			return false;
		}


		return (is_null($rNetmask) ? true : $rNetmask <= 128);
	}

	return (is_null($rNetmask) ? true : $rNetmask <= 32);
}

function encodeRow($rRow) {
	foreach ($rRow as $rKey => $rValue) {
		if (!is_array($rValue)) {
		} else {
			$rRow[$rKey] = json_encode($rValue, JSON_UNESCAPED_UNICODE);
		}
	}

	return $rRow;
}

function getArchiveFiles($rServerID, $rStreamID) {
	return json_decode(systemapirequest($rServerID, array('action' => 'get_archive_files', 'stream_id' => $rStreamID)), true)['data'];
}

function getArchive($rStreamID) {
	global $db;
	$rReturn = array();
	$rStream = StreamRepository::getById($rStreamID);
	$rEPG = EpgService::getChannelEpg($rStream, true);
	$rFiles = getArchiveFiles($rStream['tv_archive_server_id'], $rStreamID);

	if (!empty($rFiles) && !empty($rEPG)) {
		foreach ($rFiles as $rFile) {
			$rFilename = pathinfo($rFile)['filename'];
			$rTimestamp = strtotime(explode(':', $rFilename)[0] . 'T' . implode(':', explode('-', explode(':', $rFilename)[1])) . ':00Z ' . str_replace(':', '', gmdate('P')));
			$rEPGID = null;
			$rI = 0;

			foreach ($rEPG as $rEPGItem) {
				if (!filter_var($rTimestamp, FILTER_VALIDATE_INT, array('options' => array('min_range' => $rEPGItem['start'], 'max_range' => $rEPGItem['end'] - 1)))) {
					$rI++;
				} else {
					$rEPGID = $rI;

					break;
				}
			}

			if ($rEPGID) {
				if (!isset($rReturn[$rEPGID])) {
					$rReturn[$rEPGID] = $rEPG[$rEPGID];
					$rReturn[$rEPGID]['archive_stop'] = null;
					$rReturn[$rEPGID]['archive_start'] = $rReturn[$rEPGID]['archive_stop'];
				}

				if ($rTimestamp - 60 >= $rReturn[$rEPGID]['archive_start'] && $rReturn[$rEPGID]['archive_start']) {
				} else {
					$rReturn[$rEPGID]['archive_start'] = $rTimestamp - 60;
				}

				if ($rReturn[$rEPGID]['archive_stop'] >= $rTimestamp && $rReturn[$rEPGID]['archive_stop']) {
				} else {
					$rReturn[$rEPGID]['archive_stop'] = $rTimestamp;
				}
			}
		}
	}

	foreach ($rReturn as $rKey => $rItem) {
		if (time() < $rItem['end']) {
			$rReturn[$rKey]['in_progress'] = true;
		} else {
			$rReturn[$rKey]['in_progress'] = false;
		}

		if (!$rReturn[$rKey]['in_progress'] && filter_var($rItem['start'], FILTER_VALIDATE_INT, array('options' => array('min_range' => $rItem['archive_start'] - 60, 'max_range' => $rItem['archive_start'] + 60))) && filter_var($rItem['end'], FILTER_VALIDATE_INT, array('options' => array('min_range' => $rItem['archive_stop'] - 60, 'max_range' => $rItem['archive_stop'] + 60)))) {
			$rReturn[$rKey]['complete'] = true;
		} else {
			$rReturn[$rKey]['complete'] = false;
		}
	}

	return $rReturn;
}

function getMovieTMDB($rID) {
	require_once MAIN_HOME . 'includes/libs/tmdb.php';

	if (0 < strlen(SettingsManager::getAll()['tmdb_language'])) {
		$rTMDB = new TMDB(SettingsManager::getAll()['tmdb_api_key'], SettingsManager::getAll()['tmdb_language']);
	} else {
		$rTMDB = new TMDB(SettingsManager::getAll()['tmdb_api_key']);
	}

	return ($rTMDB->getMovie($rID) ?: null);
}

function getSeriesTMDB($rID) {
	require_once MAIN_HOME . 'includes/libs/tmdb.php';

	if (0 < strlen(SettingsManager::getAll()['tmdb_language'])) {
		$rTMDB = new TMDB(SettingsManager::getAll()['tmdb_api_key'], SettingsManager::getAll()['tmdb_language']);
	} else {
		$rTMDB = new TMDB(SettingsManager::getAll()['tmdb_api_key']);
	}

	return (json_decode($rTMDB->getTVShow($rID)->getJSON(), true) ?: null);
}

function getSeasonTMDB($rID, $rSeason) {
	require_once MAIN_HOME . 'includes/libs/tmdb.php';


	if (0 < strlen(SettingsManager::getAll()['tmdb_language'])) {
		$rTMDB = new TMDB(SettingsManager::getAll()['tmdb_api_key'], SettingsManager::getAll()['tmdb_language']);
	} else {
		$rTMDB = new TMDB(SettingsManager::getAll()['tmdb_api_key']);
	}

	return json_decode($rTMDB->getSeason($rID, intval($rSeason))->getJSON(), true);
}

function overwriteData($rData, $rOverwrite, $rSkip = array()) {
	foreach ($rOverwrite as $rKey => $rValue) {
		if (!array_key_exists($rKey, $rData) || in_array($rKey, $rSkip)) {
		} else {
			if (empty($rValue) && is_null($rData[$rKey])) {
				$rData[$rKey] = null;
			} else {
				$rData[$rKey] = $rValue;
			}
		}
	}

	return $rData;
}

function verifyPostTable($rTable, $rData = array(), $rOnlyExisting = false) {
	global $db;
	$rReturn = array();
	$db->query('SELECT `column_name`, `column_default`, `is_nullable`, `data_type` FROM `information_schema`.`columns` WHERE `table_schema` = (SELECT DATABASE()) AND `table_name` = ? ORDER BY `ordinal_position`;', $rTable);

	foreach ($db->get_rows() as $rRow) {
		if ($rRow['column_default'] != 'NULL') {
		} else {
			$rRow['column_default'] = null;
		}

		$rForceDefault = false;


		if ($rRow['is_nullable'] != 'NO' || $rRow['column_default']) {
		} else {
			if (in_array($rRow['data_type'], array('int', 'float', 'tinyint', 'double', 'decimal', 'smallint', 'mediumint', 'bigint', 'bit'))) {
				$rRow['column_default'] = 0;
			} else {
				$rRow['column_default'] = '';
			}

			$rForceDefault = true;
		}

		if (array_key_exists($rRow['column_name'], $rData)) {
			if (empty($rData[$rRow['column_name']]) && !is_numeric($rData[$rRow['column_name']]) && is_null($rRow['column_default'])) {
				$rReturn[$rRow['column_name']] = ($rForceDefault ? $rRow['column_default'] : null);
			} else {
				$rReturn[$rRow['column_name']] = $rData[$rRow['column_name']];
			}
		} else {
			if ($rOnlyExisting) {
			} else {
				$rReturn[$rRow['column_name']] = $rRow['column_default'];
			}
		}
	}

	return $rReturn;
}

function preparecolumn($rValue) {
	return strtolower(preg_replace('/[^a-z0-9_]+/i', '', $rValue));
}

function prepareArray($rArray) {
	$UpdateData = $rColumns = $rPlaceholder = $rData = array();


	foreach (array_keys($rArray) as $rKey) {
		$rColumns[] = '`' . preparecolumn($rKey) . '`';
		$UpdateData[] = '`' . preparecolumn($rKey) . '` = ?';
	}

	foreach (array_values($rArray) as $rValue) {
		if (is_array($rValue)) {
			$rValue = json_encode($rValue, JSON_UNESCAPED_UNICODE);
		} else {
			if (is_null($rValue) || strtolower($rValue) == 'null') {
				$rValue = null;
			}
		}

		$rPlaceholder[] = '?';
		$rData[] = $rValue;
	}

	return array('placeholder' => implode(',', $rPlaceholder), 'columns' => implode(',', $rColumns), 'data' => $rData, 'update' => implode(',', $UpdateData));
}

function setArgs($rArgs, $rGet = true) {
	$rURL = getPageName();

	if (count($rArgs) > 0) {
		$rURL .= '?' . http_build_query($rArgs);

		if ($rGet) {
			foreach ($rArgs as $rKey => $rValue) {
				RequestManager::getAll()[$rKey] = $rValue;
			}
		}
	}

	return "<script>history.replaceState({},'','" . $rURL . "');</script>";
}

function getPageFromURL($rURL) {
	if ($rURL) {
		return strtolower(basename(ltrim(parse_url($rURL)['path'], '/'), '.php'));
	}

	return null;
}

function verifyCode() {
	global $rUserInfo;

	if (isset($rUserInfo)) {
		$rAccessCode = AuthRepository::getCurrentCode(true);

		if (in_array($rUserInfo['member_group_id'], json_decode($rAccessCode['groups'], true)) || count(AuthRepository::getActiveCodes(MAIN_HOME)) == 0) {
			if (isset($_SESSION['code']) && $_SESSION['code'] != $rAccessCode['code']) {
				return false;
			}


			return true;
		}

		return false;
	}








	return false;
}

function getNearest($arr, $search) {
	$closest = null;



	foreach ($arr as $item) {
		if (!($closest === null || abs($item - $search) < abs($search - $closest))) {
		} else {
			$closest = $item;
		}
	}

	return $closest;
}

function generateUniqueCode() {
	return substr(md5(SettingsManager::getAll()['live_streaming_pass']), 0, 15);
}

function checkExists($rTable, $rColumn, $rValue, $rExcludeColumn = null, $rExclude = null) {
	global $db;


	if ($rExcludeColumn && $rExclude) {
		$db->query('SELECT COUNT(*) AS `count` FROM `' . preparecolumn($rTable) . '` WHERE `' . preparecolumn($rColumn) . '` = ? AND `' . preparecolumn($rExcludeColumn) . '` <> ?;', $rValue, $rExclude);
	} else {
		$db->query('SELECT COUNT(*) AS `count` FROM `' . preparecolumn($rTable) . '` WHERE `' . preparecolumn($rColumn) . '` = ?;', $rValue);
	}




	return 0 < $db->get_row()['count'];
}

function deleteMAG($rID, $rDeletePaired = false, $rCloseCons = true, $rConvert = false) {
	global $db;
	$rMag = getMag($rID);

	if (!$rMag) {
		return false;
	}

	$db->query('DELETE FROM `mag_devices` WHERE `mag_id` = ?;', $rID);
	$db->query('DELETE FROM `mag_claims` WHERE `mag_id` = ?;', $rID);
	$db->query('DELETE FROM `mag_events` WHERE `mag_device_id` = ?;', $rID);
	$db->query('DELETE FROM `mag_logs` WHERE `mag_id` = ?;', $rID);

	if (!$rMag['user']) {
	} else {
		if ($rConvert) {
			$db->query('UPDATE `lines` SET `is_mag` = 0 WHERE `id` = ?;', $rMag['user']['id']);
			LineService::updateLineSignal($rMag['user']['id']);
		} else {
			$rCount = 0;
			$db->query('SELECT `mag_id` FROM `mag_devices` WHERE `user_id` = ?;', $rMag['user']['id']);
			$rCount += $db->num_rows();
			$db->query('SELECT `device_id` FROM `enigma2_devices` WHERE `user_id` = ?;', $rMag['user']['id']);
			$rCount += $db->num_rows();

			if ($rCount != 0) {
			} else {
				deleteLine($rMag['user']['id'], $rDeletePaired, $rCloseCons);
			}
		}
	}

	return true;
}

function deleteMAGs($rIDs) {
	global $db;
	$rIDs = confirmIDs($rIDs);


	if (0 >= count($rIDs)) {
		return false;
	}

	$rUserIDs = array();
	$db->query('SELECT `user_id` FROM `mag_devices` WHERE `mag_id` IN (' . implode(',', $rIDs) . ');');

	foreach ($db->get_rows() as $rRow) {
		$rUserIDs[] = $rRow['user_id'];
	}
	$db->query('DELETE FROM `mag_devices` WHERE `mag_id` IN (' . implode(',', $rIDs) . ');');
	$db->query('DELETE FROM `mag_claims` WHERE `mag_id` IN (' . implode(',', $rIDs) . ');');
	$db->query('DELETE FROM `mag_events` WHERE `mag_device_id` IN (' . implode(',', $rIDs) . ');');
	$db->query('DELETE FROM `mag_logs` WHERE `mag_id` IN (' . implode(',', $rIDs) . ');');

	if (0 >= count($rUserIDs)) {
	} else {
		deletelines($rUserIDs);
	}

	return true;
}

function deleteEnigma($rID, $rDeletePaired = false, $rCloseCons = true, $rConvert = false) {
	global $db;
	$rEnigma = getEnigma($rID);

	if (!$rEnigma) {
		return false;
	}

	$db->query('DELETE FROM `enigma2_devices` WHERE `device_id` = ?;', $rID);
	$db->query('DELETE FROM `enigma2_actions` WHERE `device_id` = ?;', $rID);


	if (!$rEnigma['user']) {
	} else {
		if ($rConvert) {
			$db->query('UPDATE `lines` SET `is_e2` = 0 WHERE `id` = ?;', $rEnigma['user']['id']);
			LineService::updateLineSignal($rEnigma['user']['id']);
		} else {
			$rCount = 0;
			$db->query('SELECT `mag_id` FROM `mag_devices` WHERE `user_id` = ?;', $rEnigma['user']['id']);
			$rCount += $db->num_rows();
			$db->query('SELECT `device_id` FROM `enigma2_devices` WHERE `user_id` = ?;', $rEnigma['user']['id']);
			$rCount += $db->num_rows();

			if ($rCount != 0) {
			} else {
				deleteLine($rEnigma['user']['id'], $rDeletePaired, $rCloseCons);
			}
		}
	}

	return true;
}

function deleteEnigmas($rIDs) {
	global $db;
	$rIDs = confirmIDs($rIDs);


	if (0 >= count($rIDs)) {
		return false;
	}

	$rUserIDs = array();
	$db->query('SELECT `user_id` FROM `enigma2_devices` WHERE `device_id` IN (' . implode(',', $rIDs) . ');');


	foreach ($db->get_rows() as $rRow) {
		$rUserIDs[] = $rRow['user_id'];
	}
	$db->query('DELETE FROM `enigma2_devices` WHERE `device_id` IN (' . implode(',', $rIDs) . ');');
	$db->query('DELETE FROM `enigma2_actions` WHERE `device_id` IN (' . implode(',', $rIDs) . ');');

	if (0 >= count($rUserIDs)) {
	} else {
		deletelines($rUserIDs);
	}

	return true;
}

function deleteSeries($rID, $rDeleteFiles = true) {
	global $db;
	$rSeries = getSerie($rID);

	if (!$rSeries) {
		return false;
	}

	$db->query('SELECT `stream_id` FROM `streams_episodes` WHERE `series_id` = ?;', $rID);

	foreach ($db->get_rows() as $rRow) {
		deleteStream($rRow['stream_id'], -1, $rDeleteFiles);
	}
	$db->query('DELETE FROM `streams_episodes` WHERE `series_id` = ?;', $rID);
	$db->query('DELETE FROM `streams_series` WHERE `id` = ?;', $rID);
	BouquetService::scan();

	return true;
}

function deleteSeriesMass($rIDs) {
	global $db;
	$rIDs = confirmIDs($rIDs);

	if (0 >= count($rIDs)) {
		return false;
	}

	$db->query('SELECT `stream_id` FROM `streams_episodes` WHERE `series_id` IN (' . implode(',', $rIDs) . ');');


	foreach ($db->get_rows() as $rRow) {
		$rStreamIDs[] = $rRow['stream_id'];
	}
	$db->query('DELETE FROM `streams_episodes` WHERE `series_id` IN (' . implode(',', $rIDs) . ');');
	$db->query('DELETE FROM `streams_series` WHERE `id` IN (' . implode(',', $rIDs) . ');');

	if (0 >= count($rStreamIDs)) {
	} else {
		deleteStreams($rStreamIDs, true);
	}

	BouquetService::scan();

	return true;
}

function deleteBouquet($rID) {
	global $db;
	$rBouquet = getBouquet($rID);


	if (!$rBouquet) {
		return false;
	}

	$db->query("SELECT `id`, `bouquet` FROM `lines` WHERE JSON_CONTAINS(`bouquet`, ?, '\$');", $rID);


	foreach ($db->get_rows() as $rRow) {
		$rRow['bouquet'] = json_decode($rRow['bouquet'], true);




		if (($rKey = array_search($rID, $rRow['bouquet'])) === false) {
		} else {
			unset($rRow['bouquet'][$rKey]);
		}

		$db->query("UPDATE `lines` SET `bouquet` = '[" . implode(',', array_map('intval', $rRow['bouquet'])) . "]' WHERE `id` = ?;", $rRow['id']);
		LineService::updateLineSignal($rRow['id']);
	}
	$db->query("SELECT `id`, `bouquets` FROM `users_packages` WHERE JSON_CONTAINS(`bouquets`, ?, '\$');", $rID);

	foreach ($db->get_rows() as $rRow) {
		$rRow['bouquets'] = json_decode($rRow['bouquets'], true);

		if (($rKey = array_search($rID, $rRow['bouquets'])) === false) {
		} else {
			unset($rRow['bouquets'][$rKey]);
		}

		$db->query("UPDATE `users_packages` SET `bouquets` = '[" . implode(',', array_map('intval', $rRow['bouquets'])) . "]' WHERE `id` = ?;", $rRow['id']);
	}
	$db->query("SELECT `id`, `bouquets` FROM `watch_folders` WHERE JSON_CONTAINS(`bouquets`, ?, '\$') OR JSON_CONTAINS(`fb_bouquets`, ?, '\$');", $rID, $rID);

	foreach ($db->get_rows() as $rRow) {
		$rRow['bouquets'] = json_decode($rRow['bouquets'], true);

		if (($rKey = array_search($rID, $rRow['bouquets'])) === false) {
		} else {
			unset($rRow['bouquets'][$rKey]);
		}

		$rRow['fb_bouquets'] = json_decode($rRow['fb_bouquets'], true);





		if (($rKey = array_search($rID, $rRow['fb_bouquets'])) === false) {
		} else {
			unset($rRow['fb_bouquets'][$rKey]);
		}

		$db->query("UPDATE `watch_folders` SET `bouquets` = '[" . implode(',', array_map('intval', $rRow['bouquets'])) . "]', `fb_bouquets` = '[" . implode(',', array_map('intval', $rRow['fb_bouquets'])) . "]' WHERE `id` = ?;", $rRow['id']);
	}
	$db->query('DELETE FROM `bouquets` WHERE `id` = ?;', $rID);
	BouquetService::scan();

	return true;
}

function deleteCategory($rID) {
	global $db;
	$rCategory = getCategory($rID);


	if (!$rCategory) {
		return false;
	}

	$db->query("SELECT `id`, `category_id` FROM `streams` WHERE JSON_CONTAINS(`category_id`, ?, '\$');", $rID);

	foreach ($db->get_rows() as $rRow) {
		$rRow['category_id'] = json_decode($rRow['category_id'], true);

		if (($rKey = array_search($rID, $rRow['category_id'])) === false) {
		} else {
			unset($rRow['category_id'][$rKey]);
		}

		$db->query("UPDATE `streams` SET `category_id` = '[" . implode(',', array_map('intval', $rRow['category_id'])) . "]' WHERE `id` = ?;", $rRow['id']);
	}
	$db->query("SELECT `id`, `category_id` FROM `streams_series` WHERE JSON_CONTAINS(`category_id`, ?, '\$');", $rID);

	foreach ($db->get_rows() as $rRow) {
		$rRow['category_id'] = json_decode($rRow['category_id'], true);

		if (($rKey = array_search($rID, $rRow['category_id'])) === false) {
		} else {
			unset($rRow['category_id'][$rKey]);
		}

		$db->query("UPDATE `streams_series` SET `category_id` = '[" . implode(',', array_map('intval', $rRow['category_id'])) . "]' WHERE `id` = ?;", $rRow['id']);
	}
	$db->query('DELETE FROM `streams_categories` WHERE `id` = ?;', $rID);
	$db->query('UPDATE `watch_folders` SET `category_id` = null WHERE `category_id` = ?;', $rID);
	$db->query('UPDATE `watch_folders` SET `fb_category_id` = null WHERE `fb_category_id` = ?;', $rID);

	return true;
}

function deleteProfile($rID) {
	global $db;
	$rProfile = getTranscodeProfile($rID);

	if (!$rProfile) {
		return false;
	}

	$db->query('DELETE FROM `profiles` WHERE `profile_id` = ?;', $rID);
	$db->query('UPDATE `streams` SET `transcode_profile_id` = 0 WHERE `transcode_profile_id` = ?;', $rID);
	$db->query('UPDATE `watch_folders` SET `transcode_profile_id` = 0 WHERE `transcode_profile_id` = ?;', $rID);

	return true;
}

function AsyncAPIRequest($rServerIDs, $rData) {
	$rURLs = array();
	global $rServers;

	foreach ($rServerIDs as $rServerID) {
		if (!$rServers[$rServerID]['server_online']) {
		} else {
			$rURLs[$rServerID] = array('url' => $rServers[$rServerID]['api_url'], 'postdata' => $rData);
		}
	}
	CurlClient::getMultiCURL($rURLs);

	return array('result' => true);
}

function changePort($rServerID, $rType, $rPorts, $rReload = false) {
	global $db;
	$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServerID, time(), json_encode(array('action' => 'set_port', 'type' => intval($rType), 'ports' => $rPorts, 'reload' => $rReload)));
}

function setServices($rServerID, $rNumServices, $rReload = true) {
	global $db;
	$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServerID, time(), json_encode(array('action' => 'set_services', 'count' => intval($rNumServices), 'reload' => $rReload)));
}

function setGovernor($rServerID, $rGovernor) {
	global $db;
	$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServerID, time(), json_encode(array('action' => 'set_governor', 'data' => $rGovernor)));
}

function setSysctl($rServerID, $rSysCtl) {
	global $db;
	$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServerID, time(), json_encode(array('action' => 'set_sysctl', 'data' => $rSysCtl)));
}

function resetSTB($rID) {
	global $db;
	$db->query("UPDATE `mag_devices` SET `ip` = '', `ver` = '', `image_version` = '', `stb_type` = '', `sn` = '', `device_id` = '', `device_id2` = '', `hw_version` = '', `token` = '' WHERE `mag_id` = ?;", $rID);
}

function formatUptime($rUptime) {
	if (86400 <= $rUptime) {
		$rUptime = sprintf('%02dd %02dh %02dm', $rUptime / 86400, ($rUptime / 3600) % 24, ($rUptime / 60) % 60);
	} else {
		$rUptime = sprintf('%02dh %02dm %02ds', $rUptime / 3600, ($rUptime / 60) % 60, $rUptime % 60);
	}




	return $rUptime;
}

function getSettings() {
	global $db;
	$db->query('SELECT * FROM `settings` LIMIT 1;');



	return $db->get_row();
}


function APIRequest($rData, $rTimeout = 5) {
	ini_set('default_socket_timeout', $rTimeout);
	$rAPI = 'http://127.0.0.1:' . intval(ServerRepository::getAll()[SERVER_ID]['http_broadcast_port']) . '/admin/api';

	if (!empty(SettingsManager::getAll()['api_pass'])) {
		$rData['api_pass'] = SettingsManager::getAll()['api_pass'];
	}

	$rPost = http_build_query($rData);
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $rAPI);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $rPost);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $rTimeout);
	curl_setopt($ch, CURLOPT_TIMEOUT, $rTimeout);

	return curl_exec($ch);
}

function systemapirequest($rServerID, $rData, $rTimeout = 5) {
	ini_set('default_socket_timeout', $rTimeout);
	global $rServers, $rSettings;
	if (!is_array($rServers) || !isset($rServers[$rServerID])) {
		return null;
	}
	if ($rServers[$rServerID]['server_online']) {
		$rAPI = 'http://' . $rServers[intval($rServerID)]['server_ip'] . ':' . $rServers[intval($rServerID)]['http_broadcast_port'] . '/api';
		$rData['password'] = $rSettings['live_streaming_pass'];
		$rPost = http_build_query($rData);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $rAPI);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $rPost);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $rTimeout);
		curl_setopt($ch, CURLOPT_TIMEOUT, $rTimeout);

		return curl_exec($ch);
	}
	return null;
}

function getWatchFolder($rID) {
	global $db;
	$db->query('SELECT * FROM `watch_folders` WHERE `id` = ?;', $rID);


	if ($db->num_rows() != 1) {
	} else {
		return $db->get_row();
	}
}

function getSeriesByTMDB($rID) {
	global $db;
	$db->query('SELECT * FROM `streams_series` WHERE `tmdb_id` = ?;', $rID);

	if ($db->num_rows() != 1) {
	} else {
		return $db->get_row();
	}
}

function getSeries() {
	global $db;
	$rReturn = array();
	$db->query('SELECT * FROM `streams_series` ORDER BY `title` ASC;');

	if (0 >= $db->num_rows()) {
	} else {
		foreach ($db->get_rows() as $rRow) {
			$rReturn[] = $rRow;
		}
	}

	return $rReturn;
}

function getSerie($rID) {
	global $db;
	$db->query('SELECT * FROM `streams_series` WHERE `id` = ?;', $rID);


	if ($db->num_rows() != 1) {
	} else {
		return $db->get_row();
	}
}

function getSeriesTrailer($rTMDBID, $rLanguage = null) {
	$rURL = 'https://api.themoviedb.org/3/tv/' . intval($rTMDBID) . '/videos?api_key=' . urlencode(SettingsManager::getAll()['tmdb_api_key']);



	if ($rLanguage) {
		$rURL .= '&language=' . urlencode($rLanguage);
	} else {
		if (0 >= strlen(SettingsManager::getAll()['tmdb_language'])) {
		} else {
			$rURL .= '&language=' . urlencode(SettingsManager::getAll()['tmdb_language']);
		}
	}

	$rJSON = json_decode(file_get_contents($rURL), true);


	foreach ($rJSON['results'] as $rVideo) {
		if (!(strtolower($rVideo['type']) == 'trailer' && strtolower($rVideo['site']) == 'youtube')) {
		} else {
			return $rVideo['key'];
		}
	}

	return '';
}

function getBouquet($rID) {
	global $db;
	$db->query('SELECT * FROM `bouquets` WHERE `id` = ?;', $rID);

	if ($db->num_rows() != 1) {
	} else {
		return $db->get_row();
	}
}

function getLanguages() {
	return array();
}

function addToBouquet($rType, $rBouquetID, $rIDs) {
	global $db;

	if (is_array($rIDs)) {
	} else {
		$rIDs = array($rIDs);
	}

	$rBouquet = getBouquet($rBouquetID);

	if (!$rBouquet) {
	} else {
		if ($rType == 'stream') {
			$rColumn = 'bouquet_channels';
		} else {
			if ($rType == 'movie') {
				$rColumn = 'bouquet_movies';
			} else {
				if ($rType == 'radio') {
					$rColumn = 'bouquet_radios';
				} else {

					$rColumn = 'bouquet_series';
				}
			}
		}

		$rChanged = false;
		$rChannels = confirmIDs(json_decode($rBouquet[$rColumn], true));

		foreach ($rIDs as $rID) {
			if (0 >= intval($rID) || in_array($rID, $rChannels)) {
			} else {
				$rChannels[] = $rID;
				$rChanged = true;
			}
		}

		if (!$rChanged) {
		} else {
			$db->query('UPDATE `bouquets` SET `' . $rColumn . '` = ? WHERE `id` = ?;', '[' . implode(',', array_map('intval', $rChannels)) . ']', $rBouquetID);
		}
	}
}

function removeFromBouquet($rType, $rBouquetID, $rIDs) {
	global $db;

	if (is_array($rIDs)) {
	} else {
		$rIDs = array($rIDs);
	}

	$rBouquet = getBouquet($rBouquetID);

	if (!$rBouquet) {
	} else {
		if ($rType == 'stream') {
			$rColumn = 'bouquet_channels';
		} else {
			if ($rType == 'movie') {
				$rColumn = 'bouquet_movies';
			} else {
				if ($rType == 'radio') {
					$rColumn = 'bouquet_radios';
				} else {
					$rColumn = 'bouquet_series';
				}
			}
		}










		$rChanged = false;
		$rChannels = confirmIDs(json_decode($rBouquet[$rColumn], true));

		foreach ($rIDs as $rID) {
			if (($rKey = array_search($rID, $rChannels)) === false) {
			} else {
				unset($rChannels[$rKey]);
				$rChanged = true;
			}
		}

		if (!$rChanged) {
		} else {
			$db->query('UPDATE `bouquets` SET `' . $rColumn . '` = ? WHERE `id` = ?;', '[' . implode(',', array_map('intval', $rChannels)) . ']', $rBouquetID);
		}
	}
}

function confirmIDs($rIDs) {
	$rReturn = array();

	foreach ($rIDs as $rID) {
		if (0 >= intval($rID)) {
		} else {
			$rReturn[] = $rID;
		}
	}

	return array_unique($rReturn);
}

function downloadRemoteBackup($rPath, $rFilename) {
	require_once MAIN_HOME . 'includes/libs/Dropbox.php';
	$rClient = new DropboxClient();

	try {
		$rClient->SetBearerToken(array('t' => SettingsManager::getAll()['dropbox_token']));
		$rClient->downloadFile($rPath, $rFilename);



		return true;
	} catch (exception $e) {
		return false;
	}
}

function deleteRemoteBackup($rPath) {
	require_once MAIN_HOME . 'includes/libs/Dropbox.php';
	$rClient = new DropboxClient();








	try {
		$rClient->SetBearerToken(array('t' => SettingsManager::getAll()['dropbox_token']));
		$rClient->Delete($rPath);




		return true;
	} catch (exception $e) {







		return false;
	}
}

function parserelease($rRelease) {
	if (SettingsManager::getAll()['parse_type'] == 'guessit') {
		$rCommand = MAIN_HOME . 'bin/guess ' . escapeshellarg(pathinfo($rRelease)['filename'] . '.mkv');
	} else {
		$rCommand = '/usr/bin/python3 ' . MAIN_HOME . 'includes/python/release.py ' . escapeshellarg(pathinfo(str_replace('-', '_', $rRelease))['filename']);
	}

	return json_decode(shell_exec($rCommand), true);
}

function scanRecursive($rServerID, $rDirectory, $rAllowed = null) {
	return json_decode(systemapirequest($rServerID, array('action' => 'scandir_recursive', 'dir' => $rDirectory, 'allowed' => implode('|', $rAllowed))), true);
}

function listDir($rServerID, $rDirectory, $rAllowed = null) {
	return json_decode(systemapirequest($rServerID, array('action' => 'scandir', 'dir' => $rDirectory, 'allowed' => implode('|', $rAllowed))), true);
}

function rdeleteBlockedIP($rID) {
	global $db;
	$db->query('SELECT `id`, `ip` FROM `blocked_ips` WHERE `id` = ?;', $rID);

	if (0 >= $db->num_rows()) {
		return false;
	}

	$rRow = $db->get_row();
	$db->query('DELETE FROM `blocked_ips` WHERE `id` = ?;', $rID);

	if (!file_exists(FLOOD_TMP_PATH . 'block_' . $rRow['ip'])) {
	} else {
		unlink(FLOOD_TMP_PATH . 'block_' . $rRow['ip']);
	}

	return true;
}

function rdeleteBlockedISP($rID) {
	global $db;
	$db->query('SELECT `id` FROM `blocked_isps` WHERE `id` = ?;', $rID);

	if (0 >= $db->num_rows()) {
		return false;
	}

	$db->query('DELETE FROM `blocked_isps` WHERE `id` = ?;', $rID);








	return true;
}

function rdeleteBlockedUA($rID) {
	global $db;
	$db->query('SELECT `id` FROM `blocked_uas` WHERE `id` = ?;', $rID);


	if (0 >= $db->num_rows()) {



		return false;
	}


	$db->query('DELETE FROM `blocked_uas` WHERE `id` = ?;', $rID);






	return true;
}

function removeAccessEntry($rID) {
	global $db;
	$db->query('SELECT `id` FROM `access_codes` WHERE `id` = ?;', $rID);

	if (0 >= $db->num_rows()) {
		return false;
	}


	$db->query('DELETE FROM `access_codes` WHERE `id` = ?;', $rID);
	AuthRepository::updateCodes();




	return true;
}

function validateHMAC($rID) {
	global $db;
	$db->query('SELECT `id` FROM `hmac_keys` WHERE `id` = ?;', $rID);

	if (0 >= $db->num_rows()) {
		return false;
	}

	$db->query('DELETE FROM `hmac_keys` WHERE `id` = ?;', $rID);








	return true;
}

function getStills($rTMDBID, $rSeason, $rEpisode) {
	$rURL = 'https://api.themoviedb.org/3/tv/' . intval($rTMDBID) . '/season/' . intval($rSeason) . '/episode/' . intval($rEpisode) . '/images?api_key=' . urlencode(SettingsManager::getAll()['tmdb_api_key']);


	if (0 >= strlen(SettingsManager::getAll()['tmdb_language'])) {
	} else {
		$rURL .= '&language=' . urlencode(SettingsManager::getAll()['tmdb_language']);
	}

	return json_decode(file_get_contents($rURL), true);
}

function getUserAgents() {
	global $db;
	$rReturn = array();
	$db->query('SELECT * FROM `blocked_uas` ORDER BY `id` ASC;');

	if (0 >= $db->num_rows()) {
	} else {
		foreach ($db->get_rows() as $rRow) {
			$rReturn[] = $rRow;
		}
	}

	return $rReturn;
}

function getISPs() {
	global $db;
	$rReturn = array();
	$db->query('SELECT * FROM `blocked_isps` ORDER BY `id` ASC;');

	if (0 >= $db->num_rows()) {
	} else {
		foreach ($db->get_rows() as $rRow) {
			$rReturn[] = $rRow;
		}
	}

	return $rReturn;
}

function getStreamProviders() {
	global $db;
	$rReturn = array();
	$db->query('SELECT * FROM `providers` ORDER BY `last_changed` DESC;');

	if (0 >= $db->num_rows()) {
	} else {
		foreach ($db->get_rows() as $rRow) {
			$rReturn[] = $rRow;
		}
	}

	return $rReturn;
}

function getStreamProvider($rID) {
	global $db;
	$db->query('SELECT * FROM `providers` WHERE `id` = ?;', $rID);

	if ($db->num_rows() != 1) {
	} else {
		return $db->get_row();
	}
}

function getExpiring($rLimit = 2419200) {
	global $db;
	global $rUserInfo;
	global $rPermissions;
	$rReturn = array();
	$rReports = array_map('intval', array_merge(array($rUserInfo['id']), $rPermissions['all_reports']));

	if (0 >= count($rReports)) {
	} else {
		$db->query('SELECT `is_mag`, `is_e2`, `lines`.`id` AS `line_id`, `lines`.`reseller_notes`, `mag_devices`.`mag_id`, `enigma2_devices`.`device_id` AS `e2_id`, `member_id`, `username`, `password`, `exp_date`, `mag_devices`.`mac` AS `mag_mac`, `enigma2_devices`.`mac` AS `e2_mac` FROM `lines` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` LEFT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines`.`id` WHERE `member_id` IN (' . implode(',', $rReports) . ') AND `exp_date` IS NOT NULL AND `exp_date` >= ? AND `exp_date` < ? ORDER BY `exp_date` ASC LIMIT 250;', time(), time() + $rLimit);

		foreach ($db->get_rows() as $rRow) {
			$rReturn[] = $rRow;
		}
	}

	return $rReturn;
}

function getTickets($rID = null, $rAdmin = false) {
	global $db;
	global $rUserInfo;
	global $rPermissions;
	$rReturn = array();

	if ($rID) {
		if ($rAdmin) {
			$db->query('SELECT `tickets`.`id`, `tickets`.`member_id`, `tickets`.`title`, `tickets`.`status`, `tickets`.`admin_read`, `tickets`.`user_read`, `users`.`username` FROM `tickets`, `users` WHERE `member_id` IN (SELECT `id` FROM `users` WHERE `owner_id` = ?) AND `users`.`id` = `tickets`.`member_id` ORDER BY `id` DESC;', $rID);
		} else {
			$db->query('SELECT `tickets`.`id`, `tickets`.`member_id`, `tickets`.`title`, `tickets`.`status`, `tickets`.`admin_read`, `tickets`.`user_read`, `users`.`username` FROM `tickets`, `users` WHERE `member_id` IN (' . implode(',', array_map('intval', array_merge(array($rUserInfo['id']), $rPermissions['all_reports']))) . ') AND `users`.`id` = `tickets`.`member_id` ORDER BY `id` DESC;');
		}
	} else {
		$db->query('SELECT `tickets`.`id`, `tickets`.`member_id`, `tickets`.`title`, `tickets`.`status`, `tickets`.`admin_read`, `tickets`.`user_read`, `users`.`username` FROM `tickets`, `users` WHERE `users`.`id` = `tickets`.`member_id` ORDER BY `id` DESC;');
	}

	if (0 >= $db->num_rows()) {
	} else {
		foreach ($db->get_rows() as $rRow) {
			$db->query('SELECT MIN(`date`) AS `date` FROM `tickets_replies` WHERE `ticket_id` = ?;', $rRow['id']);

			if ($rDate = $db->get_row()['date']) {
				$rRow['created'] = date('Y-m-d H:i', $rDate);
			} else {
				$rRow['created'] = '';
			}

			$db->query('SELECT * FROM `tickets_replies` WHERE `ticket_id` = ? ORDER BY `id` DESC LIMIT 1;', $rRow['id']);
			$rLastResponse = $db->get_row();
			$rRow['last_reply'] = date('Y-m-d H:i', $rLastResponse['date']);

			if ($rRow['member_id'] == $rID) {
				if ($rRow['status'] == 0) {
				} else {
					if ($rLastResponse['admin_reply']) {
						if ($rRow['user_read'] == 1) {
							$rRow['status'] = 3;
						} else {
							$rRow['status'] = 4;
						}
					} else {
						if ($rRow['admin_read'] == 1) {
							$rRow['status'] = 5;
						} else {
							$rRow['status'] = 2;
						}
					}
				}
			} else {
				if ($rRow['status'] == 0) {
				} else {
					if ($rLastResponse['admin_reply']) {
						if ($rRow['user_read'] == 1) {
							$rRow['status'] = 6;
						} else {
							$rRow['status'] = 2;
						}
					} else {
						if ($rRow['admin_read'] == 1) {
							$rRow['status'] = 5;
						} else {
							$rRow['status'] = 4;
						}
					}
				}
			}

			$rReturn[] = $rRow;
		}
	}

	return $rReturn;
}

function cryptPassword($rPassword, $rSalt = 'xc_vm', $rRounds = 20000) {
	if ($rSalt != '') {
	} else {
		$rSalt = substr(bin2hex(openssl_random_pseudo_bytes(16)), 0, 16);
	}

	if (stripos($rSalt, 'rounds=')) {
	} else {
		$rSalt = sprintf('$6$rounds=%d$%s$', $rRounds, $rSalt);
	}

	return crypt($rPassword, $rSalt);
}

function getPermissions($rID) {
	global $db;
	$db->query('SELECT * FROM `users_groups` WHERE `group_id` = ?;', $rID);

	if ($db->num_rows() == 1) {
		$rRow = $db->get_row();
		$rRow['subresellers'] = !empty($rRow['subresellers']) ? json_decode($rRow['subresellers'], true) : [];

		if (count($rRow['subresellers'] ?? []) == 0) {
			$rRow['create_sub_resellers'] = 0;
		}

		return $rRow;
	}

	return [];
}

function destroySession($rType = 'admin') {
	global $_SESSION;
	$rKeys = array('admin' => array('hash', 'ip', 'code', 'verify', 'last_activity'), 'reseller' => array('reseller', 'rip', 'rcode', 'rverify', 'rlast_activity'), 'player' => array('phash', 'pverify'));

	foreach ($rKeys[$rType] as $rKey) {
		if (!isset($_SESSION[$rKey])) {
		} else {
			unset($_SESSION[$rKey]);
		}
	}
}

function getSelections($rSources) {
	global $db;
	$rReturn = array();

	foreach ($rSources as $rSource) {
		$db->query("SELECT `id` FROM `streams` WHERE `type` IN (2,5) AND `stream_source` LIKE ? ESCAPE '|' LIMIT 1;", '%' . str_replace('/', '\\/', $rSource) . '"%');

		if ($db->num_rows() != 1) {
		} else {
			$rReturn[] = intval($db->get_row()['id']);
		}
	}

	return $rReturn;
}

function getBackups() {
	$rBackups = array();

	foreach (scandir(MAIN_HOME . 'backups/') as $rBackup) {
		$rInfo = pathinfo(MAIN_HOME . 'backups/' . $rBackup);

		if ($rInfo['extension'] != 'sql') {
		} else {
			$rBackups[] = array('filename' => $rBackup, 'timestamp' => filemtime(MAIN_HOME . 'backups/' . $rBackup), 'date' => date('Y-m-d H:i:s', filemtime(MAIN_HOME . 'backups/' . $rBackup)), 'filesize' => filesize(MAIN_HOME . 'backups/' . $rBackup));
		}
	}
	usort(
		$rBackups,
		function ($a, $b) {
			return $a['timestamp'];
		}
	);

	return $rBackups;
}

function checkRemote() {
	require_once MAIN_HOME . 'includes/libs/Dropbox.php';

	try {
		$rClient = new DropboxClient();
		$rClient->SetBearerToken(array('t' => SettingsManager::getAll()['dropbox_token']));
		$rClient->GetFiles();

		return true;
	} catch (exception $e) {
		return false;
	}
}

function getRemoteBackups() {

	require_once MAIN_HOME . 'includes/libs/Dropbox.php';


	try {
		$rClient = new DropboxClient();
		$rClient->SetBearerToken(array('t' => SettingsManager::getAll()['dropbox_token']));
		$rFiles = $rClient->GetFiles();
	} catch (exception $e) {
		$rFiles = array();
	}
	$rBackups = array();

	foreach ($rFiles as $rFile) {
		try {
			if (!(!$rFile->isDir && strtolower(pathinfo($rFile->name)['extension']) == 'sql' && 0 < $rFile->size)) {
			} else {
				$rJSON = json_decode(json_encode($rFile, JSON_UNESCAPED_UNICODE), true);
				$rJSON['time'] = strtotime($rFile->server_modified);
				$rBackups[] = $rJSON;
			}
		} catch (exception $e) {
		}
	}
	array_multisort(array_column($rBackups, 'time'), SORT_ASC, $rBackups);

	return $rBackups;
}

function uploadRemoteBackup($rPath, $rFilename, $rOverwrite = true) {
	require_once MAIN_HOME . 'includes/libs/Dropbox.php';
	$rClient = new DropboxClient();

	try {
		$rClient->SetBearerToken(array('t' => SettingsManager::getAll()['dropbox_token']));

		return $rClient->UploadFile($rFilename, $rPath, $rOverwrite);
	} catch (exception $e) {
		return (object) array('error' => $e);
	}
}

function restoreImages() {
	global $db;
	global $rServers;
	foreach (array_keys($rServers) as $rServerID) {
		if (!$rServers[$rServerID]['server_online']) {
		} else {
			systemapirequest($rServerID, array('action' => 'restore_images'));
		}
	}

	return true;
}

function killPlexSync() {
	global $db;
	$db->query("SELECT DISTINCT(`server_id`) AS `server_id` FROM `watch_folders` WHERE `active` = 1 AND `type` = 'plex';");

	global $rServers;
	foreach ($db->get_rows() as $rRow) {
		if (!$rServers[$rRow['server_id']]['server_online']) {
		} else {
			systemapirequest($rRow['server_id'], array('action' => 'kill_plex'));
		}
	}

	return true;
}

function getPIDs($rServerID) {
	$rReturn = array();
	$rProcesses = json_decode(systemapirequest($rServerID, array('action' => 'get_pids')), true);
	if (!is_array($rProcesses)) {
		return $rReturn;
	}
	array_shift($rProcesses);

	foreach ($rProcesses as $rProcess) {
		$rSplit = explode(' ', preg_replace('!\\s+!', ' ', trim($rProcess)));

		if ($rSplit[0] == 'xc_vm') {
			$rUsage = array(0, 0, 0);
			$rTimer = explode('-', $rSplit[9]);

			if (1 < count($rTimer)) {
				$rDays = intval($rTimer[0]);
				$rTime = $rTimer[1];
			} else {
				$rDays = 0;
				$rTime = $rTimer[0];
			}

			$rTime = explode(':', $rTime);

			if (count($rTime) == 3) {
				$rSeconds = intval($rTime[0]) * 3600 + intval($rTime[1]) * 60 + intval($rTime[2]);
			} else {
				if (count($rTime) == 2) {
					$rSeconds = intval($rTime[0]) * 60 + intval($rTime[1]);
				} else {
					$rSeconds = intval($rTime[2]);
				}
			}


			$rUsage[0] = $rSeconds + $rDays * 86400;
			$rTimer = explode('-', $rSplit[8]);

			if (1 < count($rTimer)) {
				$rDays = intval($rTimer[0]);
				$rTime = $rTimer[1];
			} else {
				$rDays = 0;
				$rTime = $rTimer[0];
			}

			$rTime = explode(':', $rTime);

			if (count($rTime) == 3) {
				$rSeconds = intval($rTime[0]) * 3600 + intval($rTime[1]) * 60 + intval($rTime[2]);
			} else {
				if (count($rTime) == 2) {
					$rSeconds = intval($rTime[0]) * 60 + intval($rTime[1]);
				} else {
					$rSeconds = intval($rTime[2]);
				}
			}

			$rUsage[1] = $rSeconds + $rDays * 86400;
			if ($rUsage[0] != 0) {
				$rUsage[2] = $rUsage[1] / $rUsage[0] * 100;
			} else {
				$rUsage[2] = 0;
			}

			$rReturn[] = array('user' => $rSplit[0], 'pid' => $rSplit[1], 'cpu' => $rSplit[2], 'mem' => $rSplit[3], 'vsz' => $rSplit[4], 'rss' => $rSplit[5], 'tty' => $rSplit[6], 'stat' => $rSplit[7], 'time' => $rUsage[1], 'etime' => $rUsage[0], 'load_average' => $rUsage[2], 'command' => implode(' ', array_splice($rSplit, 10, count($rSplit) - 10)));
		}
	}

	return $rReturn;
}

function clearSettingsCache() {
	unlink(CACHE_TMP_PATH . 'settings');
}

function deleteUser($rID, $rDeleteSubUsers = false, $rDeleteLines = false, $rReplaceWith = null) {
	global $db;
	$rUser = UserRepository::getRegisteredUserById($rID);

	if (!$rUser) {
		return false;
	}

	$db->query('DELETE FROM `users` WHERE `id` = ?;', $rID);
	$db->query('DELETE FROM `users_credits_logs` WHERE `admin_id` = ?;', $rID);
	$db->query('DELETE FROM `users_logs` WHERE `owner` = ?;', $rID);
	$db->query('DELETE FROM `tickets_replies` WHERE `ticket_id` IN (SELECT `id` FROM `tickets` WHERE `member_id` = ?);', $rID);
	$db->query('DELETE FROM `tickets` WHERE `member_id` = ?;', $rID);

	if ($rDeleteSubUsers) {
		$db->query('SELECT `id` FROM `users` WHERE `owner_id` = ?;', $rID);

		foreach ($db->get_rows() as $rRow) {
			deleteUser($rRow['id'], $rDeleteSubUsers, $rDeleteLines, $rReplaceWith);
		}
	} else {
		$db->query('UPDATE `users` SET `owner_id` = ? WHERE `owner_id` = ?;', $rReplaceWith, $rID);
	}

	if ($rDeleteLines) {
		$db->query('SELECT `id` FROM `lines` WHERE `member_id` = ?;', $rID);

		foreach ($db->get_rows() as $rRow) {
			deleteLine($rRow['id']);
		}
	} else {
		$db->query('UPDATE `lines` SET `member_id` = ? WHERE `member_id` = ?;', $rReplaceWith, $rID);
	}

	return true;
}

function deleteUsers($rIDs) {
	global $db;
	$rIDs = confirmids($rIDs);

	if (0 >= count($rIDs)) {
		return false;
	}

	$db->query('DELETE FROM `users` WHERE `id` IN (' . implode(',', $rIDs) . ');');
	$db->query('DELETE FROM `users_credits_logs` WHERE `admin_id` IN (' . implode(',', $rIDs) . ');');
	$db->query('DELETE FROM `users_logs` WHERE `owner` IN (' . implode(',', $rIDs) . ');');
	$db->query('DELETE FROM `tickets_replies` WHERE `ticket_id` IN (SELECT `id` FROM `tickets` WHERE `member_id` IN (' . implode(',', $rIDs) . '));');
	$db->query('DELETE FROM `tickets` WHERE `member_id` IN (' . implode(',', $rIDs) . ');');
	$db->query('UPDATE `users` SET `owner_id` = NULL WHERE `owner_id` IN (' . implode(',', $rIDs) . ');');
	$db->query('UPDATE `lines` SET `member_id` = NULL WHERE `member_id` IN (' . implode(',', $rIDs) . ');');

	return true;
}

function deleteStream($rID, $rServerID = -1, $rDeleteFiles = true, $f2d619cb38696890 = true) {
	global $db;
	$db->query('SELECT `id`, `type` FROM `streams` WHERE `id` = ?;', $rID);

	if (0 >= $db->num_rows()) {
		return false;
	}

	$rType = $db->get_row()['type'];
	$rRemaining = 0;

	if ($rServerID == -1) {
	} else {
		$db->query('SELECT `server_stream_id` FROM `streams_servers` WHERE `stream_id` = ? AND `server_id` <> ?;', $rID, $rServerID);
		$rRemaining = $db->num_rows();
	}

	if ($rRemaining == 0 && $f2d619cb38696890) {
		$db->query('DELETE FROM `lines_logs` WHERE `stream_id` = ?;', $rID);
		$db->query('DELETE FROM `mag_claims` WHERE `stream_id` = ?;', $rID);
		$db->query('DELETE FROM `streams` WHERE `id` = ?;', $rID);
		$db->query('DELETE FROM `streams_episodes` WHERE `stream_id` = ?;', $rID);
		$db->query('DELETE FROM `streams_errors` WHERE `stream_id` = ?;', $rID);
		$db->query('DELETE FROM `streams_logs` WHERE `stream_id` = ?;', $rID);
		$db->query('DELETE FROM `streams_options` WHERE `stream_id` = ?;', $rID);
		$db->query('DELETE FROM `streams_stats` WHERE `stream_id` = ?;', $rID);
		$db->query('DELETE FROM `watch_refresh` WHERE `stream_id` = ?;', $rID);
		$db->query('DELETE FROM `watch_logs` WHERE `stream_id` = ?;', $rID);
		$db->query('DELETE FROM `recordings` WHERE `created_id` = ? OR `stream_id` = ?;', $rID, $rID);
		$db->query('UPDATE `lines_activity` SET `stream_id` = 0 WHERE `stream_id` = ?;', $rID);
		$db->query('SELECT `server_id` FROM `streams_servers` WHERE `stream_id` = ?;', $rID);
		$rServerIDs = array();

		foreach ($db->get_rows() as $rRow) {
			$rServerIDs[] = $rRow['server_id'];
		}

		if (!($rDeleteFiles && 0 < count($rServerIDs) && in_array($rType, array(2, 5)))) {
		} else {
			MovieService::deleteFile($rServerIDs, $rID);
		}

		$db->query('DELETE FROM `streams_servers` WHERE `stream_id` = ?;', $rID);
	} else {
		$rServerIDs = array($rServerID);
		$db->query('DELETE FROM `streams_servers` WHERE `stream_id` = ? AND `server_id` = ?;', $rID, $rServerID);

		if (!($rDeleteFiles && in_array($rType, array(2, 5)))) {
		} else {
			MovieService::deleteFile(array($rServerID), $rID);
		}
	}

	$db->query('DELETE FROM `streams_servers` WHERE `parent_id` IS NOT NULL AND `parent_id` > 0 AND `parent_id` NOT IN (SELECT `id` FROM `servers` WHERE `server_type` = 0);');
	StreamProcess::updateStream($rID);
	BouquetService::scan();

	return true;
}

function deleteStreams($rIDs, $rDeleteFiles = false) {
	global $db;
	$rIDs = confirmids($rIDs);


	if (0 >= count($rIDs)) {
	} else {
		$db->query('DELETE FROM `lines_logs` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `mag_claims` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams` WHERE `id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_episodes` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_errors` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_logs` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_options` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_stats` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `watch_refresh` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `watch_logs` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `lines_live` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `recordings` WHERE `created_id` IN (' . implode(',', $rIDs) . ') OR `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('UPDATE `lines_activity` SET `stream_id` = 0 WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('SELECT `server_id` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_servers` WHERE `parent_id` IS NOT NULL AND `parent_id` > 0 AND `parent_id` NOT IN (SELECT `id` FROM `servers` WHERE `server_type` = 0);');
		$db->query('INSERT INTO `signals`(`server_id`, `cache`, `time`, `custom_data`) VALUES(?, 1, ?, ?);', SERVER_ID, time(), json_encode(array('type' => 'update_streams', 'id' => $rIDs)));

		if ($rDeleteFiles) {
			foreach (array_keys(ServerRepository::getAll()) as $rServerID) {
				$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`, `cache`) VALUES(?, ?, ?, 1);', $rServerID, time(), json_encode(array('type' => 'delete_vods', 'id' => $rIDs)));
			}
		}

		BouquetService::scan();
	}

	return true;
}

function deleteStreamsByServer($rIDs, $rServerID, $rDeleteFiles = false) {
	global $db;
	$rIDs = confirmids($rIDs);

	if (0 >= count($rIDs)) {
	} else {
		$db->query('DELETE FROM `streams_servers` WHERE `server_id` = ? AND `stream_id` IN (' . implode(',', $rIDs) . ');', $rServerID);
		$db->query('UPDATE `streams_servers` SET `parent_id` = NULL WHERE `parent_id` = ? AND `stream_id` IN (' . implode(',', $rIDs) . ');', $rServerID);

		if ($rDeleteFiles) {
			$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`, `cache`) VALUES(?, ?, ?, 1);', $rServerID, time(), json_encode(array('type' => 'delete_vods', 'id' => $rIDs)));
		}

		// The panel will rescan itself within a minute.
		// scanBouquets();
	}

	return true;
}

function flushIPs() {
	global $db;
	global $rServers;
	global $rProxyServers;
	$db->query('TRUNCATE `blocked_ips`;');
	shell_exec('rm ' . FLOOD_TMP_PATH . 'block_*');

	foreach ($rServers as $rServer) {
		$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServer['id'], time(), json_encode(array('action' => 'flush')));
	}

	foreach ($rProxyServers as $rServer) {
		$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServer['id'], time(), json_encode(array('action' => 'flush')));
	}

	return true;
}

function addTMDbCategories() {
	global $db;
	require_once MAIN_HOME . 'includes/libs/tmdb.php';

	if (0 < strlen(SettingsManager::getAll()['tmdb_language'])) {
		$rTMDB = new TMDB(SettingsManager::getAll()['tmdb_api_key'], SettingsManager::getAll()['tmdb_language']);
	} else {
		$rTMDB = new TMDB(SettingsManager::getAll()['tmdb_api_key']);
	}

	$rCurrentCats = array('movie' => array(), 'series' => array());

	$db->query('SELECT `id`, `category_type`, `category_name` FROM `streams_categories`;');

	if ($db->num_rows() > 0) {
		foreach ($db->get_rows() as $rRow) {
			if (array_key_exists($rRow['category_type'], $rCurrentCats)) {
				$rCurrentCats[$rRow['category_type']][] = $rRow['category_name'];
			}
		}
	}

	$rMovieGenres = $rTMDB->getMovieGenres();
	foreach ($rMovieGenres as $rMovieGenre) {
		$movieGenreName = $rMovieGenre->getName();
		if (!in_array($movieGenreName, $rCurrentCats['movie'])) {
			$db->query("INSERT INTO `streams_categories`(`category_type`, `category_name`) VALUES('movie', ?);", $movieGenreName);
		}
	}

	$rTVGenres = $rTMDB->getTVGenres();
	foreach ($rTVGenres as $rTVGenre) {
		$seriesGenreName = $rTVGenre->getName();
		if (!in_array($seriesGenreName, $rCurrentCats['series'])) {
			$db->query("INSERT INTO `streams_categories`(`category_type`, `category_name`) VALUES('series', ?);", $seriesGenreName);
		}
	}

	return true;
}

function updateTMDbCategories() {
	global $db;
	require_once MAIN_HOME . 'includes/libs/tmdb.php';

	if (0 < strlen(SettingsManager::getAll()['tmdb_language'])) {
		$rTMDB = new TMDB(SettingsManager::getAll()['tmdb_api_key'], SettingsManager::getAll()['tmdb_language']);
	} else {
		$rTMDB = new TMDB(SettingsManager::getAll()['tmdb_api_key']);
	}

	$rCurrentCats = array(1 => array(), 2 => array());
	$db->query('SELECT `id`, `type`, `genre_id` FROM `watch_categories`;');

	if ($db->num_rows() > 0) {
		foreach ($db->get_rows() as $rRow) {
			if (array_key_exists($rRow['type'], $rCurrentCats)) {

				if (in_array($rRow['genre_id'], $rCurrentCats[$rRow['type']])) {
					$db->query('DELETE FROM `watch_categories` WHERE `id` = ?;', $rRow['id']);
				}
				$rCurrentCats[$rRow['type']][] = $rRow['genre_id'];
			}
		}
	}

	$rMovieGenres = $rTMDB->getMovieGenres();

	foreach ($rMovieGenres as $rMovieGenre) {
		if (!in_array($rMovieGenre->getID(), $rCurrentCats[1])) {
			$db->query("INSERT INTO `watch_categories`(`type`, `genre_id`, `genre`, `category_id`, `bouquets`) VALUES(1, ?, ?, 0, '[]');", $rMovieGenre->getID(), $rMovieGenre->getName());
		}

		if (!in_array($rMovieGenre->getID(), $rCurrentCats[2])) {
			$db->query("INSERT INTO `watch_categories`(`type`, `genre_id`, `genre`, `category_id`, `bouquets`) VALUES(2, ?, ?, 0, '[]');", $rMovieGenre->getID(), $rMovieGenre->getName());
		}
	}

	$rTVGenres = $rTMDB->getTVGenres();

	foreach ($rTVGenres as $rTVGenre) {
		if (!in_array($rTVGenre->getID(), $rCurrentCats[1])) {
			$db->query("INSERT INTO `watch_categories`(`type`, `genre_id`, `genre`, `category_id`, `bouquets`) VALUES(1, ?, ?, 0, '[]');", $rTVGenre->getID(), $rTVGenre->getName());
		}

		if (!in_array($rTVGenre->getID(), $rCurrentCats[2])) {
			$db->query("INSERT INTO `watch_categories`(`type`, `genre_id`, `genre`, `category_id`, `bouquets`) VALUES(2, ?, ?, 0, '[]');", $rTVGenre->getID(), $rTVGenre->getName());
		}
	}
}

function goHome() {
	header('Location: dashboard');

	exit();
}

function checkResellerPermissions($rPage = null) {
	global $rPermissions;

	if ($rPage) {
	} else {
		$rPage = strtolower(basename($_SERVER['SCRIPT_FILENAME'], '.php'));
	}

	switch ($rPage) {
		case 'user':
		case 'users':
			return $rPermissions['create_sub_resellers'];

		case 'line':
		case 'lines':
			return $rPermissions['create_line'];

		case 'mag':
		case 'mags':
			return $rPermissions['create_mag'];

		case 'enigma':
		case 'enigmas':
			return $rPermissions['create_enigma'];

		case 'epg_view':
		case 'streams':
		case 'created_channels':
		case 'movies':
		case 'episodes':
		case 'radios':
			return $rPermissions['can_view_vod'];

		case 'live_connections':
		case 'line_activity':
			return $rPermissions['reseller_client_connection_logs'];
	}

	return true;
}

function checkPermissions($rPage = null) {
	if ($rPage) {
	} else {
		$rPage = strtolower(basename($_SERVER['SCRIPT_FILENAME'], '.php'));
	}

	switch ($rPage) {
		case 'isps':
		case 'isp':
		case 'asns':
			return Authorization::check('adv', 'block_isps');

		case 'bouquet':
			if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_bouquet')) {
				return true;
			}

			if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_bouquet')) {
			} else {
				return true;
			}

			// no break
		case 'bouquet_order':
		case 'bouquet_sort':
			return Authorization::check('adv', 'edit_bouquet');

		case 'bouquets':
			return Authorization::check('adv', 'bouquets');

		case 'channel_order':
			return Authorization::check('adv', 'channel_order');

		case 'client_logs':
			return Authorization::check('adv', 'client_request_log');

		case 'created_channel':
			if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_cchannel')) {
				return true;
			}

			if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'create_channel')) {
			} else {
				return true;
			}

			// no break
		case 'code':
		case 'codes':
			return Authorization::check('adv', 'add_code');

		case 'hmac':
		case 'hmacs':
			return Authorization::check('adv', 'add_hmac');

		case 'credit_logs':
			return Authorization::check('adv', 'credits_log');

		case 'enigmas':
			return Authorization::check('adv', 'manage_e2');

		case 'epg':
			if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'epg_edit')) {
				return true;
			}

			if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_epg')) {
			} else {
				return true;
			}

			// no break
		case 'epgs':
			return Authorization::check('adv', 'epg');

		case 'episode':
			if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_episode')) {
				return true;
			}

			if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_episode')) {
			} else {
				return true;
			}

			// no break
		case 'episodes':
			return Authorization::check('adv', 'episodes');

		case 'series_mass':
		case 'episodes_mass':
			return Authorization::check('adv', 'mass_sedits');

		case 'fingerprint':
			return Authorization::check('adv', 'fingerprint');

		case 'group':
			if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_group')) {
				return true;
			}

			if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_group')) {
			} else {
				return true;
			}

			// no break
		case 'groups':
			return Authorization::check('adv', 'mng_groups');

		case 'ip':
		case 'ips':
			return Authorization::check('adv', 'block_ips');

		case 'live_connections':
			return Authorization::check('adv', 'live_connections');

		case 'mag':
			if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_mag')) {
				return true;
			}
			if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_mag')) {
				break;
			}
			return true;
		case 'mag_events':
			return Authorization::check('adv', 'manage_events');
		case 'mags':
			return Authorization::check('adv', 'manage_mag');

		case 'mass_delete':
			return Authorization::check('adv', 'mass_delete');

		case 'record':
			return Authorization::check('adv', 'add_movie');
		case 'recordings':
			return Authorization::check('adv', 'movies');
		case 'queue':
			return Authorization::check('adv', 'streams') || Authorization::check('adv', 'episodes') || Authorization::check('adv', 'series');
		case 'movie':
			if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_movie')) {
				return true;
			}
			if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_movie')) {
			} else {
				if (isset(RequestManager::getAll()['import']) && !Authorization::check('adv', 'import_movies')) {
				} else {
					return true;
				}
			}
			break;
		case 'movie_mass':
			return Authorization::check('adv', 'mass_sedits_vod');
		case 'movies':
			return Authorization::check('adv', 'movies');
		case 'package':
			if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_package')) {
				return true;
			}

			if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_packages')) {
				break;
			}
			return true;
		case 'packages':
		case 'addons':
			return Authorization::check('adv', 'mng_packages');

		case 'player':
			return Authorization::check('adv', 'player');

		case 'process_monitor':
			return Authorization::check('adv', 'process_monitor');

		case 'profile':
			return Authorization::check('adv', 'tprofile');

		case 'profiles':
			return Authorization::check('adv', 'tprofiles');

		case 'radio':
			if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_radio')) {
				return true;
			}
			if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_radio')) {
				break;
			}
			return true;
		case 'radio_mass':
			return Authorization::check('adv', 'mass_edit_radio');
		case 'radios':
			return Authorization::check('adv', 'radio');
		case 'user':
			if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_reguser')) {
				return true;
			}

			if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_reguser')) {
				break;
			}
			return true;
		case 'user_logs':
			return Authorization::check('adv', 'reg_userlog');
		case 'users':
			return Authorization::check('adv', 'mng_regusers');
		case 'rtmp_ip':
			return Authorization::check('adv', 'add_rtmp');
		case 'rtmp_ips':
		case 'rtmp_monitor':
			return Authorization::check('adv', 'rtmp');
		case 'serie':
			if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_series')) {
				return true;
			}

			if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_series')) {
				break;
			}
			return true;
		case 'series':
			return Authorization::check('adv', 'series');
		case 'series_order':
			return Authorization::check('adv', 'edit_series');
		case 'server':
		case 'proxy':
			if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_server')) {
				return true;
			}
			if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_server')) {
				break;
			}
			return true;
		case 'server_install':
			return Authorization::check('adv', 'add_server');
		case 'servers':
		case 'server_view':
		case 'server_order':
		case 'proxies':
			return Authorization::check('adv', 'servers');

		case 'settings':
			return Authorization::check('adv', 'settings');

		case 'backups':
		case 'cache':
		case 'setup':
			return Authorization::check('adv', 'database');

		case 'settings_watch':
		case 'settings_plex':
			return Authorization::check('adv', 'folder_watch_settings');

		case 'stream':
			if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_stream')) {
				return true;
			}

			if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_stream')) {
			} else {
				if (isset(RequestManager::getAll()['import']) && !Authorization::check('adv', 'import_streams')) {
				} else {
					return true;
				}
			}

			break;

		case 'review':
			return Authorization::check('adv', 'import_streams');

		case 'mass_edit_streams':
			return Authorization::check('adv', 'edit_stream');

		case 'stream_categories':
			return Authorization::check('adv', 'categories');

		case 'stream_category':
			return Authorization::check('adv', 'add_cat');

		case 'stream_errors':
			return Authorization::check('adv', 'stream_errors');

		case 'created_channel_mass':
		case 'stream_mass':
			return Authorization::check('adv', 'mass_edit_streams');

		case 'user_mass':
			return Authorization::check('adv', 'mass_edit_users');

		case 'mag_mass':
			return Authorization::check('adv', 'mass_edit_mags');



		case 'enigma_mass':
			return Authorization::check('adv', 'mass_edit_enigmas');


		case 'quick_tools':
			return Authorization::check('adv', 'quick_tools');



		case 'stream_tools':
			return Authorization::check('adv', 'stream_tools');






		case 'stream_view':
		case 'provider':
		case 'providers':
		case 'streams':
		case 'epg_view':
		case 'created_channels':
		case 'stream_rank':
		case 'archive':
			return Authorization::check('adv', 'streams');

		case 'ticket':
			return Authorization::check('adv', 'ticket');

		case 'ticket_view':
		case 'tickets':
			return Authorization::check('adv', 'manage_tickets');

		case 'line':
			if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_user')) {
				return true;
			}

			if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_user')) {
				break;
			}



			return true;


		case 'line_activity':
		case 'theft_detection':
		case 'line_ips':
			return Authorization::check('adv', 'connection_logs');

		case 'line_mass':
			return Authorization::check('adv', 'mass_edit_lines');


		case 'useragents':
		case 'useragent':
			return Authorization::check('adv', 'block_uas');











		case 'lines':
			return Authorization::check('adv', 'users');


		case 'plex':
		case 'watch':
			return Authorization::check('adv', 'folder_watch');



		case 'plex_add':
		case 'watch_add':
			return Authorization::check('adv', 'folder_watch_add');

		case 'watch_output':
			return Authorization::check('adv', 'folder_watch_output');



		case 'mysql_syslog':
		case 'panel_logs':
			return Authorization::check('adv', 'panel_logs');







		case 'login_logs':
			return Authorization::check('adv', 'login_logs');

		case 'restream_logs':
			return Authorization::check('adv', 'restream_logs');




		default:
			return true;
	}
}

function getPackages($rGroup = null, $rType = null) {
	global $db;
	$rReturn = array();
	$db->query('SELECT * FROM `users_packages` ORDER BY `id` ASC;');

	if (0 >= $db->num_rows()) {
	} else {
		foreach ($db->get_rows() as $rRow) {
			if (isset($rGroup) && !in_array(intval($rGroup), json_decode($rRow['groups'], true))) {
			} else {
				if ($rType && !$rRow['is_' . $rType]) {
				} else {
					$rReturn[intval($rRow['id'])] = $rRow;
				}
			}
		}
	}

	return $rReturn;
}





function checkCompatible($rIDA, $rIDB) {
	$rPackageA = getPackage($rIDA);
	$rPackageB = getPackage($rIDB);
	$rCompatible = true;

	if (!($rPackageA && $rPackageB)) {
	} else {
		foreach (array('bouquets', 'output_formats') as $rKey) {
			if (json_decode($rPackageA[$rKey], true) == json_decode($rPackageB[$rKey], true)) {
			} else {
				$rCompatible = false;
			}
		}

		foreach (array('is_restreamer', 'is_isplock', 'max_connections', 'force_server_id', 'forced_country', 'lock_device') as $rKey) {
			if ($rPackageA[$rKey] == $rPackageB[$rKey]) {
			} else {
				$rCompatible = false;
			}
		}
	}

	return $rCompatible;
}

function getPackage($rID) {
	global $db;
	$db->query('SELECT * FROM `users_packages` WHERE `id` = ?;', $rID);

	if ($db->num_rows() != 1) {
	} else {
		return $db->get_row();
	}
}

function getcodes($rType = null) {
	global $db;
	$rReturn = array();

	if (!is_null($rType)) {
		$db->query('SELECT * FROM `access_codes` WHERE `type` = ? ORDER BY `id` ASC;', $rType);
	} else {
		$db->query('SELECT * FROM `access_codes` ORDER BY `id` ASC;');
	}

	if (0 >= $db->num_rows()) {
	} else {
		foreach ($db->get_rows() as $rRow) {
			$rReturn[intval($rRow['id'])] = $rRow;
		}
	}

	return $rReturn;
}

function getCode($rID) {
	global $db;
	$db->query('SELECT * FROM `access_codes` WHERE `id` = ?;', $rID);

	if ($db->num_rows() != 1) {
	} else {
		return $db->get_row();
	}
}

function getBarColour($rInt) {
	if (75 <= $rInt) {
		return 'bg-danger';
	}

	if (50 <= $rInt) {
		return 'bg-warning';
	}

	return 'bg-success';
}

function getNVENCProcesses($rServerID) {
	global $db;
	$rProcesses = array();
	$rServer = getStreamingServersByID($rServerID);
	$rGPUInfo = json_decode($rServer['gpu_info'], true);

	if (!is_array($rGPUInfo)) {
	} else {
		foreach ($rGPUInfo['gpus'] as $rGPU) {
			foreach ($rGPU['processes'] as $rProcess) {
				$rArray = array('pid' => $rProcess['pid'], 'memory' => $rProcess['memory'], 'stream_id' => null);
				$db->query('SELECT `stream_id` FROM `streams_servers` WHERE `pid` = ? AND `server_id` = ?;', $rProcess['pid'], $rServerID);

				if (0 >= $db->num_rows()) {
				} else {
					$rArray['stream_id'] = $db->get_row()['stream_id'];
				}

				$rProcesses[] = $rArray;
			}
		}
	}

	return $rProcesses;
}

function deleteLine($rID, $rDeletePaired = false, $rCloseCons = true) {
	global $db;
	$rLine = UserRepository::getLineById($rID);

	if (!$rLine) {
		return false;
	}


	LineService::deleteLineSignal($rID);
	$db->query('DELETE FROM `lines` WHERE `id` = ?;', $rID);
	$db->query('DELETE FROM `lines_logs` WHERE `user_id` = ?;', $rID);
	$db->query('UPDATE `lines_activity` SET `user_id` = 0 WHERE `user_id` = ?;', $rID);

	if (!$rCloseCons) {
	} else {
		if (SettingsManager::getAll()['redis_handler']) {
			foreach (ConnectionTracker::getRedisConnections($rID, null, null, true, false, false) as $rConnection) {
				ConnectionTracker::closeConnection($rConnection);
			}
		} else {
			$db->query('SELECT * FROM `lines_live` WHERE `user_id` = ?;', $rID);

			foreach ($db->get_rows() as $rRow) {
				ConnectionTracker::closeConnection($rRow);
			}
		}
	}

	$db->query('SELECT `id` FROM `lines` WHERE `pair_id` = ?;', $rID);

	foreach ($db->get_rows() as $rRow) {
		if ($rDeletePaired) {
			deleteLine($rRow['id'], true, $rCloseCons);
		} else {
			$db->query('UPDATE `lines` SET `pair_id` = null WHERE `id` = ?;', $rRow['id']);
			LineService::updateLineSignal($rRow['id']);
		}
	}

	return true;
}

function deleteRTMPIP($rID) {
	global $db;
	$db->query('SELECT `id` FROM `rtmp_ips` WHERE `id` = ?;', $rID);

	if (0 >= $db->num_rows()) {
		return false;
	}

	$db->query('DELETE FROM `rtmp_ips` WHERE `id` = ?;', $rID);

	return true;
}

function deleteWatchFolder($rID) {
	global $db;
	$db->query('SELECT `id` FROM `watch_folders` WHERE `id` = ?;', $rID);

	if (0 >= $db->num_rows()) {
		return false;
	}



	$db->query('DELETE FROM `watch_folders` WHERE `id` = ?;', $rID);





	return true;
}

function deleteTicket($rID) {
	global $db;
	$db->query('SELECT `id` FROM `tickets` WHERE `id` = ?;', $rID);

	if (0 >= $db->num_rows()) {
		return false;
	}

	$db->query('DELETE FROM `tickets` WHERE `id` = ?;', $rID);
	$db->query('DELETE FROM `tickets_replies` WHERE `ticket_id` = ?;', $rID);

	return true;
}

function canGenerateTrials($rUserID) {
	global $db;
	global $rSettings;
	$rUser = UserRepository::getRegisteredUserById($rUserID);
	$rPermissions = getPermissions($rUser['member_group_id']);

	if ($rSettings['disable_trial']) {
		return false;
	}

	if (floatval($rUser['credits']) < floatval($rPermissions['minimum_trial_credits'])) {
		return false;
	}

	$rTotal = $rPermissions['total_allowed_gen_trials'];





	if (0 >= $rTotal) {
		return false;
	}









	$rTotalIn = $rPermissions['total_allowed_gen_in'];





	if ($rTotalIn == 'hours') {
		$rTime = time() - intval($rTotal) * 3600;
	} else {
		$rTime = time() - intval($rTotal) * 3600 * 24;
	}

	$db->query('SELECT COUNT(`id`) AS `count` FROM `lines` WHERE `member_id` = ? AND `created_at` >= ? AND `is_trial` = 1;', $rUser['id'], $rTime);

	return $db->get_row()['count'] < $rTotal;
}

function getGroupPermissions($rUserID, $rStreams = true, $rUsers = true) {
	global $db;
	$rStart = round(microtime(true) * 1000);
	$rReturn = array('create_line' => false, 'create_mag' => false, 'create_enigma' => false, 'stream_ids' => array(), 'series_ids' => array(), 'category_ids' => array(), 'users' => array(), 'direct_reports' => array(), 'all_reports' => array(), 'report_map' => array());
	$rUser = UserRepository::getRegisteredUserById($rUserID);

	if (!$rUser) {
	} else {
		if (!file_exists(CACHE_TMP_PATH . 'permissions_' . intval($rUser['member_group_id']))) {
		} else {
			$rReturn = array_merge($rReturn, igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'permissions_' . intval($rUser['member_group_id']))));
		}

		$db->query("SELECT * FROM `users_packages` WHERE JSON_CONTAINS(`groups`, ?, '\$');", $rUser['member_group_id']);



		foreach ($db->get_rows() as $rRow) {
			if (!$rRow['is_line']) {
			} else {
				$rReturn['create_line'] = true;
			}

			if (!$rRow['is_mag']) {
			} else {
				$rReturn['create_mag'] = true;
			}

			if (!$rRow['is_e2']) {
			} else {
				$rReturn['create_enigma'] = true;
			}
		}

		if (!$rUsers) {
		} else {
			$rReturn['users'] = UserRepository::getSubUsers($rUser['id']);

			foreach ($rReturn['users'] as $rUserID => $rUserData) {
				if ($rUser['id'] != $rUserData['parent']) {
				} else {
					$rReturn['direct_reports'][] = $rUserID;
				}

				$rReturn['all_reports'][] = $rUserID;
			}
		}
	}

	return $rReturn;
}

function grantPrivilegesToAllServers() {
	global $rServers;

	foreach ($rServers as $rServerID => $rServerArray) {
		BackupService::grantPrivileges($rServerArray['server_ip'], DatabaseFactory::get(), ConfigReader::getAll());
	}
}

function getTranscodeProfile($rID) {
	global $db;
	$db->query('SELECT * FROM `profiles` WHERE `profile_id` = ?;', $rID);

	if ($db->num_rows() != 1) {
	} else {
		return $db->get_row();
	}
}

function getUserAgent($rID) {
	global $db;
	$db->query('SELECT * FROM `blocked_uas` WHERE `id` = ?;', $rID);

	if ($db->num_rows() != 1) {
	} else {
		return $db->get_row();
	}
}

function getCategory($rID) {
	global $db;
	$db->query('SELECT * FROM `streams_categories` WHERE `id` = ?;', $rID);

	if ($db->num_rows() != 1) {
		return false;
	}

	return $db->get_row();
}

function getMag($rID) {
	global $db;
	$db->query('SELECT * FROM `mag_devices` WHERE `mag_id` = ?;', $rID);

	if ($db->num_rows() != 1) {
		return array();
	}

	$rRow = $db->get_row();
	$rRow['user'] = UserRepository::getLineById($rRow['user_id']);
	$db->query('SELECT `pair_id` FROM `lines` WHERE `id` = ?;', $rRow['user_id']);

	if ($db->num_rows() != 1) {
	} else {
		$rRow['paired'] = UserRepository::getLineById($rRow['user']['pair_id']);
	}



	return $rRow;
}

function getEnigma($rID) {
	global $db;
	$db->query('SELECT * FROM `enigma2_devices` WHERE `device_id` = ?;', $rID);

	if ($db->num_rows() != 1) {
		return array();
	}

	$rRow = $db->get_row();
	$rRow['user'] = UserRepository::getLineById($rRow['user_id']);

	$db->query('SELECT `pair_id` FROM `lines` WHERE `id` = ?;', $rRow['user_id']);

	if ($db->num_rows() != 1) {
	} else {
		$rRow['paired'] = UserRepository::getLineById($rRow['user']['pair_id']);
	}

	return $rRow;
}

function getE2User($rID) {
	global $db;
	$db->query('SELECT * FROM `enigma2_devices` WHERE `user_id` = ?;', $rID);

	if ($db->num_rows() != 1) {
		return '';
	}

	return $db->get_row();
}

function getTicket($rID) {
	global $db;
	$db->query('SELECT * FROM `tickets` WHERE `id` = ?;', $rID);

	if (0 >= $db->num_rows()) {
	} else {
		$rRow = $db->get_row();
		$rRow['replies'] = array();
		$rRow['title'] = htmlspecialchars($rRow['title']);
		$db->query('SELECT * FROM `tickets_replies` WHERE `ticket_id` = ? ORDER BY `date` ASC;', $rID);

		foreach ($db->get_rows() as $rReply) {
			$rReply['message'] = htmlspecialchars($rReply['message']);

			if (strlen($rReply['message']) >= 80) {
			} else {
				$rReply['message'] .= str_repeat('&nbsp; ', 80 - strlen($rReply['message']));
			}

			$rRow['replies'][] = $rReply;
		}
		$rRow['user'] = UserRepository::getRegisteredUserById($rRow['member_id']);
		return $rRow;
	}
}

function getISP($rID) {
	global $db;
	$db->query('SELECT * FROM `blocked_isps` WHERE `id` = ?;', $rID);

	if ($db->num_rows() != 1) {
	} else {
		return $db->get_row();
	}
}

function getRTMPIP($rID) {
	global $db;
	$db->query('SELECT * FROM `rtmp_ips` WHERE `id` = ?;', $rID);

	if ($db->num_rows() != 1) {
	} else {
		return $db->get_row();
	}
}

function getEPGs() {
	global $db;
	$rReturn = array();
	$db->query('SELECT * FROM `epg` ORDER BY `id` ASC;');

	if ($db->num_rows() > 0) {
		foreach ($db->get_rows() as $rRow) {
			$rReturn[intval($rRow['id'])] = $rRow;
		}
	}

	return $rReturn;
}

function getChannels($rType = 'live') {
	global $db;
	$rReturn = array();
	$db->query('SELECT * FROM `streams_categories` WHERE `category_type` = ? ORDER BY `cat_order` ASC;', $rType);

	if (0 >= $db->num_rows()) {
	} else {

		foreach ($db->get_rows() as $rRow) {
			$rReturn[intval($rRow['id'])] = $rRow;
		}
	}

	return $rReturn;
}

function getChannelsByID($rID) {
	global $db;
	$db->query('SELECT * FROM `streams` WHERE `id` = ?;', $rID);

	if ($db->num_rows() != 1) {
		return false;
	}

	return $db->get_row();
}

function getStreamingServersByID($rID) {
	global $db;
	$db->query('SELECT * FROM `servers` WHERE `id` = ?;', $rID);

	if ($db->num_rows() != 1) {
		return false;
	}

	return $db->get_row();
}

function getLiveConnections($rServerID, $rProxy = false) {
	global $db;

	if (SettingsManager::getAll()['redis_handler']) {
		$rCount = 0;


		if ($rProxy) {
			$rParentIDs = ServerRepository::getAll()[$rServerID]['parent_id'];

			foreach ($rParentIDs as $rParentID) {
				foreach (ConnectionTracker::getRedisConnections(null, $rParentID, null, true, false, false) as $rConnection) {
					if ($rConnection['proxy_id'] != $rServerID) {
					} else {
						$rCount++;
					}
				}
			}
		} else {
			list($rCount) = ConnectionTracker::getRedisConnections(null, $rServerID, null, true, true, false);
		}

		return $rCount;
	} else {
		if ($rProxy) {
			$db->query('SELECT COUNT(*) AS `count` FROM `lines_live` WHERE `proxy_id` = ? AND `hls_end` = 0;', $rServerID);
		} else {
			$db->query('SELECT COUNT(*) AS `count` FROM `lines_live` WHERE `server_id` = ? AND `hls_end` = 0;', $rServerID);
		}

		return $db->get_row()['count'];
	}
}

function getEPGSources() {
	global $db;
	$rReturn = array();
	$db->query('SELECT * FROM `epg`;');

	if (0 >= $db->num_rows()) {
	} else {

		foreach ($db->get_rows() as $rRow) {
			$rReturn[$rRow['id']] = $rRow;
		}
	}

	return $rReturn;
}

function deleteProvider($rID) {
	global $db;
	$rProvider = getstreamprovider($rID);

	if (!$rProvider) {
		return false;
	}

	$db->query('DELETE FROM `providers` WHERE `id` = ?;', $rID);
	$db->query('DELETE FROM `providers_streams` WHERE `provider_id` = ?;', $rID);

	return true;
}

function deleteEPG($rID) {
	global $db;
	$rEPG = EpgService::getById($rID);

	if (!$rEPG) {
		return false;
	}

	$db->query('DELETE FROM `epg` WHERE `id` = ?;', $rID);
	$db->query('DELETE FROM `epg_channels` WHERE `epg_id` = ?;', $rID);
	$db->query('UPDATE `streams` SET `epg_id` = null, `channel_id` = null, `epg_lang` = null WHERE `epg_id` = ?;', $rID);

	return true;
}

function deleteServer($rID, $rReplaceWith = null) {
	global $db;
	return ServerRepository::deleteById($rID, $rReplaceWith);
}

function getEncodeErrors($rID) {
	global $db;
	$rErrors = array();
	$db->query('SELECT `server_id`, `error` FROM `streams_errors` WHERE `stream_id` = ?;', $rID);

	foreach ($db->get_rows() as $rRow) {
		$rErrors[intval($rRow['server_id'])] = $rRow['error'];
	}

	return $rErrors;
}

function issecure() {
	$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
	$port443 = isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443;

	return $https || $port443;
}

function getProtocol() {
	if (issecure()) {
		return 'https';
	}

	return 'http';
}

function generateString($strength = 10) {
	$input = '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ';
	$input_length = strlen($input);
	$random_string = '';

	for ($i = 0; $i < $strength; $i++) {
		$random_character = $input[mt_rand(0, $input_length - 1)];
		$random_string .= $random_character;
	}

	return $random_string;
}

function roundUpToAny($n, $x = 5) {
	return round(($n + $x / 2) / $x) * $x;
}

function getPageName() {
	if (defined('PAGE_NAME') && PAGE_NAME) {
		return strtolower(PAGE_NAME);
	}

	return strtolower(basename(get_included_files()[0], '.php'));
}

function sortArrayByArray($rArray, $rSort) {
	if (!(empty($rArray) || empty($rSort))) {
		$rOrdered = array();

		foreach ($rSort as $rValue) {
			if (($rKey = array_search($rValue, $rArray)) === false) {
			} else {
				$rOrdered[] = $rValue;
				unset($rArray[$rKey]);
			}
		}

		return $rOrdered + $rArray;
	} else {
		return array();
	}
}

function cleanValue($rValue) {
	if ($rValue != '') {
		$rValue = str_replace('&#032;', ' ', stripslashes($rValue));
		$rValue = str_replace(array("\r\n", "\n\r", "\r"), "\n", $rValue);
		$rValue = str_replace('<!--', '&#60;&#33;--', $rValue);
		$rValue = str_replace('-->', '--&#62;', $rValue);
		$rValue = str_ireplace('<script', '&#60;script', $rValue);
		$rValue = preg_replace('/&amp;#([0-9]+);/s', '&#\\1;', $rValue);
		$rValue = preg_replace('/&#(\\d+?)([^\\d;])/i', '&#\\1;\\2', $rValue);

		return trim($rValue);
	}

	return '';
}

function generateReport($rURL, $rParams) {
	$rPost = http_build_query($rParams);
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $rURL);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $rPost);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
	curl_setopt($ch, CURLOPT_TIMEOUT, 300);

	return curl_exec($ch);
}

function convertToCSV($rData) {
	$rHeader = false;
	$rFilename = TMP_PATH . generateString(32) . '.csv';
	$rFile = fopen($rFilename, 'w');

	foreach ($rData as $rRow) {
		if (!empty($rHeader)) {
		} else {
			$rHeader = array_keys($rRow);
			fputcsv($rFile, $rHeader);
			$rHeader = array_flip($rHeader);
		}

		fputcsv($rFile, array_merge($rHeader, $rRow));
	}
	fclose($rFile);


	return $rFilename;
}

function getFooter() {
	$currentYear = date('Y');
	$startYear = 2025;
	$yearRange = ($startYear === (int)$currentYear) ? $startYear : "{$startYear}-{$currentYear}";
	
	return "&copy; {$yearRange} <img height='20px' style='padding-left: 10px; padding-right: 10px; margin-top: -2px;' src='./assets/images/logo-topbar.png' /> v" . XC_VM_VERSION;
}

/**
 * Filter and validate array of IDs
 * 
 * @param array $ids Array of IDs to filter
 * @param array $availableIDs Array of valid available IDs
 * @param bool $checkPositive Whether to check for positive integers
 * @return array Filtered array of valid IDs
 */
function filterIDs($ids, $availableIDs, $checkPositive = true) {
	$filtered = [];

	if (!is_array($ids)) {
		return $filtered;
	}

	foreach ($ids as $id) {
		$intID = (int)$id;
		$isValid = (!$checkPositive || $intID > 0) && in_array($intID, $availableIDs);

		if ($isValid) {
			$filtered[] = $intID;
		}
	}

	return $filtered;
}

function shutdown_admin() {
	global $db;

	if (!is_object($db)) {
	} else {
		$db->close_mysql();
	}
}

/**
 * Retrieves a list of available time zones with their UTC offsets.
 *
 * This function generates an array of time zones, each containing the time zone
 * identifier and its current UTC offset based on the current timestamp. It includes
 * error handling to ensure safe execution and restores the original timezone after
 * processing.
 *
 * @return array An array of associative arrays containing:
 *               - 'zone': The time zone identifier (e.g., 'America/New_York')
 *               - 'diff_from_GMT': The UTC offset (e.g., 'UTC/GMT +00:00')
 * @throws RuntimeException If setting a timezone fails or if timezone_identifiers_list() is unavailable.
 */
function TimeZoneList(): array {
	// Check if timezone_identifiers_list is available
	if (!function_exists('timezone_identifiers_list')) {
		throw new RuntimeException('Timezone identifiers list function is not available.');
	}

	$zones_array = [];
	$timestamp = time();
	$original_timezone = date_default_timezone_get(); // Store original timezone

	try {
		foreach (timezone_identifiers_list() as $key => $zone) {
			// Validate timezone identifier
			if (empty($zone) || !is_string($zone)) {
				continue; // Skip invalid timezone identifiers
			}

			// Attempt to set the timezone
			if (date_default_timezone_set($zone) === false) {
				continue; // Skip if timezone setting fails
			}

			// Store timezone data
			$zones_array[$key] = [
				'zone' => $zone,
				'diff_from_GMT' => '[UTC/GMT ' . date('P', $timestamp) . ']'
			];
		}
	} catch (Exception $e) {
		// Restore original timezone before throwing exception
		date_default_timezone_set($original_timezone);
		throw new RuntimeException('Error processing timezone list: ' . $e->getMessage());
	}

	// Restore original timezone
	date_default_timezone_set($original_timezone);

	return $zones_array;
}
