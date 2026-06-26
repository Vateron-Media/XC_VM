<?php

namespace XcVm\Core\Events\Module;

use XcVm\Core\Module\ModuleLoader;

/**
 * Fired by ModuleLoader after a module class is instantiated and stored.
 *
 * @package XC_VM_Core_Events
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class ModuleLoadedEvent {
    /**
     * @param string $name Module name.
     * @param string $path Filesystem path the module was loaded from.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $path,
    ) {}
}
