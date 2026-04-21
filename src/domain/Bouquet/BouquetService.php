<?php

/**
 * BouquetService — bouquet service
 *
 * @package XC_VM_Domain_Bouquet
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class BouquetService {
	public static function process($rData) {
		global $db;
		if (isset($rData['edit'])) {
			if (!Authorization::check('adv', 'edit_bouquet')) {
				exit();
			}

			$rArray = AdminHelpers::overwriteData(BouquetService::getById($rData['edit']), $rData);
		} else {
			if (!Authorization::check('adv', 'add_bouquet')) {
				exit();
			}

			$rArray = QueryHelper::verifyPostTable('bouquets', $rData);
			unset($rArray['id']);
		}

		if (is_array(json_decode($rData['bouquet_data'], true))) {
			$rBouquetData = json_decode($rData['bouquet_data'], true);
			$rBouquetStreams = $rBouquetData['stream'];
			$rBouquetMovies = $rBouquetData['movies'];
			$rBouquetRadios = $rBouquetData['radios'];
			$rBouquetSeries = $rBouquetData['series'];
			$rRequiredIDs = AdminHelpers::confirmIDs(array_merge($rBouquetStreams, $rBouquetMovies, $rBouquetRadios));
			$rStreams = array();

			if (count($rRequiredIDs) > 0) {
				$db->query('SELECT `id`, `type` FROM `streams` WHERE `id` IN (' . implode(',', $rRequiredIDs) . ');');

				foreach ($db->get_rows() as $rRow) {
					if (intval($rRow['type']) == 3) {
						$rRow['type'] = 1;
					}

					$rStreams[intval($rRow['type'])][] = intval($rRow['id']);
				}
			}

			if (count($rBouquetSeries) > 0) {
				$db->query('SELECT `id` FROM `streams_series` WHERE `id` IN (' . implode(',', $rBouquetSeries) . ');');

				foreach ($db->get_rows() as $rRow) {
					$rStreams[5][] = intval($rRow['id']);
				}
			}

			$rArray['bouquet_channels'] = array_intersect(array_map('intval', array_values($rBouquetStreams)), $rStreams[1] ?? []);
			$rArray['bouquet_movies'] = array_intersect(array_map('intval', array_values($rBouquetMovies)), $rStreams[2] ?? []);
			$rArray['bouquet_radios'] = array_intersect(array_map('intval', array_values($rBouquetRadios)), $rStreams[4] ?? []);
			$rArray['bouquet_series'] = array_intersect(array_map('intval', array_values($rBouquetSeries)), $rStreams[5] ?? []);
		} else if (isset($rData['edit'])) {
			return array('status' => STATUS_FAILURE, 'data' => $rData);
		}

		if (!isset($rData['edit'])) {
			$db->query('SELECT MAX(`bouquet_order`) AS `max` FROM `bouquets`;');
			$rArray['bouquet_order'] = intval($db->get_row()['max']) + 1;
		}

		$rPrepare = QueryHelper::prepareArray($rArray);
		$rQuery = 'REPLACE INTO `bouquets`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			self::scan();

			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
		}

		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}

	public static function reorder($rData) {
		global $db;
		$rOrder = json_decode($rData['stream_order_array'], true);
		$rOrder['stream'] = AdminHelpers::confirmIDs($rOrder['stream']);
		$rOrder['series'] = AdminHelpers::confirmIDs($rOrder['series']);
		$rOrder['movie'] = AdminHelpers::confirmIDs($rOrder['movie']);
		$rOrder['radio'] = AdminHelpers::confirmIDs($rOrder['radio']);
		$db->query('UPDATE `bouquets` SET `bouquet_channels` = ?, `bouquet_series` = ?, `bouquet_movies` = ?, `bouquet_radios` = ? WHERE `id` = ?;', '[' . implode(',', array_map('intval', $rOrder['stream'])) . ']', '[' . implode(',', array_map('intval', $rOrder['series'])) . ']', '[' . implode(',', array_map('intval', $rOrder['movie'])) . ']', '[' . implode(',', array_map('intval', $rOrder['radio'])) . ']', $rData['reorder']);

		return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rData['reorder']));
	}

	public static function sort($rData) {
		global $db;
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);
		$rOrder = json_decode($rData['bouquet_order_array'], true);
		$rSort = 1;

		foreach ($rOrder as $rBouquetID) {
			$db->query('UPDATE `bouquets` SET `bouquet_order` = ? WHERE `id` = ?;', $rSort, $rBouquetID);
			$rSort++;
		}

		if (isset($rData['confirmReplace'])) {
			$rUsers = self::getUserBouquets();

			foreach ($rUsers as $rUser) {
				$rBouquet = json_decode($rUser['bouquet'], true);
				$rBouquet = array_map('intval', AdminHelpers::sortArrayByArray($rBouquet, $rOrder));
				$db->query('UPDATE `lines` SET `bouquet` = ? WHERE `id` = ?;', '[' . implode(',', $rBouquet) . ']', $rUser['id']);
				LineService::updateLineSignal($rUser['id']);
			}

			$rPackages = PackageService::getAll();
			foreach ($rPackages as $rPackage) {
				$rBouquet = json_decode($rPackage['bouquets'], true);
				$rBouquet = array_map('intval', AdminHelpers::sortArrayByArray($rBouquet, $rOrder));
				$db->query('UPDATE `users_packages` SET `bouquets` = ? WHERE `id` = ?;', '[' . implode(',', $rBouquet) . ']', $rPackage['id']);
			}

			return array('status' => STATUS_SUCCESS_REPLACE);
		}

		return array('status' => STATUS_SUCCESS);
	}

	public static function scan() {
		shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php tools bouquets > /dev/null 2>/dev/null &');
	}

	public static function scanOne($rID) {
		global $db;
		$rBouquet = BouquetService::getById($rID);
		if (!$rBouquet) {
			return;
		}

		$availableStreams = [];
		$db->query('SELECT `id` FROM `streams`;');
		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$availableStreams[] = (int)$rRow['id'];
			}
		}

		$availableSeries = [];
		$db->query('SELECT `id` FROM `streams_series`;');
		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$availableSeries[] = (int)$rRow['id'];
			}
		}

		$updateData = [
			'channels' => AdminHelpers::filterIDs(json_decode($rBouquet['bouquet_channels'] ?? '[]', true), $availableStreams, true),
			'movies' => AdminHelpers::filterIDs(json_decode($rBouquet['bouquet_movies'] ?? '[]', true), $availableStreams, true),
			'radios' => AdminHelpers::filterIDs(json_decode($rBouquet['bouquet_radios'] ?? '[]', true), $availableStreams, true),
			'series' => AdminHelpers::filterIDs(json_decode($rBouquet['bouquet_series'] ?? '[]', true), $availableSeries, false)
		];

		$db->query(
			"UPDATE `bouquets` SET 
            `bouquet_channels` = ?, 
            `bouquet_movies` = ?, 
            `bouquet_radios` = ?, 
            `bouquet_series` = ? 
         WHERE `id` = ?",
			json_encode($updateData['channels']),
			json_encode($updateData['movies']),
			json_encode($updateData['radios']),
			json_encode($updateData['series']),
			$rBouquet['id']
		);
	}

	public static function getMapEntry($rStreamID) {
		$rBouquetMap = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'bouquet_map'));
		$rReturn = ($rBouquetMap[$rStreamID] ?: array());
		unset($rBouquetMap);
		return $rReturn;
	}

	public static function getAll($rForce = false) {
		global $db;
		if (!$rForce) {
			$rCache = FileCache::getCache('bouquets', 60);
			if (!empty($rCache)) {
				return $rCache;
			}
		}

		$rOutput = array();
		$db->query('SELECT *, IF(`bouquet_order` > 0, `bouquet_order`, 999) AS `order` FROM `bouquets` ORDER BY `order` ASC;');
		foreach ($db->get_rows(true, 'id') as $rID => $rChannels) {
			$rChannelsList = json_decode($rChannels['bouquet_channels'], true);
			$rMoviesList = json_decode($rChannels['bouquet_movies'], true);
			$rRadiosList = json_decode($rChannels['bouquet_radios'], true);
			$rSeriesList = json_decode($rChannels['bouquet_series'], true);

			$rChannelsList = is_array($rChannelsList) ? $rChannelsList : array();
			$rMoviesList = is_array($rMoviesList) ? $rMoviesList : array();
			$rRadiosList = is_array($rRadiosList) ? $rRadiosList : array();
			$rSeriesList = is_array($rSeriesList) ? $rSeriesList : array();

			$rOutput[$rID]['streams'] = array_merge($rChannelsList, $rMoviesList, $rRadiosList);
			$rOutput[$rID]['series'] = $rSeriesList;
			$rOutput[$rID]['channels'] = $rChannelsList;
			$rOutput[$rID]['movies'] = $rMoviesList;
			$rOutput[$rID]['radios'] = $rRadiosList;
		}

		FileCache::setCache('bouquets', $rOutput);

		return $rOutput;
	}

	public static function getUserBouquets() {
		global $db;
		$rReturn = array();
		$db->query('SELECT `id`, `bouquet` FROM `lines` ORDER BY `id` ASC;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[intval($rRow['id'])] = $rRow;
			}
		}

		return $rReturn;
	}

	public static function getAllSimple() {
		global $db;
		$rReturn = array();
		$db->query('SELECT * FROM `bouquets` ORDER BY `bouquet_order` ASC;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[intval($rRow['id'])] = $rRow;
			}
		}

		return $rReturn;
	}

	public static function getOrder() {
		return self::getAllSimple();
	}

	public static function getById($rID) {
		global $db;
		$db->query('SELECT * FROM `bouquets` WHERE `id` = ?;', $rID);

		if ($db->num_rows() != 1) {
			return null;
		}

		return $db->get_row();
	}

	public static function deleteById($rID) {
		global $db;
		$rBouquet = self::getById($rID);

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

			$db->query("UPDATE `lines` SET `bouquet` = ? WHERE `id` = ?;", '[' . implode(',', array_map('intval', $rRow['bouquet'])) . ']', $rRow['id']);
			LineService::updateLineSignal($rRow['id']);
		}
		$db->query("SELECT `id`, `bouquets` FROM `users_packages` WHERE JSON_CONTAINS(`bouquets`, ?, '\$');", $rID);

		foreach ($db->get_rows() as $rRow) {
			$rRow['bouquets'] = json_decode($rRow['bouquets'], true);

			if (($rKey = array_search($rID, $rRow['bouquets'])) === false) {
			} else {
				unset($rRow['bouquets'][$rKey]);
			}

			$db->query("UPDATE `users_packages` SET `bouquets` = ? WHERE `id` = ?;", '[' . implode(',', array_map('intval', $rRow['bouquets'])) . ']', $rRow['id']);
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

			$db->query("UPDATE `watch_folders` SET `bouquets` = ?, `fb_bouquets` = ? WHERE `id` = ?;", '[' . implode(',', array_map('intval', $rRow['bouquets'])) . ']', '[' . implode(',', array_map('intval', $rRow['fb_bouquets'])) . ']', $rRow['id']);
		}
		$db->query('DELETE FROM `bouquets` WHERE `id` = ?;', $rID);
		self::scan();

		return true;
	}

	public static function addItems($rType, $rBouquetID, $rIDs) {
		global $db;

		if (!is_array($rIDs)) {
			$rIDs = array($rIDs);
		}

		$rBouquet = self::getById($rBouquetID);

		if (!$rBouquet) {
			return;
		}

		if ($rType == 'stream') {
			$rColumn = 'bouquet_channels';
		} elseif ($rType == 'movie') {
			$rColumn = 'bouquet_movies';
		} elseif ($rType == 'radio') {
			$rColumn = 'bouquet_radios';
		} else {
			$rColumn = 'bouquet_series';
		}

		$rChanged = false;
		$rChannels = AdminHelpers::confirmIDs(json_decode($rBouquet[$rColumn], true));

		foreach ($rIDs as $rID) {
			if (0 >= intval($rID) || in_array($rID, $rChannels)) {
			} else {
				$rChannels[] = $rID;
				$rChanged = true;
			}
		}

		if ($rChanged) {
			$db->query('UPDATE `bouquets` SET `' . $rColumn . '` = ? WHERE `id` = ?;', '[' . implode(',', array_map('intval', $rChannels)) . ']', $rBouquetID);
		}
	}

	public static function removeItems($rType, $rBouquetID, $rIDs) {
		global $db;

		if (!is_array($rIDs)) {
			$rIDs = array($rIDs);
		}

		$rBouquet = self::getById($rBouquetID);

		if (!$rBouquet) {
			return;
		}

		if ($rType == 'stream') {
			$rColumn = 'bouquet_channels';
		} elseif ($rType == 'movie') {
			$rColumn = 'bouquet_movies';
		} elseif ($rType == 'radio') {
			$rColumn = 'bouquet_radios';
		} else {
			$rColumn = 'bouquet_series';
		}

		$rChanged = false;
		$rChannels = AdminHelpers::confirmIDs(json_decode($rBouquet[$rColumn], true));

		foreach ($rIDs as $rID) {
			if (($rKey = array_search($rID, $rChannels)) === false) {
			} else {
				unset($rChannels[$rKey]);
				$rChanged = true;
			}
		}

		if ($rChanged) {
			$db->query('UPDATE `bouquets` SET `' . $rColumn . '` = ? WHERE `id` = ?;', '[' . implode(',', array_map('intval', $rChannels)) . ']', $rBouquetID);
		}
	}
}
