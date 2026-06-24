<?php

namespace XcVm\Core\Http\Pipeline;

/**
 * Mutable context object passed through the stream middleware pipeline.
 *
 * A middleware reads params, may abort processing, or passes the context
 * to the next middleware via $next($ctx). The final middleware (ExecuteMiddleware)
 * actually starts the stream.
 *
 * @package XC_VM_Core_Http_Pipeline
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class StreamContext {

    private bool   $aborted     = false;
    private string $abortReason = '';
    private int    $abortCode   = 0;

    /** @var array<string, mixed> Arbitrary data bag for middleware communication */
    private array $attributes = [];

    /**
     * @param int    $streamId Stream being processed.
     * @param string $userId   User requesting the stream.
     * @param string $protocol Delivery protocol.
     * @param array  $params   Request parameters.
     */
    public function __construct(
        public readonly int    $streamId,
        public readonly string $userId,
        public readonly string $protocol,
        public readonly array  $params,
    ) {}

    // ─────────────────────────────────────────────────────────
    //  Abort control
    // ─────────────────────────────────────────────────────────

    /**
     * Abort pipeline execution with a reason and optional HTTP-style code.
     *
     * Once aborted, StreamPipeline will not call further middleware.
     *
     * @param string $reason Human-readable reason (logged / returned to client)
     * @param int    $code   Application-level error code (0 = unspecified)
     */
    public function abort(string $reason, int $code = 0): void {
        $this->aborted     = true;
        $this->abortReason = $reason;
        $this->abortCode   = $code;
    }

    /**
     * Whether the pipeline has been aborted.
     *
     * @return bool
     */
    public function isAborted(): bool {
        return $this->aborted;
    }

    /**
     * Reason passed to abort(), or '' if not aborted.
     *
     * @return string
     */
    public function getAbortReason(): string {
        return $this->abortReason;
    }

    /**
     * Application-level abort code (0 = unspecified).
     *
     * @return int
     */
    public function getAbortCode(): int {
        return $this->abortCode;
    }

    // ─────────────────────────────────────────────────────────
    //  Attribute bag (middleware communication)
    // ─────────────────────────────────────────────────────────

    /**
     * Store an arbitrary value for downstream middleware to read.
     */
    public function set(string $key, mixed $value): void {
        $this->attributes[$key] = $value;
    }

    /**
     * Read an attribute set by an earlier middleware.
     *
     * @param string $key     Attribute key.
     * @param mixed  $default Value returned when the key is absent.
     * @return mixed The stored value, or $default.
     */
    public function get(string $key, mixed $default = null): mixed {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Whether an attribute exists in the bag.
     *
     * @param string $key Attribute key.
     * @return bool
     */
    public function has(string $key): bool {
        return array_key_exists($key, $this->attributes);
    }
}
