<?php

namespace XcVm\Streaming\Fanout;

use XcVm\Core\Config\SettingsManager;

/**
 * FanoutConfig — writes the xc_fanout daemon's operator-tuning `config.json` from
 * panel settings.
 *
 * The daemon owns its config file: it self-creates it with built-in defaults and
 * backfills any key it later adds, then polls it and applies changes live
 * (mtime-gated, no restart, no viewer drop). So the panel must NOT overwrite the
 * whole file — a key the daemon added that the panel does not know about would be
 * lost. Instead it does a read-modify-write on a JSON snapshot: read the current
 * config, overlay only the keys the panel owns, write it back atomically. Keys the
 * panel does not manage are preserved untouched.
 *
 * Per-viewer prebuffer (`client_prebuffer` / `restreamer_prebuffer`) is NOT written
 * here — the panel passes it per request in the internal `?prebuffer=` X-Accel URL;
 * this file carries only the daemon's global tuning (the ring size + HLS shape).
 *
 * @package XC_VM_Streaming_Fanout
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class FanoutConfig {
	/** Absolute path to the daemon's tuning config on THIS node. */
	private static function path(): string {
		return BIN_PATH . 'xc_fanout/config.json';
	}

	/**
	 * Read-modify-write: overlay the panel-owned keys derived from $rSettings onto
	 * the current config snapshot and write it back atomically. Returns true when
	 * the file was (re)written, false when unchanged or not applicable. Never
	 * throws — a config write must never break a settings save.
	 *
	 * @param array $rSettings The just-saved settings (or SettingsManager::getAll()).
	 */
	public static function sync(array $rSettings): bool {
		$rPath = self::path();
		if (!is_dir(dirname($rPath))) {
			return false; // no daemon tree on this node — nothing to write
		}

		// Snapshot the current config (empty on a missing/torn file — the daemon
		// will re-seed the rest of the schema on its next load).
		$rSnapshot = array();
		if (is_file($rPath)) {
			$rDecoded = json_decode((string) @file_get_contents($rPath), true);
			if (is_array($rDecoded)) {
				$rSnapshot = $rDecoded;
			}
		}

		$rDesired = self::desired($rSettings, $rSnapshot);

		// Skip the write when nothing the panel owns actually changed (avoids mtime
		// churn that would make the daemon re-apply needlessly). Loose compare so an
		// int (6) never differs from a JSON float (6.0).
		$rChanged = false;
		foreach ($rDesired as $rKey => $rValue) {
			if (!array_key_exists($rKey, $rSnapshot) || $rSnapshot[$rKey] != $rValue) {
				$rChanged = true;
				break;
			}
		}
		if (!$rChanged) {
			return false;
		}

		return self::writeAtomic($rPath, array_merge($rSnapshot, $rDesired));
	}

	/**
	 * Map panel settings → daemon config keys:
	 *   hls_target_sec    ← seg_time (HLS segment duration, seconds).
	 *   prebuffer_max_sec ← the ring must cover the largest per-viewer prebuffer
	 *                       AND the HLS window (hls_window · hls_target); floored at
	 *                       the daemon default 40, capped at its clamp (120).
	 * hls_window / grace / idle_buffer_ratio / default_prebuffer_sec / chunk_bytes …
	 * stay whatever the daemon put there — preserved by the read-modify-write.
	 */
	private static function desired(array $rSettings, array $rSnapshot): array {
		$rSegTime = (int) ($rSettings['seg_time'] ?? SettingsManager::get('seg_time', 6));
		if ($rSegTime < 1) {
			$rSegTime = 6;
		}
		$rClientPre = max(0, (int) ($rSettings['client_prebuffer'] ?? 0));
		$rRestrPre = max(0, (int) ($rSettings['restreamer_prebuffer'] ?? 0));
		$rHlsWindow = (int) ($rSnapshot['hls_window'] ?? 6);
		if ($rHlsWindow < 1) {
			$rHlsWindow = 6;
		}

		$rRing = max(40, $rClientPre, $rRestrPre, $rHlsWindow * $rSegTime);
		$rRing = min(120, $rRing);

		return array(
			'hls_target_sec'    => $rSegTime,
			'prebuffer_max_sec' => $rRing,
		);
	}

	/** Atomic write (temp + rename) so the polling daemon never reads a torn file. */
	private static function writeAtomic(string $rPath, array $rConfig): bool {
		$rJson = json_encode($rConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($rJson === false) {
			return false;
		}
		$rTmp = $rPath . '.tmp';
		if (@file_put_contents($rTmp, $rJson . "\n", LOCK_EX) === false) {
			return false;
		}
		if (!@rename($rTmp, $rPath)) {
			@unlink($rTmp);
			return false;
		}
		return true;
	}
}
