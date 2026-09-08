<?php

namespace XcVm\Core\Events\Vod;

/**
 * Fired after a VOD item (movie) is created/updated from a source path.
 *
 * Lets modules (e.g. watch) reconcile their own per-file bookkeeping — such as
 * marking a watch-folder log row as imported and linking the new stream — without
 * core VOD code having to know those module tables exist.
 *
 * @package XC_VM_Core_Events
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class VodImportedEvent {
    /**
     * @param int    $streamId   Id of the created/updated VOD stream.
     * @param string $sourcePath File path the item was imported from (server prefix stripped).
     * @param int    $type       VOD type (1 = movie).
     */
    public function __construct(
        public readonly int $streamId,
        public readonly string $sourcePath,
        public readonly int $type = 1,
    ) {}
}
