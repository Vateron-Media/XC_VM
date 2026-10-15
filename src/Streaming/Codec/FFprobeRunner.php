<?php

namespace XcVm\Streaming\Codec;

/**
 * FFprobeRunner — f fprobe runner
 *
 * @package XC_VM_Streaming_Codec
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class FFprobeRunner {
	public static function probeStream($rSourceURL, $rFetchArguments = [], $rPrepend = '', $rParse = true) {
		global $rSettings, $rFFPROBE;
		$rAnalyseDuration = abs(intval($rSettings['stream_max_analyze']));
		$rProbesize = abs(intval($rSettings['probesize']));
		$rTimeout = intval($rAnalyseDuration / 1000000) + $rSettings['probe_extra_wait'];
		if (!is_array($rFetchArguments)) {
			$rFetchArguments = !empty($rFetchArguments) ? [$rFetchArguments] : [];
		}
		$rCommand = $rPrepend . 'timeout ' . $rTimeout . ' ' . $rFFPROBE . ' -probesize ' . $rProbesize . ' -analyzeduration ' . $rAnalyseDuration . ' ' . implode(' ', $rFetchArguments) . ' -i ' . escapeshellarg((string) $rSourceURL) . ' -v quiet -print_format json -show_streams -show_format';
		exec($rCommand, $rReturn);
		$result = implode("\n", $rReturn);
		if ($rParse) {
			return self::parseFFProbe(json_decode($result, true));
		}
		return json_decode($result, true);
	}

	public static function parseFFProbe($rCodecs) {
		if (empty($rCodecs)) {
			return false;
		}
		if (empty($rCodecs['codecs'])) {
			$rOutput = [];
			$rOutput['codecs']['video'] = '';
			$rOutput['codecs']['audio'] = '';
			$rOutput['container'] = $rCodecs['format']['format_name'];
			$rOutput['filename'] = $rCodecs['format']['filename'];
			$rOutput['bitrate'] = (!empty($rCodecs['format']['bit_rate']) ? $rCodecs['format']['bit_rate'] : null);
			$rOutput['of_duration'] = (!empty($rCodecs['format']['duration']) ? $rCodecs['format']['duration'] : 'N/A');
			$rOutput['duration'] = (!empty($rCodecs['format']['duration']) ? gmdate('H:i:s', intval($rCodecs['format']['duration'])) : 'N/A');
			// A master/adaptive playlist (HLS) exposes every rendition as its own
			// video stream. Keep the TOP rendition (highest picture) so the stored
			// resolution reflects what a player actually gets, not the first — often
			// the lowest — variant. Audio keeps the first stream; subtitles accumulate.
			$rVideoStreams = 0;
			$rBestHeight = -1;
			foreach ($rCodecs['streams'] as $rCodec) {
				if (!isset($rCodec['codec_type'])) {
					continue;
				}
				if ($rCodec['codec_type'] == 'video') {
					$rVideoStreams++;
					$rHeight = intval($rCodec['height'] ?? 0);
					if ($rHeight > $rBestHeight) {
						$rBestHeight = $rHeight;
						$rOutput['codecs']['video'] = $rCodec;
					}
				} elseif ($rCodec['codec_type'] == 'audio') {
					if (empty($rOutput['codecs']['audio'])) {
						$rOutput['codecs']['audio'] = $rCodec;
					}
				} elseif ($rCodec['codec_type'] == 'subtitle') {
					if (!isset($rOutput['codecs']['subtitle'])) {
						$rOutput['codecs']['subtitle'] = [];
					}
					$rOutput['codecs']['subtitle'][] = $rCodec;
				}
			}
			// More than one video rendition means format.bit_rate is the SUM of all
			// of them, not the rate any single viewer pulls. Leaving it in makes the
			// per-viewer divergence check (DivergenceSink) flag healthy viewers, so
			// drop it: an unknown bitrate disables that check (expected() == 0).
			if ($rVideoStreams > 1) {
				$rOutput['adaptive'] = true;
				$rOutput['bitrate'] = null;
			}
			return $rOutput;
		}
		return $rCodecs;
	}
}
