<?php

class BouquetRepository {
	public static function getAll($db, $rGetCacheCallback, $rSetCacheCallback, $rForce = false) {
		if (!$rForce && is_callable($rGetCacheCallback)) {
			$rCache = call_user_func($rGetCacheCallback, 'bouquets', 60);
			if (!empty($rCache)) {
				return $rCache;
			}
		}

		$rOutput = array();
		$db->query('SELECT *, IF(`bouquet_order` > 0, `bouquet_order`, 999) AS `order` FROM `bouquets` ORDER BY `order` ASC;');
		foreach ($db->get_rows(true, 'id') as $rID => $rChannels) {
			$rOutput[$rID]['streams'] = array_merge(json_decode($rChannels['bouquet_channels'], true), json_decode($rChannels['bouquet_movies'], true), json_decode($rChannels['bouquet_radios'], true));
			$rOutput[$rID]['series'] = json_decode($rChannels['bouquet_series'], true);
			$rOutput[$rID]['channels'] = json_decode($rChannels['bouquet_channels'], true);
			$rOutput[$rID]['movies'] = json_decode($rChannels['bouquet_movies'], true);
			$rOutput[$rID]['radios'] = json_decode($rChannels['bouquet_radios'], true);
		}

		if (is_callable($rSetCacheCallback)) {
			call_user_func($rSetCacheCallback, 'bouquets', $rOutput);
		}

		return $rOutput;
	}

	public static function getUserBouquets($db) {
		$rReturn = array();
		$db->query('SELECT `id`, `bouquet` FROM `lines` ORDER BY `id` ASC;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[intval($rRow['id'])] = $rRow;
			}
		}

		return $rReturn;
	}

	public static function getAllSimple($db) {
		$rReturn = array();
		$db->query('SELECT * FROM `bouquets` ORDER BY `bouquet_order` ASC;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[intval($rRow['id'])] = $rRow;
			}
		}

		return $rReturn;
	}

	public static function getOrder($db) {
		return self::getAllSimple($db);
	}
}
