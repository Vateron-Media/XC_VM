<?php

/**
 * Web request initialization
 *
 * @package XC_VM_Web
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

require_once 'constants.php';
require_once MAIN_HOME . 'core/Init/LegacyInitializer.php';
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

if (!isset($rFilename)) {
	$rFilename = strtolower(basename(get_included_files()[0], '.php'));
}

if (!in_array($rFilename, array('enigma2', 'epg', 'playlist', 'api', 'xplugin', 'live', 'proxy_api', 'thumb', 'timeshift', 'vod')) || isset($argc)) {
	$db = new DatabaseHandler($_INFO['username'], $_INFO['password'], $_INFO['database'], $_INFO['hostname'], $_INFO['port']);
	DatabaseFactory::set($db);
	LegacyInitializer::initCore();
} else {
	$db = new DatabaseHandler($_INFO['username'], $_INFO['password'], $_INFO['database'], $_INFO['hostname'], $_INFO['port']);
	DatabaseFactory::set($db);
	LegacyInitializer::initCore(true);

	if (!SettingsManager::getAll()['enable_cache']) {
		$db = new DatabaseHandler($_INFO['username'], $_INFO['password'], $_INFO['database'], $_INFO['hostname'], $_INFO['port']);
		DatabaseFactory::set($db);
	}
}

$gitRelease = new GitHubReleases(GIT_OWNER, GIT_REPO_MAIN, SettingsManager::getAll()['update_channel']);
