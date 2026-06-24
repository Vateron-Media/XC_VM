<?php

/**
 * Fired after a user successfully authenticates.
 *
 * @package XC_VM_Core_Events
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class UserAuthenticatedEvent {
    /**
     * @param int    $userId          Authenticated user id.
     * @param string $username        Authenticated username.
     * @param string $role            User role.
     * @param float  $authenticatedAt Unix timestamp (with microseconds) of authentication.
     */
    public function __construct(
        public readonly int    $userId,
        public readonly string $username,
        public readonly string $role,
        public readonly float  $authenticatedAt,
    ) {}
}
