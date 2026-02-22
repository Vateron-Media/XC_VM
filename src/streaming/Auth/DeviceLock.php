<?php

class DeviceLock {
	public static function normalizeIdentifier($rIdentifier) {
		return trim((string) $rIdentifier);
	}
}
