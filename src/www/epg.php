<?php

register_shutdown_function('shutdown');
require 'init.php';
set_time_limit(0);
header('Access-Control-Allow-Origin: *');
$rDeny = true;

if (strtolower(explode('.', ltrim(parse_url($_SERVER['REQUEST_URI'])['path'], '/'))[0]) == 'xmltv' && !SettingsManager::getAll()['legacy_xmltv']) {
	$rDeny = false;
	generateError('LEGACY_EPG_DISABLED');
}

$rDownloading = false;
$rIP = NetworkUtils::getUserIP();
$rCountryCode = GeoIP::getCountry($rIP)['country']['iso_code'];
$rUserAgent = (empty($_SERVER['HTTP_USER_AGENT']) ? '' : htmlentities(trim($_SERVER['HTTP_USER_AGENT'])));
$rUsername = RequestManager::getAll()['username'];
$rPassword = RequestManager::getAll()['password'];
$rGZ = !empty(RequestManager::getAll()['gzip']) && intval(RequestManager::getAll()['gzip']) == 1;

if (isset(RequestManager::getAll()['username']) && isset(RequestManager::getAll()['password'])) {
	$rUsername = RequestManager::getAll()['username'];
	$rPassword = RequestManager::getAll()['password'];

	if (empty($rUsername) || empty($rPassword)) {
		generateError('NO_CREDENTIALS');
	}

	$rUserInfo = UserRepository::getUserInfo(null, $rUsername, $rPassword, false, false, $rIP);
} else {
	if (isset(RequestManager::getAll()['token'])) {
		$rToken = RequestManager::getAll()['token'];

		if (empty($rToken)) {
			generateError('NO_CREDENTIALS');
		}

		$rUserInfo = UserRepository::getUserInfo(null, $rToken, null, false, false, $rIP);
	} else {
		generateError('NO_CREDENTIALS');
	}
}

ini_set('memory_limit', -1);

if ($rUserInfo) {
	$rDeny = false;

	if (!$rUserInfo['is_restreamer'] && SettingsManager::getAll()['disable_xmltv']) {
		generateError('EPG_DISABLED');
	}

	if ($rUserInfo['is_restreamer'] && SettingsManager::getAll()['disable_xmltv_restreamer']) {
		generateError('EPG_DISABLED');
	}

	if ($rUserInfo['bypass_ua'] == 0) {
		if (BlocklistService::checkAndBlockUA(BlocklistService::getBlockedUA(), $rUserAgent, true)) {
			generateError('BLOCKED_USER_AGENT');
		}
	}

	if (!is_null($rUserInfo['exp_date']) && $rUserInfo['exp_date'] <= time()) {
		generateError('EXPIRED');
	}

	if ($rUserInfo['is_mag'] || $rUserInfo['is_e2']) {
		generateError('DEVICE_NOT_ALLOWED');
	}

	if (!$rUserInfo['admin_enabled']) {
		generateError('BANNED');
	}

	if (!$rUserInfo['enabled']) {
		generateError('DISABLED');
	}

	if (SettingsManager::getAll()['restrict_playlists']) {
		if (empty($rUserAgent) && SettingsManager::getAll()['disallow_empty_user_agents'] == 1) {
			generateError('EMPTY_USER_AGENT');
		}

		if (!empty($rUserInfo['allowed_ips']) && !in_array($rIP, array_map('gethostbyname', $rUserInfo['allowed_ips']))) {
			generateError('NOT_IN_ALLOWED_IPS');
		}

		if (!empty($rCountryCode)) {
			$rForceCountry = !empty($rUserInfo['forced_country']);

			if ($rForceCountry && $rUserInfo['forced_country'] != 'ALL' && $rCountryCode != $rUserInfo['forced_country']) {
				generateError('FORCED_COUNTRY_INVALID');
			}

			if (!$rForceCountry && !in_array('ALL', SettingsManager::getAll()['allow_countries']) && !in_array($rCountryCode, SettingsManager::getAll()['allow_countries'])) {
				generateError('NOT_IN_ALLOWED_COUNTRY');
			}
		}

		if (!empty($rUserInfo['allowed_ua']) && !in_array($rUserAgent, $rUserInfo['allowed_ua'])) {
			generateError('NOT_IN_ALLOWED_UAS');
		}

		if ($rUserInfo['isp_violate'] == 1) {
			generateError('ISP_BLOCKED');
		}

		if ($rUserInfo['isp_is_server'] == 1 && !$rUserInfo['is_restreamer']) {
			generateError('ASN_BLOCKED');
		}
	}

	$rBouquets = array();

	foreach ($rUserInfo['bouquet'] as $rBouquetID) {
		if (in_array($rBouquetID, array_keys(BouquetService::getAll()))) {
			$rBouquets[] = $rBouquetID;
		}
	}
	sort($rBouquets);
	$rBouquetGroup = md5(implode('_', $rBouquets));

	if (file_exists(EPG_PATH . 'epg_' . $rBouquetGroup . '.xml')) {
		$rFile = EPG_PATH . 'epg_' . $rBouquetGroup . '.xml';
	} else {
		$rFile = EPG_PATH . 'epg_all.xml';
	}

	$rFilename = 'epg.xml';

	if ($rGZ) {
		$rFile .= '.gz';
		$rFilename .= '.gz';
	}

	if (file_exists($rFile)) {
		if (NetworkUtils::startDownload('epg', $rUserInfo, getmypid(), intval(SettingsManager::getAll()['max_simultaneous_downloads']))) {
			$rDownloading = true;
			header('Content-disposition: attachment; filename="' . $rFilename . '"');

			if ($rGZ) {
				header('Content-Type: application/octet-stream');
				header('Content-Transfer-Encoding: Binary');
			} else {
				header('Content-Type: application/xml; charset=utf-8');
			}

			readchunked($rFile);
		} else {
			generateError('DOWNLOAD_LIMIT_REACHED', false);
			http_response_code(429);

			exit();
		}
	} else {
		generateError('EPG_FILE_MISSING');
	}

	exit();
} else {
	BruteforceGuard::checkBruteforce(null, null, $rUsername);
	generateError('INVALID_CREDENTIALS');
}

function readChunked($rFilename) {
	$rHandle = fopen($rFilename, 'rb');

	if ($rHandle !== false) {
		while (!feof($rHandle)) {
			$rBuffer = fread($rHandle, 1048576);
			echo $rBuffer;
			ob_flush();
			flush();
		}
		return fclose($rHandle);
	}

	return false;
}

function shutdown() {
	global $db;
	global $rDeny;
	global $rUserInfo;
	global $rDownloading;

	if ($rDeny) {
		BruteforceGuard::checkFlood();
	}

	if (is_object($db)) {
		$db->close_mysql();
	}

	if ($rDownloading) {
		NetworkUtils::stopDownload('epg', $rUserInfo, getmypid(), intval(SettingsManager::getAll()['max_simultaneous_downloads']));
	}
}
