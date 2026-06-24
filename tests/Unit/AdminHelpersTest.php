<?php

use XcVm\Core\Util\AdminHelpers;
use PHPUnit\Framework\TestCase;

/**
 * @covers AdminHelpers
 */
final class AdminHelpersTest extends TestCase {

	public function testValidateCidrAcceptsValidAddressesAndMasks() {
		$this->assertTrue(AdminHelpers::validateCIDR('192.168.1.1'));
		$this->assertTrue(AdminHelpers::validateCIDR('192.168.1.0/24'));
		$this->assertTrue(AdminHelpers::validateCIDR('::1'));
	}

	public function testValidateCidrRejectsInvalidInput() {
		$this->assertFalse(AdminHelpers::validateCIDR('not-an-ip'));
		$this->assertFalse(AdminHelpers::validateCIDR('10.0.0.0/33'));
		$this->assertFalse(AdminHelpers::validateCIDR('2001:db8::/129'));
	}

	public function testRoundUpToAny() {
		$this->assertEquals(10, AdminHelpers::roundUpToAny(7, 5));
		$this->assertEquals(15, AdminHelpers::roundUpToAny(13, 5));
		$this->assertEquals(5, AdminHelpers::roundUpToAny(3, 5));
	}

	public function testGenerateStringLengthAndUnambiguousCharset() {
		$charset = '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ';
		$s = AdminHelpers::generateString(16);
		$this->assertSame(16, strlen($s));
		$this->assertSame(strlen($s), strspn($s, $charset));
	}

	public function testSortArrayByArray() {
		$this->assertSame(
			array('c', 'b', 'a'),
			AdminHelpers::sortArrayByArray(array('a', 'b', 'c'), array('c', 'b', 'a'))
		);
	}

	public function testGetBarColourThresholds() {
		$this->assertSame('bg-danger', AdminHelpers::getBarColour(80));
		$this->assertSame('bg-danger', AdminHelpers::getBarColour(75));
		$this->assertSame('bg-warning', AdminHelpers::getBarColour(50));
		$this->assertSame('bg-success', AdminHelpers::getBarColour(10));
	}

	public function testFormatUptime() {
		$this->assertSame('01h 01m 01s', AdminHelpers::formatUptime(3661));
		$this->assertSame('01d 01h 00m', AdminHelpers::formatUptime(90000));
	}

	public function testFilterIdsKeepsOnlyAllowedPositiveInts() {
		$this->assertSame(
			array(1, 2),
			AdminHelpers::filterIDs(array('1', '2', 'x', 5), array(1, 2, 3))
		);
	}

	public function testGetPageFromUrl() {
		$this->assertSame('bouquet', AdminHelpers::getPageFromURL('http://host/admin/bouquet.php'));
		$this->assertNull(AdminHelpers::getPageFromURL(''));
	}

	public function testProtocolDetectionFromServerVars() {
		$saved = $_SERVER;

		$_SERVER['HTTPS'] = 'on';
		$this->assertTrue(AdminHelpers::issecure());
		$this->assertSame('https', AdminHelpers::getProtocol());

		unset($_SERVER['HTTPS']);
		$_SERVER['SERVER_PORT'] = 80;
		$this->assertFalse(AdminHelpers::issecure());
		$this->assertSame('http', AdminHelpers::getProtocol());

		$_SERVER['SERVER_PORT'] = 443;
		$this->assertTrue(AdminHelpers::issecure());

		$_SERVER = $saved;
	}
}
