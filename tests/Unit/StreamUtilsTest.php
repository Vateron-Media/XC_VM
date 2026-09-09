<?php

use XcVm\Core\Util\StreamUtils;
use PHPUnit\Framework\TestCase;

/**
 * @covers StreamUtils
 */
final class StreamUtilsTest extends TestCase {

	public function testCustomOrderPutsInputArgumentsFirst() {
		$this->assertSame(-1, StreamUtils::customOrder('-i input.ts', 'something'));
		$this->assertSame(1, StreamUtils::customOrder('-c:v libx264', '-i input.ts'));
	}

	public function testDetectXcVmMatchesKnownStreamPaths() {
		$this->assertTrue(StreamUtils::detectXC_VM('http://host/live/user/123'));
	}

	public function testDetectXcVmRejectsUnrelatedPaths() {
		$this->assertFalse(StreamUtils::detectXC_VM('http://host/dashboard'));
	}

	public function testSanitizeSegmentNameStripsSeparators() {
		$this->assertSame('seg_0.ts', StreamUtils::sanitizeSegmentName('seg_0.ts'));
		$this->assertSame('....etcpasswd', StreamUtils::sanitizeSegmentName('../../etc/passwd'));
		// URL-encoded traversal: "%2e%2e%2fpasswd" → "../passwd" → strip "/" → "..passwd".
		$this->assertSame('..passwd', StreamUtils::sanitizeSegmentName('%2e%2e%2fpasswd'));
	}

	public function testContainerMimeType() {
		$this->assertSame('video/mp4', StreamUtils::containerMimeType('mp4'));
		$this->assertSame('video/x-matroska', StreamUtils::containerMimeType('mkv'));
		$this->assertSame('video/mp2t', StreamUtils::containerMimeType('ts'));
		$this->assertSame('application/octet-stream', StreamUtils::containerMimeType('xyz'));
		$this->assertSame('application/octet-stream', StreamUtils::containerMimeType(''));
	}

	public function testTimeshiftStartTimestamp() {
		$this->assertSame(1700000000, StreamUtils::timeshiftStartTimestamp('1700000000'));
		$this->assertSame(mktime(13, 0, 0, 1, 1, 2025), StreamUtils::timeshiftStartTimestamp('20250101-13'));
		$this->assertSame(mktime(13, 30, 0, 1, 1, 2025), StreamUtils::timeshiftStartTimestamp('2025-01-01:13-30'));
	}

	public function testSegmentRetryBudget() {
		$this->assertSame(20, StreamUtils::segmentRetryBudget(10, 5));   // seg_time*2 wins (the fixed bug)
		$this->assertSame(30, StreamUtils::segmentRetryBudget(3, 30));   // configured wins
		$this->assertSame(20, StreamUtils::segmentRetryBudget(3, 0));    // 0 → default floor 20
	}
}
