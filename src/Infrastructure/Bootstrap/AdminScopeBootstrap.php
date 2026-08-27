<?php

namespace XcVm\Infrastructure\Bootstrap;

use XcVm\Core\Auth\AuthRepository;
use XcVm\Core\Auth\SessionManager;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Util\NetworkUtils;
use XcVm\Domain\User\UserRepository;

/**
 * Admin scope bootstrap (Front Controller path).
 *
 * Ports the former admin_session_fc.php + admin_functions_fc.php includes:
 * admin session lifecycle (timeout, login redirect, heartbeat) followed by
 * the full framework boot, $rUserInfo / $rPermissions setup, session-integrity
 * validation and user preferences.
 *
 * @package XC_VM_Infrastructure_Bootstrap
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class AdminScopeBootstrap implements ScopeBootstrap {

	public function boot(): void {
		$this->bootSession();
		$this->bootFunctions();
	}

	/**
	 * Admin session lifecycle: start, expire after timeout, redirect if
	 * unauthenticated (JSON for AJAX). Session keys: hash, ip, code, verify,
	 * last_activity.
	 *
	 * @return void
	 */
	private function bootSession(): void {
		$rSessionTimeout = 60;

		if (!defined('TMP_PATH')) {
			define('TMP_PATH', '/home/xc_vm/tmp/');
		}

		if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
			session_start();
		}

		// Expire session after timeout
		if (
			isset($_SESSION['hash'], $_SESSION['last_activity'])
			&& ($rSessionTimeout * 60) < (time() - $_SESSION['last_activity'])
		) {
			foreach (['hash', 'ip', 'code', 'verify', 'last_activity'] as $rKey) {
				unset($_SESSION[$rKey]);
			}
		}

		// Not authenticated → redirect to login (or JSON response for AJAX)
		if (!isset($_SESSION['hash'])) {
			if (
				!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
				&& strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
			) {
				header('Content-Type: application/json');
				echo json_encode(['result' => false]);
				exit;
			}

			$referrer = '';
			if (defined('PAGE_NAME') && PAGE_NAME !== 'login') {
				$referrer = '?referrer=' . urlencode(PAGE_NAME);
			}

			header('Location: ./login' . $referrer);
			exit;
		}

		$_SESSION['last_activity'] = time();
		session_write_close();
	}

	/**
	 * Framework boot + admin user context. Injects the legacy view-facing
	 * globals ($rUserInfo, $rPermissions, $rServerError, ...) — the procedural
	 * admin views read them from scope.
	 *
	 * @return void
	 */
	private function bootFunctions(): void {
		global $db, $rSettings, $rMobile, $rServers, $rProxyServers, $rDetect,
			$rTimeout, $rProtocol, $allServers, $rPermissions, $language, $allowedLangs,
			$rServerError, $allServersHealthy, $updateRequired, $rUserInfo,
			$_STATUS, $customScript;

		if (!defined('MAIN_HOME')) {
			define('MAIN_HOME', '/home/xc_vm/');
		}

		require_once MAIN_HOME . 'bootstrap.php';
		\XC_Bootstrap::boot(\XC_Bootstrap::CONTEXT_ADMIN);

		if ($rMobile) {
			$rSettings['js_navigate'] = 0;
		}

		if (isset($_SESSION['hash'])) {
			$rUserInfo = UserRepository::getRegisteredUserById($_SESSION['hash']);

			$__tz = trim($rUserInfo['timezone'] ?? '', '" ');
			if ($__tz !== '' && in_array($__tz, timezone_identifiers_list())) {
				date_default_timezone_set($__tz);
			}

			if (!empty($rUserInfo['hue']) && (!isset($_COOKIE['hue']) || $_COOKIE['hue'] != $rUserInfo['hue'])) {
				setcookie('hue', $rUserInfo['hue'], time() + 604800);
			}

			if (!isset($_COOKIE['theme']) || $_COOKIE['theme'] != $rUserInfo['theme']) {
				setcookie('theme', $rUserInfo['theme'], time() + 604800);
			}

			if (!isset($_COOKIE['lang']) || $_COOKIE['lang'] != $rUserInfo['lang']) {
				$language::setLanguage($rUserInfo['lang']);
			}

			$rPermissions = AuthRepository::getPermissions($rUserInfo['member_group_id']);
			$rPermissions['advanced'] = json_decode($rPermissions['allowed_pages'], true);
			$rIP = NetworkUtils::getUserIP();
			$rIPMatch = ($rSettings['ip_subnet_match'] ? implode('.', array_slice(explode('.', $_SESSION['ip']), 0, -1)) == implode('.', array_slice(explode('.', $rIP), 0, -1)) : $_SESSION['ip'] == $rIP);

			if (!$rUserInfo || !$rPermissions || !$rPermissions['is_admin'] || !$rIPMatch && $rSettings['ip_logout'] || $_SESSION['verify'] != md5($rUserInfo['username'] . '||' . $rUserInfo['password'])) {
				unset($rUserInfo, $rPermissions);

				SessionManager::clearContext('admin');
				header('Location: index');

				exit();
			}

			if ($_SESSION['ip'] == $rIP || $rSettings['ip_logout']) {
			} else {
				$_SESSION['ip'] = $rIP;
			}

			$rServerError = false;

			foreach ($rServers as $rServer) {
				if (!$rServer['server_online'] && $rServer['enabled'] && $rServer['status'] != 3 && $rServer['status'] != 5) {
					$rServerError = true;
				}
			}
			$allServersHealthy = false;

			foreach ($rProxyServers as $rServer) {
				if (!$rServer['server_online'] && $rServer['enabled'] && $rServer['status'] != 3 && $rServer['status'] != 5) {
					$allServersHealthy = true;
				}
			}
			$updateRequired = false;

			if (isset($rServers[SERVER_ID]) && !version_compare($rServers[SERVER_ID]['xc_vm_version'], SettingsManager::getString('update_version'), '>=')) {
				$updateRequired = true;
			}
		}

		if (RequestManager::has('status')) {
			$_STATUS = intval(RequestManager::get('status'));
			$rArgs = RequestManager::getAll();
			unset($rArgs['status']);
			$customScript = AdminHelpers::setArgs($rArgs);
		}

		if (AdminHelpers::getPageName() != 'setup') {
			$db->query('SELECT COUNT(`id`) AS `count` FROM `users` LEFT JOIN `users_groups` ON `users_groups`.`group_id` = `users`.`member_group_id` WHERE `users_groups`.`is_admin` = 1;');

			if ($db->get_row()['count'] == 0) {
				header('Location: ./setup');
				exit();
			}
		}
	}
}
