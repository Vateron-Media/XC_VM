<?php

namespace XcVm\Core\Module\Contract;

use XcVm\Core\Module\PermissionRegistry;

/**
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
interface PermissionProviderInterface {

    /**
     * Register reseller sub-permission keys via the provided PermissionRegistry.
     *
     * Called in bootAll() (same phase as the other providers). The keys appear
     * in the group editor's permission catalogue with `permission_<key>` labels.
     */
    public function registerPermissions(PermissionRegistry $registry): void;
}
