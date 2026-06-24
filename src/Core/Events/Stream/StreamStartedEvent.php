<?php

/**
 * Fired after a stream has successfully started.
 *
 * @package XC_VM_Core_Events
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class StreamStartedEvent {
    /**
     * @param int    $streamId  Stream that started.
     * @param string $userId    User the stream started for.
     * @param string $protocol  Delivery protocol.
     * @param float  $startedAt Unix timestamp (with microseconds) of start.
     */
    public function __construct(
        public readonly int    $streamId,
        public readonly string $userId,
        public readonly string $protocol,
        public readonly float  $startedAt,
    ) {}
}
