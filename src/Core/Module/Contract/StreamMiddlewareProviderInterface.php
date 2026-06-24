<?php

namespace XcVm\Core\Module\Contract;

use XcVm\Core\Http\Pipeline\StreamPipeline;
use XcVm\Core\Http\Pipeline\StreamMiddlewareInterface;
/**
 * Optional contract for modules that inject middleware into the stream pipeline.
 *
 * NOT part of ModuleInterface — modules opt in by implementing this interface
 * in addition to their primary contracts.
 *
 * ModuleLoader checks instanceof StreamMiddlewareProviderInterface and calls
 * getStreamMiddleware() only when the StreamPipeline is available.
 *
 * @see StreamPipeline
 * @see StreamMiddlewareInterface
 *
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
interface StreamMiddlewareProviderInterface {

    /**
     * Return stream middleware instances to inject into StreamPipeline.
     *
     * Modules return one or more middleware objects. Each must implement
     * StreamMiddlewareInterface and declare its own priority via getPriority().
     *
     * @return StreamMiddlewareInterface[]
     */
    public function getStreamMiddleware(): array;
}
