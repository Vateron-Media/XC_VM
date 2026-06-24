<?php

namespace XcVm\Core\Module;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Module\Contract\CronProviderInterface;

use XcVm\Core\Http\Router;
use XcVm\Core\Database\Database;
/**
 * BaseModule — abstract base class for module implementations.
 *
 * Provides no-op defaults for all optional ModuleInterface methods so
 * concrete modules only need to override what they actually use.
 *
 * getName() and getVersion() remain abstract — they are identity
 * contracts and must be unique per module.
 *
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
abstract class BaseModule implements ModuleInterface, MigratableInterface, CronProviderInterface {

    /**
     * Unique module identifier.
     *
     * @return string
     */
    abstract public function getName(): string;

    /**
     * Module version string.
     *
     * @return string
     */
    abstract public function getVersion(): string;

    /**
     * Boot hook: register services/bindings into the container. No-op by default.
     *
     * @param \XcVm\Core\Container\ServiceContainer $container The DI container.
     * @return void
     */
    public function boot(\XcVm\Core\Container\ServiceContainer $container): void {}

    /**
     * Event subscribers provided by the module. Empty by default.
     *
     * @return array Map/list of event subscribers.
     */
    public function getEventSubscribers(): array {
        return [];
    }

    /**
     * Register the module's HTTP routes. No-op by default.
     *
     * @param Router $router The application router.
     * @return void
     */
    public function registerRoutes(Router $router): void {}

    /**
     * Register the module's CLI commands. No-op by default.
     *
     * @param \CommandRegistry $registry The CLI command registry.
     * @return void
     */
    public function registerCommands(\CommandRegistry $registry): void {}

    /**
     * Register the module's navbar entries. No-op by default.
     *
     * @param NavbarRegistry $registry The navbar registry.
     * @return void
     */
    public function registerNavbar(NavbarRegistry $registry): void {}

    /**
     * Installation hook, run when the module is installed. No-op by default.
     *
     * @return void
     */
    public function install(): void {}

    /**
     * Uninstallation hook, run when the module is removed. No-op by default.
     *
     * @return void
     */
    public function uninstall(): void {}

    /**
     * Database migrations provided by the module. Empty by default.
     *
     * @return array Migration descriptors.
     */
    public function getMigrations(): array {
        return [];
    }

    /**
     * Cron entries provided by the module. Empty by default.
     *
     * @return array Cron entry descriptors.
     */
    public function getCronEntries(): array {
        return [];
    }
}
