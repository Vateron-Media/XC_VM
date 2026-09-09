<?php

use XcVm\Core\Util\NetworkUtils;
use PHPUnit\Framework\TestCase;

/**
 * @covers XcVm\Core\Util\NetworkUtils
 */
final class NetworkUtilsTest extends TestCase {

	public function testExactMatchWhenSubnetOff() {
		$this->assertTrue(NetworkUtils::ipMatches(false, '1.2.3.4', '1.2.3.4'));
		$this->assertFalse(NetworkUtils::ipMatches(false, '1.2.3.4', '1.2.3.5'));
	}

	public function testSubnetMatchIgnoresLastOctet() {
		$this->assertTrue(NetworkUtils::ipMatches(true, '1.2.3.4', '1.2.3.99'));
		$this->assertFalse(NetworkUtils::ipMatches(true, '1.2.3.4', '1.2.9.4'));
	}

	public function testNullIpsAreHandled() {
		$this->assertTrue(NetworkUtils::ipMatches(false, null, null));
		$this->assertFalse(NetworkUtils::ipMatches(false, '1.2.3.4', null));
	}
}
