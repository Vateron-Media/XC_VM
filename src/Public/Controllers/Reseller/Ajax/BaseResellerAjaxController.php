<?php

namespace XcVm\Public\Controllers\Reseller\Ajax;

use XcVm\Core\Config\SettingsManager;
use XcVm\Infrastructure\Redis\RedisManager;

/**
 * Base class for the reseller-ajax controllers (`api?action=…`).
 *
 * They are reached via `Router::dispatchApi()` (routes/reseller.php), once
 * ResellerScopeBootstrap has signed the reseller in and set $rUserInfo (with
 * its `reports`) and $rPermissions. Each action starts with begin(), what the
 * former single endpoint applied to every action.
 *
 * An action answers as it always did: JSON echoed with no Content-Type header,
 * which some views parse from the response text themselves.
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
abstract class BaseResellerAjaxController {
	/**
	 * Release the session, refuse a request that is not AJAX (outside debug
	 * mode), connect Redis when it holds the connections, and hand the action
	 * the signed-in reseller and its permissions.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	protected function begin(): array {
		session_write_close();

		if (!(defined('PHP_ERRORS') && PHP_ERRORS) && strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== 'xmlhttprequest') {
			exit();
		}

		if (SettingsManager::get('redis_handler')) {
			RedisManager::ensureConnected();
		}

		$rUserInfo = $GLOBALS['rUserInfo'] ?? null;
		if (empty($rUserInfo['id']) || !isset($rUserInfo['reports'])) {
			echo json_encode(['result' => false]);
			exit();
		}

		return [$rUserInfo, $GLOBALS['rPermissions'] ?? []];
	}
}
