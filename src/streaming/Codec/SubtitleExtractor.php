<?php

class SubtitleExtractor {
	public static function extractSubtitle($rFFMPEG_CPU, $rSettings, $rStreamID, $rSourceURL, $rIndex) {
		$rTimeout = 10;
		$rCommand = 'timeout ' . $rTimeout . ' ' . $rFFMPEG_CPU . ' -y -nostdin -hide_banner -loglevel ' . (($rSettings['ffmpeg_warnings'] ? 'warning' : 'error')) . ' -err_detect ignore_err -i "' . $rSourceURL . '" -map 0:s:' . intval($rIndex) . ' ' . VOD_PATH . intval($rStreamID) . '_' . intval($rIndex) . '.srt';
		exec($rCommand, $rOutput);
		if (file_exists(VOD_PATH . intval($rStreamID) . '_' . intval($rIndex) . '.srt')) {
			if (filesize(VOD_PATH . intval($rStreamID) . '_' . intval($rIndex) . '.srt') != 0) {
				return true;
			}
			unlink(VOD_PATH . intval($rStreamID) . '_' . intval($rIndex) . '.srt');
			return false;
		}
		return false;
	}
}
