<?php

/**
 * Fired after a stream session ends (normally or via abort).
 *
 * @package XC_VM_Core_Events
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class StreamStoppedEvent {
    public function __construct(
        public readonly int    $streamId,
        public readonly string $userId,
        public readonly float  $stoppedAt,
        public readonly string $reason,
    ) {}
}
