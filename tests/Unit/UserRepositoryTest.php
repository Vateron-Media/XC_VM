<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\User\UserRepository;

/**
 * Unit tests for UserRepository::ispChanged() — the ISP-persist gate extracted
 * from getStreamingUserInfo()/getUserInfo(). It guards the block that writes a
 * looked-up ISP back to the line. A GeoIP lookup miss (empty con_isp_name) must
 * NOT persist: the old code did, which read the undefined isp_asn key (the
 * "Undefined array key isp_asn" warning) and stored a null isp_desc/as_number.
 */
final class UserRepositoryTest extends TestCase {

	public function testPersistsWhenIspDetectedAndChanged(): void {
		$this->assertTrue(UserRepository::ispChanged('Comcast', 0, 'Verizon'));
	}

	public function testFirstDetectionAgainstNullDescPersists(): void {
		// No ISP stored yet -> a detected ISP counts as changed.
		$this->assertTrue(UserRepository::ispChanged('Comcast', 0, null));
	}

	// ── the bug: a GeoIP miss must not reach the persist branch ──

	public function testGeoipMissDoesNotPersist(): void {
		$this->assertFalse(UserRepository::ispChanged(null, 0, 'Verizon'), 'null con_isp_name');
		$this->assertFalse(UserRepository::ispChanged('', 0, 'Verizon'), 'empty con_isp_name');
	}

	// ── no write when nothing actually changed ──

	public function testUnchangedIspDoesNotPersist(): void {
		$this->assertFalse(UserRepository::ispChanged('Comcast', 0, 'Comcast'));
		$this->assertFalse(UserRepository::ispChanged('Comcast', 0, 'comcast'), 'case-insensitive');
		$this->assertFalse(UserRepository::ispChanged('COMCAST', 0, 'comcast'));
	}

	// ── a line already flagged in violation is left alone ──

	public function testViolationDoesNotPersist(): void {
		$this->assertFalse(UserRepository::ispChanged('Comcast', 1, 'Verizon'));
	}
}
