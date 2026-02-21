<?php

class SeriesService {
	public static function process($db, $rSettings, $rData) {
		return API::processSeriesLegacy($rData);
	}

	public static function import($rData) {
		return API::importSeriesLegacy($rData);
	}

	public static function massDelete($rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		$rSeries = json_decode($rData['series'], true);
		deleteSeriesMass($rSeries);

		return array('status' => STATUS_SUCCESS);
	}

	public static function massEdit($db, $rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		$rArray = array();
		$rSeriesIDs = json_decode($rData['series'], true);

		if (0 < count($rSeriesIDs)) {
			$rCategoryMap = array();

			if (isset($rData['c_category_id']) && in_array($rData['category_id_type'], array('ADD', 'DEL'))) {
				$db->query('SELECT `id`, `category_id` FROM `streams_series` WHERE `id` IN (' . implode(',', array_map('intval', $rSeriesIDs)) . ');');

				foreach ($db->get_rows() as $rRow) {
					$rCategoryMap[$rRow['id']] = (json_decode($rRow['category_id'], true) ?: array());
				}
			}

			$rBouquets = getBouquets();
			$rAddBouquet = $rDelBouquet = array();

			foreach ($rSeriesIDs as $rSeriesID) {
				if (isset($rData['c_category_id'])) {
					$rCategories = array_map('intval', $rData['category_id']);

					if ($rData['category_id_type'] == 'ADD') {
						foreach (($rCategoryMap[$rSeriesID] ?: array()) as $rCategoryID) {
							if (!in_array($rCategoryID, $rCategories)) {
								$rCategories[] = $rCategoryID;
							}
						}
					} elseif ($rData['category_id_type'] == 'DEL') {
						$rNewCategories = $rCategoryMap[$rSeriesID];

						foreach ($rCategories as $rCategoryID) {
							if (($rKey = array_search($rCategoryID, $rNewCategories)) !== false) {
								unset($rNewCategories[$rKey]);
							}
						}
						$rCategories = $rNewCategories;
					}

					$rArray['category_id'] = '[' . implode(',', $rCategories) . ']';
				}

				$rPrepare = prepareArray($rArray);

				if (0 < count($rPrepare['data'])) {
					$rPrepare['data'][] = $rSeriesID;
					$rQuery = 'UPDATE `streams_series` SET ' . $rPrepare['update'] . ' WHERE `id` = ?;';
					$db->query($rQuery, ...$rPrepare['data']);
				}

				if (isset($rData['c_bouquets'])) {
					if ($rData['bouquets_type'] == 'SET') {
						foreach ($rData['bouquets'] as $rBouquet) {
							$rAddBouquet[$rBouquet][] = $rSeriesID;
						}

						foreach ($rBouquets as $rBouquet) {
							if (!in_array($rBouquet['id'], $rData['bouquets'])) {
								$rDelBouquet[$rBouquet['id']][] = $rSeriesID;
							}
						}
					} elseif ($rData['bouquets_type'] == 'ADD') {
						foreach ($rData['bouquets'] as $rBouquet) {
							$rAddBouquet[$rBouquet][] = $rSeriesID;
						}
					} elseif ($rData['bouquets_type'] == 'DEL') {
						foreach ($rData['bouquets'] as $rBouquet) {
							$rDelBouquet[$rBouquet][] = $rSeriesID;
						}
					}
				}
			}

			foreach ($rAddBouquet as $rBouquetID => $rAddIDs) {
				addToBouquet('series', $rBouquetID, $rAddIDs);
			}

			foreach ($rDelBouquet as $rBouquetID => $rRemIDs) {
				removeFromBouquet('series', $rBouquetID, $rRemIDs);
			}

			if (isset($rData['reprocess_tmdb'])) {
				foreach ($rSeriesIDs as $rSeriesID) {
					if (0 < intval($rSeriesID)) {
						$db->query('INSERT INTO `watch_refresh`(`type`, `stream_id`, `status`) VALUES(2, ?, 0);', $rSeriesID);
					}
				}
			}
		}

		return array('status' => STATUS_SUCCESS);
	}
}
