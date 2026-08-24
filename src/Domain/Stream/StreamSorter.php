<?php

namespace XcVm\Domain\Stream;

use XcVm\Core\Config\SettingsManager;

/**
 * StreamSorter — stream sorter
 *
 * @package XC_VM_Domain_Stream
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class StreamSorter {
	/**
	 * Append the year to a title according to the movie_year_append setting.
	 *
	 * @param string     $rTitle Base title.
	 * @param int|string $rYear  Year (only applied when a valid 1900..next-year value).
	 * @return string Formatted title.
	 */
	public static function formatTitle($rTitle, $rYear) {
		if (is_numeric($rYear) && 1900 <= $rYear && $rYear <= intval(date('Y') + 1)) {
			if (SettingsManager::get('movie_year_append') == 0) {
				return trim($rTitle) . ' (' . $rYear . ')';
			}
			if (SettingsManager::get('movie_year_append') == 1) {
				return trim($rTitle) . ' - ' . $rYear;
			}
		}
		return $rTitle;
	}

	/**
	 * Reorder channel ids by the cached channel order.
	 *
	 * Returns the input unchanged when no cached order exists or bouquet
	 * numbering is in use.
	 *
	 * @param int[] $rChannels Channel ids.
	 * @return int[] Reordered channel ids.
	 */
	public static function sortChannels($rChannels) {
		if (!(0 < count($rChannels) && file_exists(CACHE_TMP_PATH . 'channel_order') && SettingsManager::get('channel_number_type') != 'bouquet')) {
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

	/**
	 * Reorder series ids by the cached series order.
	 *
	 * @param int[] $rSeries Series ids.
	 * @return int[] Reordered series ids (input unchanged if no cached order).
	 */
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

	/**
	 * Return the value in an array numerically closest to a target.
	 *
	 * @param array     $rArray  Numeric values.
	 * @param int|float $rSearch Target value.
	 * @return mixed The closest value, or null if the array is empty.
	 */
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
