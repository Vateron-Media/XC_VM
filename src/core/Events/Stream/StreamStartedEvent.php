<?php

/**
 * Fired after a stream has successfully started.
 *
 * @package XC_VM_Core_Events
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final readonly class StreamStartedEvent {
    public function __construct(
        public int    $streamId,
        public string $userId,
        public string $protocol,
        public float  $startedAt,
    ) {}
}
