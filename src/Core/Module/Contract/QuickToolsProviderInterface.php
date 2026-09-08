<?php

namespace XcVm\Core\Module\Contract;

use XcVm\Core\Module\QuickToolsRegistry;

/**
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
interface QuickToolsProviderInterface {

    /**
     * Register one-shot Quick Tools actions via the provided registry.
     *
     * Called in bootAll() (same phase as the other providers), before the Quick
     * Tools page renders and before post.php dispatches the action. Each tool
     * contributes a button and a handler owned by the module.
     */
    public function registerQuickTools(QuickToolsRegistry $registry): void;
}
