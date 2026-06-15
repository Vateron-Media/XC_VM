<?php

declare(strict_types=1);

/**
 * Module lifecycle state stored in config/modules.php overrides.
 *
 * Replaces the legacy bool `enabled` flag with an exhaustive enum
 * so transition logic can use match() without defensive defaults.
 *
 * Backward compatibility: ModuleState::fromRaw() maps
 * `true` → Enabled and `false` → Disabled when reading legacy overrides.
 *
 * @package XC_VM_Core_Enum
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
enum ModuleState: string {
    /** Module is active and loaded by ModuleLoader. */
    case Enabled    = 'enabled';

    /** Module is present but suppressed from loading. */
    case Disabled   = 'disabled';

    /** Module install is in progress (transient; set before install(), cleared on completion). */
    case Installing = 'installing';

    /** Module install or update failed; manual intervention required. */
    case Failed     = 'failed';

    /**
     * Return true when this state means the module should be loaded by ModuleLoader.
     */
    public function isLoadable(): bool {
        return $this === self::Enabled;
    }

    /**
     * Convert a raw overrides value (bool|string|null) to a ModuleState.
     *
     * Handles legacy `true` / `false` boolean values written by the old
     * setEnabled(name, bool) API, as well as new string values.
     *
     * @param mixed $raw The raw value from config/modules.php overrides.
     */
    public static function fromRaw(mixed $raw): self {
        if ($raw === true || $raw === null) {
            return self::Enabled;
        }
        if ($raw === false) {
            return self::Disabled;
        }
        return self::tryFrom((string) $raw) ?? self::Enabled;
    }
}
