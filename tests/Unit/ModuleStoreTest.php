<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Module\ModuleStore;

/**
 * The official store as the modules page lists it (Core\Module\ModuleStore):
 * every module, free, bought by the API key or for sale; each with what this
 * panel has installed of it, matched by the store slug.
 */
final class ModuleStoreTest extends TestCase {
	private const CATALOGUE = [
		['slug' => 'watch', 'name' => 'Watch Folder', 'version' => '1.2.0', 'price' => 0, 'environment' => 'main', 'xc_vm_compatibility' => '>=2.6.1'],
		['slug' => 'plex-sync', 'name' => 'Plex', 'version' => '2.0.0', 'price' => 19.0, 'environment' => 'main'],
		['slug' => 'epg-pro', 'name' => 'EPG Pro', 'version' => '1.0.0', 'price' => 9.5, 'environment' => 'both'],
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

	public function testEveryModuleIsListedFreeBoughtOrForSale(): void {
		$rRows = ModuleStore::rows(self::CATALOGUE, ['plex-sync'], []);

		$this->assertSame(['EPG Pro' => 'paid', 'Plex' => 'purchased', 'Watch Folder' => 'free'], array_column($rRows, 'badge', 'name'));
		$this->assertSame([9.5, 19.0, 0.0], array_column($rRows, 'price'));
	}

	public function testAnInstalledModuleIsMatchedByItsStoreSlug(): void {
		$rInstalled = [
			['name' => 'watch', 'installed_version' => '1.1.1', 'update' => ['slug' => '']],
			['name' => 'plex', 'installed_version' => '2.0.0', 'update' => ['slug' => 'plex-sync']],
		];

		$rRows = array_column(ModuleStore::rows(self::CATALOGUE, ['plex-sync'], $rInstalled), null, 'slug');

		$this->assertSame(['1.1.1', '1.2.0', 'watch'], [$rRows['watch']['installed_version'], $rRows['watch']['update_to'], $rRows['watch']['module']]);
		$this->assertSame(['2.0.0', '', 'plex'], [$rRows['plex-sync']['installed_version'], $rRows['plex-sync']['update_to'], $rRows['plex-sync']['module']]);
	}

	public function testOwnershipIsAskedOnlyForPaidModulesAndTheAnswerIsCached(): void {
		$rAsked = [];
		$rList = static fn(string $rKey): array => ['ok' => true, 'extensions' => self::CATALOGUE];
		$rCheck = static function (string $rSlug) use (&$rAsked): array {
			$rAsked[] = $rSlug;
			return ['ok' => true, 'allowed' => $rSlug === 'epg-pro'];
		};

		$rFirst = ModuleStore::catalogue('key-1', false, $rList, $rCheck);
		$rAgain = ModuleStore::catalogue('key-1', false, static fn(): array => ['ok' => false, 'reason' => 'request_failed'], $rCheck);

		$this->assertSame(['plex-sync', 'epg-pro'], $rAsked);
		$this->assertSame(['epg-pro'], $rFirst['owned']);
		$this->assertSame($rFirst, $rAgain, 'served from the cache');
	}

	public function testAnotherKeyOrARefreshAsksTheStoreAgain(): void {
		$rCalls = 0;
		$rList = static function () use (&$rCalls): array {
			$rCalls++;
			return ['ok' => true, 'extensions' => []];
		};
		$rCheck = static fn(): array => ['allowed' => false];

		ModuleStore::catalogue('key-1', false, $rList, $rCheck);
		ModuleStore::catalogue('key-2', false, $rList, $rCheck);
		ModuleStore::catalogue('key-2', true, $rList, $rCheck);

		$this->assertSame(3, $rCalls);
	}

	public function testAStoreThatDidNotAnswerIsNotCached(): void {
		$rCheck = static fn(): array => ['allowed' => false];

		$rFailed = ModuleStore::catalogue('key-1', false, static fn(): array => ['ok' => false, 'reason' => 'request_failed'], $rCheck);
		$rLater = ModuleStore::catalogue('key-1', false, static fn(): array => ['ok' => true, 'extensions' => []], $rCheck);

		$this->assertSame(['ok' => false, 'reason' => 'request_failed'], $rFailed);
		$this->assertTrue($rLater['ok']);
	}

	public function testNoApiKeyAsksNothing(): void {
		$this->assertSame(['ok' => false, 'reason' => 'no_api_key'], ModuleStore::catalogue(''));
	}
}
