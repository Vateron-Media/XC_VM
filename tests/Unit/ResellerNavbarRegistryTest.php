<?php

use XcVm\Core\Module\CoreResellerNavbarProvider;
use XcVm\Core\Module\NavbarItem;
use XcVm\Core\Module\ResellerNavbarRegistry;
use PHPUnit\Framework\TestCase;

/**
 * ResellerNavbarRegistry — registry contract + CoreResellerNavbarProvider
 * migration tests.
 *
 * Mirrors NavbarProfileMenuTest's approach for the admin NavbarRegistry:
 * lock down the tree/order contract, then lock down the exact permission
 * gates CoreResellerNavbarProvider registers, since those reproduce the
 * behaviour of the formerly hardcoded $xmMenuSections array in
 * Public/Views/layouts/reseller/header.php.
 */
final class ResellerNavbarRegistryTest extends TestCase {

    protected function setUp(): void {
        ResellerNavbarRegistry::reset();
    }

    protected function tearDown(): void {
        ResellerNavbarRegistry::reset();
    }

    /** @param NavbarItem[] $items @return string[] */
    private function keys(array $items): array {
        return array_map(static fn(NavbarItem $i) => $i->key, $items);
    }

    // ── Registry contract (add / getTopLevel / getChildren / hasChildren) ──

    public function testAddAndGetTopLevelSortedByOrder(): void {
        ResellerNavbarRegistry::add((new NavbarItem('b'))->url('b')->order(20));
        ResellerNavbarRegistry::add((new NavbarItem('a'))->url('a')->order(10));

        $this->assertSame(['a', 'b'], $this->keys(ResellerNavbarRegistry::getTopLevel()));
    }

    public function testGetChildrenReturnsOnlyDirectChildrenSortedByOrder(): void {
        ResellerNavbarRegistry::add((new NavbarItem('parent'))->url('#'));
        ResellerNavbarRegistry::add((new NavbarItem('parent.b'))->parent('parent')->url('b')->order(20));
        ResellerNavbarRegistry::add((new NavbarItem('parent.a'))->parent('parent')->url('a')->order(10));
        ResellerNavbarRegistry::add((new NavbarItem('parent.a.grandchild'))->parent('parent.a')->url('c'));

        $this->assertSame(['parent.a', 'parent.b'], $this->keys(ResellerNavbarRegistry::getChildren('parent')));
    }

    public function testHasChildrenReflectsRegisteredParent(): void {
        ResellerNavbarRegistry::add((new NavbarItem('leaf'))->url('leaf'));
        ResellerNavbarRegistry::add((new NavbarItem('branch'))->url('#'));
        ResellerNavbarRegistry::add((new NavbarItem('branch.child'))->parent('branch')->url('child'));

        $this->assertFalse(ResellerNavbarRegistry::hasChildren('leaf'));
        $this->assertTrue(ResellerNavbarRegistry::hasChildren('branch'));
    }

    public function testDuplicateKeyOverwritesPreviousItem(): void {
        ResellerNavbarRegistry::add((new NavbarItem('dup'))->url('first'));
        ResellerNavbarRegistry::add((new NavbarItem('dup'))->url('second'));

        $top = ResellerNavbarRegistry::getTopLevel();
        $this->assertCount(1, $top);
        $this->assertSame('second', $top[0]->url);
    }

    public function testResetClearsAllItems(): void {
        ResellerNavbarRegistry::add((new NavbarItem('x'))->url('x'));
        ResellerNavbarRegistry::reset();

        $this->assertSame([], ResellerNavbarRegistry::getTopLevel());
    }

    // ── Separate storage from admin's NavbarRegistry ────────────────────

    public function testStorageIsIndependentFromAdminNavbarRegistry(): void {
        \XcVm\Core\Module\NavbarRegistry::reset();
        \XcVm\Core\Module\NavbarRegistry::add((new NavbarItem('dashboard'))->url('index'));

        // Same key ('dashboard') registered independently in each registry —
        // this would silently overwrite in a single shared static map.
        ResellerNavbarRegistry::add((new NavbarItem('dashboard'))->url('dashboard'));

        $this->assertSame('index', \XcVm\Core\Module\NavbarRegistry::getTopLevel()[0]->url);
        $this->assertSame('dashboard', ResellerNavbarRegistry::getTopLevel()[0]->url);

        \XcVm\Core\Module\NavbarRegistry::reset();
    }

