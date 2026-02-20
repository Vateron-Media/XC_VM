<?php

class SettingsRepository {
	public static function getAll($db, $rGetCacheCallback, $rSetCacheCallback, $rForce = false) {
		if (!$rForce && is_callable($rGetCacheCallback)) {
			$rCache = call_user_func($rGetCacheCallback, 'settings', 20);
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

		if (is_callable($rSetCacheCallback)) {
			call_user_func($rSetCacheCallback, 'settings', $rOutput);
		}

		return $rOutput;
	}
}
