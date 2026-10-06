<?php

namespace XcVm\Streaming\Delivery;

use XcVm\Core\Cluster\ViewerKey;
use XcVm\Domain\Server\ProxyRoute;

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
	 * Tokenize a stream's on-disk HLS playlist (`STREAMS_PATH/<id>_.m3u8`, written
	 * by the stream's own ffmpeg or PHP producer). This is the pre-fanout client
	 * path, used only while fanout is switched off (FanoutMode): each segment
	 * (and an fMP4 init map) becomes a per-segment auth'd /hls/ URL that
	 * segment.php serves from STREAMS_PATH.
	 *
	 * @param array       $rSettings  Settings (encrypt_hls, allow_cdn_access, live_streaming_pass, secure_stream_tokens).
	 * @param string      $rM3U8      Path to the on-disk playlist.
	 * @return string|false Tokenized playlist, or false when it is missing or lists no segment.
	 */
	public static function generateHLS($rSettings, $rM3U8, $rUsername, $rPassword, $rStreamID, $rUUID, $rIP, $rIsHMAC = null, $rIdentifier = '', $rVideoCodec = 'h264', $rOnDemand = 0, $rServerID = null, $rProxyID = null) {
		if (!is_file($rM3U8)) {
			return false;
		}
		$rSource = (string) @file_get_contents($rM3U8);
		$rPrefix = ($rProxyID ? '/' . ProxyRoute::segment((int) $rProxyID, (int) $rServerID) : '');
		$rSecure = !empty($rSettings['secure_stream_tokens']);
		$rURL = static function (string $rSegment) use ($rSettings, $rUsername, $rPassword, $rStreamID, $rUUID, $rIP, $rIsHMAC, $rIdentifier, $rVideoCodec, $rOnDemand, $rPrefix, $rSecure): string {
			// segment.php splits a link on '/' and reads its fields by position: a
			// viewer's names are written encoded, so one that holds a '/' moves none.
			if ($rIsHMAC) {
				$rPayload = 'HMAC#' . $rIsHMAC . '/' . rawurlencode((string) $rIdentifier) . '/' . $rIP . '/' . $rStreamID . '/' . $rSegment . '/' . $rUUID . '/' . SERVER_ID . '/' . $rVideoCodec . '/' . $rOnDemand;
			} else {
				$rPayload = rawurlencode((string) $rUsername) . '/' . rawurlencode((string) $rPassword) . '/' . $rIP . '/' . $rStreamID . '/' . $rSegment . '/' . $rUUID . '/' . SERVER_ID . '/' . $rVideoCodec . '/' . $rOnDemand;
			}
			$rToken = ViewerKey::mintOwn($rPayload, $rSettings, $rSecure);
			return !empty($rSettings['allow_cdn_access'])
				? $rPrefix . '/hls/' . $rSegment . '?token=' . $rToken
				: $rPrefix . '/hls/' . $rToken;
		};

		// Segment lines, one at a time (a blanket str_replace of each name also
		// rewrote any other line that contained it, e.g. 1_1.ts inside 11_1.ts).
		$rReplaced = 0;
		$rSource = preg_replace_callback('/^([^#\r\n][^\r\n]*\.(?:ts|m4s))$/m', static function ($rM) use ($rURL, &$rReplaced) {
			$rReplaced++;
			return $rURL(basename(trim($rM[1])));
		}, $rSource);
		if ($rReplaced === 0) {
			return false;
		}

		$rSource = preg_replace_callback('/#EXT-X-MAP:URI="([^"]+)"/', static function ($rM) use ($rURL) {
			return '#EXT-X-MAP:URI="' . $rURL(basename($rM[1])) . '"';
		}, $rSource);

		if (!empty($rSettings['encrypt_hls'])) {
			$rIVFile = STREAMS_PATH . intval($rStreamID) . '_.iv';
			if (is_file($rIVFile)) {
				$rKeyToken = ViewerKey::mintOwn($rIP . '/' . $rStreamID, $rSettings, $rSecure);
				$rKeyLine = '#EXT-X-KEY:METHOD=AES-128,URI="' . $rPrefix . '/key/' . $rKeyToken . '",IV=0x' . bin2hex((string) file_get_contents($rIVFile));
				$rSource = preg_replace('/(#EXTM3U\r?\n)/', '$1' . $rKeyLine . "\n", $rSource, 1);
			}
		}

		return $rSource;
	}

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
	public static function tokenizeDaemonPlaylist(string $rPlaylist, $rSettings, $rUsername, $rPassword, $rStreamID, $rUUID, $rIP, $rIsHMAC, $rIdentifier, $rVideoCodec, $rOnDemand, $rServerID, $rProxyID) {
		$rPrefix = ($rProxyID ? '/' . ProxyRoute::segment((int) $rProxyID, (int) $rServerID) : '');
		$rReplaced = 0;

		$rSource = preg_replace_callback(
			'/^(\d+)\.ts$/m',
			function ($rM) use ($rSettings, $rUsername, $rPassword, $rStreamID, $rUUID, $rIP, $rIsHMAC, $rIdentifier, $rVideoCodec, $rOnDemand, $rPrefix, &$rReplaced) {
				$rReplaced++;
				$rSegName = intval($rStreamID) . '_d' . $rM[1] . '.ts';
				// The viewer's names encoded, as in generateHLS().
				if ($rIsHMAC) {
					$rPayload = 'HMAC#' . $rIsHMAC . '/' . rawurlencode((string) $rIdentifier) . '/' . $rIP . '/' . $rStreamID . '/' . $rSegName . '/' . $rUUID . '/' . SERVER_ID . '/' . $rVideoCodec . '/' . $rOnDemand;
				} else {
					$rPayload = rawurlencode((string) $rUsername) . '/' . rawurlencode((string) $rPassword) . '/' . $rIP . '/' . $rStreamID . '/' . $rSegName . '/' . $rUUID . '/' . SERVER_ID . '/' . $rVideoCodec . '/' . $rOnDemand;
				}
				return $rPrefix . '/hls/' . ViewerKey::mintOwn($rPayload, $rSettings);
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
				$rKeyToken = ViewerKey::mintOwn($rIP . '/' . $rStreamID, $rSettings);
				$rKeyLine = '#EXT-X-KEY:METHOD=AES-128,URI="' . $rPrefix . '/key/' . $rKeyToken . '",IV=0x' . bin2hex((string) file_get_contents($rIVFile));
				$rSource = preg_replace('/(#EXTM3U\r?\n)/', '$1' . $rKeyLine . "\n", $rSource, 1);
			}
		}

		return $rSource;
	}
}
