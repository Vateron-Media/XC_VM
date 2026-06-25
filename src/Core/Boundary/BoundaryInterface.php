<?php

namespace XcVm\Core\Boundary;

use XcVm\Core\Http\Router;
use XcVm\Core\Module\ModuleLoader;
use XcVm\Core\Module\NavbarRegistry;

/**
 * Marks a subsystem as an isolated request boundary.
 *
 * A boundary has its own entry point and bootstrap path, and does not
 * participate in the main application's Router, ModuleLoader bootAll(),
 * or NavbarRegistry. It shares infrastructure (db, cache, config) but
 * nothing above that.
 *
 * Implementing this interface is a declaration of intent — it makes the
 * isolation explicit and discoverable without runtime enforcement.
 *
 * @package XC_VM_Core_Boundary
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
interface BoundaryInterface {

    /**
     * Unique boundary name (e.g. 'ministra', 'player').
     */
    public function getName(): string;

    /**
     * Public entry-point path relative to src/ (e.g. 'ministra/portal.php').
     */
    public function getEntryPoint(): string;

    /**
     * Whether this boundary has its own bootstrap, independent of XC_Bootstrap.
     */
    public function isIsolated(): bool;
}
