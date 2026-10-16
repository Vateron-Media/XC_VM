<?php

namespace XcVm\Tests\Support;

use XcVm\Core\Http\Router;

/**
 * Runs a reseller panel action the way `api?action=…` does: through
 * routes/reseller.php and Router::dispatchApi(), for the reseller and the
 * permissions ResellerScopeBootstrap would have set. The action ends the
 * request, so it is called from a child PHP.
 */
final class ResellerAjax {
	public static function call(string $rAction, array $rUserInfo, array $rPermissions): void {
		$GLOBALS['rUserInfo'] = $rUserInfo;
		$GLOBALS['rPermissions'] = $rPermissions;
		$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

		$router = Router::getInstance();
		require MAIN_HOME . 'Public/routes/reseller.php';
		if (!$router->dispatchApi($rAction)) {
			throw new \LogicException('No reseller action ' . $rAction);
		}
	}
}
