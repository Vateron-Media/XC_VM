<?php

class FFmpegCommand {
	public static function createChannelItem($rStreamID, $rSource) {
		return StreamProcess::createChannelItem($rStreamID, $rSource);
	}
}
