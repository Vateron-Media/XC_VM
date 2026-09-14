<?php

namespace XcVm\Streaming\Delivery;

use XcVm\Core\Util\Encryption;

/**
 * HLSGenerator — turns the xc_fanout daemon's in-RAM HLS playlist into the
 * per-viewer, token-authenticated playlist a client receives.
 *
 * @package XC_VM_Streaming_Delivery
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class HLSGenerator {
	/**
	 * Tokenize the xc_fanout daemon's in-RAM HLS playlist (ADR 0003, Phase B).
	 * The daemon lists plain segments by sequence (`<seq>.ts`); each is rewritten
	 * into a per-segment auth'd URL with a segment name marked as a daemon segment
	 * (`<id>_d<seq>.ts`), which segment.php proxies from the daemon's RAM. When
	 * encrypt_hls is on, the daemon serves AES-128-CBC segments (it was given the
	 * stream's key/iv at ingest registration) and the #EXT-X-KEY line is added here.
	 *
	 * @param string $rPlaylist Raw daemon m3u8.
	 * @return string|false Tokenized playlist, or false if it has no segments.
	 */
	public static function tokenizeDaemonPlaylist($rPlaylist, $rSettings, $rUsername, $rPassword, $rStreamID, $rUUID, $rIP, $rIsHMAC, $rIdentifier, $rVideoCodec, $rOnDemand, $rServerID, $rProxyID) {
		$rPrefix = ($rProxyID ? '/' . md5($rProxyID . '_' . $rServerID . '_' . OPENSSL_EXTRA) : '');
		$rReplaced = 0;

		$rSource = preg_replace_callback(
			'/^(\d+)\.ts$/m',
			function ($rM) use ($rSettings, $rUsername, $rPassword, $rStreamID, $rUUID, $rIP, $rIsHMAC, $rIdentifier, $rVideoCodec, $rOnDemand, $rPrefix, &$rReplaced) {
				$rReplaced++;
				$rSegName = intval($rStreamID) . '_d' . $rM[1] . '.ts';
				if ($rIsHMAC) {
					$rPayload = 'HMAC#' . $rIsHMAC . '/' . $rIdentifier . '/' . $rIP . '/' . $rStreamID . '/' . $rSegName . '/' . $rUUID . '/' . SERVER_ID . '/' . $rVideoCodec . '/' . $rOnDemand;
				} else {
					$rPayload = $rUsername . '/' . $rPassword . '/' . $rIP . '/' . $rStreamID . '/' . $rSegName . '/' . $rUUID . '/' . SERVER_ID . '/' . $rVideoCodec . '/' . $rOnDemand;
				}
				return $rPrefix . '/hls/' . Encryption::encrypt($rPayload, $rSettings['live_streaming_pass'], OPENSSL_EXTRA);
			},
			$rPlaylist
		);

		if ($rReplaced === 0) {
			return false;
		}

		// Keep MEDIA-SEQUENCE monotonic across the off-air ↔ live transition. The
		// off-air placeholder numbers its loop floor(time()/10) (~1.7e8) while the
		// daemon restarts its own counter from 0 per stream, so a cold on-demand
		// start would drop the sequence by ~10^8 and stall players. Re-anchor the
		// live sequence to the same wall-clock base via a persisted per-stream offset
		// so it only ever advances (see HlsSequence).
		if (preg_match('/#EXT-X-MEDIA-SEQUENCE:(\d+)/', $rSource, $rSeqMatch)) {
			$rTarget = preg_match('/#EXT-X-TARGETDURATION:(\d+)/', $rSource, $rTargetMatch) ? max(1, (int) $rTargetMatch[1]) : HlsSequence::SEG;
			$rSeq = HlsSequence::liveSequence((int) $rStreamID, (int) $rSeqMatch[1], $rTarget);
			$rSource = preg_replace('/#EXT-X-MEDIA-SEQUENCE:\d+/', '#EXT-X-MEDIA-SEQUENCE:' . $rSeq, $rSource, 1);
		}

		// Encrypted HLS: the daemon serves AES-128-CBC segments (it was given the
		// same key/iv), so declare the key — URI to the /key token endpoint, IV
		// from the stream's iv file.
		if (!empty($rSettings['encrypt_hls'])) {
			$rIVFile = STREAMS_PATH . intval($rStreamID) . '_.iv';
			if (is_file($rIVFile)) {
				$rKeyToken = Encryption::encrypt($rIP . '/' . $rStreamID, $rSettings['live_streaming_pass'], OPENSSL_EXTRA);
				$rKeyLine = '#EXT-X-KEY:METHOD=AES-128,URI="' . $rPrefix . '/key/' . $rKeyToken . '",IV=0x' . bin2hex((string) file_get_contents($rIVFile));
				$rSource = preg_replace('/(#EXTM3U\r?\n)/', '$1' . $rKeyLine . "\n", $rSource, 1);
			}
		}

		return $rSource;
	}
}
