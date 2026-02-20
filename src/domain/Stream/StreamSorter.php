<?php

class StreamSorter {
	public static function formatTitle($rSettings, $rTitle, $rYear) {
		if (is_numeric($rYear) && 1900 <= $rYear && $rYear <= intval(date('Y') + 1)) {
			if ($rSettings['movie_year_append'] == 0) {
				return trim($rTitle) . ' (' . $rYear . ')';
			}
			if ($rSettings['movie_year_append'] == 1) {
				return trim($rTitle) . ' - ' . $rYear;
			}
		}
		return $rTitle;
	}

	public static function sortChannels($rSettings, $rChannels) {
		if (!(0 < count($rChannels) && file_exists(CACHE_TMP_PATH . 'channel_order') && $rSettings['channel_number_type'] != 'bouquet')) {
			return $rChannels;
		}

		$rOrder = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'channel_order'));
		$rChannels = array_flip($rChannels);
		$rNewOrder = array();

		foreach ($rOrder as $rID) {
			if (isset($rChannels[$rID])) {
				$rNewOrder[] = $rID;
			}
		}

		if (0 < count($rNewOrder)) {
			return $rNewOrder;
		}

		return $rChannels;
	}

	public static function sortSeries($rSeries) {
		if (!(0 < count($rSeries) && file_exists(CACHE_TMP_PATH . 'series_order'))) {
			return $rSeries;
		}

		$rOrder = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'series_order'));
		$rSeries = array_flip($rSeries);
		$rNewOrder = array();

		foreach ($rOrder as $rID) {
			if (isset($rSeries[$rID])) {
				$rNewOrder[] = $rID;
			}
		}

		if (0 < count($rNewOrder)) {
			return $rNewOrder;
		}

		return $rSeries;
	}

	public static function getNearest($rArray, $rSearch) {
		$rClosest = null;
		foreach ($rArray as $rItem) {
			if ($rClosest === null || abs($rItem - $rSearch) < abs($rSearch - $rClosest)) {
				$rClosest = $rItem;
			}
		}
		return $rClosest;
	}
}
