<?php

namespace XcVm\Core\Events\Vod;

/**
 * Fired once per file processed by VodItemImporter (`console.php vod_import_item`),
 * with the outcome of that file.
 *
 * Lets modules (e.g. watch) keep their own per-file log without core VOD code
 * knowing those module tables exist.
 *
 * @package XC_VM_Core_Events
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class VodImportResultEvent {
	public const STATUS_IMPORTED = 1;
	public const STATUS_INSERT_FAILED = 2;
	public const STATUS_NO_CATEGORY = 3;
	public const STATUS_NO_MATCH = 4;
	public const STATUS_BROKEN_FILE = 5;
	public const STATUS_UPGRADED = 6;

	/**
	 * @param int    $type     1 = movie, 2 = series.
	 * @param int    $serverId Server the item was processed on.
	 * @param string $filename Source file path or URL, raw (not HTML-escaped).
	 * @param int    $status   One of the STATUS_* constants.
	 * @param int    $streamId Created stream id (STATUS_IMPORTED only, else 0).
	 * @param string $title    The entry's name from an M3U import, raw; '' for a folder scan.
	 */
	public function __construct(
		public readonly int $type,
		public readonly int $serverId,
		public readonly string $filename,
		public readonly int $status,
		public readonly int $streamId = 0,
		public readonly string $title = '',
	) {
	}
}
