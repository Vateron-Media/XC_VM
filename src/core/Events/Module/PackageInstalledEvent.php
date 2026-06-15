<?php

/**
 * Fired after XC_VM::module_install() completes successfully.
 *
 * Allows subsystems to react to a new module being downloaded and extracted
 * by the C extension — e.g. triggering hot-reload or audit logging.
 *
 * @package XC_VM_Core_Events
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class PackageInstalledEvent {
    public function __construct(
        public readonly string $slug,
        public readonly string $version,
        public readonly string $path,
        public readonly int    $installedAt,
    ) {}
}
