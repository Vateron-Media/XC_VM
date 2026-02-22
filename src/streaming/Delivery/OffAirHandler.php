<?php

class OffAirHandler {
	public static function getOffAirVideo($rSettings, $rPathKey) {
		if (!(isset($rSettings[$rPathKey]) && 0 < strlen($rSettings[$rPathKey]))) {
			switch ($rPathKey) {
				case 'connected_video_path':
					if (file_exists(VIDEO_PATH . 'connected.ts')) {
						return VIDEO_PATH . 'connected.ts';
					}
					break;
				case 'expired_video_path':
					if (file_exists(VIDEO_PATH . 'expired.ts')) {
						return VIDEO_PATH . 'expired.ts';
					}
					break;
				case 'banned_video_path':
					if (file_exists(VIDEO_PATH . 'banned.ts')) {
						return VIDEO_PATH . 'banned.ts';
					}
					break;
				case 'not_on_air_video_path':
					if (file_exists(VIDEO_PATH . 'offline.ts')) {
						return VIDEO_PATH . 'offline.ts';
					}
					break;
				case 'expiring_video_path':
					if (file_exists(VIDEO_PATH . 'expiring.ts')) {
						return VIDEO_PATH . 'expiring.ts';
					}
					break;
			}
		} else {
			return $rSettings[$rPathKey];
		}
	}

	public static function showVideoServer($rSettings, $rServers, $rShowOptionKey, $rVideoPathKey, $rExtension, $rUserInfo, $rIP, $rCountryCode, $rISP, $rServerID = null, $rProxyID = null, $rSelectServerCallback = null, $rGetProxiesCallback = null, $rSelectProxyCallback = null, $rEncryptDataCallback = null) {
		$rVideoPath = self::getOffAirVideo($rSettings, $rVideoPathKey);
		if (!(!$rUserInfo['is_restreamer'] && $rSettings[$rShowOptionKey] && 0 < strlen($rVideoPath))) {
			switch ($rShowOptionKey) {
				case 'show_expired_video':
					generateError('EXPIRED');
					break;
				case 'show_banned_video':
					generateError('BANNED');
					break;
				case 'show_not_on_air_video':
					generateError('STREAM_OFFLINE');
					break;
				default:
					generate404();
					break;
			}
		}
		if (!$rServerID) {
			$rServerID = call_user_func($rSelectServerCallback, $rUserInfo, $rIP, $rCountryCode, $rISP);
		}
		if (!$rServerID) {
			$rServerID = SERVER_ID;
		}
		$rOriginatorID = null;
		if ($rServers[$rServerID]['enable_proxy'] && (!$rUserInfo['is_restreamer'] || !$rSettings['restreamer_bypass_proxy'])) {
			$rProxies = call_user_func($rGetProxiesCallback, $rServerID);
			$rProxyID = call_user_func($rSelectProxyCallback, array_keys($rProxies), $rCountryCode, $rUserInfo['con_isp_name']);
			if (!$rProxyID) {
				generate404();
			}
			$rOriginatorID = $rServerID;
			$rServerID = $rProxyID;
		}
		if ($rServers[$rServerID]['random_ip'] && 0 < count($rServers[$rServerID]['domains']['urls'])) {
			$rURL = $rServers[$rServerID]['domains']['protocol'] . '://' . $rServers[$rServerID]['domains']['urls'][array_rand($rServers[$rServerID]['domains']['urls'])] . ':' . $rServers[$rServerID]['domains']['port'];
		} else {
			$rURL = rtrim($rServers[$rServerID]['site_url'], '/');
		}
		if ($rOriginatorID && !$rServers[$rOriginatorID]['is_main']) {
			$rURL .= '/' . md5($rServerID . '_' . $rOriginatorID . '_' . OPENSSL_EXTRA);
		}
		$rTokenData = array('expires' => time() + 10, 'video_path' => $rVideoPath);
		$rToken = call_user_func($rEncryptDataCallback, json_encode($rTokenData), $rSettings['live_streaming_pass'], OPENSSL_EXTRA);
		if ($rExtension == 'm3u8') {
			$segmentDuration = 10;
			$sequence = intval(time() / $segmentDuration);
			$rM3U8 = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-MEDIA-SEQUENCE:{$sequence}\n#EXT-X-ALLOW-CACHE:NO\n#EXT-X-TARGETDURATION:{$segmentDuration}\n#EXT-X-PLAYLIST-TYPE:EVENT\n";
			for ($i = 0; $i < 3; $i++) {
				$rM3U8 .= "#EXTINF:{$segmentDuration}.0,\n" . $rURL . '/auth/' . $rToken . "\n";
			}
			header('Content-Type: application/x-mpegurl');
			header('Content-Length: ' . strlen($rM3U8));
			echo $rM3U8;
			exit();
		}
		header('Location: ' . $rURL . '/auth/' . $rToken);
		exit();
	}
}
