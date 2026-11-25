<?php

namespace XcVm\Core\Cluster;

/**
 * Removes credentials from text that leaves the node: log lines, a stream's
 * current source, process command lines. It covers:
 *
 *  - credentials and tokens in query strings, headers and argument lists;
 *  - the `/user/pass/` segments of Xtream-style URLs (`/live/u/p/1.ts`,
 *    `/movie/…`, `/series/…`, `/timeshift/u/p/…`);
 *  - `user:pass@` in URLs, as ffmpeg and curl command lines carry them.
 *
 * Pure and idempotent. The cluster API sinks (LogSink's API backend,
 * `stream.state` events, `get_pids`) run it before anything is journaled.
 * The legacy SQL backends keep writing what they wrote before.
 */
final class Redactor {
	public const MASK = '***';

	public static function redact(string $rText): string {
		if ($rText === '') {
			return $rText;
		}
		// URL credentials must go first: otherwise `user:pass@host` looks like
		// an unquoted `user: value` and the rest of the URL is lost.
		$rText = (string) preg_replace('#\b([a-z][a-z0-9+.\-]*://)[^/\s:@"\']+:[^/\s@"\']*@#i', '$1' . self::MASK . '@', $rText);
		// Quoted values may contain spaces, commas and escaped quotes.
		$rText = (string) preg_replace(
			'/\b((?:[a-z0-9]+_)*(?:password|passwd|pwd|pass|secret(?:[_ -]?prefix)?|token|api[_-]?key|access[_-]?key|username|user))(["\']?\s*(?:=>|[:=])\s*)(["\'])((?:\\\\.|(?!\3).)*)\3/i',
			'$1$2$3' . self::MASK . '$3',
			$rText
		);
		// Authorization headers need no credential or quoted value left behind.
		$rText = (string) preg_replace(
			'/\b(authorization)(["\']?\s*(?:=>|[:=])\s*)(?:(["\'])(?:\\\\.|(?!\3).)*\3|(?:bearer|basic)\s+[^\s,;)}]+|[^&\s"\'\\\\,;)}]+)/i',
			'$1$2' . self::MASK,
			$rText
		);
		$rText = (string) preg_replace('/\b(bearer|basic)\s+[A-Za-z0-9._\-\/+~=]+/i', '$1 ' . self::MASK, $rText);
		// Unquoted key=value / key: value / JSON-ish key => value.
		$rText = (string) preg_replace(
			'/\b((?:[a-z0-9]+_)*(?:password|passwd|pwd|pass|secret(?:[_ -]?prefix)?|token|api[_-]?key|access[_-]?key|username|user))(["\']?\s*(?:=>|[:=])\s*)([^&\s"\'\\\\,;)}]+)/i',
			'$1$2' . self::MASK,
			$rText
		);
		// Xtream paths: /live/<user>/<pass>/… and friends.
		$rText = (string) preg_replace('#/(live|movie|series|timeshift)/[^/\s?"\']+/[^/\s?"\']+/#i', '/$1/' . self::MASK . '/' . self::MASK . '/', $rText);
		return $rText;
	}

	/**
	 * Redact every string value of a row, keys untouched.
	 *
	 * @param array<string, mixed> $rRow
	 * @return array<string, mixed>
	 */
	public static function redactRow(array $rRow): array {
		foreach ($rRow as $rKey => $rValue) {
			if (is_string($rValue)) {
				$rRow[$rKey] = self::redact($rValue);
			}
		}
		return $rRow;
	}
}
