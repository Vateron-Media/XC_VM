<?php

namespace XcVm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\AuthService;

/**
 * The internal API, the admin stream proxies and RTMP authenticate with shared
 * secrets. == compared them: loosely (two numeric-looking strings compare as
 * numbers) and byte by byte until the first difference.
 */
class SecretMatchesTest extends TestCase {
	public function testTheSameSecretMatches(): void {
		$this->assertTrue(AuthService::secretMatches('Xk29fQ0pLmN7', 'Xk29fQ0pLmN7'));
		$this->assertTrue(AuthService::secretMatches(12345, '12345'), 'a numeric setting read back as an int');
	}

	public function testNumbersThatAreEqualAreNotTheSameSecret(): void {
		$this->assertFalse(AuthService::secretMatches('1000', '1e3'));
		$this->assertFalse(AuthService::secretMatches('0e1111', '0e2222'));
		$this->assertFalse(AuthService::secretMatches('10', '010'));
	}

	public function testAnythingElseDoesNotMatch(): void {
		$this->assertFalse(AuthService::secretMatches('secret', 'Secret'));
		$this->assertFalse(AuthService::secretMatches('secret', ''));
		$this->assertFalse(AuthService::secretMatches('secret', null));
		$this->assertFalse(AuthService::secretMatches('secret', ['secret']), 'password[]=secret');
		$this->assertFalse(AuthService::secretMatches('', ''), 'no secret configured matches nothing');
		$this->assertFalse(AuthService::secretMatches(null, ''));
	}
}
