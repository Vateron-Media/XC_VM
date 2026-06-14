<?php

/**
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
interface NavbarProviderInterface {

    /**
     * Register navbar items via NavbarRegistry::add().
     *
     * Called in bootAll() after all modules have been booted.
     */
    public function registerNavbar(): void;
}
