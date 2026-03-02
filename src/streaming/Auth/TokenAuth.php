<?php

class TokenAuth {
	public static function validateHMAC($rSettings, $rCached, $rHMAC, $rExpiry, $rStreamID, $rExtension, $rIP = '', $rMACIP = '', $rIdentifier = '', $rMaxConnections = 0, $rDecryptCallback = null) {
		global $db;
		return AuthService::validateHMAC($rSettings, $rCached, $rHMAC, $rExpiry, $rStreamID, $rExtension, $rIP, $rMACIP, $rIdentifier, $rMaxConnections, $rDecryptCallback);
	}
}
