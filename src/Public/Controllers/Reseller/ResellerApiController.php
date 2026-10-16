<?php

namespace XcVm\Public\Controllers\Reseller;

/**
 * ResellerApiController — reseller-ajax fallback.
 *
 * Every real `?action=` endpoint is handled by a controller under
 * {@see Ajax}, reached via `Router::dispatchApi()` before this fallback (see
 * `routes/reseller.php` and `Public/index.php`). Only an unknown action, or a
 * bare `/api` request, reaches here, and it answers `{"result":false}`.
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ResellerApiController extends BaseResellerController {
	public function index() {
		if (!PHP_ERRORS && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest') {
			exit();
		}

		echo json_encode(['result' => false]);
		exit();
	}
}
