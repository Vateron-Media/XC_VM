<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Line\LineService;

/**
 * The expiry a mass edit of lines, MAG or Enigma devices sets. With the box
 * ticked and no date, `new DateTime('')` is now: every selected line expired
 * at once. No date now leaves each line's own expiry.
 */
final class MassExpiryTest extends TestCase {
	public function testNoDateLeavesEachLinesOwnExpiry(): void {
		$this->assertFalse(LineService::massExpiry(['c_exp_date' => 'on', 'exp_date' => '']));
		$this->assertFalse(LineService::massExpiry(['c_exp_date' => 'on', 'exp_date' => '   ']));
		$this->assertFalse(LineService::massExpiry(['c_exp_date' => 'on']));
		$this->assertFalse(LineService::massExpiry(['c_exp_date' => 'on', 'exp_date' => 'not a date']));
	}

	public function testADateOrNoExpiryIsSet(): void {
		$this->assertSame((new DateTime('2027-01-31'))->format('U'), LineService::massExpiry(['exp_date' => '2027-01-31']));
		$this->assertNull(LineService::massExpiry(['exp_date' => '', 'no_expire' => 'on']), 'no expiry asked for');
	}
}
