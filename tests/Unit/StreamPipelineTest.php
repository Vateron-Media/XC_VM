<?php

use XcVm\Core\Http\Pipeline\StreamPipeline;
use XcVm\Core\Http\Pipeline\StreamMiddlewareInterface;
use XcVm\Core\Http\Pipeline\StreamContext;
use PHPUnit\Framework\TestCase;

final class StreamPipelineTest extends TestCase {

    // ── Basic pipeline execution ───────────────────────────────

    public function testRunPassesContextThroughMiddleware(): void {
        $pipeline = new StreamPipeline();
        $pipeline->pipe(new SetAttributeMiddleware('touched', true));

        $ctx    = new StreamContext(1, 'u1', 'hls', []);
        $result = $pipeline->run($ctx);

        $this->assertTrue($result->get('touched'));
    }

    public function testRunWithNoMiddlewareReturnsContextUnchanged(): void {
        $pipeline = new StreamPipeline();
        $ctx      = new StreamContext(1, 'u1', 'hls', []);
        $result   = $pipeline->run($ctx);

        $this->assertSame($ctx, $result);
    }

    // ── Priority ordering ──────────────────────────────────────

    public function testMiddlewareRunsInPriorityDescendingOrder(): void {
        $order    = [];
        $pipeline = new StreamPipeline();
        $pipeline->pipe(new RecordOrderMiddleware($order, 'low',  10));
        $pipeline->pipe(new RecordOrderMiddleware($order, 'high', 50));
        $pipeline->pipe(new RecordOrderMiddleware($order, 'mid',  30));

        $pipeline->run(new StreamContext(1, 'u', 'hls', []));

        $this->assertSame(['high', 'mid', 'low'], $order);
    }

    // ── Abort behaviour ────────────────────────────────────────

    public function testAbortedContextSkipsRemainingMiddleware(): void {
        $afterAbort = false;
        $pipeline   = new StreamPipeline();
        $pipeline->pipe(new AbortingMiddleware(100));
        $pipeline->pipe(new CallbackMiddleware(function (StreamContext $ctx, callable $next) use (&$afterAbort) {
            $afterAbort = true;
            return $next($ctx);
        }, 50));

        $result = $pipeline->run(new StreamContext(1, 'u', 'hls', []));

        $this->assertTrue($result->isAborted());
        $this->assertFalse($afterAbort);
    }

    public function testAbortSetsReasonAndCode(): void {
        $pipeline = new StreamPipeline();
        $pipeline->pipe(new AbortingMiddleware(100, 'blocked', 403));

        $result = $pipeline->run(new StreamContext(1, 'u', 'hls', []));

        $this->assertSame('blocked', $result->getAbortReason());
        $this->assertSame(403, $result->getAbortCode());
    }

    // ── StreamContext attribute bag ────────────────────────────

    public function testContextAttributeBag(): void {
        $ctx = new StreamContext(1, 'u', 'hls', []);
        $ctx->set('key', 'value');

        $this->assertTrue($ctx->has('key'));
        $this->assertSame('value', $ctx->get('key'));
    }

    public function testContextGetReturnsNullForMissingAttribute(): void {
        $ctx = new StreamContext(1, 'u', 'hls', []);

        $this->assertNull($ctx->get('missing'));
    }

    // ── getMiddleware ──────────────────────────────────────────

    public function testGetMiddlewareReturnsRegisteredMiddleware(): void {
        $mw       = new SetAttributeMiddleware('k', 'v');
        $pipeline = new StreamPipeline();
        $pipeline->pipe($mw);

        $this->assertContains($mw, $pipeline->getMiddleware());
    }
}

// ── Middleware fixtures ────────────────────────────────────────────

final class SetAttributeMiddleware implements StreamMiddlewareInterface {
    public function __construct(
        private readonly string $key,
        private readonly mixed  $value,
        private readonly int    $priority = 0,
    ) {}

    public function handle(StreamContext $ctx, callable $next): StreamContext {
        $ctx->set($this->key, $this->value);
        return $next($ctx);
    }

    public function getPriority(): int { return $this->priority; }
}

final class RecordOrderMiddleware implements StreamMiddlewareInterface {
    public function __construct(
        private array  &$order,
        private string  $label,
        private int     $priority,
    ) {}

    public function handle(StreamContext $ctx, callable $next): StreamContext {
        $this->order[] = $this->label;
        return $next($ctx);
    }

    public function getPriority(): int { return $this->priority; }
}

final class AbortingMiddleware implements StreamMiddlewareInterface {
    public function __construct(
        private int    $priority,
        private string $reason = 'aborted',
        private int    $code   = 0,
    ) {}

    public function handle(StreamContext $ctx, callable $next): StreamContext {
        $ctx->abort($this->reason, $this->code);
        return $ctx;
    }

    public function getPriority(): int { return $this->priority; }
}

final class CallbackMiddleware implements StreamMiddlewareInterface {
    public function __construct(
        private readonly \Closure $callback,
        private readonly int      $priority = 0,
    ) {}

    public function handle(StreamContext $ctx, callable $next): StreamContext {
        return ($this->callback)($ctx, $next);
    }

    public function getPriority(): int { return $this->priority; }
}
