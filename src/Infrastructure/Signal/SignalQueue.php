<?php

namespace XcVm\Infrastructure\Signal;

/**
 * SignalQueue — a tiny cross-process queue for deferred work.
 *
 * A producer on the hot request path drops a small `[key, data]` record; the
 * background cache-handler daemon later drains the queue and applies each one
 * (an ISP/forced_country/expiring DB write, a flood/bruteforce block, …), so
 * the request itself never blocks on those writes.
 *
 * Backing store: one file per signal under SIGNALS_TMP_PATH, named
 * `cache_<md5(key)>` and holding `json_encode([key, data])`. The key's first
 * '/'-separated segment selects the consumer action, e.g. `isp/<lineId>` or
 * `forced_country/<lineId>`.
 *
 * @package XC_VM_Infrastructure_Signal
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class SignalQueue {

	/** Filename prefix shared by every queued signal. */
	public const PREFIX = 'cache_';

	/**
	 * Filesystem path of the signal file backing $rKey.
	 *
	 * @param string $rKey Signal key.
	 * @return string Absolute path under SIGNALS_TMP_PATH.
	 */
	public static function pathFor(string $rKey): string {
		return SIGNALS_TMP_PATH . self::PREFIX . md5($rKey);
	}

	/**
	 * Queue a signal for the background worker. Re-queuing the same key
	 * overwrites the pending record (idempotent per key).
	 *
	 * @param string $rKey  Signal key; its first '/'-segment routes the action.
	 * @param mixed  $rData JSON-encodable payload.
	 * @return void
	 */
	public static function push(string $rKey, $rData): void {
		file_put_contents(self::pathFor($rKey), json_encode(array($rKey, $rData)));
	}

	/**
	 * Every queued signal as a `[file, key, data]` tuple (filesystem order).
	 * Malformed records are skipped. The caller deletes each file once handled.
	 *
	 * @return array<int,array{0:string,1:string,2:mixed}>
	 */
	public static function pending(): array {
		$rOut = array();
		foreach (glob(SIGNALS_TMP_PATH . self::PREFIX . '*') ?: array() as $rFile) {
			$rDecoded = json_decode((string) @file_get_contents($rFile), true);
			if (is_array($rDecoded) && array_key_exists(0, $rDecoded) && array_key_exists(1, $rDecoded)) {
				$rOut[] = array($rFile, $rDecoded[0], $rDecoded[1]);
			}
		}
		return $rOut;
	}
}
