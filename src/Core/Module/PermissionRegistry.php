<?php

namespace XcVm\Core\Module;

/**
 * PermissionRegistry — reseller sub-permission keys contributed by modules.
 *
 * The group editor's permission catalogue ({@see \XcVm\Core\Reference\PermissionReference})
 * is a core list; a module adds its OWN permission keys here instead of them
 * being hard-coded in core, so the module owns the permissions it gates on.
 * Keys are appended after the core list, in registration order, de-duplicated.
 *
 * Labels come from the Translator exactly like core keys
 * (`permission_<key>` / `permission_<key>_text`), so a module only needs those
 * language entries.
 *
 * A module implements {@see \XcVm\Core\Module\Contract\PermissionProviderInterface}
 * and calls add() in registerPermissions() (same boot phase as the others).
 *
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class PermissionRegistry {

    /** @var array<string,true> permission key => registered (ordered set) */
    private static array $keys = [];

    /** Register a reseller sub-permission key a module gates on. */
    public static function add(string $key): void {
        self::$keys[$key] = true;
    }

    /**
     * All module-registered permission keys, in registration order.
     *
     * @return string[]
     */
    public static function keys(): array {
        return array_keys(self::$keys);
    }

    /** Clear all registered keys (used by tests / a fresh boot). */
    public static function reset(): void {
        self::$keys = [];
    }
}
