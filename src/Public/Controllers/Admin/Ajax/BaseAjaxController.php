<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Auth\Authorization;

/**
 * Base class for admin-ajax controllers extracted from the legacy `admin/api.php`.
 *
 * api.php is a flat chain of ~90 `if (action == 'x') { … exit(); }` blocks, each
 * repeating the same scaffolding: permission gate, `echo json_encode(...)`,
 * `exit()`. This class collects that scaffolding into a few methods so an
 * extracted action reads as "gate -> service -> response".
 *
 * Emits JSON only (a POPO, like {@see \XcVm\Public\Controllers\Admin\TmdbController})
 * — no layout/templates — so it does NOT extend
 * {@see \XcVm\Public\Controllers\Admin\BaseAdminController}.
 *
 * Actions reach the controller via `Router::dispatchApi()` (see
 * `Public/index.php`), which runs BEFORE the `AjaxController` -> api.php
 * fallback. Admin authentication is already enforced by
 * `AdminScopeBootstrap::boot()` before dispatch, so only the per-action
 * permission gate and (for parity with api.php) the XHR guard remain here.
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
abstract class BaseAjaxController {

    /**
     * JSON response that terminates the request — the `echo json_encode(...);
     * exit();` of api.php, but with a correct Content-Type (like
     * {@see \XcVm\Public\Controllers\Admin\BaseAdminController::json()}).
     *
     * @param array<string, mixed> $rData
     * @param int $rFlags optional json_encode() flags (e.g. JSON_PARTIAL_OUTPUT_ON_ERROR)
     */
    protected function json(array $rData, int $rFlags = 0): never {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }

        echo json_encode($rData, $rFlags);

        exit();
    }

    /**
     * Success: `{"result":true}` plus optional extra keys.
     *
     * @param array<string, mixed> $rExtra
     */
    protected function ok(array $rExtra = array()): never {
        $this->json(array('result' => true) + $rExtra);
    }

    /**
     * Failure: `{"result":false}` plus optional extra keys. The canonical tail
     * of almost every api.php block.
     *
     * @param array<string, mixed> $rExtra
     */
    protected function fail(array $rExtra = array()): never {
        $this->json(array('result' => false) + $rExtra);
    }

    /**
     * Permission gate: the per-action `Authorization::check($type, $key)` of
     * api.php. On failure it emits `{"result":false}` and ends the request —
     * exactly like `else { echo json_encode(['result'=>false]); exit(); }`.
     */
    protected function gate(string $rType, string $rKey): void {
        if (!Authorization::check($rType, $rKey)) {
            $this->fail();
        }
    }

    /**
     * OR gate: passes if at least one check succeeds — the api.php idiom
     * `if (Authorization::check(a) || Authorization::check(b)) { … }`. If every
     * check fails it emits `{"result":false}` and ends the request.
     *
     * @param array<array{0: string, 1: string}> $rChecks [type, key] pairs
     */
    protected function gateAny(array $rChecks): void {
        foreach ($rChecks as $rCheck) {
            if (Authorization::check($rCheck[0], $rCheck[1])) {
                return;
            }
        }

        $this->fail();
    }

    /**
     * XHR guard from api.php (its `if (!PHP_ERRORS) { … X-Requested-With … }`):
     * non-AJAX requests are rejected unless debug mode (`PHP_ERRORS`) is on.
     * Reproduces the api.php behaviour for actions moved into controllers.
     */
    protected function requireXhr(): void {
        if (defined('PHP_ERRORS') && PHP_ERRORS) {
            return;
        }

        $rRequestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';

        if (strtolower($rRequestedWith) !== 'xmlhttprequest') {
            exit();
        }
    }
}
