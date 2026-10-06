<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\CacheCronJob;
use XcVm\Core\Config\SettingsManager;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * The heavy pass of cron:cache builds the maps every stream open and list reads
 * (channel_order, bouquet_map, category_map, series_order) from one read of the
 * episodes, and builds none when the bouquets or the episodes cannot be read: an
 * empty bouquet_map would refuse every stream until the next pass.
 *
 * The expected maps are what the pass gave before it read the episodes once, on
 * a catalogue full of awkward cases: a series in two bouquets and twice in one,
 * ids as strings, 0, ids of nothing, episodes without a stream, ties in the
 * order, empty and NULL lists.
 */
final class CacheHeavyPassTest extends TestCase {
	private TestDb $rDb;

	private QueryLogDb $rLog;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['bouquets', 'streams', 'streams_episodes', 'streams_series'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		for ($i = 1; $i <= 44; $i++) {
			// 41-44: one of each type, in no bouquet.
			$rType = $i > 40 ? [1, 2, 4, 5][$i - 41] : ($i <= 10 ? 1 : ($i <= 12 ? 4 : ($i <= 22 ? 2 : ($i <= 38 ? 5 : ($i == 39 ? 3 : 1)))));
			$rCategory = $i % 7 === 0 ? null : ($i % 5 === 0 ? '' : '[' . (1 + $i % 4) . ($i % 3 === 0 ? ',9' : '') . ']');
			$this->rDb->query('INSERT INTO `streams` (`id`, `type`, `category_id`, `stream_display_name`, `order`, `added`) VALUES (?, ?, ?, ?, ?, ?)', $i, $rType, $rCategory, 'S' . $i, $i % 4 === 0 ? 0 : 50 - $i, 1000 + ($i * 37) % 11);
		}
		$this->rDb->exec("INSERT INTO `streams_series` (`id`, `title`, `category_id`, `last_modified`) VALUES (1, 'A', '[41]', 5), (2, 'B', '[42,43]', 9), (3, 'C', NULL, 9), (4, 'D', '[41]', 1), (5, 'E', '', 3)");
		// (season, episode, series, stream), out of order: a tie in series 3, no stream in series 4.
		$this->rDb->exec('INSERT INTO `streams_episodes` (`season_num`, `episode_num`, `series_id`, `stream_id`) VALUES (2,1,1,25), (1,2,1,24), (1,1,1,23), (2,2,1,26), (1,1,2,27), (1,2,2,28), (1,3,2,29), (1,4,2,30), (1,1,3,32), (1,1,3,31), (1,2,3,33), (2,1,3,34), (1,1,4,0), (1,2,4,NULL), (1,3,4,35), (1,1,NULL,36), (3,1,2,37), (1,1,77,38)');
		$this->bouquets('[6,7]');
		$this->rLog = new QueryLogDb($this->rDb);
		DatabaseFactory::set($this->rLog);
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
	}

	private function bouquets(string $rFourChannels): void {
		$this->rDb->exec('DELETE FROM `bouquets`');
		foreach ([[1, 'one', '[3,1,2,"4",1,0,999,39]', '[13,14,"15"]', '[11]', '[1,2,2,"3",0,99]', 2], [2, 'two', '[2,5,39,40]', '[14,16,13]', '[12,11]', '[1,4,5]', 1], [3, 'three', '', null, '[]', '[3]', 0], [4, 'four', $rFourChannels, '[22,21]', 'null', '[2]', 0]] as $rRow) {
			$this->rDb->query('INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_channels`, `bouquet_movies`, `bouquet_radios`, `bouquet_series`, `bouquet_order`) VALUES (?, ?, ?, ?, ?, ?, ?)', ...$rRow);
		}
	}

	private function maps(string $rNumbering, int $rNewest = 0): ?array {
		SettingsManager::set(['channel_number_type' => $rNumbering, 'vod_sort_newest' => $rNewest]);
		$rJob = new CacheCronJob();
		return (new ReflectionMethod($rJob, 'catalogMaps'))->invoke($rJob);
	}

	public function testTheMapsAreTheOnesThePassAlwaysBuilt(): void {
		$this->assertSame(json_decode(self::BOUQUET, true), $this->maps('bouquet'));
		$this->assertSame(json_decode(self::MANUAL, true), $this->maps('manual'));
		$this->assertSame(json_decode(self::NEWEST, true), $this->maps('bouquet', 1));
	}

	public function testAListHoldingSomethingOtherThanIdsBuildsAsBefore(): void {
		$this->bouquets('[6,7,[8],{"a":9}]');
		set_error_handler(static fn(): bool => true); // "Array to string conversion", as before
		try {
			$rMaps = $this->maps('bouquet');
		} finally {
			restore_error_handler();
		}
		$this->assertSame(json_decode(self::NESTED, true), $rMaps);
	}

	public function testTheEpisodesAreReadOnce(): void {
		$this->maps('bouquet');
		$this->assertCount(1, array_filter($this->rLog->rQueries, static fn(string $rQuery): bool => str_contains($rQuery, 'streams_episodes')));
	}

	public function testAFailedReadBuildsNoMaps(): void {
		foreach (['/FROM `bouquets`/', '/FROM `streams_episodes`/'] as $rRefused) {
			$this->rLog->rRefuse = $rRefused;
			$this->assertNull($this->maps('bouquet'), $rRefused);
		}
	}

