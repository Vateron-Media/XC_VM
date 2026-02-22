<?php

class StreamingBootstrap {
	public static function bootstrap($rFilename, $rSettings) {
		$rProbeEndpoints = array('probe', 'player_api');
		$rDefaultEndpoints = array('live', 'thumb', 'subtitle', 'timeshift', 'vod', 'status');
		$rPrivilegedEndpoints = array('rtmp', 'portal');

		if (!in_array($rFilename, array_merge($rProbeEndpoints, $rDefaultEndpoints, $rPrivilegedEndpoints), true)) {
			return null;
		}

		require_once INCLUDES_PATH . 'libs/AsyncFileOperations.php';
		require_once MAIN_HOME . 'core/Database/DatabaseHandler.php';
		require_once INCLUDES_PATH . 'StreamingUtilities.php';

		StreamingUtilities::$rSettings = $rSettings;
		StreamingUtilities::$rAccess = $rFilename;
		StreamingUtilities::init();

		return StreamingUtilities::$db;
	}
}
