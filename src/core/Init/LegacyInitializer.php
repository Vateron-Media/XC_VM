<?php

class LegacyInitializer {
	public static function initCore($rUseCache = false) {
		if (!empty($_GET)) {
			InputValidator::cleanGlobals($_GET);
		}
		if (!empty($_POST)) {
			InputValidator::cleanGlobals($_POST);
		}
		if (!empty($_SESSION)) {
			InputValidator::cleanGlobals($_SESSION);
		}
		if (!empty($_COOKIE)) {
			InputValidator::cleanGlobals($_COOKIE);
		}

		$rInput = @InputValidator::parseIncomingRecursively($_GET, array());
		RequestManager::set(@InputValidator::parseIncomingRecursively($_POST, $rInput));

		if (!defined('SERVER_ID')) {
			define('SERVER_ID', intval(ConfigReader::get('server_id')));
		}

		if ($rUseCache) {
			SettingsManager::set(FileCache::getCache('settings') ?: array());
		} else {
			SettingsManager::set(SettingsRepository::getAll());
		}

		if (!empty(SettingsManager::get('default_timezone'))) {
			date_default_timezone_set(SettingsManager::get('default_timezone'));
		}

		if (SettingsManager::get('on_demand_wait_time') == 0) {
			SettingsManager::update('on_demand_wait_time', 15);
		}

		FfmpegPaths::resolve(SettingsManager::get('ffmpeg_cpu'));

		if (!$rUseCache) {
			ServerRepository::getAll();
			CronGenerator::generate();
		}

		self::exportGlobals();
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

		FfmpegPaths::resolve($GLOBALS['rSettings']['ffmpeg_cpu']);

		$GLOBALS['rCached'] = CacheReader::isReady($GLOBALS['rSettings']);
		$GLOBALS['rServers'] = CacheReader::get('servers');
		$GLOBALS['rBlockedUA'] = CacheReader::get('blocked_ua');
		$GLOBALS['rBlockedISP'] = CacheReader::get('blocked_isp');
		$GLOBALS['rBlockedIPs'] = CacheReader::get('blocked_ips');
		$GLOBALS['rBlockedServers'] = CacheReader::get('blocked_servers');
		$GLOBALS['rAllowedIPs'] = CacheReader::get('allowed_ips');
		$GLOBALS['rProxies'] = CacheReader::get('proxy_servers');
		$GLOBALS['rBouquets'] = CacheReader::get('bouquets') ?: array();
		$GLOBALS['rSegmentSettings'] = array(
			'seg_time' => intval($GLOBALS['rSettings']['seg_time']),
			'seg_list_size' => intval($GLOBALS['rSettings']['seg_list_size'])
		);
		DatabaseFactory::connect();

		// Синхронизация singleton-менеджеров для классов, мигрированных с CU
		SettingsManager::set($GLOBALS['rSettings']);
		RequestManager::set($GLOBALS['rRequest']);

		// FFmpeg paths — export to globals (streaming context)
		$GLOBALS['rFFPROBE']    = FfmpegPaths::probe();
		$GLOBALS['rFFMPEG']     = FfmpegPaths::cpu();
		$GLOBALS['rFFMPEG_GPU'] = FfmpegPaths::gpu();

		self::syncStreamingContainer();
	}

	/**
	 * Экспортирует данные singleton-менеджеров в глобальные переменные.
	 *
	 * Позволяет использовать `global $rSettings;` внутри функций
	 * вместо повторного вызова SettingsManager::getAll().
	 *
	 * Вызывается один раз в конце initCore() / initStreaming().
	 * Глобалы — read-only snapshot. Для мутаций использовать Manager.
	 *
	 * Экспортируемые переменные:
	 *   $rSettings   — настройки панели (SettingsManager)
	 *   $rRequest    — параметры HTTP-запроса (RequestManager)
	 *   $rConfig     — config.ini данные (ConfigReader)
	 *   $rServers    — все серверы (ServerRepository)
	 *   $rFFPROBE    — путь к ffprobe (FfmpegPaths)
	 *   $rFFMPEG     — путь к ffmpeg CPU (FfmpegPaths)
	 *   $rFFMPEG_GPU — путь к ffmpeg GPU (FfmpegPaths)
	 */
	public static function exportGlobals(): void {
		$GLOBALS['rSettings']   = SettingsManager::getAll();
		$GLOBALS['rRequest']    = RequestManager::getAll();
		$GLOBALS['rConfig']     = ConfigReader::getAll();
		$GLOBALS['rServers']    = ServerRepository::getAll();
		$GLOBALS['rFFPROBE']    = FfmpegPaths::probe();
		$GLOBALS['rFFMPEG']     = FfmpegPaths::cpu();
		$GLOBALS['rFFMPEG_GPU'] = FfmpegPaths::gpu();
	}

	private static function syncCoreContainer() {
		$rContainer = ServiceContainer::getInstance();
		$rContainer->set('core.request', RequestManager::getAll());
		$rContainer->set('core.config', ConfigReader::getAll());
		$rContainer->set('core.settings', SettingsManager::getAll());
		$rContainer->set('core.servers', ServerRepository::getAll());
		$rContainer->set('core.bouquets', BouquetService::getAll());
		$rContainer->set('core.categories', CategoryService::getFromDatabase());
	}

	private static function syncStreamingContainer() {
		$rContainer = ServiceContainer::getInstance();
		$rContainer->set('streaming.request', $GLOBALS['rRequest']);
		$rContainer->set('streaming.config', $GLOBALS['rConfig']);
		$rContainer->set('streaming.settings', $GLOBALS['rSettings']);
		$rContainer->set('streaming.servers', $GLOBALS['rServers']);
	}
}
