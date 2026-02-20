<?php

register_shutdown_function('shutdown');
require 'init.php';
set_time_limit(0);
header('Access-Control-Allow-Origin: *');
$rDeny = true;

if (strtolower(explode('.', ltrim(parse_url($_SERVER['REQUEST_URI'])['path'], '/'))[0]) == 'get' && !CoreUtilities::$rSettings['legacy_get']) {
	$rDeny = false;
	generateError('LEGACY_GET_DISABLED');
}

$rDownloading = false;
$rIP = CoreUtilities::getUserIP();
$rCountryCode = CoreUtilities::getIPInfo($rIP)['country']['iso_code'];
$rUserAgent = (empty($_SERVER['HTTP_USER_AGENT']) ? '' : htmlentities(trim($_SERVER['HTTP_USER_AGENT'])));
$rDeviceKey = (empty(CoreUtilities::$rRequest['type']) ? 'm3u_plus' : CoreUtilities::$rRequest['type']);
$rTypeKey = (empty(CoreUtilities::$rRequest['key']) ? null : explode(',', CoreUtilities::$rRequest['key']));
$rOutputKey = (empty(CoreUtilities::$rRequest['output']) ? '' : CoreUtilities::$rRequest['output']);
$rNoCache = !empty(CoreUtilities::$rRequest['nocache']);

if (isset(CoreUtilities::$rRequest['username']) && isset(CoreUtilities::$rRequest['password'])) {
	$rUsername = CoreUtilities::$rRequest['username'];
	$rPassword = CoreUtilities::$rRequest['password'];

	if (empty($rUsername) || empty($rPassword)) {
		generateError('NO_CREDENTIALS');
	}

	$rUserInfo = CoreUtilities::getUserInfo(null, $rUsername, $rPassword, true, false, $rIP);
} else {
	if (isset(CoreUtilities::$rRequest['token'])) {
		$rToken = CoreUtilities::$rRequest['token'];

		if (empty($rToken)) {
			generateError('NO_CREDENTIALS');
		}

		$rUserInfo = CoreUtilities::getUserInfo(null, $rToken, null, true, false, $rIP);
	} else {
		generateError('NO_CREDENTIALS');
	}
}

ini_set('memory_limit', -1);

if ($rUserInfo) {
	$rDeny = false;

	if (!$rUserInfo['is_restreamer'] && CoreUtilities::$rSettings['disable_playlist']) {
		generateError('PLAYLIST_DISABLED');
	}

	if ($rUserInfo['is_restreamer'] && CoreUtilities::$rSettings['disable_playlist_restreamer']) {
		generateError('PLAYLIST_DISABLED');
	}

	if ($rUserInfo['bypass_ua'] == 0) {
		if (CoreUtilities::checkBlockedUAs($rUserAgent, true)) {
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

	if (!CoreUtilities::$rSettings['restrict_playlists']) {
	} else {
		if (!(empty($rUserAgent) && CoreUtilities::$rSettings['disallow_empty_user_agents'] == 1)) {
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

			if ($rForceCountry || in_array('ALL', CoreUtilities::$rSettings['allow_countries']) || in_array($rCountryCode, CoreUtilities::$rSettings['allow_countries'])) {
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

	if (CoreUtilities::startDownload('playlist', $rUserInfo, getmypid())) {
		$db = new DatabaseHandler($_INFO['username'], $_INFO['password'], $_INFO['database'], $_INFO['hostname'], $_INFO['port']);
		CoreUtilities::$db = &$db;
		$rProxyIP = ($_SERVER['HTTP_X_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));

		if (!CoreUtilities::generatePlaylist($rUserInfo, $rDeviceKey, $rOutputKey, $rTypeKey, $rNoCache, CoreUtilities::isProxy($rProxyIP))) {
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
		CoreUtilities::stopDownload('playlist', $rUserInfo, getmypid());
	}
}
