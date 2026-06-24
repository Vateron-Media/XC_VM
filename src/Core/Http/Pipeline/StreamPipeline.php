<?php

namespace XcVm\Core\Http\Pipeline;
use XcVm\Core\Module\ModuleLoader;

/**
 * Ordered middleware pipeline for stream lifecycle processing.
 *
 * Middleware is sorted by getPriority() descending (highest runs first).
 * The pipeline short-circuits when StreamContext::isAborted() returns true.
 *
 * ──────────────────────────────────────────────────────────────────
 * Usage:
 * ──────────────────────────────────────────────────────────────────
 *
 *   $pipeline = new StreamPipeline();
 *
 *   // Core adds its own middleware at boot time (high priority)
 *   $pipeline->pipe(new AuthStreamMiddleware());        // priority 100
 *   $pipeline->pipe(new PermissionMiddleware());        // priority  90
 *   $pipeline->pipe(new ConnectionLimitMiddleware());   // priority  80
 *
 *   // Modules add theirs via ModuleLoader::bootAll()
 *   $pipeline->pipe(new FingerprintMiddleware());       // priority  50
 *   $pipeline->pipe(new TheftDetectionMiddleware());    // priority  60
 *
 *   // Terminal middleware (always last)
 *   $pipeline->pipe(new ExecuteMiddleware());           // priority  -1
 *
 *   $ctx    = new StreamContext($streamId, $userId, $protocol, $params);
 *   $result = $pipeline->run($ctx);
 *
 *   if ($result->isAborted()) {
 *       // handle abort: $result->getAbortReason(), $result->getAbortCode()
 *   }
 *
 * @package XC_VM_Core_Http_Pipeline
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class StreamPipeline {

    /** @var StreamMiddlewareInterface[] Sorted by priority descending */
    private array $middleware = [];

    private bool $sorted = true;

    /**
     * Add a middleware to the pipeline.
     *
     * Inserting middleware triggers a re-sort on next run().
     */
    public function pipe(StreamMiddlewareInterface $middleware): static {
        $this->middleware[] = $middleware;
        $this->sorted       = false;
        return $this;
    }

    /**
     * Execute the pipeline against the given context.
     *
     * Builds a recursive closure chain and invokes it. Each middleware in
     * the chain may call $next($ctx) to continue, or skip it to abort.
     * Aborted contexts are passed through without calling further middleware.
     */
    public function run(StreamContext $ctx): StreamContext {
        if (!$this->sorted) {
            usort(
                $this->middleware,
                fn(StreamMiddlewareInterface $a, StreamMiddlewareInterface $b) =>
                    $b->getPriority() <=> $a->getPriority()
            );
            $this->sorted = true;
        }

        $chain = $this->buildChain($this->middleware);
        return $chain($ctx);
    }

    /**
     * Return all registered middleware in their current sort order.
     *
     * @return StreamMiddlewareInterface[]
     */
    public function getMiddleware(): array {
        return $this->middleware;
    }

    /**
     * Build the recursive closure chain from the middleware array.
     *
     * Iterates in reverse so the first element in $stack becomes the
     * outermost function (called first when the chain is invoked).
     *
     * @param StreamMiddlewareInterface[] $stack
     * @return callable(StreamContext): StreamContext
     */
    private function buildChain(array $stack): callable {
        $terminal = static fn(StreamContext $ctx): StreamContext => $ctx;

        return array_reduce(
            array_reverse($stack),
            static function (callable $next, StreamMiddlewareInterface $mw): callable {
                return static function (StreamContext $ctx) use ($mw, $next): StreamContext {
                    if ($ctx->isAborted()) {
                        return $ctx;
                    }
                    return $mw->handle($ctx, $next);
                };
            },
            $terminal
        );
    }
}
