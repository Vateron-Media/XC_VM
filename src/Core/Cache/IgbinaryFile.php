<?php

namespace XcVm\Core\Cache;

/**
 * Reads the igbinary files the cache crons write (stream_<id>, channel_order,
 * permissions_<group>, ...). A file that is missing, unreadable or not an
 * igbinary array reads as null, so a caller skips it instead of passing false
 * on to count() or an offset.
 */
final class IgbinaryFile {
	public static function read(string $rPath): ?array {
		if (!is_file($rPath)) {
			return null;
		}
		$rRaw = @file_get_contents($rPath);
		if ($rRaw === false || $rRaw === '') {
			return null;
		}
		$rData = @igbinary_unserialize($rRaw);
		return is_array($rData) ? $rData : null;
	}
}
