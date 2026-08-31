<?php

namespace XcVm\Infrastructure\Bootstrap;

use XcVm\Core\Auth\AuthRepository;
use XcVm\Core\Auth\SessionManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Localization\Translator;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Util\NetworkUtils;
use XcVm\Domain\User\UserRepository;

/**
 * Reseller scope bootstrap (Front Controller path).
 *
 * Ports the former reseller_session.php + reseller_functions.php includes:
 * reseller session lifecycle (timeout, login redirect, heartbeat) followed by
 * the framework boot, $rUserInfo / $rPermissions setup and session-integrity
 * validation.
 *
 * @package XC_VM_Infrastructure_Bootstrap
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class ResellerScopeBootstrap implements ScopeBootstrap {

	public function boot(): void {
		$this->bootSession();
		$this->bootFunctions();
	}

	/**
	 * Reseller session lifecycle. Session keys: reseller, rip, rcode, rverify,
	 * rlast_activity.
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
			isset($_SESSION['reseller'], $_SESSION['rlast_activity'])
			&& ($rSessionTimeout * 60) < (time() - $_SESSION['rlast_activity'])
		) {
			foreach (['reseller', 'rip', 'rcode', 'rverify', 'rlast_activity'] as $rKey) {
				unset($_SESSION[$rKey]);
			}

			if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
				session_start();
			}
		}

		// Not logged in → redirect to login (unless this is a direct session check via FC)
		if (!isset($_SESSION['reseller'])) {
			// FC handles login/index pages via noBootstrapPages — this code runs only
			// for authenticated pages. Redirect to login with referrer.
			$referrer = defined('PAGE_NAME') ? PAGE_NAME : basename($_SERVER['REQUEST_URI'] ?? '', '.php');
			header('Location: login?referrer=' . urlencode($referrer));
			exit();
		}

		$_SESSION['rlast_activity'] = time();
		session_write_close();
	}

	/**
	 * Framework boot + reseller user context. Injects the legacy view-facing
	 * globals ($rUserInfo, $rPermissions, ...) read by the reseller views.
	 *
	 * @return void
	 */
	private function bootFunctions(): void {
		global $db, $rSettings, $rMobile, $rPermissions, $rUserInfo,
			$_STATUS, $customScript;

		if (!defined('MAIN_HOME')) {
			define('MAIN_HOME', '/home/xc_vm/');
		}

		require_once MAIN_HOME . 'bootstrap.php';
		\XC_Bootstrap::boot(\XC_Bootstrap::CONTEXT_ADMIN);

		if ($rMobile) {
			$rSettings['js_navigate'] = 0;
		}

		if (isset($_SESSION['reseller'])) {
			$rUserInfo = UserRepository::getRegisteredUserById($_SESSION['reseller']);

			if (strlen($rUserInfo['timezone'] ?? '') > 0) {
				date_default_timezone_set($rUserInfo['timezone']);
			}

			setcookie('hue', $rUserInfo['hue'] ?? '', time() + 604800);
			setcookie('theme', $rUserInfo['theme'] ?? '', time() + 604800);
			Translator::setLanguage($rUserInfo['lang']);

			$rPermissions = array_merge(AuthRepository::getPermissions($rUserInfo['member_group_id']), AuthRepository::getGroupPermissions($rUserInfo['id']));
			$rPermissions['direct_reports'] = $rPermissions['direct_reports'] ?? [];
			$rPermissions['all_reports'] = $rPermissions['all_reports'] ?? [];
			$rPermissions['stream_ids'] = $rPermissions['stream_ids'] ?? [];
			$rPermissions['category_ids'] = $rPermissions['category_ids'] ?? [];
			$rPermissions['series_ids'] = $rPermissions['series_ids'] ?? [];
			$rPermissions['subresellers'] = $rPermissions['subresellers'] ?? [];
			$rUserInfo['reports'] = array_map('intval', array_merge(array($rUserInfo['id']), $rPermissions['all_reports']));
			$rIP = NetworkUtils::getUserIP();
			$rIPMatch = ($rSettings['ip_subnet_match'] ? implode('.', array_slice(explode('.', $_SESSION['rip']), 0, -1)) == implode('.', array_slice(explode('.', $rIP), 0, -1)) : $_SESSION['rip'] == $rIP);

			if (!$rUserInfo || !$rPermissions['is_reseller'] || !$rIPMatch && $rSettings['ip_logout'] || $_SESSION['rverify'] != md5($rUserInfo['username'] . '||' . $rUserInfo['password'])) {
				unset($rUserInfo, $rPermissions);

				SessionManager::clearContext('reseller');
				header('Location: ./index');

				exit();
			}
			if ($_SESSION['rip'] != $rIP && !$rSettings['ip_logout']) {
				$_SESSION['rip'] = $rIP;
			}
		}

		if (RequestManager::has('status')) {
			$_STATUS = intval(RequestManager::get('status'));
			$rArgs = RequestManager::getAll();
			unset($rArgs['status']);
			$customScript = AdminHelpers::setArgs($rArgs);
		}
	}
}
