<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Stream\StreamSorter;

/**
 * @covers \XcVm\Domain\Stream\StreamSorter
 */
final class StreamSorterTest extends TestCase {

	protected function setUp(): void {
		SettingsManager::set(['movie_year_append' => 0]);
	}

	public function testFormatTitleAppendsAValidYear() {
		$this->assertSame('Film (2020)', StreamSorter::formatTitle('Film', 2020));
		$this->assertSame('Film', StreamSorter::formatTitle('Film', 1800));
	}

	public function testFormatTitleTakesAMissingTitle() {
		// streams.stream_display_name and streams_series.title are nullable; a
		// null title raised a TypeError that took down a whole player_api listing.
		$this->assertSame('', StreamSorter::formatTitle(null));
	}
}
