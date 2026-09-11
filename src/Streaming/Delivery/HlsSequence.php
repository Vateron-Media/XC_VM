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
 * persisted per-stream offset, so the published value only ever advances, while
 * still incrementing by the daemon's own per-segment delta so segment identity is
 * preserved.
 *
 * The wall-clock floor is applied only where a player can actually have seen the
 * off-air loop: the first publish, a daemon counter that went backwards (a
 * restart), a publish gap long enough for the loop to have been served, or an
 * explicit {@see markOffAir()}. Applying it on every publish renumbered a stream
 * whose segments run longer than SEG seconds (seg_time above 10, or keyframes
 * that stretch segments past it): the daemon's counter falls behind the wall
 * clock, and each catch-up shifted every listed segment by one — players replayed
 * or skipped a segment several times a minute.
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
	 * {@see OffAirHandler::showVideoServer()} so the live and off-air playlists
	 * share one wall-clock-aligned sequence line.
	 */
	public const SEG = 10;

	/** A publish gap longer than this many target durations may hide an off-air loop. */
	private const STALE_TARGETS = 3;

	/** ...and never shorter than this many seconds. */
	private const STALE_MIN_SEC = 30;

	/**
	 * The published live MEDIA-SEQUENCE for a stream: the daemon's 0-based segment
	 * counter re-anchored to the off-air wall-clock base so it never drops below
	 * what a player last saw. Persists a tiny per-stream state file (see
	 * stateFile()), guarded by flock for concurrent viewers. On any I/O failure (or
	 * before the paths are defined) it degrades to a time-aligned value that is
	 * still ≥ the off-air line, never throwing.
	 *
	 * @param int $rStreamID  Stream id.
	 * @param int $rDaemonSeq The daemon playlist's own `#EXT-X-MEDIA-SEQUENCE`.
	 * @param int $rTarget    The daemon playlist's `#EXT-X-TARGETDURATION` (seconds).
	 * @return int The monotonic, off-air-aligned sequence to publish.
	 */
	public static function liveSequence(int $rStreamID, int $rDaemonSeq, int $rTarget = self::SEG): int {
		$rNow = time();
		$rFloor = intdiv($rNow, self::SEG);
		if (!defined('SIGNALS_TMP_PATH')) {
			return max($rDaemonSeq, $rFloor);
		}

		$rFp = @fopen(self::stateFile($rStreamID), 'c+');
		if ($rFp === false) {
			return max($rDaemonSeq, $rFloor);
		}

		try {
			@flock($rFp, LOCK_EX);
			$rRaw = stream_get_contents($rFp);
			$rState = (is_string($rRaw) && $rRaw !== '') ? json_decode($rRaw, true) : null;

			[$rSeq, $rNext] = self::reconcile($rDaemonSeq, $rFloor, is_array($rState) ? $rState : null, $rNow, $rTarget);

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
	 * Record that a player of this stream was just served the off-air loop, so the
	 * next live publish re-anchors at or above the loop's wall-clock sequence even
	 * when the daemon's counter ran on uninterrupted (a brief outage).
	 *
	 * @param int $rStreamID Stream id.
	 * @return void
	 */
	public static function markOffAir(int $rStreamID): void {
		if (!defined('SIGNALS_TMP_PATH')) {
			return;
		}
		$rFp = @fopen(self::stateFile($rStreamID), 'c+');
		if ($rFp === false) {
			return;
		}
		try {
			@flock($rFp, LOCK_EX);
			$rRaw = stream_get_contents($rFp);
			$rState = (is_string($rRaw) && $rRaw !== '') ? json_decode($rRaw, true) : null;
			if (!is_array($rState) || !isset($rState['at'])) {
				return; // no live publish to follow yet: the first one anchors anyway
			}
			$rState['at'] = 0;
			ftruncate($rFp, 0);
			rewind($rFp);
			fwrite($rFp, (string) json_encode($rState));
			fflush($rFp);
		} finally {
			@flock($rFp, LOCK_UN);
			fclose($rFp);
		}
	}

	/**
	 * Pure re-anchoring step (no I/O): given the daemon's current sequence, the
	 * off-air wall-clock floor (`floor(time()/SEG)`) and the prior persisted state,
	 * return `[publishedSequence, newState]`.
	 *
	 * Invariants:
	 *   - the published sequence never decreases (≥ prior `last`);
	 *   - wherever a player may have seen the off-air loop — first use, a daemon
	 *     restart, a stale or off-air-marked state, or when $rNow is not given — it
	 *     is at least the off-air floor;
	 *   - while the daemon counter runs uninterrupted and publishes stay fresh,
	 *     `base` stays fixed, so the published value advances by exactly the
	 *     daemon's per-segment delta — even when that is slower than the floor.
	 *
	 * @param array{base?:int,last?:int,daemon?:int,at?:int}|null $rState
	 * @param int|null $rNow    Current time, or null to always apply the floor.
	 * @param int      $rTarget Target segment duration (seconds), sizing the stale gap.
	 * @return array{0:int,1:array<string,int>}
	 */
	public static function reconcile(int $rDaemonSeq, int $rFloor, ?array $rState, ?int $rNow = null, int $rTarget = self::SEG): array {
		$rBase = isset($rState['base']) ? (int) $rState['base'] : 0;
		$rLast = isset($rState['last']) ? (int) $rState['last'] : 0;

		$rContinuous = $rNow !== null
			&& isset($rState['daemon'], $rState['at'])
			&& $rDaemonSeq >= (int) $rState['daemon']
			&& $rNow - (int) $rState['at'] <= max(self::STALE_MIN_SEC, self::STALE_TARGETS * max(1, $rTarget));

		$rSeq = $rDaemonSeq + $rBase;
		$rMin = $rContinuous ? $rLast : max($rLast, $rFloor);
		if ($rSeq < $rMin) {
			$rBase = $rMin - $rDaemonSeq; // re-anchor: first on-air, daemon restart, or back from off-air
			$rSeq = $rMin;
		}

		$rNext = array('base' => $rBase, 'last' => $rSeq);
		if ($rNow !== null) {
			$rNext['daemon'] = $rDaemonSeq;
			$rNext['at'] = $rNow;
		}
		return [$rSeq, $rNext];
	}

	/**
	 * The per-stream state lives with the other runtime markers, not beside the
	 * stream's files: a restart wipes `STREAMS_PATH/<id>_*`, and losing `last` there
	 * let the first publish after a restart step back under what a player that
	 * stayed connected had already seen. TmpCronJob ages it out once a stream has
	 * gone unwatched for ten minutes.
	 */
	private static function stateFile(int $rStreamID): string {
		return SIGNALS_TMP_PATH . 'hlsseq_' . $rStreamID;
	}
}
