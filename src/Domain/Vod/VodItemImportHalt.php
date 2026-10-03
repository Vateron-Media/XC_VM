<?php

namespace XcVm\Domain\Vod;

/**
 * VodItemImportHalt — internal control-flow signal for "stop processing this file".
 *
 * VodItemImporter::run() and its helpers (applyUpgrade, persistImport, the
 * new-series-with-no-category branch of buildSeriesImportArray) used to call
 * exit() at every one of these points, after already logging the outcome via
 * reportResult(). Since vod_import_item always runs as its own PHP process (one
 * per file), exit() and "throw this, catch it once in
 * run(), then return normally" are observably identical to every caller —
 * but the exit() form also kills the PHPUnit process the instant a test
 * exercises that branch. Throwing this instead keeps the exact same
 * behavior while making those branches testable with expectException().
 *
 * @package XC_VM_Domain_Vod
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class VodItemImportHalt extends \RuntimeException {
}
