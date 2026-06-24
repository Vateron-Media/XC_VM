<?php

/**
 * Fired before a stream starts — stoppable.
 *
 * A listener can call stopPropagation() to abort the stream.
 * Callers must check isPropagationStopped() after dispatch and honour the abort.
 *
 * @package XC_VM_Core_Events
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class StreamStartingEvent extends AbstractEvent {

    private string $abortReason = '';

    /**
     * @param int    $streamId Stream being started.
     * @param string $userId   User requesting the stream.
     * @param string $protocol Delivery protocol (e.g. hls, mpegts).
     * @param array  $params   Additional request parameters.
     */
    public function __construct(
        public readonly int    $streamId,
        public readonly string $userId,
        public readonly string $protocol,
        public readonly array  $params,
    ) {}

    /**
     * Abort the stream start and stop event propagation.
     *
     * @param string $reason Human-readable abort reason.
     * @return void
     */
    public function abort(string $reason): void {
        $this->abortReason = $reason;
        $this->stopPropagation();
    }

    /**
     * Reason supplied to abort(), or '' if not aborted.
     *
     * @return string
     */
    public function getAbortReason(): string {
        return $this->abortReason;
    }
}
