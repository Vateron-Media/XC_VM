<?php

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
	public static function getAll($rForce = false) {
		global $db;
		if (!$rForce) {
			$rCache = FileCache::getCache('settings', 20);
			if (!empty($rCache)) {
				return $rCache;
			}
		}

		$rOutput = array();
		$db->query('SELECT * FROM `settings`');
		$rRows = $db->get_row();
		foreach ($rRows as $rKey => $rValue) {
			$rOutput[$rKey] = $rValue;
		}

		$rOutput['allow_countries'] = json_decode($rOutput['allow_countries'], true);

		$decodedAllowedSTB = json_decode($rOutput['allowed_stb_types'], true);
		$rOutput['allowed_stb_types'] = array();
		if (is_array($decodedAllowedSTB)) {
			$rOutput['allowed_stb_types'] = array_map('strtolower', $decodedAllowedSTB);
		}

		$rOutput['stalker_lock_images'] = json_decode($rOutput['stalker_lock_images'], true);
		if (array_key_exists('bouquet_name', $rOutput)) {
			$rOutput['bouquet_name'] = str_replace(' ', '_', $rOutput['bouquet_name']);
		}
		$rOutput['api_ips'] = !empty($rOutput['api_ips']) ? explode(',', $rOutput['api_ips']) : [];

		FileCache::setCache('settings', $rOutput);

		return $rOutput;
	}
}
