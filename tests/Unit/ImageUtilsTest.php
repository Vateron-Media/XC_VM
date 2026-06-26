<?php

use XcVm\Core\Util\ImageUtils;
use PHPUnit\Framework\TestCase;

/**
 * @covers ImageUtils
 */
final class ImageUtilsTest extends TestCase {

	public function testKeepAspectRatioDownscales() {
		$size = ImageUtils::getImageSizeKeepAspectRatio(1000, 500, 100, 100);
		$this->assertEquals(100, $size['width']);
		$this->assertEquals(50, $size['height']);
	}

	public function testKeepAspectRatioDoesNotUpscale() {
		$size = ImageUtils::getImageSizeKeepAspectRatio(50, 50, 100, 100);
		$this->assertEquals(50, $size['width']);
		$this->assertEquals(50, $size['height']);
	}

	public function testKeepAspectRatioTreatsZeroMaxAsUnbounded() {
		$size = ImageUtils::getImageSizeKeepAspectRatio(200, 100, 0, 50);
		$this->assertEquals(100, $size['width']);
		$this->assertEquals(50, $size['height']);
	}

	public function testIsAbsoluteUrl() {
		$this->assertTrue(ImageUtils::isAbsoluteUrl('http://example.com/a.png'));
		$this->assertTrue(ImageUtils::isAbsoluteUrl('https://example.com/a.png'));
		$this->assertFalse(ImageUtils::isAbsoluteUrl('/relative/a.png'));
		$this->assertFalse(ImageUtils::isAbsoluteUrl('a.png'));
	}
}
