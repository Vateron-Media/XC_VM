<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\CacheEngineCronJob;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * The groups worker of cron:cache_engine writes each reseller group's
 * permissions (what its packages let it create, and the streams, series and
 * categories of their bouquets) from one read of the episodes. When that read
 * fails, the run is marked failed and the files already there stay; a bouquet
 * column that is not a JSON list counts as empty.
 *
 * The expected files are what the worker wrote before it read the episodes once.
 */
final class CacheEngineGroupsTest extends TestCase {
	private const EXPECTED = '{"permissions_1":{"create_line":true,"create_mag":true,"stream_ids":{"0":3,"1":1,"2":2,"3":"4","5":0,"6":999,"7":13,"8":14,"9":11,"10":25,"11":24,"12":23,"13":27,"14":28,"15":37,"19":32,"20":31,"22":5,"23":19,"24":20,"26":16,"27":12,"33":35},"series_ids":{"0":1,"1":2,"3":"3","4":0,"5":99,"7":4},"category_ids":[2,3,4,1,41,42,43]},"permissions_2":{"create_mag":true,"create_enigma":true,"stream_ids":[2,5,19,20,14,16,12,11,25,24,23,0,35,32,31,6,7,27,28,37],"series_ids":[1,4,3,2],"category_ids":[3,2,4,1,41,42,43]},"permissions_3":[]}';

	private TestDb $rDb;

	private QueryLogDb $rLog;

	private mixed $rSavedDb;

	protected function setUp(): void {
		if (!defined('CACHE_TMP_PATH')) {
			$rDir = sys_get_temp_dir() . '/xcvm_bouquet_map_test/';
			@mkdir($rDir, 0755, true);
			define('CACHE_TMP_PATH', $rDir);
		}
		@mkdir(CACHE_TMP_PATH, 0755, true);
		$this->clean();

		$this->rDb = new TestDb();
		foreach (['bouquets', 'streams', 'streams_episodes', 'streams_series', 'users_packages'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec('CREATE TABLE `users_groups` (`group_id` INT)');
		$this->rDb->exec('INSERT INTO `users_groups` VALUES (1), (2), (3)');
		$this->rDb->exec("INSERT INTO `users_packages` (`id`, `package_name`, `groups`, `bouquets`, `is_line`, `is_mag`, `is_e2`) VALUES (1, 'a', '[1]', '[1,2]', 1, 0, 0), (2, 'b', '[1,2]', '[2,\"3\"]', 0, 1, 0), (3, 'c', '[2]', '[3,4]', 0, 0, 1)");
		for ($i = 1; $i <= 40; $i++) {
			$this->rDb->query('INSERT INTO `streams` (`id`, `type`, `category_id`, `stream_display_name`) VALUES (?, ?, ?, ?)', $i, $i <= 20 ? 1 : 5, $i % 7 === 0 ? null : '[' . (1 + $i % 4) . ']', 'S' . $i);
		}
		$this->rDb->exec("INSERT INTO `streams_series` (`id`, `title`, `category_id`) VALUES (1, 'A', '[41]'), (2, 'B', '[42,43]'), (3, 'C', NULL), (4, 'D', '[41]')");
		// Series 1 out of order, 3 with a tie, 4 with an episode without a stream, 77 in no bouquet.
		$this->rDb->exec('INSERT INTO `streams_episodes` (`season_num`, `episode_num`, `series_id`, `stream_id`) VALUES (2,1,1,25), (1,2,1,24), (1,1,1,23), (1,1,2,27), (1,2,2,28), (1,1,3,32), (1,1,3,31), (1,1,4,0), (1,3,4,35), (3,1,2,37), (1,1,77,38)');
		$this->rDb->exec("INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_channels`, `bouquet_movies`, `bouquet_radios`, `bouquet_series`) VALUES
			(1, 'one', '[3,1,2,\"4\",1,0,999]', '[13,14]', '[11]', '[1,2,2,\"3\",0,99]'),
			(2, 'two', '[2,5,19,20]', '[14,16]', '[12,11]', '[1,4]'),
			(3, 'three', '', NULL, '[]', '[3]'),
			(4, 'four', '[6,7]', '[]', '[]', '[2]')");

		$this->rLog = new QueryLogDb($this->rDb);
		$this->rSavedDb = $GLOBALS['db'] ?? null;
		$GLOBALS['db'] = $this->rLog;
	}

	protected function tearDown(): void {
		$GLOBALS['db'] = $this->rSavedDb;
		$this->clean();
	}

	private function clean(): void {
		foreach ([...(glob(CACHE_TMP_PATH . 'permissions_*') ?: []), CACHE_TMP_PATH . 'cache_engine_failed'] as $rFile) {
			@unlink($rFile);
		}
	}

	private function runWorker(): void {
		$rJob = new CacheEngineCronJob();
		(new ReflectionMethod($rJob, 'generateGroups'))->invoke($rJob);
	}

	/** @return array<string, mixed> the permissions files the worker left, by name */
	private function generate(): array {
		$this->runWorker();
		$rFiles = [];
		foreach (glob(CACHE_TMP_PATH . 'permissions_*') ?: [] as $rFile) {
			$rFiles[basename($rFile)] = igbinary_unserialize((string) file_get_contents($rFile));
		}
		ksort($rFiles);
		return $rFiles;
	}

	public function testThePermissionsAreTheOnesTheWorkerAlwaysWrote(): void {
		$this->assertSame(json_decode(self::EXPECTED, true), $this->generate());
	}

	public function testTheEpisodesAreReadOnce(): void {
		$this->generate();
		$this->assertCount(1, array_filter($this->rLog->rQueries, static fn(string $rQuery): bool => str_contains($rQuery, 'streams_episodes')));
	}

	public function testAFailedEpisodeReadKeepsThePermissions(): void {
		file_put_contents(CACHE_TMP_PATH . 'permissions_1', 'earlier');
		$this->rLog->rRefuse = '/FROM `streams_episodes`/';

		$this->runWorker();

		$this->assertFileExists(CACHE_TMP_PATH . 'cache_engine_failed');
		$this->assertSame('earlier', file_get_contents(CACHE_TMP_PATH . 'permissions_1'));
	}

	public function testABouquetColumnThatIsNotAListCountsAsEmpty(): void {
		$this->rDb->exec("UPDATE `bouquets` SET `bouquet_radios` = 'null', `bouquet_movies` = '5', `bouquet_series` = '\"x\"' WHERE `id` = 4");

		$rFiles = $this->generate();

		$this->assertNotContains(37, $rFiles['permissions_2']['stream_ids'], 'series 2 is no longer in bouquet 4');
		$this->assertContains(6, $rFiles['permissions_2']['stream_ids']);
	}
}
