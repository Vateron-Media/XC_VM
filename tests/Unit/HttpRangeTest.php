<?php

use PHPUnit\Framework\TestCase;
use XcVm\Streaming\Delivery\HttpRange;

/**
 * @covers \XcVm\Streaming\Delivery\HttpRange
 *
 * The single-range parser the VOD and timeshift endpoints share. The inline
 * copies it replaced broke on suffix ranges (`bytes=-N`) and answered an
 * unsatisfiable range with the full resource's Content-Range.
 */
final class HttpRangeTest extends TestCase {

	public function testNoRangeServesTheWholeResource(): void {
		$this->assertNull(HttpRange::parse(null, 1000));
		$this->assertNull(HttpRange::parse('', 1000));
		$this->assertNull(HttpRange::parse('items=0-9', 1000), 'another unit is ignored');
	}

	public function testBoundedAndOpenRanges(): void {
		$this->assertSame([0, 99], HttpRange::parse('bytes=0-99', 1000));
		$this->assertSame([0, 1], HttpRange::parse('bytes=0-1', 1000), 'a player probing the first bytes');
		$this->assertSame([500, 999], HttpRange::parse('bytes=500-', 1000));
		$this->assertSame([10, 999], HttpRange::parse('bytes=10-5000', 1000), 'end clamped to the size');
		$this->assertSame([10, 20], HttpRange::parse(' Bytes = 10 - 20 ', 1000));
	}

	public function testSuffixRangeIsTheLastBytes(): void {
		$this->assertSame([900, 999], HttpRange::parse('bytes=-100', 1000));
		$this->assertSame([0, 999], HttpRange::parse('bytes=-5000', 1000), 'longer than the resource: all of it');
	}

	public function testUnsatisfiableRanges(): void {
		$this->assertFalse(HttpRange::parse('bytes=1000-', 1000), 'starts past the end');
		$this->assertFalse(HttpRange::parse('bytes=5-2', 1000), 'end before start');
		$this->assertFalse(HttpRange::parse('bytes=-0', 1000), 'empty suffix');
		$this->assertFalse(HttpRange::parse('bytes=0-1,5-6', 1000), 'multipart is not served');
		$this->assertFalse(HttpRange::parse('bytes=abc', 1000));
		$this->assertFalse(HttpRange::parse('bytes=-', 1000));
		$this->assertFalse(HttpRange::parse('bytes=0-9', 0), 'nothing to serve');
	}
}
