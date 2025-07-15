<?php

require_once 'constants.php';
require_once INCLUDES_PATH . 'xc_vm.php';
require_once INCLUDES_PATH . 'pdo.php';

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
	$db = new Database($_INFO['username'], $_INFO['password'], $_INFO['database'], $_INFO['hostname'], $_INFO['port']);;
	CoreUtilities::$db = &$db;
	CoreUtilities::init();
} else {
	$db = new Database($_INFO['username'], $_INFO['password'], $_INFO['database'], $_INFO['hostname'], $_INFO['port']);
	CoreUtilities::$db = &$db;
	CoreUtilities::init(true);

	if (!CoreUtilities::$rCached) {
		$db = new Database($_INFO['username'], $_INFO['password'], $_INFO['database'], $_INFO['hostname'], $_INFO['port']);
		CoreUtilities::$db = &$db;
	}
}
