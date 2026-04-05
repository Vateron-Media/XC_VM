<?php

/**
 * SignalSender — signal sender
 *
 * @package XC_VM_Streaming_Delivery
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class SignalSender {
	public static function sendSignal($rFFMPEG_CPU, $rSignalData, $rSegmentFile, $rCodec = 'h264', $rReturn = false) {
		if (empty($rSignalData['xy_offset'])) {
			$x = rand(150, 380);
			$y = rand(110, 250);
		} else {
			list($x, $y) = explode('x', $rSignalData['xy_offset']);
		}
		if ($rReturn) {
			$rOutput = SIGNALS_TMP_PATH . $rSignalData['activity_id'] . '_' . $rSegmentFile;
			shell_exec($rFFMPEG_CPU . ' -copyts -vsync 0 -nostats -nostdin -hide_banner -loglevel quiet -y -i ' . escapeshellarg(STREAMS_PATH . $rSegmentFile) . ' -filter_complex "drawtext=fontfile=' . FFMPEG_FONT . ":text='" . escapeshellcmd($rSignalData['message']) . "':fontsize=" . escapeshellcmd($rSignalData['font_size']) . ':x=' . intval($x) . ':y=' . intval($y) . ':fontcolor=' . escapeshellcmd($rSignalData['font_color']) . '" -map 0 -vcodec ' . $rCodec . ' -preset ultrafast -acodec copy -scodec copy -mpegts_flags +initial_discontinuity -mpegts_copyts 1 -f mpegts ' . escapeshellarg($rOutput));
			$rData = file_get_contents($rOutput);
			unlink($rOutput);
			return $rData;
		}
		passthru($rFFMPEG_CPU . ' -copyts -vsync 0 -nostats -nostdin -hide_banner -loglevel quiet -y -i ' . escapeshellarg(STREAMS_PATH . $rSegmentFile) . ' -filter_complex "drawtext=fontfile=' . FFMPEG_FONT . ":text='" . escapeshellcmd($rSignalData['message']) . "':fontsize=" . escapeshellcmd($rSignalData['font_size']) . ':x=' . intval($x) . ':y=' . intval($y) . ':fontcolor=' . escapeshellcmd($rSignalData['font_color']) . '" -map 0 -vcodec ' . $rCodec . ' -preset ultrafast -acodec copy -scodec copy -mpegts_flags +initial_discontinuity -mpegts_copyts 1 -f mpegts -');
		return true;
	}
}
