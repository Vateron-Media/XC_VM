<?php

namespace XcVm\Core\Module;
use XcVm\Core\Module\Contract\ServiceProviderInterface;
use XcVm\Core\Module\Contract\RouteProviderInterface;
use XcVm\Core\Module\Contract\NavbarProviderInterface;
use XcVm\Core\Module\Contract\CommandProviderInterface;

/**
 * Module contract — composite interface for full lifecycle modules.
 *
 * Extends all sub-interfaces for backward compatibility. Existing modules
 * implementing ModuleInterface continue to work unchanged.
 *
 * New modules can implement only the sub-interfaces they actually need:
 *   - ServiceProviderInterface   — boot(), getEventSubscribers(), getStreamMiddleware()
 *   - RouteProviderInterface     — registerRoutes()
 *   - CommandProviderInterface   — registerCommands()
 *   - NavbarProviderInterface    — registerNavbar()
 *
 * ModuleLoader checks each interface separately via instanceof, so partial
 * implementations are fully supported.
 *
 * Lifecycle (web context):
 *   Discovery → Load → boot() → registerRoutes() → registerNavbar()
 *               → getEventSubscribers() → getStreamMiddleware()
 *
 * Lifecycle (install/uninstall):
 *   install()   — create tables, seed initial data
 *   uninstall() — drop tables, clean settings, remove cron entries
 *
 * @see ServiceProviderInterface
 * @see RouteProviderInterface
 * @see CommandProviderInterface
 * @see NavbarProviderInterface
 *
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
interface ModuleInterface extends
    ServiceProviderInterface,
    RouteProviderInterface,
    CommandProviderInterface,
    NavbarProviderInterface
{
    /**
     * Unique module name — must match the directory name in modules/.
     */
    public function getName(): string;

    /**
     * Module version (semver).
     */
    public function getVersion(): string;

    /**
     * Run once when the module is enabled.
     *
     * Creates tables, seeds initial data.
     */
    public function install(): void;

    /**
     * Run once when the module is disabled or removed.
     *
     * Drops tables, clears settings entries, removes cron records.
     */
    public function uninstall(): void;
}
