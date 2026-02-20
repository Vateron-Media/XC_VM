<?php

require_once 'constants.php';
require_once INCLUDES_PATH . 'CoreUtilities.php';
require_once MAIN_HOME . 'core/Database/DatabaseHandler.php';
require_once INCLUDES_PATH . 'libs/GithubReleases.php';

if (!function_exists('getallheaders')) {
	function getallheaders() {
		$rHeaders = array();

		foreach ($_SERVER as $rName => $rValue) {
			if (substr($rName, 0, 5) == 'HTTP_') {
				$rHeaders[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($rName, 5)))))] = $rValue;
			}
		}

		return $rHeaders;
	}
}

if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
	generate404();
}

$rFilename = strtolower(basename(get_included_files()[0], '.php'));

if (!in_array($rFilename, array('enigma2', 'epg', 'playlist', 'api', 'xplugin', 'live', 'proxy_api', 'thumb', 'timeshift', 'vod')) || isset($argc)) {
	$db = new DatabaseHandler($_INFO['username'], $_INFO['password'], $_INFO['database'], $_INFO['hostname'], $_INFO['port']);
	CoreUtilities::$db = &$db;
	CoreUtilities::init();
} else {
	$db = new DatabaseHandler($_INFO['username'], $_INFO['password'], $_INFO['database'], $_INFO['hostname'], $_INFO['port']);
	CoreUtilities::$db = &$db;
	CoreUtilities::init(true);

	if (!CoreUtilities::$rCached) {
		$db = new DatabaseHandler($_INFO['username'], $_INFO['password'], $_INFO['database'], $_INFO['hostname'], $_INFO['port']);
		CoreUtilities::$db = &$db;
	}
}

$gitRelease = new GitHubReleases(GIT_OWNER, GIT_REPO_MAIN, CoreUtilities::$rSettings['update_channel']);