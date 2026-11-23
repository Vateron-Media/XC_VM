<?php

namespace XcVm\Streaming\Delivery;

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
		if (basename((string) $rSegmentFile) !== (string) $rSegmentFile) {
			return $rReturn ? false : true;
		}
		$rRoot = realpath(STREAMS_PATH);
		$rInput = realpath(STREAMS_PATH . basename((string) $rSegmentFile));
		if ($rRoot === false || $rInput === false || !str_starts_with($rInput, $rRoot . DIRECTORY_SEPARATOR)) {
			return $rReturn ? false : true;
		}
		if (!is_executable($rFFMPEG_CPU) || !preg_match('/^[a-zA-Z0-9_]+$/D', (string) $rCodec)) {
			return $rReturn ? false : true;
		}
		if (empty($rSignalData['xy_offset'])) {
			$x = rand(150, 380);
			$y = rand(110, 250);
		} else {
			[$x, $y] = array_pad(explode('x', (string) $rSignalData['xy_offset'], 2), 2, 0);
		}
		$rFontSize = filter_var($rSignalData['font_size'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 256]]);
		$rColor = (string) ($rSignalData['font_color'] ?? 'white');
		if ($rFontSize === false || !preg_match('/^(?:#[0-9a-f]{6}|[a-z]+)(?:@[0-9.]+)?$/iD', $rColor)) {
			return $rReturn ? false : true;
		}

		$rMessage = str_replace(['\\', "'", ':', '%'], ['\\\\', "\\'", '\\:', '\\%'], (string) ($rSignalData['message'] ?? ''));
		$rFilter = "drawtext=fontfile=" . FFMPEG_FONT . ":text='" . $rMessage . "':fontsize=" . $rFontSize
			. ':x=' . intval($x) . ':y=' . intval($y) . ':fontcolor=' . $rColor;
		$rOutput = $rReturn ? tempnam(SIGNALS_TMP_PATH, '.signal-') : '-';
		if ($rOutput === false) {
			return $rReturn ? false : true;
		}
		$rArgv = [$rFFMPEG_CPU, '-copyts', '-vsync', '0', '-nostats', '-nostdin', '-hide_banner', '-loglevel', 'quiet', '-y',
			'-i', $rInput, '-filter_complex', $rFilter, '-map', '0', '-vcodec', $rCodec, '-preset', 'ultrafast', '-acodec', 'copy',
			'-scodec', 'copy', '-mpegts_flags', '+initial_discontinuity', '-mpegts_copyts', '1', '-f', 'mpegts', $rOutput];
		$rStdout = $rReturn ? ['file', '/dev/null', 'w'] : ['file', 'php://output', 'w'];
		$rProc = proc_open($rArgv, [0 => ['file', '/dev/null', 'r'], 1 => $rStdout, 2 => ['file', '/dev/null', 'w']], $rPipes);
		$rStatus = is_resource($rProc) ? proc_close($rProc) : 1;
		if ($rReturn) {
			$rData = $rStatus === 0 ? file_get_contents($rOutput) : false;
			@unlink($rOutput);
			return $rData;
		}
		return true;
	}
}
