<?php

use XcVm\Core\Util\Encryption;
use PHPUnit\Framework\TestCase;

/**
 * @covers Encryption
 */
final class EncryptionTest extends TestCase {

	public function testEncryptDecryptRoundTrip() {
		$plain = 'hello world / стрим 123';
		$cipher = Encryption::encrypt($plain, 'secret-key', 'device-1');

		$this->assertNotSame($plain, $cipher);
		$this->assertSame($plain, Encryption::decrypt($cipher, 'secret-key', 'device-1'));
	}

	public function testEncryptIsDeterministicForSameInputs() {
		// Same data/key/device → same ciphertext (derived key + IV are deterministic).
		$a = Encryption::encrypt('data', 'k', 'd');
		$b = Encryption::encrypt('data', 'k', 'd');
		$this->assertSame($a, $b);
	}

	public function testDecryptWithWrongKeyFails() {
		$cipher = Encryption::encrypt('secret', 'right-key', 'device');
		$this->assertFalse(Encryption::decrypt($cipher, 'wrong-key', 'device'));
	}

	public function testDecryptWithWrongDeviceFails() {
		$cipher = Encryption::encrypt('secret', 'key', 'device-a');
		$this->assertFalse(Encryption::decrypt($cipher, 'key', 'device-b'));
	}

	public function testBase64urlIsUrlSafeAndUnpadded() {
		$raw = "\xff\xfe\xfd\x00binary?";
		$encoded = Encryption::base64urlEncode($raw);

		$this->assertStringNotContainsString('+', $encoded);
		$this->assertStringNotContainsString('/', $encoded);
		$this->assertStringNotContainsString('=', $encoded);
		$this->assertSame($raw, Encryption::base64urlDecode($encoded));
	}

	public function testRandomStringLengthAndCharset() {
		$s = Encryption::randomString(40);
		$this->assertSame(40, strlen($s));
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $s);
	}

	public function testRandomTokenLengthIsHex() {
		$token = Encryption::randomToken(16);
		$this->assertSame(32, strlen($token));
		$this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $token);
	}

	public function testGenerateKeyByteLength() {
		$this->assertSame(16, strlen(Encryption::generateKey(128)));
		$this->assertSame(32, strlen(Encryption::generateKey(256)));
	}

	public function testGenerateIvLengthForAes128() {
		$this->assertSame(16, strlen(Encryption::generateIV('AES-128-CBC')));
	}

	public function testGenerateUniqueCodeIsStable15Chars() {
		$code = Encryption::generateUniqueCode('pass');
		$this->assertSame(15, strlen($code));
		$this->assertSame($code, Encryption::generateUniqueCode('pass'));
		$this->assertNotSame($code, Encryption::generateUniqueCode('other'));
	}
}
