<?php

namespace XcVm\Public\Controllers\Admin;

/**
 * AjaxController — legacy admin-ajax fallback.
 *
 * Every real `?action=` endpoint is now handled by a dedicated controller under
 * {@see \XcVm\Public\Controllers\Admin\Ajax}, reached via `Router::dispatchApi()`
 * before this fallback (see `routes/admin.php` and `Public/index.php`). Only an
 * unknown or removed action — or a bare `/api` request — reaches here, and it
 * answers `{"result":false}`. Admin authentication is already enforced by
 * `AdminScopeBootstrap::boot()` before dispatch.
 *
 * (Historically this proxied the ~4500-line `Views/admin/api.php`; that file was
 * fully extracted into the Admin\Ajax controllers and retired.)
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class AjaxController extends BaseAdminController {

    public function index() {
        // Non-AJAX requests are rejected unless debug mode is on — the guard the
        // legacy api.php applied to every action.
        if (!defined('PHP_ERRORS') || !PHP_ERRORS) {
            $rRequestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';

            if (strtolower($rRequestedWith) !== 'xmlhttprequest') {
                exit();
            }
        }

        $this->json(array('result' => false));
    }
}
