<?php

/**
 * Fired after a user successfully authenticates.
 *
 * @package XC_VM_Core_Events
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final readonly class UserAuthenticatedEvent {
    public function __construct(
        public int    $userId,
        public string $username,
        public string $role,
        public float  $authenticatedAt,
    ) {}
}
