<?php

namespace XcVm\Infrastructure\Bootstrap;

/**
 * Resolve the {@see ScopeBootstrap} implementation for a request scope.
 *
 * Unknown scopes (e.g. ministra) fall back to admin — matching the former
 * `$rBootstrapFiles[$scope] ?? $rBootstrapFiles['admin']` behaviour.
 *
 * @package XC_VM_Infrastructure_Bootstrap
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class ScopeBootstrapFactory {

	/**
	 * @param string $rScope 'admin' | 'reseller' | 'player' (others → admin).
	 * @return ScopeBootstrap
	 */
	public static function create(string $rScope): ScopeBootstrap {
		switch ($rScope) {
			case 'reseller':
				return new ResellerScopeBootstrap();
			case 'player':
				return new PlayerScopeBootstrap();
			default:
				return new AdminScopeBootstrap();
		}
	}
}
