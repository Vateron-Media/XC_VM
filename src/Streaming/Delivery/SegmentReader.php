<?php

namespace XcVm\Streaming\Delivery;

/**
 * SegmentReader — segment reader
 *
 * @package XC_VM_Streaming_Delivery
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class SegmentReader {
	public static function getLLODSegments($rStreamID, $rPlaylist, $rPrebuffer = 1) {
		$rPrebuffer++;
		$rSegments = $rKeySegments = array();
		if (!file_exists($rPlaylist)) {
			return null;
		}
		$rSource = file_get_contents($rPlaylist);
		if (!preg_match_all('/(.*?).ts((#\\w+)+|#?)/', $rSource, $rMatches)) {
			return null;
		}
		if (0 >= count($rMatches[1])) {
			return null;
		}
		$rLastKey = null;
		for ($i = 0; $i < count($rMatches[1]); $i++) {
			$rFilename = $rMatches[1][$i];
			list($rSID, $rSegmentID) = explode('_', $rFilename);
			if (!empty($rMatches[2][$i])) {
				$rKeySegments[$rSegmentID] = array();
				$rLastKey = $rSegmentID;
			}
			if ($rLastKey) {
				$rKeySegments[$rLastKey][] = $rSegmentID;
			}
		}
		$rKeySegments = array_slice($rKeySegments, count($rKeySegments) - $rPrebuffer, $rPrebuffer, true);
		foreach ($rKeySegments as $rKeySegment => $rSubSegments) {
			foreach ($rSubSegments as $rSegmentID) {
				$rSegments[] = $rStreamID . '_' . $rSegmentID . '.ts';
			}
		}
		return (!empty($rSegments) ? $rSegments : null);
	}

	public static function getPlaylistSegments($rPlaylist, $rPrebuffer = 0, $rSegmentDuration = 10) {
		if (!file_exists($rPlaylist)) {
			return null;
		}
		return self::selectSegments(file_get_contents($rPlaylist), $rPrebuffer, $rSegmentDuration);
	}

	/**
	 * Select segments from raw playlist content. Pure (no I/O) — unit testable.
	 *
	 * - $rPrebuffer > 0  : the newest segments whose #EXTINF durations sum to at
	 *   least $rPrebuffer seconds. Duration-based, so the buffer holds the
	 *   requested seconds even when segments are shorter than the nominal
	 *   $rSegmentDuration — e.g. the 2s fast-start segments an on-demand stream
	 *   emits before it ramps up to seg_time, which the old
	 *   intval($rPrebuffer / $rSegmentDuration) collapsed to a single ~2s
	 *   segment. Falls back to the nominal count when there are no #EXTINF lines.
	 * - $rPrebuffer == -1: every segment.
	 * - $rPrebuffer == 0 : the current (last) segment index only.
	 *
	 * @param string $rSource          Raw playlist text.
	 * @param int    $rPrebuffer       Seconds of prebuffer, -1 (all) or 0 (index).
	 * @param int    $rSegmentDuration Nominal segment length (count fallback).
	 * @return array<int,string>|string|null
	 */
	public static function selectSegments($rSource, $rPrebuffer = 0, $rSegmentDuration = 10) {
		$rSource = str_replace(array("\r\n", "\r"), "\n", $rSource);

		if (!preg_match_all('/(.*?)\.(ts|m4s)/', $rSource, $rMatches)) {
			return null;
		}

		if ($rPrebuffer == -1) {
			return $rMatches[0];
		}

		if (0 < $rPrebuffer) {
			if (strpos($rSource, '#EXTINF:') !== false) {
				$rPairs = self::parseSegmentDurations($rSource);
				$rAccum = 0.0;
				$rPicked = array();
				for ($i = count($rPairs) - 1; $i >= 0; $i--) {
					array_unshift($rPicked, $rPairs[$i][1]);
					$rAccum += $rPairs[$i][0];
					if ($rAccum >= $rPrebuffer) {
						break;
					}
				}
				return $rPicked;
			}
			// No #EXTINF durations: fall back to the nominal segment count.
			$rTotalSegments = intval($rPrebuffer / $rSegmentDuration) ?: 1;
			return array_slice($rMatches[0], 0 - $rTotalSegments);
		}

		preg_match('/_(.*)\./', array_pop($rMatches[0]), $rCurrentSegment);
		return $rCurrentSegment[1] ?? null;
	}

	/**
	 * Total buffered seconds currently in a playlist (sum of every #EXTINF), or
	 * 0.0 when the file is missing or carries no duration tags. Used to gate the
	 * on-demand prebuffer: a cold-started stream should serve a full buffer, not
	 * just the fast-start segments that exist the instant its playlist appears.
	 *
	 * @param string $rPlaylist Path to the playlist file.
	 * @return float Sum of segment durations in seconds.
	 */
	public static function playlistBufferedSeconds($rPlaylist) {
		if (!is_file($rPlaylist)) {
			return 0.0;
		}
		$rTotal = 0.0;
		$rSource = str_replace(array("\r\n", "\r"), "\n", (string) file_get_contents($rPlaylist));
		foreach (self::parseSegmentDurations($rSource) as $rPair) {
			$rTotal += $rPair[0];
		}
		return $rTotal;
	}

	/**
	 * Parse [duration, segment] pairs from playlist content, oldest first. A
	 * segment inherits the most recent preceding #EXTINF (0.0 when absent).
	 *
	 * @param string $rSource Raw playlist text (newline-normalised).
	 * @return array<int,array{0:float,1:string}>
	 */
	private static function parseSegmentDurations($rSource) {
		$rPairs = array();
		$rDuration = null;
		foreach (explode("\n", $rSource) as $rLine) {
			$rLine = trim($rLine);
			if (strncmp($rLine, '#EXTINF:', 8) === 0) {
				$rDuration = (float) substr($rLine, 8);
			} elseif ($rLine !== '' && $rLine[0] !== '#' && preg_match('/\.(ts|m4s)$/', $rLine)) {
				$rPairs[] = array($rDuration !== null ? $rDuration : 0.0, $rLine);
				$rDuration = null;
			}
		}
		return $rPairs;
	}
}
