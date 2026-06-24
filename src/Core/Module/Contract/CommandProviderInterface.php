<?php

/**
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
interface CommandProviderInterface {

    /**
     * Register CLI commands and cron jobs for this module.
     *
     * Module explicitly instantiates and registers CommandInterface instances.
     * No filesystem scanning — all registration is explicit PHP.
     *
     * @param CommandRegistry $registry
     */
    public function registerCommands(CommandRegistry $registry): void;
}
