<?php

namespace XcVm\Core\Enum;

/**
 * Server deployment environment.
 *
 * Used by ModuleLoader to filter modules by their target environment.
 * Determined from SERVER_TYPE constant at runtime.
 *
 * @package XC_VM_Core_Enum
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
enum ServerEnvironment: string {
    /** Standard panel installation. */
    case Main = 'main';

    /** Load-balancer node (SERVER_TYPE=lb). */
    case LoadBalancer = 'lb';
}
