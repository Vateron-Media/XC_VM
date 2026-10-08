<?php

namespace XcVm\Core\Logging;

use XcVm\Core\Util\NetworkUtils;

/**
 * Refusal Log
 *
 * The client refusals core answered without writing a client log: a stream id
 * that does not exist (INVALID_STREAM_ID) and credentials that match no line
 * or device (INVALID_CREDENTIALS). Each now goes into the client logs
 * (DatabaseLogger::clientLog), so it reaches MAIN's `lines_logs` from every
 * server as the other refusals do, and an abuse detector can count scraping
 * and credential guessing per address.
 *
 * At most CAP a minute per address: past it the address is over every
 * threshold that reads these rows, and a flood must not fill `lines_logs`. The
 * query string is left out: on a refused sign-in it carries the password
 * tried. Never throws: logging a refusal must not change the answer.
 *
 * ```text
 * FLOOD_TMP_PATH/refusal_<sha256(ip)>   "<minute> <count>", this minute's count
 *                                    (swept by cron:tmp once idle)
 * ```
 */
final class RefusalLog {
	/** The refusals logged here; every other one keeps its own path. */
	public const CODES = ['INVALID_STREAM_ID', 'INVALID_CREDENTIALS'];

	/** Rows a minute per address. */
	public const CAP = 120;

	/** Tests: another directory for the counts; null restores FLOOD_TMP_PATH. */
	private static ?string $rDir = null;

	public static function useDir(?string $rDir): void {
		self::$rDir = $rDir;
	}

	private static function dir(): ?string {
		return self::$rDir ?? (defined('FLOOD_TMP_PATH') ? FLOOD_TMP_PATH : null);
	}

	public static function record(string $rCode, ?string $rIP = null): void {
		if (!in_array($rCode, self::CODES, true) || self::dir() === null) {
			return;
		}
		try {
			$rIP ??= NetworkUtils::getUserIP();
			if (filter_var($rIP, FILTER_VALIDATE_IP) === false || !self::counted($rIP, time())) {
				return;
			}
			DatabaseLogger::clientLog(0, 0, $rCode, $rIP, '', false, false);
		} catch (\Throwable) {
			// The refusal is answered as before.
		}
	}

	/** Count one for $rIP in its minute; false once the minute holds CAP. */
	public static function counted(string $rIP, int $rNow): bool {
		$rHandle = @fopen(self::dir() . 'refusal_' . hash('sha256', $rIP), 'c+');
		if ($rHandle === false) {
			return false;
		}
		try {
			flock($rHandle, LOCK_EX);
			[$rMinute, $rCount] = array_map('intval', explode(' ', trim((string) stream_get_contents($rHandle)) . ' 0'));
			$rNowMinute = intdiv($rNow, 60);
			$rCount = $rMinute === $rNowMinute ? $rCount + 1 : 1;
			if ($rCount <= self::CAP + 1) {
				ftruncate($rHandle, 0);
				rewind($rHandle);
				fwrite($rHandle, $rNowMinute . ' ' . $rCount);
			}
			return $rCount <= self::CAP;
		} finally {
			flock($rHandle, LOCK_UN);
			fclose($rHandle);
		}
	}
}
