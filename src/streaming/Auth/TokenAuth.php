<?php

class TokenAuth {
	public static function validateHMAC($db, $rSettings, $rCached, $rHMAC, $rExpiry, $rStreamID, $rExtension, $rIP = '', $rMACIP = '', $rIdentifier = '', $rMaxConnections = 0, $rDecryptCallback = null) {
		return HMACValidator::validate($db, $rSettings, $rCached, $rHMAC, $rExpiry, $rStreamID, $rExtension, $rIP, $rMACIP, $rIdentifier, $rMaxConnections, $rDecryptCallback);
	}
}
