<?php

namespace XcVm\Core\Events\Bouquet;

/**
 * Fired after a bouquet has been deleted.
 *
 * Lets modules (e.g. watch) drop the deleted bouquet from their own data
 * without core bouquet code referencing module-owned tables.
 *
 * @package XC_VM_Core_Events
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class BouquetDeletedEvent {
    /**
     * @param int $bouquetId Id of the bouquet that was deleted.
     */
    public function __construct(
        public readonly int $bouquetId,
    ) {}
}
