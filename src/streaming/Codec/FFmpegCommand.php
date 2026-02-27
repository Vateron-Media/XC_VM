<?php

class FFmpegCommand {
	public static function createChannelItem($rSettings, $rServers, $rFFMPEG_CPU, $rFFMPEG_GPU, $rStreamID, $rSource) {
		global $db;
		return StreamProcess::createChannelItem($rSettings, $rServers, $rFFMPEG_CPU, $rFFMPEG_GPU, $rStreamID, $rSource);
	}
}
