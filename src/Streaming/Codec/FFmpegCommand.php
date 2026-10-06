<?php

namespace XcVm\Streaming\Codec;

use XcVm\Core\Process\ProcessRunner;
use XcVm\Domain\Stream\StreamProcess;

/**
 * FFmpegCommand — f fmpeg command
 *
 * @package XC_VM_Streaming_Codec
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class FFmpegCommand {
	public static function createChannelItem($rStreamID, $rSource) {
		return StreamProcess::createChannelItem($rStreamID, $rSource);
	}

	public static function extractSubtitle($rStreamID, $rSourceURL, $rIndex) {
		global $rSettings, $rFFMPEG_CPU;
		$rTimeout = 10;
		// The movie's path ends in its stored container: one argument, no shell.
		ProcessRunner::run(['timeout', (string) $rTimeout, (string) $rFFMPEG_CPU, '-y', '-nostdin', '-hide_banner', '-loglevel', $rSettings['ffmpeg_warnings'] ? 'warning' : 'error', '-err_detect', 'ignore_err', '-i', (string) $rSourceURL, '-map', '0:s:' . intval($rIndex), VOD_PATH . intval($rStreamID) . '_' . intval($rIndex) . '.srt']);
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
