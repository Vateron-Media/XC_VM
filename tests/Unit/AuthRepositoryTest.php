<?php

use XcVm\Core\Auth\AuthRepository;
use PHPUnit\Framework\TestCase;

/**
 * AuthRepository — access-code and HMAC-key reads/deletes. Covers the
 * query methods against the TestDb injected via the $db global. (getGroupPermissions uses MySQL JSON_CONTAINS and deleteCode
 * regenerates nginx configs, so both are left to integration tests.)
 */
final class AuthRepositoryTest extends TestCase {

	private TestDb $db;
	private array $serverBackup;

	protected function setUp(): void {
		$this->serverBackup = $_SERVER;
		$this->db = new TestDb();
		$this->db->exec(
			'CREATE TABLE access_codes (id INTEGER PRIMARY KEY AUTO_INCREMENT, type INTEGER, code TEXT);
			 CREATE TABLE hmac_keys (id INTEGER PRIMARY KEY AUTO_INCREMENT, `key` TEXT);
			 INSERT INTO access_codes (id, type, code) VALUES (1, 0, "alpha"), (2, 1, "beta"), (3, 0, "gamma");
			 INSERT INTO hmac_keys (id, `key`) VALUES (7, "k7"), (8, "k8");'
		);
		$GLOBALS['db'] = $this->db;
	}

	protected function tearDown(): void {
		$_SERVER = $this->serverBackup;
		unset($GLOBALS['db']);
	}

	public function testGetAllCodesKeyedById(): void {
		$codes = AuthRepository::getAllCodes();
		$this->assertSame([1, 2, 3], array_keys($codes));
		$this->assertSame('beta', $codes[2]['code']);
	}

	public function testGetAllCodesFilteredByType(): void {
		$codes = AuthRepository::getAllCodes(0);
		$this->assertSame([1, 3], array_keys($codes), 'only type 0');
	}

	public function testGetCodeByIdReturnsRowOrNull(): void {
		$this->assertSame('alpha', AuthRepository::getCodeById(1)['code']);
		$this->assertNull(AuthRepository::getCodeById(999));
	}

	public function testGetCurrentCodeReadsXcCodeServerParam(): void {
		$_SERVER['XC_CODE'] = 'gamma';
		$this->assertSame('gamma', AuthRepository::getCurrentCode());

		$row = AuthRepository::getCurrentCode(true);
		$this->assertSame(3, (int) $row['id'], 'full row when $rInfo = true');

		$_SERVER['XC_CODE'] = 'no_such_code';
		$this->assertNull(AuthRepository::getCurrentCode(true));
	}

	public function testGetAllHmacKeyedById(): void {
		$keys = AuthRepository::getAllHMAC();
		$this->assertSame([7, 8], array_keys($keys));
		$this->assertSame('k7', $keys[7]['key']);
	}

	public function testGetHmacByIdReturnsRowOrNull(): void {
		$this->assertSame('k8', AuthRepository::getHMACById(8)['key']);
		$this->assertNull(AuthRepository::getHMACById(999));
	}

	public function testDeleteHmacRemovesExistingAndReportsMissing(): void {
		$this->assertTrue(AuthRepository::deleteHMAC(7));
		$this->assertNull(AuthRepository::getHMACById(7), 'gone after delete');
		$this->assertFalse(AuthRepository::deleteHMAC(7), 'already deleted');
		$this->assertFalse(AuthRepository::deleteHMAC(999));
	}
}
