<?php

namespace XcVm\Streaming\Delivery;

/**
 * HttpRange — one parser for the single byte ranges the VOD and timeshift
 * endpoints serve (RFC 7233).
 *
 * The endpoints used to parse `Range` inline, and the copies had drifted into
 * the same bugs: a suffix range (`bytes=-500`, the last 500 bytes) was read as a
 * start of '' and died on PHP 8 arithmetic, and an unsatisfiable range answered
 * with the full resource's range instead of `bytes *\/size`.
 */
final class HttpRange {
	/**
	 * Resolve a Range header against a resource size.
	 *
	 * @param string|null $rHeader The Range header value (e.g. "bytes=0-1023"), or null/'' for none.
	 * @param int         $rSize   Resource size in bytes.
	 * @return array{0:int,1:int}|null|false [first, last] byte offsets (inclusive) for a
	 *         satisfiable single range; null when there is no usable Range (serve the
	 *         whole resource, 200); false when it cannot be satisfied (416).
	 */
	public static function parse(?string $rHeader, int $rSize) {
		if ($rHeader === null || trim($rHeader) === '') {
			return null;
		}
		if (!preg_match('/^\s*bytes\s*=\s*(.+)$/i', $rHeader, $rMatch)) {
			return null; // another unit: ignore the header, as RFC 7233 allows
		}
		$rSpec = trim($rMatch[1]);
		if (strpos($rSpec, ',') !== false) {
			return false; // multipart/byteranges is not served
		}
		if (!preg_match('/^(\d*)\s*-\s*(\d*)$/', $rSpec, $rParts) || ($rParts[1] === '' && $rParts[2] === '')) {
			return false;
		}
		if ($rSize <= 0) {
			return false;
		}

		if ($rParts[1] === '') {
			// Suffix range: the last N bytes.
			$rLength = (int) $rParts[2];
			if ($rLength <= 0) {
				return false;
			}
			return array(max(0, $rSize - $rLength), $rSize - 1);
		}

		$rFirst = (int) $rParts[1];
		$rLast = ($rParts[2] === '') ? $rSize - 1 : min((int) $rParts[2], $rSize - 1);
		if ($rFirst >= $rSize || $rLast < $rFirst) {
			return false;
		}
		return array($rFirst, $rLast);
	}

	/**
	 * Send the headers for a resolved range: 206 + Content-Range for a range, 200
	 * for the whole resource, or 416 + `bytes *\/size` for an unsatisfiable one.
	 *
	 * @param array{0:int,1:int}|null|false $rRange What parse() returned.
	 * @param int                           $rSize  Resource size in bytes.
	 * @return array{0:int,1:int}|null [first, last] to send, or null after a 416 (send no body).
	 */
	public static function sendHeaders($rRange, int $rSize): ?array {
		header('Accept-Ranges: bytes');
		if ($rRange === false) {
			header('HTTP/1.1 416 Requested Range Not Satisfiable');
			header('Content-Range: bytes */' . $rSize);
			return null;
		}
		if ($rRange === null) {
			header('Content-Length: ' . $rSize);
			return array(0, $rSize - 1);
		}
		header('HTTP/1.1 206 Partial Content');
		header('Content-Range: bytes ' . $rRange[0] . '-' . $rRange[1] . '/' . $rSize);
		header('Content-Length: ' . ($rRange[1] - $rRange[0] + 1));
		return $rRange;
	}
}
