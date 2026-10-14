<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Vod\SeriesService;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * SeriesService::generatePlaylist() returns a FLAT list of `vod:<episode id>`
 * sources (or []), ordered by season then episode — the shape cron:series
 * consumes. cron:series used to read it as ['success']/['sources'], which never
 * matched a flat list, so the condition was always false: the series-source
 * refresh was dead code and warned "Undefined array key success" on every row.
 */
final class SeriesPlaylistShapeTest extends TestCase {

	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `streams_episodes` (`id` INT PRIMARY KEY AUTO_INCREMENT, `series_id` INT, `stream_id` INT, `season_num` INT, `episode_num` INT);');
		DatabaseFactory::set($this->rDb);
	}

	public function testGeneratePlaylistReturnsFlatOrderedListOfThisSeriesOnly(): void {
		// Inserted out of order; another series' episode must not leak in.
		$this->rDb->query('INSERT INTO `streams_episodes` (`series_id`, `stream_id`, `season_num`, `episode_num`) VALUES (42, 11, 1, 2);');
		$this->rDb->query('INSERT INTO `streams_episodes` (`series_id`, `stream_id`, `season_num`, `episode_num`) VALUES (42, 10, 1, 1);');
		$this->rDb->query('INSERT INTO `streams_episodes` (`series_id`, `stream_id`, `season_num`, `episode_num`) VALUES (99, 77, 1, 1);');

		$rSources = SeriesService::generatePlaylist(42);

		$this->assertSame(['vod:10', 'vod:11'], $rSources, 'a flat, season/episode-ordered list of this series only');
		// It is a plain list, never the ['success'=>..,'sources'=>..] shape the old cron expected.
		$this->assertArrayNotHasKey('success', $rSources);
		$this->assertArrayNotHasKey('sources', $rSources);
	}

	public function testGeneratePlaylistIsEmptyForASeriesWithNoEpisodes(): void {
		$this->assertSame([], SeriesService::generatePlaylist(12345));
	}
}
