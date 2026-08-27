<?php

namespace XcVm\Infrastructure\Bootstrap;

/**
 * Per-scope request bootstrap contract.
 *
 * A scope bootstrap runs the session lifecycle (start/timeout/login redirect)
 * and then the framework + user context init (XC_Bootstrap::boot, $rUserInfo /
 * $rPermissions, session-integrity checks). It replaces the former per-scope
 * pair of procedural includes (`<scope>_session.php` + `<scope>_functions.php`)
 * that the front controller `require`d at global scope.
 *
 * Implementations still inject the legacy view-facing globals ($rUserInfo,
 * $rPermissions, ...) via `global` declarations — the ~140 procedural view
 * templates read them from scope — so behaviour is identical to the includes.
 *
 * @package XC_VM_Infrastructure_Bootstrap
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
interface ScopeBootstrap {

	/**
	 * Boot the request scope: session lifecycle, then framework + user context.
	 *
	 * May `header()`/`exit()` on an unauthenticated or invalidated session,
	 * exactly as the former include pair did.
	 *
	 * @return void
	 */
	public function boot(): void;
}
