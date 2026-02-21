<?php

class BouquetService {
	public static function process($rData, $db, $rGetBouquetCallback, $rScanBouquetCallback) {
		if (isset($rData['edit'])) {
			if (!hasPermissions('adv', 'edit_bouquet')) {
				exit();
			}

			$rArray = overwriteData(call_user_func($rGetBouquetCallback, $rData['edit']), $rData);
		} else {
			if (!hasPermissions('adv', 'add_bouquet')) {
				exit();
			}

			$rArray = verifyPostTable('bouquets', $rData);
			unset($rArray['id']);
		}

		if (is_array(json_decode($rData['bouquet_data'], true))) {
			$rBouquetData = json_decode($rData['bouquet_data'], true);
			$rBouquetStreams = $rBouquetData['stream'];
			$rBouquetMovies = $rBouquetData['movies'];
			$rBouquetRadios = $rBouquetData['radios'];
			$rBouquetSeries = $rBouquetData['series'];
			$rRequiredIDs = confirmIDs(array_merge($rBouquetStreams, $rBouquetMovies, $rBouquetRadios));
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

		$rPrepare = prepareArray($rArray);
		$rQuery = 'REPLACE INTO `bouquets`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			call_user_func($rScanBouquetCallback, $rInsertID);

			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
		}

		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}

	public static function reorder($rData, $db) {
		$rOrder = json_decode($rData['stream_order_array'], true);
		$rOrder['stream'] = confirmIDs($rOrder['stream']);
		$rOrder['series'] = confirmIDs($rOrder['series']);
		$rOrder['movie'] = confirmIDs($rOrder['movie']);
		$rOrder['radio'] = confirmIDs($rOrder['radio']);
		$db->query('UPDATE `bouquets` SET `bouquet_channels` = ?, `bouquet_series` = ?, `bouquet_movies` = ?, `bouquet_radios` = ? WHERE `id` = ?;', '[' . implode(',', array_map('intval', $rOrder['stream'])) . ']', '[' . implode(',', array_map('intval', $rOrder['series'])) . ']', '[' . implode(',', array_map('intval', $rOrder['movie'])) . ']', '[' . implode(',', array_map('intval', $rOrder['radio'])) . ']', $rData['reorder']);

		return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rData['reorder']));
	}

	public static function sort($rData, $db, $rGetUserBouquetsCallback, $rGetPackagesCallback, $rSortArrayByArrayCallback, $rUpdateLineCallback) {
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
			$rUsers = call_user_func($rGetUserBouquetsCallback);

			foreach ($rUsers as $rUser) {
				$rBouquet = json_decode($rUser['bouquet'], true);
				$rBouquet = array_map('intval', call_user_func($rSortArrayByArrayCallback, $rBouquet, $rOrder));
				$db->query('UPDATE `lines` SET `bouquet` = ? WHERE `id` = ?;', '[' . implode(',', $rBouquet) . ']', $rUser['id']);
				call_user_func($rUpdateLineCallback, $rUser['id']);
			}

			$rPackages = call_user_func($rGetPackagesCallback);
			foreach ($rPackages as $rPackage) {
				$rBouquet = json_decode($rPackage['bouquets'], true);
				$rBouquet = array_map('intval', call_user_func($rSortArrayByArrayCallback, $rBouquet, $rOrder));
				$db->query('UPDATE `users_packages` SET `bouquets` = ? WHERE `id` = ?;', '[' . implode(',', $rBouquet) . ']', $rPackage['id']);
			}

			return array('status' => STATUS_SUCCESS_REPLACE);
		}

		return array('status' => STATUS_SUCCESS);
	}

	public static function scan() {
		shell_exec(PHP_BIN . ' ' . CLI_PATH . 'tools.php "bouquets" > /dev/null 2>/dev/null &');
	}

	public static function scanOne($db, $rID, $rGetBouquetCallback, $rFilterIDsCallback) {
		$rBouquet = call_user_func($rGetBouquetCallback, $rID);
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
			'channels' => call_user_func($rFilterIDsCallback, json_decode($rBouquet['bouquet_channels'] ?? '[]', true), $availableStreams, true),
			'movies' => call_user_func($rFilterIDsCallback, json_decode($rBouquet['bouquet_movies'] ?? '[]', true), $availableStreams, true),
			'radios' => call_user_func($rFilterIDsCallback, json_decode($rBouquet['bouquet_radios'] ?? '[]', true), $availableStreams, true),
			'series' => call_user_func($rFilterIDsCallback, json_decode($rBouquet['bouquet_series'] ?? '[]', true), $availableSeries, false)
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
}
