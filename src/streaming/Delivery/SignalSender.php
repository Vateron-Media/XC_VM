<?php

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
