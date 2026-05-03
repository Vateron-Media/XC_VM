<?php

/**
 * NavbarRegistry — tree-based registry for admin navigation.
 *
 * All items (core and module) are registered via add().
 * Core items are registered by CoreNavbarProvider::register() before any
 * module's registerNavbar() is called.
 *
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class NavbarRegistry {

    /** @var NavbarItem[] Key → NavbarItem map */
    private static $items = [];

    /**
     * Register a single navigation item in the registry.
     *
     * Items are stored by their unique dot-separated key.
     * Duplicate keys will overwrite previously registered items.
     *
     * @param NavbarItem $item The navigation item to register
     * @return void
     */
    public static function add(NavbarItem $item): void {
        self::$items[$item->key] = $item;
    }

    /**
     * Returns all top-level navigation items.
     *
     * Top-level items are those with parent === null.
     * Items are sorted by their order property (ascending).
     *
     * @return NavbarItem[] Array of top-level items sorted by order
     */
    public static function getTopLevel(): array {
        return self::_sorted(null);
    }

    /**
     * Returns direct children of the given parent key.
     *
     * Only immediate children are returned (not descendants).
     * Items are sorted by their order property (ascending).
     *
     * @param string $parentKey The dot-separated key of the parent item
     * @return NavbarItem[] Array of child items sorted by order
     */
    public static function getChildren(string $parentKey): array {
        return self::_sorted($parentKey);
    }

    /**
     * Check if a navigation item has any child items.
     *
     * @param string $key The dot-separated key of the item to check
     * @return bool True if the item has at least one child, false otherwise
     */
    public static function hasChildren(string $key): bool {
        foreach (self::$items as $item) {
            if ($item->parent === $key) return true;
        }
        return false;
    }

    /**
     * Reset the registry by clearing all registered items.
     *
     * Primarily useful for unit testing to ensure a clean state
     * between test cases.
     *
     * @return void
     */
    public static function reset(): void {
        self::$items = [];
    }

    /**
     * Get all navigation items belonging to a specific parent.
     *
     * Internal helper method that filters and sorts items by their
     * order property. Returns empty array if no items match.
     *
     * @param string|null $parent Parent key (null for top-level items)
     * @return NavbarItem[] Array of matching items sorted by order
     */
    private static function _sorted(?string $parent): array {
        $result = [];
        foreach (self::$items as $item) {
            if ($item->parent === $parent) {
                $result[] = $item;
            }
        }
        usort($result, function ($a, $b) {
            return $a->order - $b->order;
        });
        return $result;
    }
}
