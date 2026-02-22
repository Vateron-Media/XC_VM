<?php

class HLSGenerator {
	public static function generateHLS($rSettings, $rM3U8, $rUsername, $rPassword, $rStreamID, $rUUID, $rIP, $rIsHMAC = null, $rIdentifier = '', $rVideoCodec = 'h264', $rOnDemand = 0, $rServerID = null, $rProxyID = null, $rEncryptDataCallback = null) {
		if (!file_exists($rM3U8)) {
			return false;
		}
		$rSource = file_get_contents($rM3U8);
		if ($rSettings['encrypt_hls']) {
			$rKeyToken = call_user_func($rEncryptDataCallback, $rIP . '/' . $rStreamID, $rSettings['live_streaming_pass'], OPENSSL_EXTRA);
			$rSource = "#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI=\"" . (($rProxyID ? '/' . md5($rProxyID . '_' . $rServerID . '_' . OPENSSL_EXTRA) : '')) . '/key/' . $rKeyToken . "\",IV=0x" . bin2hex(file_get_contents(STREAMS_PATH . $rStreamID . '_.iv')) . "\n" . substr($rSource, 8, strlen($rSource) - 8);
		}

		if (preg_match('/#EXT-X-MAP:URI="(.*?)"/', $rSource, $rInitMatch)) {
			$rInitSegment = $rInitMatch[1];
			if ($rIsHMAC) {
				$rInitToken = call_user_func($rEncryptDataCallback, 'HMAC#' . $rIsHMAC . '/' . $rIdentifier . '/' . $rIP . '/' . $rStreamID . '/' . $rInitSegment . '/' . $rUUID . '/' . SERVER_ID . '/' . $rVideoCodec . '/' . $rOnDemand, $rSettings['live_streaming_pass'], OPENSSL_EXTRA);
			} else {
				$rInitToken = call_user_func($rEncryptDataCallback, $rUsername . '/' . $rPassword . '/' . $rIP . '/' . $rStreamID . '/' . $rInitSegment . '/' . $rUUID . '/' . SERVER_ID . '/' . $rVideoCodec . '/' . $rOnDemand, $rSettings['live_streaming_pass'], OPENSSL_EXTRA);
			}
			if ($rSettings['allow_cdn_access']) {
				$rSource = str_replace('URI="' . $rInitSegment . '"', 'URI="' . (($rProxyID ? '/' . md5($rProxyID . '_' . $rServerID . '_' . OPENSSL_EXTRA) : '')) . '/hls/' . $rInitSegment . '?token=' . $rInitToken . '"', $rSource);
			} else {
				$rSource = str_replace('URI="' . $rInitSegment . '"', 'URI="' . (($rProxyID ? '/' . md5($rProxyID . '_' . $rServerID . '_' . OPENSSL_EXTRA) : '')) . '/hls/' . $rInitToken . '"', $rSource);
			}
		}

		if (preg_match_all('/(.*?)\.(ts|m4s)/', $rSource, $rMatches)) {
			foreach ($rMatches[0] as $rMatch) {
				if ($rIsHMAC) {
					$rToken = call_user_func($rEncryptDataCallback, 'HMAC#' . $rIsHMAC . '/' . $rIdentifier . '/' . $rIP . '/' . $rStreamID . '/' . $rMatch . '/' . $rUUID . '/' . SERVER_ID . '/' . $rVideoCodec . '/' . $rOnDemand, $rSettings['live_streaming_pass'], OPENSSL_EXTRA);
				} else {
					$rToken = call_user_func($rEncryptDataCallback, $rUsername . '/' . $rPassword . '/' . $rIP . '/' . $rStreamID . '/' . $rMatch . '/' . $rUUID . '/' . SERVER_ID . '/' . $rVideoCodec . '/' . $rOnDemand, $rSettings['live_streaming_pass'], OPENSSL_EXTRA);
				}
				if ($rSettings['allow_cdn_access']) {
					$rSource = str_replace($rMatch, (($rProxyID ? '/' . md5($rProxyID . '_' . $rServerID . '_' . OPENSSL_EXTRA) : '')) . '/hls/' . $rMatch . '?token=' . $rToken, $rSource);
				} else {
					$rSource = str_replace($rMatch, (($rProxyID ? '/' . md5($rProxyID . '_' . $rServerID . '_' . OPENSSL_EXTRA) : '')) . '/hls/' . $rToken, $rSource);
				}
			}
			return $rSource;
		}

		return false;
	}
}
