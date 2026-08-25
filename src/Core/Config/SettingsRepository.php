<?php

namespace XcVm\Core\Config;

/**
 * SettingsRepository — settings repository
 *
 * @package XC_VM_Core_Config
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class SettingsRepository {
	/**
	 * Load all panel settings, decoding JSON fields and caching the result.
	 *
	 * @param bool $rForce Bypass the file cache and re-read from the database.
	 * @return array Settings map (with normalized array fields).
	 */
	public static function getAll($rForce = false) {
		global $db;
		if (!$rForce) {
			$rCache = \XcVm\Core\Cache\FileCache::getCache('settings', 20);
			if (!empty($rCache)) {
				return $rCache;
			}
		}

		$rOutput = array();
		$db->query('SELECT * FROM `settings`');
		$rRows = $db->get_row();
		foreach ($rRows ?: array() as $rKey => $rValue) {
			$rOutput[$rKey] = $rValue;
		}

		$rOutput['allow_countries'] = json_decode($rOutput['allow_countries'] ?? '', true);

		$decodedAllowedSTB = json_decode($rOutput['allowed_stb_types'] ?? '', true);
		$rOutput['allowed_stb_types'] = array();
		if (is_array($decodedAllowedSTB)) {
			// Drop blank entries so an "empty" selection (an unset multiselect is
			// commonly stored as [""]) collapses to a truly empty array. An empty
			// Allowed STB Types list means every STB type is accepted — see the
			// get_profile gate in Ministra/portal.php, which allows when this is empty.
			$rOutput['allowed_stb_types'] = array_values(array_filter(
				array_map(static fn ($rType) => strtolower(trim((string) $rType)), $decodedAllowedSTB),
				static fn ($rType) => $rType !== ''
			));
		}

		$rOutput['stalker_lock_images'] = json_decode($rOutput['stalker_lock_images'] ?? '', true);
		if (array_key_exists('bouquet_name', $rOutput)) {
			$rOutput['bouquet_name'] = str_replace(' ', '_', $rOutput['bouquet_name']);
		}
		$rOutput['api_ips'] = !empty($rOutput['api_ips']) ? explode(',', $rOutput['api_ips']) : [];

		$rDecodedPrefixes = json_decode($rOutput['shared_mount_prefixes'] ?? '', true);
		if (!is_array($rDecodedPrefixes)) {
			// Legacy CSV format from before this became a select2 tags field; self-heals to JSON on next save.
			$rDecodedPrefixes = !empty($rOutput['shared_mount_prefixes']) ? explode(',', $rOutput['shared_mount_prefixes']) : [];
		}
		$rOutput['shared_mount_prefixes'] = array_values(array_filter(array_map('trim', $rDecodedPrefixes)));

		\XcVm\Core\Cache\FileCache::setCache('settings', $rOutput);

		return $rOutput;
	}
}
