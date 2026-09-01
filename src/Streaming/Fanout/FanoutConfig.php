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
	 * Map panel settings → daemon config keys. Values are clamped to the daemon's
	 * own ranges (see internal/config/config.go clamp() in the XC_VM_Fanout repo)
	 * so a bad panel value can never push the daemon into a pathological state.
	 *
	 * Derived (not raw settings):
	 *   hls_target_sec    ← seg_time (HLS segment duration, seconds; 1…30).
	 *   prebuffer_max_sec ← the ring must cover the largest per-viewer prebuffer AND
	 *                       the HLS window (hls_window · hls_target); floored at the
	 *                       daemon default 40, capped at its clamp (120).
	 * Direct panel-owned tuning (the `fanout_*` settings columns):
	 *   hls_window, grace_sec, write_timeout_sec, chunk_bytes, max_gop_bytes,
	 *   source_insecure, default_prebuffer_sec, idle_buffer_grace_sec,
	 *   idle_buffer_ratio.
	 *
	 * @param array $rSnapshot Current on-disk config (unused now the panel owns
	 *                         every key; kept for signature stability / future use).
	 */
	private static function desired(array $rSettings, array $rSnapshot): array {
		$rSegTime = self::clampInt((int) ($rSettings['seg_time'] ?? SettingsManager::get('seg_time', 6)), 1, 30);
		$rHlsWindow = self::clampInt((int) ($rSettings['fanout_hls_window'] ?? 6), 1, 20);

		$rClientPre = max(0, (int) ($rSettings['client_prebuffer'] ?? 0));
		$rRestrPre = max(0, (int) ($rSettings['restreamer_prebuffer'] ?? 0));
		$rRing = min(120, max(40, $rClientPre, $rRestrPre, $rHlsWindow * $rSegTime));

		$rRatio = (float) ($rSettings['fanout_idle_buffer_ratio'] ?? 0.5);
		if ($rRatio < 0.1) {
			$rRatio = 0.1;
		} elseif ($rRatio > 1.0) {
			$rRatio = 1.0;
		}

		return array(
			'prebuffer_max_sec'     => $rRing,
			'hls_target_sec'        => $rSegTime,
			'hls_window'            => $rHlsWindow,
			'grace_sec'             => self::clampInt((int) ($rSettings['fanout_grace_sec'] ?? 10), 1, 3600),
			'write_timeout_sec'     => self::clampInt((int) ($rSettings['fanout_write_timeout_sec'] ?? 15), 1, 600),
			'chunk_bytes'           => self::clampInt((int) ($rSettings['fanout_chunk_bytes'] ?? 12032), 188, 4194304),
			'max_gop_bytes'         => self::clampInt((int) ($rSettings['fanout_max_gop_bytes'] ?? 10528000), 188, 268435456),
			'source_insecure'       => (bool) ($rSettings['fanout_source_insecure'] ?? true),
			'default_prebuffer_sec' => self::clampInt((int) ($rSettings['fanout_default_prebuffer_sec'] ?? 0), 0, 120),
			'idle_buffer_grace_sec' => self::clampInt((int) ($rSettings['fanout_idle_buffer_grace_sec'] ?? 30), 0, 3600),
			'idle_buffer_ratio'     => $rRatio,
		);
	}

	/** Clamp an int into [lo, hi]. */
	private static function clampInt(int $rValue, int $rLo, int $rHi): int {
		if ($rValue < $rLo) {
			return $rLo;
		}
		if ($rValue > $rHi) {
			return $rHi;
		}
		return $rValue;
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
