<?php

/**
 * Fired by ModuleLoader after a module's boot() has been called.
 *
 * @package XC_VM_Core_Events
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class ModuleBootedEvent {
    /**
     * @param string $name    Module name.
     * @param string $version Module version.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
    ) {}
}
