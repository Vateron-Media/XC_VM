<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cache\FileCache;
use XcVm\Domain\Bouquet\BouquetService;

/**
 * BouquetService::getMapEntry() — the stream → bouquets lookup stream auth
 * checks every playback against. It is memoised per request, so it must still
 * see a rebuilt map file. Where the cache pass also wrote the map as shards of
 * 1,024 ids, and its marker is as new as the whole map, it reads only the
 * stream's shard.
 *
 * @covers BouquetService
 */
final class BouquetMapEntryTest extends TestCase {

	private string $rPath;

	private ?string $rSaved = null;

	protected function setUp(): void {
		if (!defined('CACHE_TMP_PATH')) {
			$dir = sys_get_temp_dir() . '/xcvm_bouquet_map_test/';
			@mkdir($dir, 0755, true);
			define('CACHE_TMP_PATH', $dir);
		}
		@mkdir(CACHE_TMP_PATH, 0755, true);
		$this->rPath = CACHE_TMP_PATH . 'bouquet_map';
		if (is_file($this->rPath)) {
			$this->rSaved = file_get_contents($this->rPath);
		}
	}

	protected function tearDown(): void {
		if ($this->rSaved !== null) {
			file_put_contents($this->rPath, $this->rSaved);
		} else {
			@unlink($this->rPath);
		}
		// One CACHE_TMP_PATH for the whole run: a marker left here would shadow other tests' maps.
		foreach (glob(CACHE_TMP_PATH . 'bouquet_map_*') ?: [] as $rFile) {
			@unlink($rFile);
		}
		foreach (glob(CACHE_TMP_PATH . '.bouquet_map_*') ?: [] as $rDir) {
			@rmdir($rDir);
		}
	}

	private function writeShard(int $rShard, mixed $rEntries): void {
		file_put_contents(CACHE_TMP_PATH . 'bouquet_map_' . $rShard, is_array($rEntries) ? igbinary_serialize($rEntries) : (string) $rEntries);
	}

	private function marker(int $rMtime): void {
		touch(CACHE_TMP_PATH . BouquetService::MAP_SHARDS, $rMtime);
	}

	private function writeMap(array $rMap, int $rMtime): void {
		file_put_contents($this->rPath, igbinary_serialize($rMap));
		touch($this->rPath, $rMtime);
	}

	public function testReturnsTheBouquetsHoldingAStream(): void {
		$this->writeMap([5 => [1, 2]], time() - 100);
		$this->assertSame([1, 2], BouquetService::getMapEntry(5));
		$this->assertSame([], BouquetService::getMapEntry(9));
	}

	public function testSeesARebuiltMap(): void {
		$this->writeMap([5 => [1]], time() - 50);
		$this->assertSame([1], BouquetService::getMapEntry(5));
		// The cache pass rewrites the file: the memo must not keep the old answer.
		$this->writeMap([5 => [3]], time() - 10);
		$this->assertSame([3], BouquetService::getMapEntry(5));
	}

	public function testReadsTheShardOfTheStream(): void {
		$this->writeMap([5 => [1]], time() - 200);
		$this->writeShard(0, [5 => [2]]);
		$this->marker(time() - 200);
		$this->assertSame([2], BouquetService::getMapEntry(5));
	}

	public function testNoShardForTheRangeIsNoBouquet(): void {
		$this->writeMap([5000 => [1]], time() - 210);
		$this->marker(time() - 210);
		$this->assertSame([], BouquetService::getMapEntry(5000));
	}

	public function testWithoutAMarkerOrWithAMapNewerThanTheShardsTheWholeMapAnswers(): void {
		$this->writeMap([5 => [1]], time() - 220);
		$this->writeShard(0, [5 => [2]]);
		$this->assertSame([1], BouquetService::getMapEntry(5), 'no marker');

		$this->writeMap([5 => [3]], time() - 215);
		$this->marker(time() - 216);
		$this->assertSame([3], BouquetService::getMapEntry(5), 'the map is newer than the shards');
	}

	public function testAShardThatDoesNotReadFallsBackToTheWholeMap(): void {
		$this->writeMap([5 => [1]], time() - 230);
		$this->writeShard(0, 'not igbinary');
		$this->marker(time() - 230);
		$this->assertSame([1], BouquetService::getMapEntry(5));
	}

	public function testTheShardsAnswerAsTheWholeMap(): void {
		$rMap = [0 => [1], 1 => [2, 3], 1023 => [4], 1024 => [5], 5000 => [6, 7]];
		$this->writeMap($rMap, time() - 240);
		$this->assertTrue(BouquetService::writeMapShards(new FileCache(CACHE_TMP_PATH), $rMap));
		touch(CACHE_TMP_PATH . BouquetService::MAP_SHARDS, time() - 240);
		foreach ($rMap + [7 => [], 2047 => [], 9000 => []] as $rID => $rEntry) {
			$this->assertSame($rEntry, BouquetService::getMapEntry($rID), (string) $rID);
		}

		// The next pass has no stream left in 4096-5119: its shard goes.
		unset($rMap[5000]);
		$this->writeMap($rMap, time() - 235);
		$this->assertTrue(BouquetService::writeMapShards(new FileCache(CACHE_TMP_PATH), $rMap));
		touch(CACHE_TMP_PATH . BouquetService::MAP_SHARDS, time() - 235);
		$this->assertFileDoesNotExist(CACHE_TMP_PATH . 'bouquet_map_4');
		$this->assertSame([], BouquetService::getMapEntry(5000));
	}

	public function testAShardThatCouldNotBeWrittenLeavesTheWholeMapAnswering(): void {
		$rMap = [5 => [1], 5000 => [2]];
		$this->writeMap($rMap, time() - 250);
		$this->assertTrue(BouquetService::writeMapShards(new FileCache(CACHE_TMP_PATH), $rMap));

		// The next pass cannot write shard 4 (something is in the way of its temp file).
		$rNext = [5 => [3], 5000 => [4]];
		$this->writeMap($rNext, time() - 245);
		mkdir(CACHE_TMP_PATH . '.bouquet_map_4.' . getmypid() . '.tmp');
		$rWarned = [];
		set_error_handler(static function (int $rNo, string $rMessage) use (&$rWarned): bool {
			$rWarned[] = $rMessage;
			return true;
		});
		try {
			$this->assertFalse(BouquetService::writeMapShards(new FileCache(CACHE_TMP_PATH), $rNext));
		} finally {
			restore_error_handler();
		}
		$this->assertStringContainsString('bouquet_map_4', implode("\n", $rWarned));

		$this->assertFileDoesNotExist(CACHE_TMP_PATH . BouquetService::MAP_SHARDS);
		$this->assertSame([3], BouquetService::getMapEntry(5));
		$this->assertSame([4], BouquetService::getMapEntry(5000));
	}
}
