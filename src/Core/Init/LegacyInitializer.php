<?php

namespace XcVm\Core\Init;

use XcVm\Core\Cache\FileCache;
use XcVm\Core\Config\ConfigReader;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Config\SettingsRepository;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Http\Request;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Validation\InputValidator;

/**
 * LegacyInitializer — legacy initializer
 *
 * @package XC_VM_Core_Init
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class LegacyInitializer {
	/**
	 * Bootstrap the admin/core request context.
	 *
	 * Sanitizes superglobals, builds the request, defines SERVER_ID, loads
	 * settings/servers (optionally from cache), resolves ffmpeg paths,
	 * regenerates cron and exports globals/container bindings.
	 *
	 * @param bool $rUseCache Load settings from cache instead of the database.
	 * @return void
	 */
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

		\XcVm\Streaming\Codec\FfmpegPaths::resolve(SettingsManager::get('ffmpeg_cpu'), SettingsManager::get('ffmpeg_gpu'));

		if (!$rUseCache) {
			\XcVm\Domain\Server\ServerRepository::getAll();
			self::generateCron();
		}

		self::exportGlobals();
		self::syncCoreContainer();
	}

	/**
	 * Regenerate the xc_vm user crontab from the `crontab` table.
	 *
	 * Runs once per boot (guarded by a marker file in TMP_PATH).
	 *
	 * @return bool True if the crontab was regenerated, false if skipped.
	 */
	private static function generateCron() {
		global $db;
		if (file_exists(TMP_PATH . 'crontab')) {
			return false;
		}

		$rJobs = array();
		$db->query('SELECT * FROM `crontab` WHERE `enabled` = 1;');
		foreach ($db->get_rows() as $rRow) {
			$rJobs[] = $rRow['time'] . ' ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:' . $rRow['filename'] . ' # XC_VM';
		}

		shell_exec('crontab -r');
		$rTempName = tempnam('/tmp', 'crontab');
		$rHandle = fopen($rTempName, 'w');
		fwrite($rHandle, implode("\n", $rJobs) . "\n");
		fclose($rHandle);
		shell_exec('crontab -u xc_vm ' . $rTempName);
		@unlink($rTempName);
		@file_put_contents(TMP_PATH . 'crontab', '1', LOCK_EX);
		return true;
	}

	/**
	 * Bootstrap the streaming request context.
	 *
	 * Sanitizes superglobals, builds the request, loads cached settings/servers
	 * and blocklists, resolves ffmpeg paths, connects the database and syncs the
	 * streaming container bindings.
	 *
	 * @return void
	 */
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

		if (!defined('SERVER_ID')) {
			define('SERVER_ID', intval(ConfigReader::get('server_id')));
		}

		if (!$GLOBALS['rSettings']) {
			$GLOBALS['rSettings'] = \XcVm\Infrastructure\Cache\CacheReader::get('settings');
		}

		if (!empty($GLOBALS['rSettings']['default_timezone'])) {
			date_default_timezone_set($GLOBALS['rSettings']['default_timezone']);
		}

		if ($GLOBALS['rSettings']['on_demand_wait_time'] == 0) {
			$GLOBALS['rSettings']['on_demand_wait_time'] = 15;
		}

		\XcVm\Streaming\Codec\FfmpegPaths::resolve($GLOBALS['rSettings']['ffmpeg_cpu'], $GLOBALS['rSettings']['ffmpeg_gpu'] ?? null);

		$GLOBALS['rCached'] = \XcVm\Infrastructure\Cache\CacheReader::isReady($GLOBALS['rSettings']);
		$GLOBALS['rServers'] = \XcVm\Infrastructure\Cache\CacheReader::get('servers') ?: array();
		$GLOBALS['rBlockedUA'] = \XcVm\Infrastructure\Cache\CacheReader::get('blocked_ua') ?: array();
		$GLOBALS['rBlockedISP'] = \XcVm\Infrastructure\Cache\CacheReader::get('blocked_isp') ?: array();
		$GLOBALS['rBlockedIPs'] = \XcVm\Infrastructure\Cache\CacheReader::get('blocked_ips') ?: array();
		$GLOBALS['rBlockedServers'] = \XcVm\Infrastructure\Cache\CacheReader::get('blocked_servers') ?: array();
		$GLOBALS['rAllowedIPs'] = \XcVm\Infrastructure\Cache\CacheReader::get('allowed_ips') ?: array();
		$GLOBALS['rProxies'] = \XcVm\Infrastructure\Cache\CacheReader::get('proxy_servers') ?: array();
		$GLOBALS['rBouquets'] = \XcVm\Infrastructure\Cache\CacheReader::get('bouquets') ?: array();
		$GLOBALS['rSegmentSettings'] = array(
			'seg_time' => intval($GLOBALS['rSettings']['seg_time']),
			'seg_list_size' => intval($GLOBALS['rSettings']['seg_list_size'])
		);
		\XcVm\Infrastructure\Database\DatabaseFactory::connect();

		// Синхронизация singleton-менеджеров для классов, мигрированных с CU
		SettingsManager::set($GLOBALS['rSettings']);
		RequestManager::set($GLOBALS['rRequest']);

		// FFmpeg paths — export to globals (streaming context)
		$GLOBALS['rFFPROBE']    = \XcVm\Streaming\Codec\FfmpegPaths::probe();
		$GLOBALS['rFFMPEG_CPU']     = \XcVm\Streaming\Codec\FfmpegPaths::cpu();
		$GLOBALS['rFFMPEG_GPU'] = \XcVm\Streaming\Codec\FfmpegPaths::gpu();

		self::syncStreamingContainer();
	}

	/**
	 * Export the singleton managers to legacy superglobals.
	 *
	 * @return void
	 */
	public static function exportGlobals(): void {
		$GLOBALS['rSettings']   = SettingsManager::getAll();
		$GLOBALS['rRequest']    = RequestManager::getAll();
		$GLOBALS['rServers']    = \XcVm\Domain\Server\ServerRepository::getAll();
		$GLOBALS['rFFPROBE']    = \XcVm\Streaming\Codec\FfmpegPaths::probe();
		$GLOBALS['rFFMPEG_CPU']     = \XcVm\Streaming\Codec\FfmpegPaths::cpu();
		$GLOBALS['rFFMPEG_GPU'] = \XcVm\Streaming\Codec\FfmpegPaths::gpu();
	}

	/**
	 * Populate the DI container with core-context services.
	 *
	 * @return void
	 */
	private static function syncCoreContainer() {
		$rContainer = ServiceContainer::getInstance();
		$rContainer->set('core.request', RequestManager::getAll());
		$rContainer->set('core.config', ConfigReader::getAll());
		$rContainer->set('core.settings', SettingsManager::getAll());
		$rContainer->set('core.servers', \XcVm\Domain\Server\ServerRepository::getAll());
		$rContainer->set('core.bouquets', \XcVm\Domain\Bouquet\BouquetService::getAll());
		$rContainer->set('core.categories', \XcVm\Domain\Stream\CategoryService::getFromDatabase());
	}

	/**
	 * Populate the DI container with streaming-context services.
	 *
	 * @return void
	 */
	private static function syncStreamingContainer() {
		$rContainer = ServiceContainer::getInstance();
		$rContainer->set('streaming.request', $GLOBALS['rRequest']);
		$rContainer->set('streaming.config', ConfigReader::getAll());
		$rContainer->set('streaming.settings', $GLOBALS['rSettings']);
		$rContainer->set('streaming.servers', $GLOBALS['rServers']);
	}
}
