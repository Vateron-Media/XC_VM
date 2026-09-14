<?php

namespace XcVm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\AuthService;
use XcVm\Core\Util\Encryption;

/**
 * validateHMAC must accept the key's HMAC and nothing else.
 *
 * The fixture below was found by search: with this secret and these request
 * parameters the genuine HMAC's MD5 is 0e554217211296920002813236859630 — a
 * string PHP's == reads as the number 0. The old check compared
 * md5($genuine) == md5($given), so any `hmac` whose MD5 also reads as 0 (such
 * as 240610708) passed as the key, for any request whose HMAC happened to land
 * there.
 */
class HmacTokenTest extends TestCase {
	private const SECRET = 's3cret';
	private const IDENTIFIER = '1690494838';

	protected function setUp(): void {
		if (!defined('OPENSSL_EXTRA')) {
			define('OPENSSL_EXTRA', 'test-extra');
		}
		$GLOBALS['rSettings'] = ['enable_cache' => 0, 'live_streaming_pass' => 'streaming-pass'];
		$rKey = Encryption::encrypt(self::SECRET, 'streaming-pass', OPENSSL_EXTRA);
		$GLOBALS['db'] = new class($rKey) {
			public function __construct(private string $rKey) {
			}

			public function query($rQuery) {
				return true;
			}

			public function get_rows() {
				return [['id' => 9, 'key' => $this->rKey]];
			}
		};
	}

	private function check(string $rHMAC) {
		return AuthService::validateHMAC($rHMAC, null, 1, 'ts', '', '', self::IDENTIFIER, 0);
	}

	public function testTheKeysHmacIsAccepted(): void {
		$rGenuine = hash_hmac('sha256', '1##ts######' . self::IDENTIFIER . '##0', self::SECRET);
		$this->assertSame('0e554217211296920002813236859630', md5($rGenuine), 'fixture');

		$this->assertSame(9, $this->check($rGenuine));
	}

	public function testAValueWhoseMd5ReadsAsZeroIsNotTheKey(): void {
		$this->assertSame('0e462097431906509019562988736854', md5('240610708'), 'fixture');

		$this->assertNull($this->check('240610708'));
	}

	public function testAnotherHmacIsRefused(): void {
		$this->assertNull($this->check(hash_hmac('sha256', '1##ts######' . self::IDENTIFIER . '##0', 'other-secret')));
		$this->assertNull($this->check(''));
	}
}
