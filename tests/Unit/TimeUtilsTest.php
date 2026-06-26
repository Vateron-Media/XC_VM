<?php

use XcVm\Core\Util\TimeUtils;
use PHPUnit\Framework\TestCase;

/**
 * @covers TimeUtils
 */
final class TimeUtilsTest extends TestCase {

	public function testSecondsToTimeWithSeconds() {
		$this->assertSame('1h 1m 1s', TimeUtils::secondsToTime(3661));
		$this->assertSame('1d 0s', TimeUtils::secondsToTime(86400));
		$this->assertSame('1m 30s', TimeUtils::secondsToTime(90));
		$this->assertSame('0s', TimeUtils::secondsToTime(0));
	}

	public function testSecondsToTimeWithoutSeconds() {
		$this->assertSame('1h 1m', TimeUtils::secondsToTime(3661, false));
		$this->assertSame('1d', TimeUtils::secondsToTime(86400, false));
	}

	public function testDurationToSecondsThreeParts() {
		$this->assertSame(3661, TimeUtils::durationToSeconds('01:01:01'));
		$this->assertSame(7200, TimeUtils::durationToSeconds('02:00:00'));
	}

	public function testDurationToSecondsTwoParts() {
		$this->assertSame(90, TimeUtils::durationToSeconds('01:30'));
	}

	public function testDurationToSecondsPlainNumber() {
		$this->assertSame(42, TimeUtils::durationToSeconds('42'));
	}

	public function testTimeAgoBuckets() {
		$now = time();
		$this->assertStringEndsWith('s ago', TimeUtils::timeAgo($now - 5));
		$this->assertStringEndsWith('m ago', TimeUtils::timeAgo($now - 120));
		$this->assertStringEndsWith('h ago', TimeUtils::timeAgo($now - 7200));
		$this->assertStringEndsWith('d ago', TimeUtils::timeAgo($now - 172800));
	}

	public function testNowMatchesFormat() {
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', TimeUtils::now());
		$this->assertMatchesRegularExpression('/^\d{4}$/', TimeUtils::now('Y'));
	}

	public function testGetDiffTimezoneReturnsInteger() {
		$diff = TimeUtils::getDiffTimezone('UTC');
		$this->assertIsInt($diff);
		// Both anchors are "now in UTC"; allow ±1s for a second-boundary race.
		$this->assertLessThanOrEqual(1, abs($diff));
	}
}
