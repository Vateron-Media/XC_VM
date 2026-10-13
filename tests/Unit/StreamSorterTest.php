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

	public function testCustomerBouquetOrderSurvivesChannelAndSeriesSorting(): void {
		$custom = json_encode(['bouquet_order' => true]);
		$this->assertSame([30, 10, 20], StreamSorter::sortChannels([30, 10, 20], $custom));
		$this->assertSame([9, 2, 7], StreamSorter::sortSeries([9, 2, 7], $custom));
	}
}
