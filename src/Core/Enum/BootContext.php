<?php

namespace XcVm\Core\Enum;

use XcVm\Core\Database\Database;
use XcVm\Core\Init\LegacyInitializer;
use XcVm\Core\Localization\Translator;

/**
 * Boot context for XC_Bootstrap::boot().
 *
 * Replace CONTEXT_* string constants — exhaustive match() enforced by PHP.
 *
 * @package XC_VM_Core_Enum
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
enum BootContext: string {
    /** Constants + config only. No DB. */
    case Minimal = 'minimal';

    /** + Database + LegacyInitializer. For cron jobs and CLI scripts. */
    case Cli = 'cli';

    /** + Database (cached). Lightweight path for streaming endpoints. */
    case Stream = 'stream';

    /** Full initialization: DB + API + Translator + session. */
    case Admin = 'admin';
}
