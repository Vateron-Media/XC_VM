<?php

namespace XcVm\Core\Module;

/**
 * QuickToolsRegistry — one-shot maintenance actions contributed by modules.
 *
 * The admin Quick Tools page (`Views/admin/quick_tools.php`) renders a grouped
 * set of Run buttons; each posts its key to `post.php?action=quick_tools`, whose
 * handler runs the matching action. Both halves are core lists. A module adds
 * its OWN tool — the button AND the action — through
 * {@see \XcVm\Core\Module\Contract\QuickToolsProviderInterface}, so the tool
 * lives in the module instead of being hard-coded in core.
 *
 * add($group, $key, $label, $handler):
 *   - $group   existing Quick Tools tab key (e.g. 'logs', 'general') or a new
 *              one (rendered with a generic icon and $group as its label key).
 *   - $key     the POST action key (also the checkbox/button action name).
 *   - $label   translation key for the button label.
 *   - $handler fn(): void — performs the action. It must NOT echo/exit; post.php
 *              emits the standard success JSON after it runs. Query via global $db.
 *
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class QuickToolsRegistry {

    /** @var array<string,array<int,array{0:string,1:string}>> group => list of [key, label] */
    private static array $tools = [];

    /** @var array<string,callable> key => handler */
    private static array $handlers = [];

    /** Register a Quick Tools action (button + handler). */
    public static function add(string $group, string $key, string $label, callable $handler): void {
        self::$tools[$group][] = [$key, $label];
        self::$handlers[$key] = $handler;
    }

    /** Groups that have at least one module tool. */
    public static function groups(): array {
        return array_keys(self::$tools);
    }

    /**
     * `[key, label]` rows a module added to a group (for the view).
     *
     * @return array<int,array{0:string,1:string}>
     */
    public static function forGroup(string $group): array {
        return self::$tools[$group] ?? [];
    }

    /** All registered tool keys (for post.php dispatch). */
    public static function keys(): array {
        return array_keys(self::$handlers);
    }

    /** The handler for a tool key, or null. */
    public static function handler(string $key): ?callable {
        return self::$handlers[$key] ?? null;
    }

    /** Clear all registered tools (used by tests / a fresh boot). */
    public static function reset(): void {
        self::$tools = [];
        self::$handlers = [];
    }
}
