<?php

namespace XcVm\Streaming\Delivery;

/**
 * HlsSequence — keeps a stream's HLS `#EXT-X-MEDIA-SEQUENCE` monotonic across the
 * off-air ↔ live transition.
 *
 * Two independent producers number the same client-facing playlist URL:
 *   - the off-air placeholder ({@see OffAirHandler::showNotOnAir()}) numbers its
 *     loop `floor(time() / SEG)` — a large, wall-clock-advancing value (~1.7e8);
 *   - the live playlist comes from the xc_fanout daemon, whose segment counter
 *     restarts from 0 for each freshly (re)started stream.
 *
 * On an on-demand cold start a player therefore saw the off-air sequence (~1.7e8)
 * and then the live one (~1) — a ~10^8 BACKWARD jump, which HLS forbids
 * (MEDIA-SEQUENCE must never decrease), stalling the player during warm-up.
 *
 * This re-anchors the live sequence to the same wall-clock base via a tiny
 * persisted per-stream offset, so the published value only ever advances: it stays
 * ≥ the off-air line and ≥ whatever the last live window showed, while still
 * incrementing by the daemon's own per-segment delta so segment identity is
 * preserved. The off-air handler is left untouched (it has no stream id and stays
 * stream-agnostic); anchoring only the live side is enough for a smooth transition.
 *
 * @package XC_VM_Streaming_Delivery
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class HlsSequence {
	/**
	 * Segment duration (seconds) the wall-clock base is measured in. MUST match the
	 * off-air placeholder's sequence divisor / `#EXT-X-TARGETDURATION` in
	 * {@see OffAirHandler::showNotOnAir()} so the live and off-air playlists share
	 * one wall-clock-aligned sequence line.
	 */
	private const SEG = 10;

	/**
	 * The published live MEDIA-SEQUENCE for a stream: the daemon's 0-based segment
	 * counter re-anchored to the off-air wall-clock base so it never drops below
	 * what a player last saw. Persists a tiny per-stream `{base,last}` file under
	 * STREAMS_PATH, guarded by flock for concurrent viewers. On any I/O failure (or
	 * before STREAMS_PATH is defined) it degrades to a time-aligned value that is
	 * still ≥ the off-air line, never throwing.
	 *
	 * @param int $rStreamID Stream id.
	 * @param int $rDaemonSeq The daemon playlist's own `#EXT-X-MEDIA-SEQUENCE`.
	 * @return int The monotonic, off-air-aligned sequence to publish.
	 */
	public static function liveSequence(int $rStreamID, int $rDaemonSeq): int {
		$rFloor = intdiv(time(), self::SEG);
		if (!defined('STREAMS_PATH')) {
			return max($rDaemonSeq, $rFloor);
		}

		$rFile = STREAMS_PATH . $rStreamID . '_.hlsseq';
		$rFp = @fopen($rFile, 'c+');
		if ($rFp === false) {
			return max($rDaemonSeq, $rFloor);
		}

		try {
			@flock($rFp, LOCK_EX);
			$rRaw = stream_get_contents($rFp);
			$rState = (is_string($rRaw) && $rRaw !== '') ? json_decode($rRaw, true) : null;

			[$rSeq, $rNext] = self::reconcile($rDaemonSeq, $rFloor, is_array($rState) ? $rState : null);

			ftruncate($rFp, 0);
			rewind($rFp);
			fwrite($rFp, (string) json_encode($rNext));
			fflush($rFp);

			return $rSeq;
		} finally {
			@flock($rFp, LOCK_UN);
			fclose($rFp);
		}
	}

	/**
	 * Pure re-anchoring step (no I/O): given the daemon's current sequence, the
	 * off-air wall-clock floor (`floor(time()/SEG)`) and the prior persisted state
	 * (`{base,last}`, or null on first use), return `[publishedSequence, newState]`.
	 *
	 * Invariants:
	 *   - the published sequence never decreases (≥ prior `last`);
	 *   - it never drops below the off-air floor (so off-air → live is smooth);
	 *   - while the daemon counter runs uninterrupted, `base` stays fixed, so the
	 *     published value advances by exactly the daemon's per-segment delta.
	 * A daemon restart (counter reset to 0) or a stale/low state only ever bumps
	 * `base` UP, so the sequence keeps climbing — never a backward step.
	 *
	 * @param array{base?:int,last?:int}|null $rState
	 * @return array{0:int,1:array{base:int,last:int}}
	 */
	public static function reconcile(int $rDaemonSeq, int $rFloor, ?array $rState): array {
		$rBase = isset($rState['base']) ? (int) $rState['base'] : 0;
		$rLast = isset($rState['last']) ? (int) $rState['last'] : 0;

		$rSeq = $rDaemonSeq + $rBase;
		$rMin = max($rLast, $rFloor);
		if ($rSeq < $rMin) {
			$rBase = $rMin - $rDaemonSeq; // re-anchor: first on-air, daemon restart, or a time skip
			$rSeq = $rMin;
		}

		return [$rSeq, ['base' => $rBase, 'last' => $rSeq]];
	}
}
