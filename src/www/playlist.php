<?php

register_shutdown_function('shutdown');
require 'init.php';
set_time_limit(0);
header('Access-Control-Allow-Origin: *');
$rDeny = true;

if (strtolower(explode('.', ltrim(parse_url($_SERVER['REQUEST_URI'])['path'], '/'))[0]) == 'get' && !SettingsManager::getAll()['legacy_get']) {
	$rDeny = false;
	generateError('LEGACY_GET_DISABLED');
}

$rDownloading = false;
$rIP = NetworkUtils::getUserIP();
$rCountryCode = GeoIP::getCountry($rIP)['country']['iso_code'];
$rUserAgent = (empty($_SERVER['HTTP_USER_AGENT']) ? '' : htmlentities(trim($_SERVER['HTTP_USER_AGENT'])));
$rDeviceKey = (empty(RequestManager::getAll()['type']) ? 'm3u_plus' : RequestManager::getAll()['type']);
$rTypeKey = (empty(RequestManager::getAll()['key']) ? null : explode(',', RequestManager::getAll()['key']));
$rOutputKey = (empty(RequestManager::getAll()['output']) ? '' : RequestManager::getAll()['output']);
$rNoCache = !empty(RequestManager::getAll()['nocache']);

if (isset(RequestManager::getAll()['username']) && isset(RequestManager::getAll()['password'])) {
	$rUsername = RequestManager::getAll()['username'];
	$rPassword = RequestManager::getAll()['password'];

	if (empty($rUsername) || empty($rPassword)) {
		generateError('NO_CREDENTIALS');
	}

	$rUserInfo = UserRepository::getUserInfo(null, $rUsername, $rPassword, true, false, $rIP);
} else {
	if (isset(RequestManager::getAll()['token'])) {
		$rToken = RequestManager::getAll()['token'];

		if (empty($rToken)) {
			generateError('NO_CREDENTIALS');
		}

		$rUserInfo = UserRepository::getUserInfo(null, $rToken, null, true, false, $rIP);
	} else {
		generateError('NO_CREDENTIALS');
	}
}

ini_set('memory_limit', -1);

if ($rUserInfo) {
	$rDeny = false;

	if (!$rUserInfo['is_restreamer'] && SettingsManager::getAll()['disable_playlist']) {
		generateError('PLAYLIST_DISABLED');
	}

	if ($rUserInfo['is_restreamer'] && SettingsManager::getAll()['disable_playlist_restreamer']) {
		generateError('PLAYLIST_DISABLED');
	}

	if ($rUserInfo['bypass_ua'] == 0) {
		if (BlocklistService::checkAndBlockUA(BlocklistService::getBlockedUA(), $rUserAgent, true)) {
			generateError('BLOCKED_USER_AGENT');
		}
	}

	if (is_null($rUserInfo['exp_date']) || $rUserInfo['exp_date'] > time()) {
	} else {
		generateError('EXPIRED');
	}

	if (!($rUserInfo['is_mag'] || $rUserInfo['is_e2'])) {
	} else {
		generateError('DEVICE_NOT_ALLOWED');
	}

	if ($rUserInfo['admin_enabled']) {
	} else {
		generateError('BANNED');
	}

	if ($rUserInfo['enabled']) {
	} else {
		generateError('DISABLED');
	}

	if (!SettingsManager::getAll()['restrict_playlists']) {
	} else {
		if (!(empty($rUserAgent) && SettingsManager::getAll()['disallow_empty_user_agents'] == 1)) {
		} else {
			generateError('EMPTY_USER_AGENT');
		}

		if (empty($rUserInfo['allowed_ips']) || in_array($rIP, array_map('gethostbyname', $rUserInfo['allowed_ips']))) {
		} else {
			generateError('NOT_IN_ALLOWED_IPS');
		}

		if (empty($rCountryCode)) {
		} else {
			$rForceCountry = !empty($rUserInfo['forced_country']);

			if (!($rForceCountry && $rUserInfo['forced_country'] != 'ALL' && $rCountryCode != $rUserInfo['forced_country'])) {
			} else {
				generateError('FORCED_COUNTRY_INVALID');
			}

			if ($rForceCountry || in_array('ALL', SettingsManager::getAll()['allow_countries']) || in_array($rCountryCode, SettingsManager::getAll()['allow_countries'])) {
			} else {
				generateError('NOT_IN_ALLOWED_COUNTRY');
			}
		}

		if (empty($rUserInfo['allowed_ua']) || in_array($rUserAgent, $rUserInfo['allowed_ua'])) {
		} else {
			generateError('NOT_IN_ALLOWED_UAS');
		}

		if ($rUserInfo['isp_violate'] != 1) {
		} else {
			generateError('ISP_BLOCKED');
		}

		if ($rUserInfo['isp_is_server'] != 1 || $rUserInfo['is_restreamer']) {
		} else {
			generateError('ASN_BLOCKED');
		}
	}

	$rDownloading = true;

	if (NetworkUtils::startDownload('playlist', $rUserInfo, getmypid(), intval(SettingsManager::getAll()['max_simultaneous_downloads']))) {
		$db = new DatabaseHandler($_INFO['username'], $_INFO['password'], $_INFO['database'], $_INFO['hostname'], $_INFO['port']);
		DatabaseFactory::set($db);
		$rProxyIP = ($_SERVER['HTTP_X_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));

		if (!PlaylistGenerator::generate($rUserInfo, $rDeviceKey, $rOutputKey, $rTypeKey, $rNoCache, BlocklistService::isProxy($rProxyIP))) {
			generateError('GENERATE_PLAYLIST_FAILED');
		}
	} else {
		generateError('DOWNLOAD_LIMIT_REACHED', false);
		http_response_code(429);

		exit();
	}
} else {
	BruteforceGuard::checkBruteforce(null, null, $rUsername);
	generateError('INVALID_CREDENTIALS');
}

function shutdown() {
	global $db;
	global $rDeny;
	global $rDownloading;
	global $rUserInfo;

	if (!$rDeny) {
	} else {
		BruteforceGuard::checkFlood();
	}

	if (!is_object($db)) {
	} else {
		$db->close_mysql();
	}

	if (!$rDownloading) {
	} else {
		NetworkUtils::stopDownload('playlist', $rUserInfo, getmypid(), intval(SettingsManager::getAll()['max_simultaneous_downloads']));
	}
}
