<?php

class TokenAuth {
	public static function validateHMAC($rHMAC, $rExpiry, $rStreamID, $rExtension, $rIP = '', $rMACIP = '', $rIdentifier = '', $rMaxConnections = 0) {
		global $db;
		return AuthService::validateHMAC($rHMAC, $rExpiry, $rStreamID, $rExtension, $rIP, $rMACIP, $rIdentifier, $rMaxConnections);
	}
}
