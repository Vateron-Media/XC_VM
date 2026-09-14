<?php

namespace XcVm\Core\Module;

/**
 * TopbarRegistry — per-page action-bar contributions from modules.
 *
 * The admin per-page topbar (primary button + related-tools dropdown) is built
 * by {@see \XcVm\Core\Util\Topbar}. Core pages come from Topbar's own literal;
 * modules contribute their own buttons — for their own pages AND for existing
 * core pages — through this registry, so a module owns its topbar entries
 * instead of them being hard-coded in the core panel.
 *
 * A module implements {@see \XcVm\Core\Module\Contract\TopbarProviderInterface}
 * and, in registerTopbar(), calls add() once per button. Topbar::config() then
 * merges these on top of the core literal (module entries are appended after a
 * page's core entries, ordered among themselves by the `order` argument).
 *
 * Entry spec matches the core literal shape: [url, permission, attr].
 *   - url        page/URL the button links to (null for a JS-only action)
 *   - permission 'adv' sub-permission gating the button (null = always shown)
 *   - attr       raw extra HTML attributes, e.g. onClick="..." or id="btn-*"
 *
 * Registration order per (page,label) is last-wins, mirroring NavbarRegistry.
 *
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class TopbarRegistry {

    /** @var array<string,array<string,array{spec:array{0:?string,1:?string,2:?string},order:int}>> page => label => entry */
    private static array $entries = [];

    /** @var array<string,true> pages a module marked as export (CSV/JSON) pages */
    private static array $exportPages = [];

    /** @var array<string,string> page => clear-logs type (for the btn-clear-logs button) */
    private static array $logTypes = [];

    /**
     * Register (or override) one topbar button for a page.
     *
     * @param string      $page       Topbar page key (AdminHelpers::getPageName()), e.g. 'watch' or 'movies'.
     * @param string      $label      Button label (also the per-page dedup key).
     * @param string|null $url        Target page/URL; null for a JS-only action (pair with $attr).
     * @param string|null $permission 'adv' sub-permission required to see the button; null = always.
     * @param string|null $attr       Raw extra attributes (onClick="...", id="btn-export-csv", …).
     * @param int         $order      Sort order among a page's module entries (ascending).
     */
    public static function add(
        string $page,
        string $label,
        ?string $url = null,
        ?string $permission = null,
        ?string $attr = null,
        int $order = 100
    ): void {
        self::$entries[$page][$label] = [
            'spec'  => [$url, $permission, $attr],
            'order' => $order,
        ];
    }

    /**
     * Page keys that have at least one module-contributed entry.
     *
     * @return string[]
     */
    public static function pages(): array {
        return array_keys(self::$entries);
    }

    /**
     * Ordered `label => [url, permission, attr]` entries for one page.
     *
     * @return array<string,array{0:?string,1:?string,2:?string}>
     */
    public static function forPage(string $page): array {
        $rItems = self::$entries[$page] ?? [];
        uasort($rItems, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);

        $rOut = [];
        foreach ($rItems as $rLabel => $rEntry) {
            $rOut[$rLabel] = $rEntry['spec'];
        }
        return $rOut;
    }

    /**
     * Mark a page as a report/export page so its "Export as CSV/JSON" topbar
     * buttons pass the core export gate (still additionally requires the
     * `backups` permission). Use for a module's own log/report page.
     */
    public static function markExportPage(string $page): void {
        self::$exportPages[$page] = true;
    }

    /** Whether a module marked this page as an export page. */
    public static function isExportPage(string $page): bool {
        return isset(self::$exportPages[$page]);
    }

    /**
     * Declare the clear-logs type for a page's `btn-clear-logs` button
     * (the `type` sent to action=clear_logs), for a module-owned log page.
     */
    public static function setLogType(string $page, string $type): void {
        self::$logTypes[$page] = $type;
    }

    /** The clear-logs type a module declared for a page, or null. */
    public static function logType(string $page): ?string {
        return self::$logTypes[$page] ?? null;
    }

    /**
     * Clear all registered entries (used by tests / a fresh boot).
     */
    public static function reset(): void {
        self::$entries = [];
        self::$exportPages = [];
        self::$logTypes = [];
    }
}
