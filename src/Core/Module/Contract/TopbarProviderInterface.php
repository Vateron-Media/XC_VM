<?php

namespace XcVm\Core\Module\Contract;

use XcVm\Core\Module\TopbarRegistry;

/**
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
interface TopbarProviderInterface {

    /**
     * Register per-page topbar buttons via the provided TopbarRegistry.
     *
     * Called in bootAll() after all modules have been booted (same phase as
     * registerNavbar). A module may add buttons to its OWN pages and to
     * existing core pages (e.g. inject a "Watch Folder" button into 'movies').
     * Use $registry->add() (static under the hood, mirrors NavbarRegistry).
     */
    public function registerTopbar(TopbarRegistry $registry): void;
}
