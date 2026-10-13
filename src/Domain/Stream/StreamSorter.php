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
	 * @param string|null     $rTitle Base title (the title columns are nullable; null is '').
	 * @param int|string|null $rYear  Year (only applied when a valid 1900..next-year value).
	 * @return string Formatted title.
	 */
	public static function formatTitle(?string $rTitle, int|string|null $rYear = null): string {
		$rTitle ??= '';
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
	public static function sortChannels(array $rChannels, mixed $customData = null) {
		if (\XcVm\Domain\Bouquet\ResellerBouquetPolicy::hasCustomOrder($customData)) {
			return self::customerOrder($rChannels, $customData, 'streams');
		}
		if (!(0 < count($rChannels) && file_exists(CACHE_TMP_PATH . 'channel_order') && SettingsManager::get('channel_number_type') != 'bouquet')) {
			return $rChannels;
		}

		$rOrder = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'channel_order'));
		$rChannels = array_flip($rChannels);
		$rNewOrder = [];

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
	public static function sortSeries(array $rSeries, mixed $customData = null) {
		if (\XcVm\Domain\Bouquet\ResellerBouquetPolicy::hasCustomOrder($customData)) {
			return self::customerOrder($rSeries, $customData, 'series');
		}
		if (0 >= count($rSeries) || !file_exists(CACHE_TMP_PATH . 'series_order')) {
			return $rSeries;
		}

		$rOrder = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'series_order'));
		$rSeries = array_flip($rSeries);
		$rNewOrder = [];

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

	private static function customerOrder(array $ids, mixed $customer, string $group): array {
		if (!is_array($customer) || !array_key_exists('bouquet', $customer) || $ids === []) {
			return $ids;
		}
		return self::orderByBouquets($ids, \XcVm\Domain\Bouquet\ResellerBouquetPolicy::ids($customer['bouquet']), \XcVm\Domain\Bouquet\BouquetService::getAll() ?: [], $group);
	}

	/** Reorder only visible ids; shared streams keep their first bouquet occurrence. */
	public static function orderByBouquets(array $ids, array $order, array $bouquets, string $group = 'streams'): array {
		$preferred = [];
		foreach ($order as $id) {
			$preferred = array_merge($preferred, $bouquets[$id][$group] ?? []);
		}
		return \XcVm\Domain\Bouquet\ResellerBouquetPolicy::order($ids, $preferred);
	}

	/**
	 * Return the value in an array numerically closest to a target.
	 *
	 * @param array     $rArray  Numeric values.
	 * @param int|float $rSearch Target value.
	 * @return mixed The closest value, or null if the array is empty.
	 */
	public static function getNearest(array $rArray, int|float $rSearch) {
		$rClosest = null;
		foreach ($rArray as $rItem) {
			if ($rClosest === null || abs($rItem - $rSearch) < abs($rSearch - $rClosest)) {
				$rClosest = $rItem;
			}
		}
		return $rClosest;
	}
}