    // ── CoreResellerNavbarProvider migration contract ───────────────────

    public function testDashboardIsTopLevelWithNoPermissionGate(): void {
        CoreResellerNavbarProvider::register();

        $byKey = $this->indexByKey(ResellerNavbarRegistry::getTopLevel());
        $this->assertArrayHasKey('dashboard', $byKey);
        $this->assertSame('dashboard', $byKey['dashboard']->url);
        $this->assertSame([], $byKey['dashboard']->permissions);
    }

    public function testClientsGroupPermissionsMatchLegacyHardcodedGates(): void {
        CoreResellerNavbarProvider::register();

        $byKey = $this->indexByKey(ResellerNavbarRegistry::getTopLevel());
        $this->assertSame(['create_line'], $byKey['user_lines']->permissions);
        $this->assertSame(['create_mag'], $byKey['mag_devices']->permissions);
        $this->assertSame(['create_enigma'], $byKey['enigma_devices']->permissions);
        // active_codes reuses create_line, matching the pre-migration condition.
        $this->assertSame(['create_line'], $byKey['active_codes']->permissions);
        $this->assertSame(['create_sub_resellers'], $byKey['sub_resellers']->permissions);
    }

    public function testTrialChildrenGateOnComputedCapabilityPermission(): void {
        CoreResellerNavbarProvider::register();

        foreach (['user_lines', 'mag_devices', 'enigma_devices'] as $parent) {
            $children = $this->indexByKey(ResellerNavbarRegistry::getChildren($parent));
            $this->assertSame(['can_generate_trials'], $children["{$parent}.trial"]->permissions);
            $this->assertStringContainsString('trial=1', $children["{$parent}.trial"]->url);
            // Sibling add/manage children carry no permission of their own —
            // visibility is inherited from the (already permission-gated) parent.
            $this->assertSame([], $children["{$parent}.add"]->permissions);
        }
    }

    public function testContentItemsEachRequireCanViewVod(): void {
        CoreResellerNavbarProvider::register();

        $byKey = $this->indexByKey(ResellerNavbarRegistry::getTopLevel());
        foreach (['streams', 'created_channels', 'movies', 'episodes', 'radios', 'tv_guide'] as $key) {
            $this->assertSame(['can_view_vod'], $byKey[$key]->permissions, "{$key} must gate on can_view_vod");
        }
        $this->assertTrue($byKey['tv_guide']->desktopOnly, 'tv_guide must stay desktop-only, matching the pre-migration !$rMobile check');
    }

    public function testCategoryTemplatesAndTicketsHaveNoPermissionGate(): void {
        CoreResellerNavbarProvider::register();

        $byKey = $this->indexByKey(ResellerNavbarRegistry::getTopLevel());
        $this->assertSame([], $byKey['category_templates']->permissions);
        $this->assertSame([], $byKey['tickets']->permissions);
    }

    public function testLogsChildrenPermissionsMatchLegacyHardcodedGates(): void {
        CoreResellerNavbarProvider::register();

        $children = $this->indexByKey(ResellerNavbarRegistry::getChildren('logs'));
        $this->assertSame(['reseller_client_connection_logs'], $children['logs.live_connections']->permissions);
        $this->assertSame(['reseller_client_connection_logs'], $children['logs.activity_logs']->permissions);
        $this->assertSame([], $children['logs.user_logs']->permissions);
    }

    /** @param NavbarItem[] $items @return array<string,NavbarItem> */
    private function indexByKey(array $items): array {
        $byKey = [];
        foreach ($items as $item) {
            $byKey[$item->key] = $item;
        }
        return $byKey;
    }

    public function testActiveCodesMenuAndActionsFollowTheHideSetting(): void {
        CoreResellerNavbarProvider::register();
        $items = array_merge(ResellerNavbarRegistry::getTopLevel(), ResellerNavbarRegistry::getChildren('active_codes'));
        $codes = array_filter($items, static fn(NavbarItem $item): bool => $item->key === 'active_codes' || str_starts_with($item->key, 'active_codes.'));
        $this->assertGreaterThan(1, count($codes));
        foreach ($codes as $item) {
            $this->assertSame('hide_active_codes', $item->settingDisabled, $item->key);
        }
    }
}
