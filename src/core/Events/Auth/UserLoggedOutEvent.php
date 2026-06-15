<?php

/**
 * Fired after a user session is terminated.
 *
 * @package XC_VM_Core_Events
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class UserLoggedOutEvent {
    public function __construct(
        public readonly int    $userId,
        public readonly string $username,
        public readonly float  $loggedOutAt,
    ) {}
}
