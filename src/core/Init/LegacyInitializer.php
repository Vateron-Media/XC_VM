<?php

class LegacyInitializer {
	public static function initCore($rUseCache = false) {
		if (!empty($_GET)) {
			CoreUtilities::cleanGlobals($_GET);
		}
		if (!empty($_POST)) {
			CoreUtilities::cleanGlobals($_POST);
		}
		if (!empty($_SESSION)) {
			CoreUtilities::cleanGlobals($_SESSION);
		}
		if (!empty($_COOKIE)) {
			CoreUtilities::cleanGlobals($_COOKIE);
		}

		$rInput = @CoreUtilities::parseIncomingRecursively($_GET, array());
		CoreUtilities::$rRequest = @CoreUtilities::parseIncomingRecursively($_POST, $rInput);
		CoreUtilities::$rConfig = parse_ini_file(CONFIG_PATH . 'config.ini');

		if (!defined('SERVER_ID')) {
			define('SERVER_ID', intval(CoreUtilities::$rConfig['server_id']));
		}

		if ($rUseCache) {
			CoreUtilities::$rSettings = CoreUtilities::getCache('settings');
		} else {
			CoreUtilities::$rSettings = CoreUtilities::getSettings();
		}

		if (!empty(CoreUtilities::$rSettings['default_timezone'])) {
			date_default_timezone_set(CoreUtilities::$rSettings['default_timezone']);
		}

		if (CoreUtilities::$rSettings['on_demand_wait_time'] == 0) {
			CoreUtilities::$rSettings['on_demand_wait_time'] = 15;
		}

		CoreUtilities::$rSegmentSettings = array(
			'seg_time' => intval(CoreUtilities::$rSettings['seg_time']),
			'seg_list_size' => intval(CoreUtilities::$rSettings['seg_list_size']),
			'seg_delete_threshold' => intval(CoreUtilities::$rSettings['seg_delete_threshold'])
		);

		switch (CoreUtilities::$rSettings['ffmpeg_cpu']) {
			case '8.0':
				CoreUtilities::$rFFMPEG_CPU = FFMPEG_BIN_80;
				CoreUtilities::$rFFPROBE = FFPROBE_BIN_80;
				CoreUtilities::$rFFMPEG_GPU = FFMPEG_BIN_80;
				break;
			case '7.1':
				CoreUtilities::$rFFMPEG_CPU = FFMPEG_BIN_71;
				CoreUtilities::$rFFPROBE = FFPROBE_BIN_71;
				CoreUtilities::$rFFMPEG_GPU = FFMPEG_BIN_71;
				break;
			case '5.1':
				CoreUtilities::$rFFMPEG_CPU = FFMPEG_BIN_51;
				CoreUtilities::$rFFPROBE = FFPROBE_BIN_51;
				CoreUtilities::$rFFMPEG_GPU = FFMPEG_BIN_40;
				break;
			case '4.4':
				CoreUtilities::$rFFMPEG_CPU = FFMPEG_BIN_44;
				CoreUtilities::$rFFPROBE = FFPROBE_BIN_44;
				CoreUtilities::$rFFMPEG_GPU = FFMPEG_BIN_40;
				break;
			case '4.3':
				CoreUtilities::$rFFMPEG_CPU = FFMPEG_BIN_43;
				CoreUtilities::$rFFPROBE = FFPROBE_BIN_43;
				CoreUtilities::$rFFMPEG_GPU = FFMPEG_BIN_40;
				break;
			default:
				CoreUtilities::$rFFMPEG_CPU = FFMPEG_BIN_40;
				CoreUtilities::$rFFPROBE = FFPROBE_BIN_40;
				CoreUtilities::$rFFMPEG_GPU = FFMPEG_BIN_40;
				break;
		}

		CoreUtilities::$rCached = CoreUtilities::$rSettings['enable_cache'];
		if ($rUseCache) {
			CoreUtilities::$rServers = CoreUtilities::getCache('servers');
			CoreUtilities::$rBouquets = CoreUtilities::getCache('bouquets');
			CoreUtilities::$rBlockedUA = CoreUtilities::getCache('blocked_ua');
			CoreUtilities::$rBlockedISP = CoreUtilities::getCache('blocked_isp');
			CoreUtilities::$rBlockedIPs = CoreUtilities::getCache('blocked_ips');
			CoreUtilities::$rProxies = CoreUtilities::getCache('proxy_servers');
			CoreUtilities::$rBlockedServers = CoreUtilities::getCache('blocked_servers');
			CoreUtilities::$rAllowedDomains = CoreUtilities::getCache('allowed_domains');
			CoreUtilities::$rAllowedIPs = CoreUtilities::getCache('allowed_ips');
			CoreUtilities::$rCategories = CoreUtilities::getCache('categories');
		} else {
			CoreUtilities::$rServers = CoreUtilities::getServers();
			CoreUtilities::$rBouquets = CoreUtilities::getBouquets();
			CoreUtilities::$rBlockedUA = CoreUtilities::getBlockedUA();
			CoreUtilities::$rBlockedISP = CoreUtilities::getBlockedISP();
			CoreUtilities::$rBlockedIPs = CoreUtilities::getBlockedIPs();
			CoreUtilities::$rProxies = CoreUtilities::getProxyIPs();
			CoreUtilities::$rBlockedServers = CoreUtilities::getBlockedServers();
			CoreUtilities::$rAllowedDomains = CoreUtilities::getAllowedDomains();
			CoreUtilities::$rAllowedIPs = CoreUtilities::getAllowedIPs();
			CoreUtilities::$rCategories = CoreUtilities::getCategories();
			CoreUtilities::generateCron();
		}

		self::syncCoreContainer();
	}

