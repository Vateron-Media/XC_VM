<?php

namespace XcVm\Infrastructure\Bootstrap;
use XcVm\Infrastructure\Database\DatabaseFactory;

use XcVm\Core\Updates\GitHubReleases;
use XcVm\Core\Init\LegacyInitializer;
use XcVm\Core\Database\Database;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Logging\Logger;
/**
 * WebApiBootstrap — bootstrap для web API endpoint'ов
 *
 * @package XC_VM_Infrastructure_Bootstrap
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/\XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class WebApiBootstrap {

	/**
	 * Инициализирует web API контекст.
	 *
	 * @param string $rFilename  Имя endpoint'а (enigma2, epg, playlist, xplugin, api, …)
	 */
	public static function init(string $rFilename): void {
		// ── 1. Ошибки ────────────────────────────────────────────
		require_once MAIN_HOME . 'Core/Error/ErrorCodes.php';
		require_once MAIN_HOME . 'Core/Error/ErrorHandler.php';

		// ── 2. PHP defaults ──────────────────────────────────────
		@ini_set('user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/83.0.4103.61 Safari/537.36');
		@ini_set('default_socket_timeout', 5);

		// ── 3. Константы и конфигурация ──────────────────────────
		require_once MAIN_HOME . 'Core/Config/Paths.php';
		require_once MAIN_HOME . 'Core/Config/AppConfig.php';
		require_once MAIN_HOME . 'Core/Config/Binaries.php';

		// ── 4. Flood / host / Logger ─────────────────────────────
		require_once MAIN_HOME . 'Core/Http/RequestGuard.php';

		// ── 5. DB + LegacyInitializer ────────────────────────────
		require_once MAIN_HOME . 'Core/Init/LegacyInitializer.php';
		require_once MAIN_HOME . 'Core/Database/DatabaseHandler.php';
		require_once MAIN_HOME . 'Core/Updates/GitHubReleases.php';

		global $db, $gitRelease;

		$rCachedEndpoints = ['enigma2', 'epg', 'playlist', 'api', 'xplugin', 'live', 'proxy_api', 'thumb', 'timeshift', 'vod'];
		$rUseCache = in_array($rFilename, $rCachedEndpoints, true);

		$db = new DatabaseHandler();
		DatabaseFactory::set($db);
		LegacyInitializer::initCore($rUseCache);

		if ($rUseCache && !SettingsManager::getAll()['enable_cache']) {
			$db = new DatabaseHandler();
			DatabaseFactory::set($db);
		}

		// ── 6. GithubReleases ────────────────────────────────────
		$gitRelease = new GitHubReleases(GIT_OWNER, GIT_REPO_MAIN, SettingsManager::getAll()['update_channel']);
	}
}
