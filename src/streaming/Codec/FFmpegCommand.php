<?php

class FFmpegCommand {
	public static function createChannelItem($db, $rSettings, $rServers, $rFFMPEG_CPU, $rFFMPEG_GPU, $rStreamID, $rSource) {
		return StreamProcess::createChannelItem($db, $rSettings, $rServers, $rFFMPEG_CPU, $rFFMPEG_GPU, $rStreamID, $rSource);
	}
}
