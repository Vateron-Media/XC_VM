<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Module\ModuleStore;

/**
 * The official store as the modules page lists it (Core\Module\ModuleStore):
 * a public catalogue fetched a page at a time, every module free, bought by
 * the API key or for sale; each with what this panel has installed of it,
 * matched by the store slug. An extension older than 2.4.0 answers the whole
 * list by key only, and the page is cut here.
 */
final class ModuleStoreTest extends TestCase {
	private const CATALOGUE = [
		['slug' => 'watch', 'name' => 'Watch Folder', 'version' => '1.2.0', 'price' => 0, 'environment' => 'main', 'xc_vm_compatibility' => '>=2.6.1'],
		['slug' => 'plex-sync', 'name' => 'Plex', 'version' => '2.0.0', 'price' => 19.0, 'environment' => 'main', 'owned' => true],
		['slug' => 'epg-pro', 'name' => 'EPG Pro', 'version' => '1.0.0', 'price' => 9.5, 'environment' => 'both', 'description' => 'Guide grabber'],
	];

	protected function setUp(): void {
		if (!defined('CACHE_TMP_PATH')) {
			define('CACHE_TMP_PATH', sys_get_temp_dir() . '/xcvm-modstore-' . getmypid() . '/');
		}
		@mkdir(CACHE_TMP_PATH, 0777, true);
		ModuleStore::forget();
	}

	protected function tearDown(): void {
		ModuleStore::forget();
	}

	public function testEveryModuleIsListedFreeBoughtOrForSaleInTheStoresOrder(): void {
		$rRows = ModuleStore::rows(self::CATALOGUE, []);

		$this->assertSame(['Watch Folder' => 'free', 'Plex' => 'purchased', 'EPG Pro' => 'paid'], array_column($rRows, 'badge', 'name'));
		$this->assertSame([0.0, 19.0, 9.5], array_column($rRows, 'price'));
	}

	public function testAnInstalledModuleIsMatchedByItsStoreSlug(): void {
		$rInstalled = [
			['name' => 'watch', 'installed_version' => '1.1.1', 'update' => ['slug' => '']],
			['name' => 'plex', 'installed_version' => '2.0.0', 'update' => ['slug' => 'plex-sync']],
		];

		$rRows = array_column(ModuleStore::rows(self::CATALOGUE, $rInstalled), null, 'slug');

		$this->assertSame(['1.1.1', '1.2.0', 'watch'], [$rRows['watch']['installed_version'], $rRows['watch']['update_to'], $rRows['watch']['module']]);
		$this->assertSame(['2.0.0', '', 'plex'], [$rRows['plex-sync']['installed_version'], $rRows['plex-sync']['update_to'], $rRows['plex-sync']['module']]);
	}

	public function testTheCatalogueIsListedWithoutAKeyAndTheOptionsReachTheStore(): void {
		$rAsked = [];
		$rList = static function (?string $rKey, array $rOptions) use (&$rAsked): array {
			$rAsked[] = [$rKey, $rOptions];
			return ['ok' => true, 'extensions' => [self::CATALOGUE[0]], 'page' => 2, 'per_page' => 1, 'total' => 3, 'last_page' => 3];
		};

		$rAnswer = ModuleStore::catalogue('', ['page' => '2', 'per_page' => '1', 'search' => '  watch ', 'sort' => 'popular'], false, $rList);

		$this->assertSame([[null, ['page' => 2, 'per_page' => 1, 'search' => 'watch', 'sort' => 'popular']]], $rAsked);
		$this->assertSame([2, 1, 3, 3], [$rAnswer['page'], $rAnswer['per_page'], $rAnswer['total'], $rAnswer['last_page']]);
		$this->assertFalse($rAnswer['extensions'][0]['owned']);
	}

	public function testOptionsOutsideTheStoresLimitsAreBroughtWithinThem(): void {
		$this->assertSame(['page' => 1, 'per_page' => 50, 'search' => '', 'sort' => 'name'], ModuleStore::options(['page' => '-3', 'per_page' => 'x', 'search' => ['a'], 'sort' => 'DROP']));
		$this->assertSame(100, ModuleStore::options(['per_page' => 500])['per_page']);
		$this->assertSame(100, strlen(ModuleStore::options(['search' => str_repeat('я', 80)])['search']));
	}