	public function testNothingWritesTheUnreadCategoryList(): void {
		$this->assertStringNotContainsString('channels_categories', (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/CacheCronJob.php'));
	}

	/** The maps before the episodes were read once: bouquet numbering. */
	private const BOUQUET = '{"channel_order":{"0":2,"1":5,"2":39,"3":40,"4":3,"5":1,"6":4,"7":999,"8":6,"9":7,"10":8,"11":41,"12":10,"13":9,"14":12,"15":11,"16":43,"17":14,"18":16,"19":13,"20":15,"21":22,"22":21,"23":20,"24":42,"25":19,"26":18,"27":17,"28":23,"29":24,"30":25,"31":26,"32":35,"37":27,"38":28,"39":29,"40":30,"41":37,"47":32,"48":31,"49":33,"50":34,"60":36,"61":44,"62":38},"bouquet_map":{"2":[2,1],"5":[2],"39":[2,1],"40":[2],"12":[2],"11":[2,1],"14":[2,1],"16":[2],"13":[2,1],"23":[2,1],"24":[2,1],"25":[2,1],"26":[2,1],"0":[2,2,1],"35":[2],"3":[1],"1":[1,1],"4":[1],"999":[1],"15":[1],"27":[1,1,4],"28":[1,1,4],"29":[1,1,4],"30":[1,1,4],"37":[1,1,4],"32":[1,3],"31":[1,3],"33":[1,3],"34":[1,3],"6":[4],"7":[4],"22":[4],"21":[4]},"category_map":{"2":{"0":3,"1":4,"2":1,"3":9,"4":2,"8":41},"1":{"0":2,"1":3,"2":4,"3":9,"4":1,"6":41,"7":42,"8":43},"3":[],"4":{"0":3,"1":9,"3":42,"4":43}},"series_order":null}';

	/** Manual numbering. */
	private const MANUAL = '{"channel_order":[4,8,12,16,20,24,28,32,36,40,44,43,42,41,39,38,37,35,34,33,31,30,29,27,26,25,23,22,21,19,18,17,15,14,13,11,10,9,7,6,5,3,2,1],"bouquet_map":{"2":[2,1],"5":[2],"39":[2,1],"40":[2],"12":[2],"11":[2,1],"14":[2,1],"16":[2],"13":[2,1],"23":[2,1],"24":[2,1],"25":[2,1],"26":[2,1],"0":[2,2,1],"35":[2],"3":[1],"1":[1,1],"4":[1],"999":[1],"15":[1],"27":[1,1,4],"28":[1,1,4],"29":[1,1,4],"30":[1,1,4],"37":[1,1,4],"32":[1,3],"31":[1,3],"33":[1,3],"34":[1,3],"6":[4],"7":[4],"22":[4],"21":[4]},"category_map":{"2":{"0":3,"1":4,"2":1,"3":9,"4":2,"8":41},"1":{"0":2,"1":3,"2":4,"3":9,"4":1,"6":41,"7":42,"8":43},"3":[],"4":{"0":3,"1":9,"3":42,"4":43}},"series_order":null}';

	/** Bouquet numbering, newest VOD first. */
	private const NEWEST = '{"channel_order":[2,5,39,40,3,1,4,999,6,7,8,41,10,9,12,11,43,19,16,13,21,18,15,42,20,17,14,22,30,38,27,35,24,32,29,37,26,34,23,31,28,36,25,44,33],"bouquet_map":{"2":[2,1],"5":[2],"39":[2,1],"40":[2],"12":[2],"11":[2,1],"14":[2,1],"16":[2],"13":[2,1],"23":[2,1],"24":[2,1],"25":[2,1],"26":[2,1],"0":[2,2,1],"35":[2],"3":[1],"1":[1,1],"4":[1],"999":[1],"15":[1],"27":[1,1,4],"28":[1,1,4],"29":[1,1,4],"30":[1,1,4],"37":[1,1,4],"32":[1,3],"31":[1,3],"33":[1,3],"34":[1,3],"6":[4],"7":[4],"22":[4],"21":[4]},"category_map":{"2":{"0":3,"1":4,"2":1,"3":9,"4":2,"8":41},"1":{"0":2,"1":3,"2":4,"3":9,"4":1,"6":41,"7":42,"8":43},"3":[],"4":{"0":3,"1":9,"3":42,"4":43}},"series_order":[2,1,4,3,5]}';

	/** Bouquet numbering, bouquet 4 listing [6,7,[8],{"a":9}]. */
	private const NESTED = '{"channel_order":{"0":2,"1":5,"2":39,"3":40,"4":3,"5":1,"6":4,"7":999,"8":6,"9":7,"12":8,"13":41,"14":10,"15":9,"16":12,"17":11,"18":43,"19":14,"20":16,"21":13,"22":15,"23":22,"24":21,"25":20,"26":42,"27":19,"28":18,"29":17,"30":23,"31":24,"32":25,"33":26,"34":35,"39":27,"40":28,"41":29,"42":30,"43":37,"49":32,"50":31,"51":33,"52":34,"62":36,"63":44,"64":38},"bouquet_map":{"2":[2,1],"5":[2],"39":[2,1],"40":[2],"12":[2],"11":[2,1],"14":[2,1],"16":[2],"13":[2,1],"23":[2,1],"24":[2,1],"25":[2,1],"26":[2,1],"0":[2,2,1],"35":[2],"3":[1],"1":[1,1,4,4],"4":[1],"999":[1],"15":[1],"27":[1,1,4],"28":[1,1,4],"29":[1,1,4],"30":[1,1,4],"37":[1,1,4],"32":[1,3],"31":[1,3],"33":[1,3],"34":[1,3],"6":[4],"7":[4],"22":[4],"21":[4]},"category_map":{"2":{"0":3,"1":4,"2":1,"3":9,"4":2,"8":41},"1":{"0":2,"1":3,"2":4,"3":9,"4":1,"6":41,"7":42,"8":43},"3":[],"4":{"0":2,"1":3,"2":9,"4":42,"5":43}},"series_order":null}';
}
