<?php

use XcVm\Core\Util\Encryption;
use XcVm\Domain\Stream\AdminStreamToken;
use PHPUnit\Framework\TestCase;

/**
 * @covers XcVm\Domain\Stream\AdminStreamToken
 */
final class AdminStreamTokenTest extends TestCase {

	private const KEY = 'live-streaming-pass';

	/** @param array<string,mixed> $data */
	private function makeToken(array $data): string {
		return Encryption::encrypt(json_encode($data), self::KEY, OPENSSL_EXTRA);
	}

	public function testDecodeReturnsNullOnGarbageCiphertext() {
		$this->assertNull(AdminStreamToken::decode('not-a-valid-token', self::KEY, true));
	}

	public function testDecodeReturnsNullOnWrongKey() {
		$token = $this->makeToken(array('stream_id' => 7, 'ip' => '1.2.3.4', 'expires' => time() + 60));
		$this->assertNull(AdminStreamToken::decode($token, 'wrong-key', true));
	}

	public function testDecodeReturnsNullWhenRequiredFieldMissing() {
		$token = $this->makeToken(array('stream_id' => 7, 'ip' => '1.2.3.4')); // no expires
		$this->assertNull(AdminStreamToken::decode($token, self::KEY, true));
	}

	public function testDecodeExposesTypedFieldsAndKeepsStartRaw() {
		$token = $this->makeToken(array(
			'stream_id' => '7',
			'ip' => '1.2.3.4',
			'expires' => '123',
			'container' => 'mp4',
			'start' => '20250101-13',
			'duration' => 30,
		));
		$rToken = AdminStreamToken::decode($token, self::KEY, true);

		$this->assertNotNull($rToken);
		$this->assertSame(7, $rToken->streamId);
		$this->assertSame('1.2.3.4', $rToken->ip);
		$this->assertSame(123, $rToken->expires);
		$this->assertSame('mp4', $rToken->container);
		$this->assertSame('20250101-13', $rToken->start); // raw — NOT cast to int
		$this->assertSame(30, $rToken->duration);
	}

	public function testIsValidExpiryBoundaryIsInclusive() {
		$rToken = AdminStreamToken::decode($this->makeToken(array('stream_id' => 1, 'ip' => '1.2.3.4', 'expires' => 1000)), self::KEY, true);

		$this->assertTrue($rToken->isValid(false, '1.2.3.4', 1000));  // expires == now → valid
		$this->assertFalse($rToken->isValid(false, '1.2.3.4', 1001)); // now past expiry
	}

	public function testIsValidExactIpMatch() {
		$rToken = AdminStreamToken::decode($this->makeToken(array('stream_id' => 1, 'ip' => '1.2.3.4', 'expires' => 2000)), self::KEY, true);

		$this->assertTrue($rToken->isValid(false, '1.2.3.4', 1000));
		$this->assertFalse($rToken->isValid(false, '1.2.3.5', 1000));
	}

	public function testIsValidSubnetMatch() {
		$rToken = AdminStreamToken::decode($this->makeToken(array('stream_id' => 1, 'ip' => '1.2.3.4', 'expires' => 2000)), self::KEY, true);

		$this->assertTrue($rToken->isValid(true, '1.2.3.99', 1000)); // same /24
		$this->assertFalse($rToken->isValid(true, '1.2.9.4', 1000)); // different third octet
	}

	/** A sealed token decodes whatever the setting; a legacy one only while it is still accepted. */
	public function testDecodeReadsSealedTokensAndRefusesLegacyOnesWhenSecure() {
		$data = array('stream_id' => 9, 'ip' => '1.2.3.4', 'expires' => 2000);
		$sealed = Encryption::seal(json_encode($data), self::KEY, OPENSSL_EXTRA);
		$this->assertSame(9, AdminStreamToken::decode($sealed, self::KEY, false)->streamId);
		$this->assertSame(9, AdminStreamToken::decode($sealed, self::KEY, true)->streamId);

		$this->assertNull(AdminStreamToken::decode($this->makeToken($data), self::KEY, false));
	}
}
