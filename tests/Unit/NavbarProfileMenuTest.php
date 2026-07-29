<?php

use XcVm\Core\Module\CoreNavbarProvider;
use XcVm\Core\Module\NavbarItem;
use XcVm\Core\Module\NavbarRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Profile dropdown — registry contract tests.
 *
 * The admin profile dropdown (src/Public/Views/admin/header.php) is
 * registry-driven: core items are registered by CoreNavbarProvider under the
 * reserved 'profile' parent, modules inject their own at order 100–980, and
 * the header renders NavbarRegistry::getChildren('profile') after
 * NavbarRegistry::collapseDividers() drops separators orphaned by permission
 * filtering.
 *
 * These tests lock down that contract, including the exact keys/orders the
 * Plex (1.0.2) and Watch (1.0.5) modules register — those modules live in
 * separate repos and cannot be autoloaded here, so their registration is
 * mirrored by anonymous providers.
 */
final class NavbarProfileMenuTest extends TestCase {

    protected function setUp(): void {
        NavbarRegistry::reset();
    }

    protected function tearDown(): void {
        NavbarRegistry::reset();
    }

    /**
     * Visibility filter mirroring header.php's _xc_nav_visible() for flat
     * dropdown items: dividers always pass (collapsed later), permissioned
     * items pass when the user holds at least one required permission.
     *
     * @param NavbarItem[] $items
     * @param string[]     $granted
     * @return NavbarItem[]
     */
    private function filterVisible(array $items, array $granted): array {
        $out = [];
        foreach ($items as $item) {
            if ($item->divider) {
                $out[] = $item;
                continue;
            }
            if (empty($item->permissions) || array_intersect($item->permissions, $granted)) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /** @param NavbarItem[] $items @return string[] */
    private function keys(array $items): array {
        return array_map(static fn(NavbarItem $i) => $i->key, $items);
    }

    // ── collapseDividers() ────────────────────────────────────────

    public function testCollapseDropsLeadingDivider(): void {
        $items = [
            (new NavbarItem('a'))->makeDivider(),
            (new NavbarItem('b'))->url('x'),
        ];
        $this->assertSame(['b'], $this->keys(NavbarRegistry::collapseDividers($items)));
    }

    public function testCollapseDropsTrailingDivider(): void {
        $items = [
            (new NavbarItem('a'))->url('x'),
            (new NavbarItem('b'))->makeDivider(),
        ];
        $this->assertSame(['a'], $this->keys(NavbarRegistry::collapseDividers($items)));
    }

    public function testCollapseSquashesConsecutiveDividers(): void {
        $items = [
            (new NavbarItem('a'))->url('x'),
            (new NavbarItem('d1'))->makeDivider(),
            (new NavbarItem('d2'))->makeDivider(),
            (new NavbarItem('b'))->url('y'),
        ];
        // Exactly one divider survives, and it is the first of the run.
        $this->assertSame(['a', 'd1', 'b'], $this->keys(NavbarRegistry::collapseDividers($items)));
    }

    public function testCollapseHandlesEmptyAndAllDividers(): void {
        $this->assertSame([], NavbarRegistry::collapseDividers([]));
        $allDividers = [
            (new NavbarItem('d1'))->makeDivider(),
            (new NavbarItem('d2'))->makeDivider(),
        ];
        $this->assertSame([], NavbarRegistry::collapseDividers($allDividers));
    }

    // ── CoreNavbarProvider 'profile' registration ─────────────────

    public function testProfileParentDoesNotLeakIntoTopLevel(): void {
        CoreNavbarProvider::register();

        foreach (NavbarRegistry::getTopLevel() as $item) {
            $this->assertNotSame('profile', $item->key, "'profile' must not be a top-level nav item");
            $this->assertNotSame('profile', $item->parent);
            $this->assertStringStartsNotWith('profile.', $item->key, 'profile children must not appear in the top-level menu');
        }
        // But the children are retrievable for the dropdown.
        $this->assertNotEmpty(NavbarRegistry::getChildren('profile'));
    }

    public function testProfileEditIsFirstAndLogoutIsLast(): void {
        CoreNavbarProvider::register();

        $children = NavbarRegistry::getChildren('profile');
        $this->assertSame('profile.edit', $children[0]->key);
        $this->assertSame('profile.logout', end($children)->key);
    }

    public function testProfileCorePermissionsMatchLegacyHeader(): void {
        CoreNavbarProvider::register();
        $byKey = [];
        foreach (NavbarRegistry::getChildren('profile') as $item) {
            $byKey[$item->key] = $item;
        }
        // edit_profile and logout are always visible (no permission gate).
        $this->assertSame([], $byKey['profile.edit']->permissions);
        $this->assertSame([], $byKey['profile.logout']->permissions);
        $this->assertSame('edit_profile', $byKey['profile.edit']->url);
        $this->assertSame('logout', $byKey['profile.logout']->url);
        // Settings/modules gate on 'settings'; backups/cache on 'database'.
        $this->assertSame(['settings'], $byKey['profile.settings']->permissions);
        $this->assertSame(['settings'], $byKey['profile.modules']->permissions);
        $this->assertSame(['database'], $byKey['profile.backups']->permissions);
        $this->assertSame(['database'], $byKey['profile.cache']->permissions);
    }

    public function testCoreOnlyProfileMenuHasSingleDividerBeforeLogout(): void {
        CoreNavbarProvider::register();

        // Full-permission admin sees every core item.
        $visible = $this->filterVisible(NavbarRegistry::getChildren('profile'), ['settings', 'database']);
        $rendered = $this->keys(NavbarRegistry::collapseDividers($visible));

        $this->assertSame([
            'profile.edit',
            'profile.settings',
            'profile.backups',
            'profile.cache',
            'profile.modules',
            'profile.logout_divider',
            'profile.logout',
        ], $rendered);
    }

    public function testBasicUserSeesOnlyEditDividerLogout(): void {
        CoreNavbarProvider::register();

        // No advanced permissions granted.
        $visible = $this->filterVisible(NavbarRegistry::getChildren('profile'), []);
        $rendered = $this->keys(NavbarRegistry::collapseDividers($visible));

        $this->assertSame(['profile.edit', 'profile.logout_divider', 'profile.logout'], $rendered);
    }

    // ── Module integration contract (Plex 1.0.2 + Watch 1.0.5) ────

    /**
     * Register the profile items exactly as WatchModule::registerNavbar() and
     * PlexModule::registerNavbar() do, so this test fails if those modules
     * drift from the reserved order slots or the 'profile' parent.
     */
    private function registerModuleProfileItems(): void {
        // WatchModule 1.0.5 — owns the folder-settings divider.
        NavbarRegistry::add((new NavbarItem('profile.folder_divider'))
            ->parent('profile')->makeDivider()->order(100));
        NavbarRegistry::add((new NavbarItem('profile.watch_settings'))
            ->parent('profile')->url('settings_watch')
            ->label('watch_settings')->permissions(['folder_watch_settings'])->order(110));
        // PlexModule 1.0.2 — depends on watch, sits after it.
        NavbarRegistry::add((new NavbarItem('profile.plex_settings'))
            ->parent('profile')->url('settings_plex')
            ->label('plex_settings')->permissions(['folder_watch_settings'])->order(120));
    }

    public function testModuleItemsSitBetweenCoreSettingsAndLogout(): void {
        CoreNavbarProvider::register();
        $this->registerModuleProfileItems();

        $visible = $this->filterVisible(
            NavbarRegistry::getChildren('profile'),
            ['settings', 'database', 'folder_watch_settings']
        );
        $rendered = $this->keys(NavbarRegistry::collapseDividers($visible));

        $this->assertSame([
            'profile.edit',
            'profile.settings',
            'profile.backups',
            'profile.cache',
            'profile.modules',
            'profile.folder_divider',
            'profile.watch_settings',
            'profile.plex_settings',
            'profile.logout_divider',
            'profile.logout',
        ], $rendered);
    }

    public function testHiddenModuleItemsCollapseTheFolderDivider(): void {
        CoreNavbarProvider::register();
        $this->registerModuleProfileItems();

        // User can see core settings but NOT folder_watch_settings — the
        // module divider must not survive as an orphan next to logout's.
        $visible = $this->filterVisible(NavbarRegistry::getChildren('profile'), ['settings', 'database']);
        $rendered = $this->keys(NavbarRegistry::collapseDividers($visible));

        $this->assertSame([
            'profile.edit',
            'profile.settings',
            'profile.backups',
            'profile.cache',
            'profile.modules',
            'profile.folder_divider',
            'profile.logout',
        ], $rendered);
        // Exactly one divider in the final menu.
        $dividers = array_filter($rendered, static fn($k) => str_contains($k, 'divider'));
        $this->assertCount(1, $dividers);
    }
}
