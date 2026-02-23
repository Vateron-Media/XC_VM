<?php

class FFprobeRunner {
	public static function probeStream($rSettings, $rFFPROBE, $rSourceURL, $rFetchArguments = array(), $rPrepend = '', $rParse = true, $rParseCallback = null) {
		$rAnalyseDuration = abs(intval($rSettings['stream_max_analyze']));
		$rProbesize = abs(intval($rSettings['probesize']));
		$rTimeout = intval($rAnalyseDuration / 1000000) + $rSettings['probe_extra_wait'];
		if (!is_array($rFetchArguments)) {
			$rFetchArguments = !empty($rFetchArguments) ? [$rFetchArguments] : [];
		}
		$rCommand = $rPrepend . 'timeout ' . $rTimeout . ' ' . $rFFPROBE . ' -probesize ' . $rProbesize . ' -analyzeduration ' . $rAnalyseDuration . ' ' . implode(' ', $rFetchArguments) . ' -i "' . $rSourceURL . '" -v quiet -print_format json -show_streams -show_format';
		exec($rCommand, $rReturn);
		$result = implode("\n", $rReturn);
		if ($rParse) {
			return call_user_func($rParseCallback, json_decode($result, true));
		}
		return json_decode($result, true);
	}

	public static function parseFFProbe($rCodecs) {
		if (empty($rCodecs)) {
			return false;
		}
		if (empty($rCodecs['codecs'])) {
			$rOutput = array();
			$rOutput['codecs']['video'] = '';
			$rOutput['codecs']['audio'] = '';
			$rOutput['container'] = $rCodecs['format']['format_name'];
			$rOutput['filename'] = $rCodecs['format']['filename'];
			$rOutput['bitrate'] = (!empty($rCodecs['format']['bit_rate']) ? $rCodecs['format']['bit_rate'] : null);
			$rOutput['of_duration'] = (!empty($rCodecs['format']['duration']) ? $rCodecs['format']['duration'] : 'N/A');
			$rOutput['duration'] = (!empty($rCodecs['format']['duration']) ? gmdate('H:i:s', intval($rCodecs['format']['duration'])) : 'N/A');
			foreach ($rCodecs['streams'] as $rCodec) {
				if (isset($rCodec['codec_type']) && !($rCodec['codec_type'] != 'audio' && $rCodec['codec_type'] != 'video' && $rCodec['codec_type'] != 'subtitle')) {
					if ($rCodec['codec_type'] == 'audio' || $rCodec['codec_type'] == 'video') {
						if (!empty($rOutput['codecs'][$rCodec['codec_type']])) {
						} else {
							$rOutput['codecs'][$rCodec['codec_type']] = $rCodec;
						}
					} else {
						if ($rCodec['codec_type'] != 'subtitle') {
						} else {
							if (isset($rOutput['codecs'][$rCodec['codec_type']])) {
							} else {
								$rOutput['codecs'][$rCodec['codec_type']] = array();
							}
							$rOutput['codecs'][$rCodec['codec_type']][] = $rCodec;
						}
					}
				}
			}
			return $rOutput;
		}
		return $rCodecs;
	}
}