	public function testAKeyIsSentAndMarksTheModulesItBought(): void {
		$rList = static fn(?string $rKey): array => ['ok' => true, 'extensions' => $rKey === 'key-1' ? self::CATALOGUE : []];

		$rAnswer = ModuleStore::catalogue('key-1', [], false, $rList);

		$this->assertSame(['free', 'purchased', 'paid'], array_column(ModuleStore::rows($rAnswer['extensions'], []), 'badge'));
		$this->assertSame([1, 50, 3, 1], [$rAnswer['page'], $rAnswer['per_page'], $rAnswer['total'], $rAnswer['last_page']]);
	}

	public function testTheCacheIsKeptPerKeyAndRequestAndARefreshAsksAgain(): void {
		$rCalls = 0;
		$rList = static function () use (&$rCalls): array {
			$rCalls++;
			return ['ok' => true, 'extensions' => []];
		};

		ModuleStore::catalogue('', ['page' => 1], false, $rList);
		ModuleStore::catalogue('', ['page' => 1], false, $rList);
		ModuleStore::catalogue('', ['page' => 2], false, $rList);
		ModuleStore::catalogue('key-1', ['page' => 2], false, $rList);
		ModuleStore::catalogue('key-1', ['page' => 2], true, $rList);

		$this->assertSame(4, $rCalls);
	}

	public function testAStoreThatDidNotAnswerIsNotCached(): void {
		$rFailed = ModuleStore::catalogue('', [], false, static fn(): array => ['ok' => false, 'reason' => 'request_failed']);
		$rLater = ModuleStore::catalogue('', [], false, static fn(): array => ['ok' => true, 'extensions' => []]);

		$this->assertSame(['ok' => false, 'reason' => 'request_failed'], $rFailed);
		$this->assertTrue($rLater['ok']);
	}

	public function testAnOldExtensionNeedsAKeyAndAsksOwnershipOnlyForPaidModules(): void {
		$rAsked = [];
		$rList = static fn(string $rKey): array => ['ok' => true, 'extensions' => array_map(static fn(array $rExt): array => array_diff_key($rExt, ['owned' => 0]), self::CATALOGUE)];
		$rCheck = static function (string $rSlug) use (&$rAsked): array {
			$rAsked[] = $rSlug;
			return ['ok' => true, 'allowed' => $rSlug === 'epg-pro'];
		};

		$this->assertSame(['ok' => false, 'reason' => 'no_api_key'], ModuleStore::catalogue('', [], false, $rList, $rCheck));
		$rFirst = ModuleStore::catalogue('key-1', [], false, $rList, $rCheck);
		$rPage2 = ModuleStore::catalogue('key-1', ['per_page' => 2, 'page' => 2], false, $rList, $rCheck);

		$this->assertSame(['plex-sync', 'epg-pro'], $rAsked, 'the whole list is cached once, every page cut from it');
		$this->assertSame(['EPG Pro' => 'purchased', 'Plex' => 'paid', 'Watch Folder' => 'free'], array_column(ModuleStore::rows($rFirst['extensions'], []), 'badge', 'name'));
		$this->assertSame(['Watch Folder'], array_column($rPage2['extensions'], 'name'));
		$this->assertSame([2, 2, 3, 2], [$rPage2['page'], $rPage2['per_page'], $rPage2['total'], $rPage2['last_page']]);
	}

	public function testAnOldExtensionsListIsSearchedAndSortedHere(): void {
		$rList = static fn(): array => ['ok' => true, 'extensions' => self::CATALOGUE];
		$rCheck = static fn(): array => ['allowed' => false];

		$rByPrice = ModuleStore::catalogue('key-1', ['sort' => 'price_desc'], false, $rList, $rCheck);
		$rCheap = ModuleStore::catalogue('key-1', ['sort' => 'price_asc'], false, $rList, $rCheck);
		$rFound = ModuleStore::catalogue('key-1', ['search' => 'GRABBER'], false, $rList, $rCheck);

		$this->assertSame(['plex-sync', 'epg-pro', 'watch'], array_column($rByPrice['extensions'], 'slug'));
		$this->assertSame(['watch', 'epg-pro', 'plex-sync'], array_column($rCheap['extensions'], 'slug'));
		$this->assertSame(['epg-pro'], array_column($rFound['extensions'], 'slug'));
		$this->assertSame(1, $rFound['total']);
	}
}
