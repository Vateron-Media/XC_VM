<?php

/**
 * Contract for stream pipeline middleware.
 *
 * Middleware receives a StreamContext and a $next callable.
 * It must either call $next($ctx) to continue the chain or abort via $ctx->abort().
 *
 * Core middleware (Auth, Permission, ConnectionLimit) use high priorities (80–100).
 * Module middleware should use priorities in the 0–79 range.
 * The terminal ExecuteMiddleware uses priority -1 and must always be last.
 *
 * @package XC_VM_Core_Http_Pipeline
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
interface StreamMiddlewareInterface {

    /**
     * Handle the stream context and pass control to the next middleware.
     *
     * @param StreamContext       $ctx  Mutable context object
     * @param callable(StreamContext): StreamContext $next Next middleware in chain
     */
    public function handle(StreamContext $ctx, callable $next): StreamContext;

    /**
     * Execution priority — higher value runs first.
     *
     * Core reserved ranges:
     *   100 = AuthStreamMiddleware
     *   90  = PermissionMiddleware
     *   80  = ConnectionLimitMiddleware
     *   0–79 = module middleware
     *   -1  = ExecuteMiddleware (terminal)
     */
    public function getPriority(): int;
}
