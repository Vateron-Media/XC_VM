<?php

namespace XcVm\Core\Module\Contract;

use XcVm\Core\Module\TableRegistry;

/**
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
interface TableProviderInterface {

    /**
     * Register serverSide DataTable handlers via the provided TableRegistry.
     *
     * Called in bootAll() (same phase as registerNavbar/registerTopbar), before
     * the ./table request dispatches. Register a handler for each table id the
     * module owns so its builder lives in the module, not in core TableController.
     */
    public function registerTables(TableRegistry $registry): void;
}