	public static function initStreaming() {
		if (!empty($_GET)) {
			Request::cleanGlobals($_GET);
		}
		if (!empty($_POST)) {
			Request::cleanGlobals($_POST);
		}
		if (!empty($_SESSION)) {
			Request::cleanGlobals($_SESSION);
		}
		if (!empty($_COOKIE)) {
			Request::cleanGlobals($_COOKIE);
		}

		$rInput = @Request::parseIncomingRecursively($_GET, array());
		$GLOBALS['rRequest'] = @Request::parseIncomingRecursively($_POST, $rInput);
		$GLOBALS['rConfig'] = parse_ini_file(CONFIG_PATH . 'config.ini');

		if (!defined('SERVER_ID')) {
			define('SERVER_ID', intval($GLOBALS['rConfig']['server_id']));
		}

		if (!$GLOBALS['rSettings']) {
			$GLOBALS['rSettings'] = CacheReader::get('settings');
		}

		if (!empty($GLOBALS['rSettings']['default_timezone'])) {
			date_default_timezone_set($GLOBALS['rSettings']['default_timezone']);
		}

		if ($GLOBALS['rSettings']['on_demand_wait_time'] == 0) {
			$GLOBALS['rSettings']['on_demand_wait_time'] = 15;
		}

		switch ($GLOBALS['rSettings']['ffmpeg_cpu']) {
			case '8.0':
				$GLOBALS['rFFMPEG_CPU'] = FFMPEG_BIN_80;
				$GLOBALS['rFFMPEG_GPU'] = FFMPEG_BIN_80;
				break;
			case '7.1':
				$GLOBALS['rFFMPEG_CPU'] = FFMPEG_BIN_71;
				$GLOBALS['rFFMPEG_GPU'] = FFMPEG_BIN_71;
				break;
			case '5.1':
				$GLOBALS['rFFMPEG_CPU'] = FFMPEG_BIN_51;
				$GLOBALS['rFFMPEG_GPU'] = FFMPEG_BIN_40;
				break;
			case '4.4':
				$GLOBALS['rFFMPEG_CPU'] = FFMPEG_BIN_44;
				$GLOBALS['rFFMPEG_GPU'] = FFMPEG_BIN_40;
				break;
			case '4.3':
				$GLOBALS['rFFMPEG_CPU'] = FFMPEG_BIN_43;
				$GLOBALS['rFFMPEG_GPU'] = FFMPEG_BIN_40;
				break;
			default:
				$GLOBALS['rFFMPEG_CPU'] = FFMPEG_BIN_40;
				$GLOBALS['rFFMPEG_GPU'] = FFMPEG_BIN_40;
				break;
		}

		$GLOBALS['rCached'] = CacheReader::isReady($GLOBALS['rSettings']);
		$GLOBALS['rServers'] = CacheReader::get('servers');
		$GLOBALS['rBlockedUA'] = CacheReader::get('blocked_ua');
		$GLOBALS['rBlockedISP'] = CacheReader::get('blocked_isp');
		$GLOBALS['rBlockedIPs'] = CacheReader::get('blocked_ips');
		$GLOBALS['rBlockedServers'] = CacheReader::get('blocked_servers');
		$GLOBALS['rAllowedIPs'] = CacheReader::get('allowed_ips');
		$GLOBALS['rProxies'] = CacheReader::get('proxy_servers');
		$GLOBALS['rSegmentSettings'] = array(
			'seg_time' => intval($GLOBALS['rSettings']['seg_time']),
			'seg_list_size' => intval($GLOBALS['rSettings']['seg_list_size'])
		);
		DatabaseFactory::connect();

		self::syncStreamingContainer();
	}

	private static function syncCoreContainer() {
		$rContainer = ServiceContainer::getInstance();
		$rContainer->set('core.request', CoreUtilities::$rRequest);
		$rContainer->set('core.config', CoreUtilities::$rConfig);
		$rContainer->set('core.settings', CoreUtilities::$rSettings);
		$rContainer->set('core.servers', CoreUtilities::$rServers);
		$rContainer->set('core.bouquets', CoreUtilities::$rBouquets);
		$rContainer->set('core.categories', CoreUtilities::$rCategories);
	}

	private static function syncStreamingContainer() {
		$rContainer = ServiceContainer::getInstance();
		$rContainer->set('streaming.request', $GLOBALS['rRequest']);
		$rContainer->set('streaming.config', $GLOBALS['rConfig']);
		$rContainer->set('streaming.settings', $GLOBALS['rSettings']);
		$rContainer->set('streaming.servers', $GLOBALS['rServers']);
	}
}
