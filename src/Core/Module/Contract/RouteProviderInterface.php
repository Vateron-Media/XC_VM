<?php

namespace XcVm\Core\Module\Contract;

use XcVm\Core\Http\Router;

/**
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
interface RouteProviderInterface {

    /**
     * Register HTTP routes for this module.
     *
     * Called after boot(). Module registers GET/POST routes and API handlers.
     *
     * @param Router $router
     */
    public function registerRoutes(Router $router): void;
}
