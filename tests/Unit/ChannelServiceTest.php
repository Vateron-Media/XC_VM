<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Vod\SeriesService;
use XcVm\Infrastructure\Database\DatabaseFactory;

/** A created channel built from a series stores typed vod:<id> references. */
final class ChannelServiceTest extends TestCase {
	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `streams_episodes` (`id` INT AUTO_INCREMENT PRIMARY KEY, `season_num` INT, `episode_num` INT, `series_id` INT, `stream_id` INT)');
		DatabaseFactory::set($this->rDb);
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
	}

	public function testSeriesPlaylistEmitsVodRefs(): void {
		// Inserted out of order: the playlist follows season/episode.
		foreach ([[1, 2, 12], [1, 1, 11], [2, 1, 99]] as $i => $rRow) {
			$rSeries = $i === 2 ? 6 : 5;
			$this->rDb->exec("INSERT INTO `streams_episodes`(`season_num`,`episode_num`,`series_id`,`stream_id`) VALUES ({$rRow[0]},{$rRow[1]},{$rSeries},{$rRow[2]})");
		}
		$this->assertSame(['vod:11', 'vod:12'], SeriesService::generatePlaylist(5));
	}
}
