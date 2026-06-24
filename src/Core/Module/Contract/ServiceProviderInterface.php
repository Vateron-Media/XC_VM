<?php

namespace XcVm\Core\Module\Contract;
use XcVm\Core\Container\ServiceContainer;

/**
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
interface ServiceProviderInterface {

    /**
     * Register module services in the DI container.
     *
     * Called once per request during module boot phase.
     *
     * @param \XcVm\Core\Container\ServiceContainer $container
     */
    public function boot(\XcVm\Core\Container\ServiceContainer $container): void;

    /**
     * Return event subscribers declared by this module.
     *
     * Key is a fully-qualified event class name, value is a callable
     * or [callable, int $priority] tuple.
     *
     * @return array<class-string, callable|array{callable, int}>
     */
    public function getEventSubscribers(): array;

}
