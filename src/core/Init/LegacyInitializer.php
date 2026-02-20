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
			StreamingUtilities::cleanGlobals($_GET);
		}
		if (!empty($_POST)) {
			StreamingUtilities::cleanGlobals($_POST);
		}
		if (!empty($_SESSION)) {
			StreamingUtilities::cleanGlobals($_SESSION);
		}
		if (!empty($_COOKIE)) {
			StreamingUtilities::cleanGlobals($_COOKIE);
		}

		$rInput = @StreamingUtilities::parseIncomingRecursively($_GET, array());
		StreamingUtilities::$rRequest = @StreamingUtilities::parseIncomingRecursively($_POST, $rInput);
		StreamingUtilities::$rConfig = parse_ini_file(CONFIG_PATH . 'config.ini');

		if (!defined('SERVER_ID')) {
			define('SERVER_ID', intval(StreamingUtilities::$rConfig['server_id']));
		}

		if (!StreamingUtilities::$rSettings) {
			StreamingUtilities::$rSettings = StreamingUtilities::getCache('settings');
		}

		if (!empty(StreamingUtilities::$rSettings['default_timezone'])) {
			date_default_timezone_set(StreamingUtilities::$rSettings['default_timezone']);
		}

		if (StreamingUtilities::$rSettings['on_demand_wait_time'] == 0) {
			StreamingUtilities::$rSettings['on_demand_wait_time'] = 15;
		}

		switch (StreamingUtilities::$rSettings['ffmpeg_cpu']) {
			case '8.0':
				StreamingUtilities::$rFFMPEG_CPU = FFMPEG_BIN_80;
				StreamingUtilities::$rFFMPEG_GPU = FFMPEG_BIN_80;
				break;
			case '7.1':
				StreamingUtilities::$rFFMPEG_CPU = FFMPEG_BIN_71;
				StreamingUtilities::$rFFMPEG_GPU = FFMPEG_BIN_71;
				break;
			case '5.1':
				StreamingUtilities::$rFFMPEG_CPU = FFMPEG_BIN_51;
				StreamingUtilities::$rFFMPEG_GPU = FFMPEG_BIN_40;
				break;
			case '4.4':
				StreamingUtilities::$rFFMPEG_CPU = FFMPEG_BIN_44;
				StreamingUtilities::$rFFMPEG_GPU = FFMPEG_BIN_40;
				break;
			case '4.3':
				StreamingUtilities::$rFFMPEG_CPU = FFMPEG_BIN_43;
				StreamingUtilities::$rFFMPEG_GPU = FFMPEG_BIN_40;
				break;
			default:
				StreamingUtilities::$rFFMPEG_CPU = FFMPEG_BIN_40;
				StreamingUtilities::$rFFMPEG_GPU = FFMPEG_BIN_40;
				break;
		}

		StreamingUtilities::$rCached = StreamingUtilities::isCacheEnabledAndComplete();
		StreamingUtilities::$rServers = StreamingUtilities::getCache('servers');
		StreamingUtilities::$rBlockedUA = StreamingUtilities::getCache('blocked_ua');
		StreamingUtilities::$rBlockedISP = StreamingUtilities::getCache('blocked_isp');
		StreamingUtilities::$rBlockedIPs = StreamingUtilities::getCache('blocked_ips');
		StreamingUtilities::$rBlockedServers = StreamingUtilities::getCache('blocked_servers');
		StreamingUtilities::$rAllowedIPs = StreamingUtilities::getCache('allowed_ips');
		StreamingUtilities::$rProxies = StreamingUtilities::getCache('proxy_servers');
		StreamingUtilities::$rSegmentSettings = array(
			'seg_time' => intval(StreamingUtilities::$rSettings['seg_time']),
			'seg_list_size' => intval(StreamingUtilities::$rSettings['seg_list_size'])
		);
		StreamingUtilities::connectDatabase();

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
		$rContainer->set('streaming.request', StreamingUtilities::$rRequest);
		$rContainer->set('streaming.config', StreamingUtilities::$rConfig);
		$rContainer->set('streaming.settings', StreamingUtilities::$rSettings);
		$rContainer->set('streaming.servers', StreamingUtilities::$rServers);
	}
}
