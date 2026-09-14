<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Auth\Authorization;

/**
 * Base class for the admin-ajax controllers.
 *
 * Emits JSON only (no layout/templates), so it does NOT extend
 * {@see \XcVm\Public\Controllers\Admin\BaseAdminController}. Provides the shared
 * scaffolding every action reuses: ok()/fail() for the `{"result":…}` envelope,
 * gate()/gateAny() for per-action permission checks, requireXhr() for the
 * AJAX-only guard, and json() for a raw JSON body.
 *
 * Controllers are reached via `Router::dispatchApi()`; admin authentication is
 * enforced by `AdminScopeBootstrap::boot()` before dispatch, so only the
 * per-action permission gate and the XHR guard remain here.
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
abstract class BaseAjaxController {

    /**
     * JSON response that terminates the request, with the correct Content-Type
     * (like {@see \XcVm\Public\Controllers\Admin\BaseAdminController::json()}).
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
     * Failure: `{"result":false}` plus optional extra keys.
     *
     * @param array<string, mixed> $rExtra
     */
    protected function fail(array $rExtra = array()): never {
        $this->json(array('result' => false) + $rExtra);
    }

    /**
     * Permission gate: `Authorization::check($type, $key)`. On failure it emits
     * `{"result":false}` and ends the request.
     */
    protected function gate(string $rType, string $rKey): void {
        if (!Authorization::check($rType, $rKey)) {
            $this->fail();
        }
    }

    /**
     * OR gate: passes if at least one `[type, key]` check succeeds; if every
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
     * XHR guard: non-AJAX requests are rejected unless debug mode (`PHP_ERRORS`)
     * is on.
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
