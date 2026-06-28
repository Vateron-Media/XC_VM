<?php

namespace XcVm\Core\Events\Stream;

/**
 * Fired after one or more streams have been deleted.
 *
 * Lets modules (e.g. watch) clean up their own per-stream data without core
 * stream code having to know those tables exist.
 *
 * @package XC_VM_Core_Events
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class StreamsDeletedEvent {
    /**
     * @param int[] $streamIds Ids of the streams that were deleted.
     */
    public function __construct(
        public readonly array $streamIds,
    ) {}
}
