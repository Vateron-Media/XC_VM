<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\ReplicaApply;
use XcVm\Core\Cluster\ReplicaBoot;
use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Cluster\ReplicaBuilder;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\ReplicaFixture;

/**
 * The viewer catalogue on the node (cluster plan, section 9; section 10): the
 * `bouquets` and `categories` caches cron:cache built from MAIN's database
 * come from the replica's R1 sections of those names once the CONFIG flow is
 * on, in the same shapes (BouquetService::getAll, CategoryService), and their
 * readers stop reading MAIN's database once an apply built them. In shadow
 * the apply only names the bouquets and categories that differ. A section
 * that is not a list of rows hands its cache back. A process booted from the
 * replica never reads MAIN's database for them either.
 */
final class ReplicaCatalogTest extends TestCase {
	private string $rDir;

	private ReplicaFixture $rFixture;

	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-catalog-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'cluster', 0777, true);
		mkdir($this->rDir . 'cache', 0777, true);
		$this->rFixture = new ReplicaFixture($this->rDir . 'cluster/');
		ReplicaApply::useDir($this->rFixture->dir());
		ReplicaApply::useConfigDir($this->rDir);
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, new FileCache($this->rDir . 'cache/'));
		$this->flows(NodeFlows::CONFIG);
		$this->rDb = new TestDb();
		$this->rDb->exec(InstallSchema::table('bouquets'));
		$this->rDb->exec(InstallSchema::table('streams_categories'));
		$this->rDb->exec("INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_channels`, `bouquet_movies`, `bouquet_radios`, `bouquet_series`, `bouquet_order`) VALUES
			(1, 'Sports', '[1,2]', '[3]', '[]', '[7]', 2),
			(2, 'News', '[\"4\"]', NULL, '[5]', NULL, 0),
			(3, 'Kids', '[6]', '[]', '[]', '[]', 1)");
		$this->rDb->exec("INSERT INTO `streams_categories` (`id`, `category_type`, `category_name`, `parent_id`, `cat_order`, `is_adult`) VALUES
			(1, 'live', 'News', 0, 2, 0), (2, 'movie', 'Films', 0, 1, 1), (3, 'live', 'Sport', 0, 1, 0)");
		DatabaseFactory::set($this->rDb);
		$this->unwire();
	}

	protected function tearDown(): void {
		$this->unwire();
		DatabaseFactory::reset();
		ReplicaBoot::reset();
		ReplicaApply::useDir(null);
		ReplicaApply::useConfigDir(null);
		NodeFlows::usePath(null);
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, null);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** The readers take DatabaseFactory's handle, not one another test injected. */
	private function unwire(): void {
		foreach ([BouquetService::class, CategoryService::class] as $rClass) {
			(new \ReflectionProperty($rClass, 'db'))->setValue(null, null);
		}
	}

	private function flows(?int $rFlows): void {
		if ($rFlows === null) {
			@unlink($this->rDir . 'flows.json');
		} else {
			file_put_contents($this->rDir . 'flows.json', json_encode(['mode' => 1, 'flows' => $rFlows, 'state' => 'active']));
		}
		NodeFlows::usePath($this->rDir . 'flows.json');
	}

	/** Both sections as the agent stores them, built by MAIN's own code from the same rows. */
	private function store(): void {
		$this->rFixture->whole(ReplicaSections::BOUQUETS, ReplicaBuilder::bouquetsData());
		$this->rFixture->whole(ReplicaSections::CATEGORIES, ReplicaBuilder::categoriesData());
	}

	/** @return array<string, mixed> */
	private function apply(bool $rAuthoritative, bool $rFromDisk = false): array {
		$rReport = ReplicaApply::run($rAuthoritative, 1800000000, 5, $rFromDisk);
		$this->assertIsArray($rReport);
		return $rReport;
	}

	/** Scalars as strings, as a driver reads them, at any depth. */
	private static function loose(mixed $rValue): mixed {
		if (is_array($rValue)) {
			return array_map([self::class, 'loose'], $rValue);
		}
		return $rValue === null ? null : (string) $rValue;
	}

	public function testTheCachesKeepCronCachesShapes(): void {
		// What cron:cache builds from MAIN's database.
		$this->flows(0);
		$rBouquets = BouquetService::getAll(true);
		$rCategories = CategoryService::getFromDatabase(null, true);
		$this->assertSame([3, 1, 2], array_keys($rBouquets), 'by bouquet_order, 0 last');
		$this->assertSame([2, 3, 1], array_keys($rCategories), 'by cat_order');

		$this->store();
		$this->flows(NodeFlows::CONFIG);
		$rReport = $this->apply(true);
		$this->assertSame(['applied', 3], [$rReport[ReplicaSections::BOUQUETS]['mode'], $rReport[ReplicaSections::BOUQUETS]['rows']]);
		$this->assertSame(['applied', 3], [$rReport[ReplicaSections::CATEGORIES]['mode'], $rReport[ReplicaSections::CATEGORIES]['rows']]);
		$rReplicaB = FileCache::getCache('bouquets');
		$rReplicaC = FileCache::getCache('categories');
		// The same keys in the same order at every level, the same values (typed as the other replica caches are).
		$this->assertSame(array_keys($rBouquets), array_keys($rReplicaB));
		$this->assertSame(array_keys($rCategories), array_keys($rReplicaC));
		foreach ($rBouquets as $rID => $rRow) {
			$this->assertSame(array_keys($rRow), array_keys($rReplicaB[$rID]), 'bouquet ' . $rID);
			$this->assertSame(self::loose($rRow), self::loose($rReplicaB[$rID]), 'bouquet ' . $rID);
		}
		foreach ($rCategories as $rID => $rRow) {
			$this->assertSame(array_keys($rRow), array_keys($rReplicaC[$rID]), 'category ' . $rID);
			$this->assertSame(self::loose($rRow), self::loose($rReplicaC[$rID]), 'category ' . $rID);
		}
		$this->assertSame(['4'], $rReplicaB[2]['channels'], 'the lists decoded as BouquetService decodes them');
		$this->assertSame([[1, 2, 3], [7], []], [$rReplicaB[1]['streams'], $rReplicaB[1]['series'], $rReplicaB[2]['series']]);
		$this->assertSame(['live', 'Sport'], [$rReplicaC[3]['category_type'], $rReplicaC[3]['category_name']]);
	}

	public function testOnceAnApplyBuiltThemTheReadersStopReadingMainsDatabase(): void {
		$this->store();
		// Before any apply, and in shadow, the readers keep MAIN's database.
		$this->assertSame('Sports', BouquetService::getAll(true)[1]['bouquet_name']);
		$this->apply(false);
		$this->assertFalse(ReplicaApply::built(ReplicaSections::BOUQUETS));

		$this->apply(true);
		$this->assertTrue(ReplicaApply::owns(ReplicaSections::BOUQUETS));
		$this->assertTrue(ReplicaApply::owns(ReplicaSections::CATEGORIES));
		$this->rDb->exec("UPDATE `bouquets` SET `bouquet_name` = 'Renamed' WHERE `id` = 1");
		DatabaseFactory::reset();
		$this->assertSame('Sports', BouquetService::getAll(true)[1]['bouquet_name'], 'the replica\'s, however old, even when forced');
		$this->assertSame([2, 3, 1], array_keys(CategoryService::getFromDatabase(null, true)));
		$this->assertSame([3, 1], array_keys(CategoryService::getFromDatabase('live')), 'by type, from the same cache');
		$this->assertSame([2], array_keys(CategoryService::getFromDatabase('movie')));

		// Gone from tmp/: built again from the section on disk, never from MAIN's database.
		FileCache::delCache('bouquets');
		$this->assertSame('Sports', BouquetService::getAll()[1]['bouquet_name']);
		$this->assertIsArray(FileCache::getCache('bouquets'));

		// CONFIG off: MAIN's database's again.
		DatabaseFactory::set($this->rDb);
		$this->flows(0);
		$this->apply(false);
		$this->assertFalse(ReplicaApply::built(ReplicaSections::CATEGORIES));
		$this->assertSame('Renamed', BouquetService::getAll(true)[1]['bouquet_name']);
	}

	public function testShadowOnlyNamesWhatDiffers(): void {
		$this->store();
		$this->flows(0);
		FileCache::setCache('bouquets', BouquetService::getAll(true));
		FileCache::setCache('categories', CategoryService::getFromDatabase(null, true));
		$rReport = $this->apply(false);
		$this->assertSame(['mode' => 'shadow', 'rows' => 3, 'missing' => [], 'extra' => [], 'differ' => []], array_diff_key($rReport[ReplicaSections::BOUQUETS], ['etag' => 0]), 'identical to cron:cache\'s');
		$this->assertSame(['mode' => 'shadow', 'rows' => 3, 'missing' => [], 'extra' => [], 'differ' => []], array_diff_key($rReport[ReplicaSections::CATEGORIES], ['etag' => 0]));

		// MAIN changed since: a renamed bouquet, a new one, a category gone.
		$this->rDb->exec("UPDATE `bouquets` SET `bouquet_name` = 'Renamed' WHERE `id` = 1");
		$this->rDb->exec("INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_order`) VALUES (4, 'New', 3)");
		$this->rDb->exec('DELETE FROM `streams_categories` WHERE `id` = 2');
		FileCache::setCache('bouquets', BouquetService::getAll(true));
		FileCache::setCache('categories', CategoryService::getFromDatabase(null, true));
		$rReport = $this->apply(false);
		$this->assertSame(['missing' => [4], 'extra' => [], 'differ' => [1]], array_intersect_key($rReport[ReplicaSections::BOUQUETS], ['missing' => 0, 'extra' => 0, 'differ' => 0]));
		$this->assertSame(['missing' => [], 'extra' => [2], 'differ' => []], array_intersect_key($rReport[ReplicaSections::CATEGORIES], ['missing' => 0, 'extra' => 0, 'differ' => 0]));
		$this->assertStringNotContainsString('Renamed', (string) json_encode($rReport), 'ids, never a name');
		$this->assertSame('Renamed', FileCache::getCache('bouquets')[1]['bouquet_name'], 'nothing written in shadow');
	}

	public function testASectionThatIsNotAListOfRowsHandsItsCacheBack(): void {
		$this->store();
		$this->apply(true);
		$this->assertTrue(ReplicaApply::built(ReplicaSections::BOUQUETS));
		foreach ([
			'no list' => ['bouquets' => 'x'],
			'a row without an id' => ['bouquets' => [['bouquet_name' => 'x']]],
			'two rows with one id' => ['bouquets' => [['id' => 1], ['id' => 1]]],
			'an id that is no row\'s' => ['bouquets' => [['id' => 0]]],
			'another section\'s rows' => ['categories' => []],
		] as $rWhy => $rData) {
			$this->rFixture->whole(ReplicaSections::BOUQUETS, $rData);
			$this->assertSame('refused', $this->apply(true)[ReplicaSections::BOUQUETS]['mode'], $rWhy);
			$this->assertFalse(ReplicaApply::built(ReplicaSections::BOUQUETS), $rWhy . ': MAIN\'s database\'s again');
			$this->assertTrue(ReplicaApply::built(ReplicaSections::CATEGORIES), 'the other section stays');
			$this->store();
			$this->apply(true);
		}
		// No bouquets at all is a section too.
		$this->rFixture->whole(ReplicaSections::BOUQUETS, ['bouquets' => []]);
		$rPart = $this->apply(true)[ReplicaSections::BOUQUETS];
		$this->assertSame(['applied', 0], [$rPart['mode'], $rPart['rows']]);
		$this->assertSame([], BouquetService::getAll(true));
	}

	public function testASectionMainNoLongerSendsForItsSizeHandsItsCacheBack(): void {
		$this->store();
		$this->apply(true);
		$this->assertTrue(ReplicaApply::owns(ReplicaSections::BOUQUETS));
		// MAIN's bouquets grew past what one reply carries: MAIN answers
		// `too_large`, and the agent deletes the section it held (ADR 0004).
		$this->rDb->exec("INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_order`) VALUES (4, 'Everything', 3)");
		unlink($this->rFixture->dir() . 'bouquets.rep');
		unlink($this->rFixture->dir() . 'bouquets.json');
		$this->assertFalse(ReplicaApply::owns(ReplicaSections::BOUQUETS), 'no section: MAIN\'s database\'s again');
		$this->assertSame([3, 1, 4, 2], array_keys(BouquetService::getAll(true)), 'the bouquets MAIN has now');
		$rReport = $this->apply(true);
		$this->assertArrayNotHasKey(ReplicaSections::BOUQUETS, $rReport, 'nothing to apply');
		$this->assertSame('applied', $rReport[ReplicaSections::CATEGORIES]['mode'], 'the other section stays');
		$this->assertFalse(ReplicaApply::owns(ReplicaSections::BOUQUETS));
		// Sent again once it fits: the replica's once an apply built it.
		$this->store();
		$this->apply(true);
		$this->assertTrue(ReplicaApply::owns(ReplicaSections::BOUQUETS));
		$this->rDb->exec('DELETE FROM `bouquets` WHERE `id` = 4');
		$this->assertSame([3, 1, 4, 2], array_keys(BouquetService::getAll(true)), 'the replica\'s');
	}

	public function testFromDiskTheRecordsWin(): void {
		$this->store();
		$this->rFixture->whole(ReplicaSections::CATEGORIES, ReplicaBuilder::categoriesData(), ['categories' => []]);
		$rReport = $this->apply(true, true);
		$this->assertSame(['bouquets', 'categories'], $rReport['from_disk']['verified']);
		$this->assertSame([2, 3, 1], array_keys(FileCache::getCache('categories')));
		$this->rFixture->corrupt('bouquets.rep');
		$rReport = $this->apply(true, true);
		$this->assertSame(['bouquets'], $rReport['from_disk']['unverified']);
		$this->assertSame('refused', $rReport[ReplicaSections::BOUQUETS]['mode']);
		$this->assertFalse(ReplicaApply::built(ReplicaSections::BOUQUETS));
	}

	public function testAProcessBootedFromTheReplicaNeverReadsMainsDatabaseForThem(): void {
		FileCache::setCache('bouquets', [9 => ['id' => 9]]);
		DatabaseFactory::reset();
		ReplicaBoot::start();
		$this->assertSame([9 => ['id' => 9]], BouquetService::getAll(true), 'the cache as it is');
		$this->assertSame([], CategoryService::getFromDatabase(null, true), 'none: nothing');
		$this->assertSame([], CategoryService::getFromDatabase('live'));
	}

	public function testModeZeroIsUnchanged(): void {
		$this->store();
		$this->flows(null);
		$this->apply(false);
		FileCache::setCache(ReplicaApply::OWNED_CACHE, [ReplicaSections::BOUQUETS => 'x', ReplicaSections::CATEGORIES => 'x']);
		$this->rDb->exec("UPDATE `bouquets` SET `bouquet_name` = 'Renamed' WHERE `id` = 1");
		$this->assertSame('Renamed', BouquetService::getAll(true)[1]['bouquet_name'], 'MAIN\'s database, as before');
		$this->assertSame([3, 1], array_keys(CategoryService::getFromDatabase('live')));
	}
}
