<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Util\AdminHelpers;

/**
 * AdminHelpers::generateString() makes the stream secret at the first setup
 * and the generated usernames and passwords of lines, devices and resellers,
 * so its characters come from the system's secure random source: what it
 * returns does not follow from the state of the seeded generator.
 */
final class AuditCoreDataGenerateStringTest extends TestCase {
	protected function tearDown(): void {
		mt_srand(); // back to a random seed for whatever runs next
	}

	public function testTheResultDoesNotFollowFromTheSeededGenerator(): void {
		mt_srand(20270115);
		$rFirst = AdminHelpers::generateString(25);
		mt_srand(20270115);
		$rSecond = AdminHelpers::generateString(25);

		$this->assertNotSame($rFirst, $rSecond);
	}

	public function testLengthAndCharactersAreAsBefore(): void {
		foreach ([0, 1, 10, 25, 512] as $rLength) {
			$rString = AdminHelpers::generateString($rLength);
			$this->assertSame($rLength, strlen($rString));
			$this->assertSame($rLength, strspn($rString, '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ'));
		}
		// Every character of the set is used.
		$this->assertCount(54, count_chars(AdminHelpers::generateString(20000), 1));
	}
}
