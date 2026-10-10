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

	/**
	 * IPv6 has no dots: the comparison dropped "what follows the last dot" of
	 * each address, which left nothing of either, and any two IPv6 addresses
	 * matched. Their subnet is the /64; a mapped IPv4 address is its IPv4.
	 */
	public function testSubnetMatchOfIpv6IsItsSlash64() {
		$this->assertFalse(NetworkUtils::ipMatches(true, '2001:db8:1:2::1', '2a02:1234:5:6::9'), 'two unrelated networks');
		$this->assertFalse(NetworkUtils::ipMatches(true, '2001:db8:1:2::1', '2001:db8:1:3::1'), 'another /64');
		$this->assertTrue(NetworkUtils::ipMatches(true, '2001:db8:1:2::1', '2001:db8:1:2:aaaa:bbbb:cccc:dddd'), 'the same /64');
		$this->assertTrue(NetworkUtils::ipMatches(true, '::ffff:1.2.3.4', '1.2.3.99'), 'a mapped IPv4 address is that address');
		$this->assertFalse(NetworkUtils::ipMatches(true, '::ffff:1.2.3.4', '::ffff:9.9.9.9'));
		$this->assertFalse(NetworkUtils::ipMatches(true, '1.2.3.4', '2001:db8:1:2::1'), 'one of each');
	}

	public function testWhatIsNotAnAddressMatchesOnlyItself() {
		$this->assertFalse(NetworkUtils::ipMatches(true, '', '2001:db8::1'));
		$this->assertFalse(NetworkUtils::ipMatches(true, 'abc', 'xyz'));
		$this->assertFalse(NetworkUtils::ipMatches(true, null, '1.2.3.4'));
		$this->assertTrue(NetworkUtils::ipMatches(true, 'unknown', 'unknown'));
	}

	public function testNullIpsAreHandled() {
		$this->assertTrue(NetworkUtils::ipMatches(false, null, null));
		$this->assertFalse(NetworkUtils::ipMatches(false, '1.2.3.4', null));
	}
}
