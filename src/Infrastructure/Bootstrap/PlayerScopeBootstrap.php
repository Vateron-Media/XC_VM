<?php

namespace XcVm\Infrastructure\Bootstrap;

use XcVm\Core\Auth\SessionManager;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\User\UserRepository;
use XcVm\Domain\Vod\TMDbService;

/**
 * Player scope bootstrap (Front Controller path).
 *
 * Ports the former player_session.php + player_functions.php includes: player
 * session lifecycle, framework boot, HTTPS/login redirects, player-session
 * integrity validation, and loading of the player utility functions
 * (getStream, getUserStreams, ...).
 *
 * @package XC_VM_Infrastructure_Bootstrap
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class PlayerScopeBootstrap implements ScopeBootstrap {

	public function boot(): void {
		$this->bootSession();
		$this->bootFunctions();
	}

	/**
	 * Player session lifecycle. Session keys: phash, pverify.
	 *
	 * @return void
	 */
	private function bootSession(): void {
		if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
			session_start();
		}

		// Not logged in → redirect to login
		if (!isset($_SESSION['phash'])) {
			$referrer = defined('PAGE_NAME') ? PAGE_NAME : '';
			$code = $_SERVER['XC_CODE'] ?? '';
			$loginUrl = $code ? '/' . $code . '/login' : 'login';
			header('Location: ' . $loginUrl . ($referrer ? '?referrer=' . urlencode($referrer) : ''));
			exit();
		}
	}

	/**
	 * Framework boot + player user context, then load the player utility
	 * functions. Injects the legacy view-facing globals ($rServers, $rUserInfo,
	 * $_PAGE) read by the player views/header/footer.
	 *
	 * @return void
	 */
	private function bootFunctions(): void {
		global $rServers, $rUserInfo, $_PAGE;

		// Mark bootstrap as done to prevent double-init when legacy files
		// include player/functions.php via require_once
		define('PLAYER_BOOTSTRAP_DONE', true);

		if (!defined('MAIN_HOME')) {
			define('MAIN_HOME', dirname(__DIR__, 2) . '/');
		}

		require_once MAIN_HOME . 'bootstrap.php';
		\XC_Bootstrap::boot(\XC_Bootstrap::CONTEXT_ADMIN);
		TMDbService::requireLibrary();

		if (!defined('SERVER_ID')) {
			define('SERVER_ID', ConnectionTracker::getMainID());
		}

		// $_PAGE is used by header.php and footer.php for active nav highlighting
		$_PAGE = defined('PAGE_NAME') ? PAGE_NAME : 'index';

		$rServers = ServerRepository::getAll();
		SettingsManager::update('live_streaming_pass', md5(sha1($rServers[SERVER_ID]['server_name'] . $rServers[SERVER_ID]['server_ip']) . '5f13a731fb85944e5c69ce863b0c990d'));

		// HTTPS check: redirect to HTTP if HTTPS is on but not enabled in panel settings
		if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' && !$rServers[SERVER_ID]['enable_https']) {
			header('Location: ' . $rServers[SERVER_ID]['http_url'] . ltrim($_SERVER['REQUEST_URI'], '/'));
			exit();
		}

		// Player auth verification
		if (isset($_SESSION['phash'])) {
			$rUserInfo = UserRepository::getUserInfo($_SESSION['phash'], null, null, true);

			if (
				!$rUserInfo
				|| $_SESSION['pverify'] != md5($rUserInfo['username'] . '||' . $rUserInfo['password'])
				|| (!is_null($rUserInfo['exp_date']) && $rUserInfo['exp_date'] <= time())
				|| $rUserInfo['admin_enabled'] == 0
				|| $rUserInfo['enabled'] == 0
			) {
				SessionManager::clearContext('player');
				$code = $_SERVER['XC_CODE'] ?? '';
				header('Location: ' . ($code ? '/' . $code . '/login' : 'login'));
				exit();
			}

			sort($rUserInfo['bouquet']);
		} else {
			$code = $_SERVER['XC_CODE'] ?? '';
			header('Location: ' . ($code ? '/' . $code . '/login' : 'login'));
			exit();
		}

		// Load player utility functions (getStream, getUserStreams, etc.)
		require_once MAIN_HOME . 'Infrastructure/Bootstrap/player_utility_functions.php';
	}
}
