<?php

class HMACValidator {
	public static function validate($db, $rSettings, $rCached, $rHMAC, $rExpiry, $rStreamID, $rExtension, $rIP = '', $rMACIP = '', $rIdentifier = '', $rMaxConnections = 0, $rDecryptCallback = null) {
		if (0 < strlen($rIP) && 0 < strlen($rMACIP) && $rIP != $rMACIP) {
			return null;
		}

		$rKeyID = null;
		if ($rCached) {
			$rKeys = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'hmac_keys'));
		} else {
			$rKeys = array();
			$db->query('SELECT `id`, `key` FROM `hmac_keys` WHERE `enabled` = 1;');
			foreach ($db->get_rows() as $rKey) {
				$rKeys[] = $rKey;
			}
		}

		foreach ($rKeys as $rKey) {
			$rSecret = call_user_func($rDecryptCallback, $rKey['key'], $rSettings['live_streaming_pass'], OPENSSL_EXTRA);
			$rResult = hash_hmac('sha256', (string) $rStreamID . '##' . $rExtension . '##' . $rExpiry . '##' . $rMACIP . '##' . $rIdentifier . '##' . $rMaxConnections, $rSecret);

			if (md5($rResult) == md5($rHMAC)) {
				$rKeyID = $rKey['id'];
				break;
			}
		}

		return $rKeyID;
	}
}
