<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ViewerKey;
use XcVm\Core\Config\OpensslExtra;
use XcVm\Core\Config\StreamSecret;
use XcVm\Core\Util\Encryption;

/**
 * The segment gateway (Phase 12, docs/superpowers/specs/2026-10-08-lb-segment-
 * gateway-and-native-restreamer-design.md) opens in Go the stream-link tokens
 * PHP mints, and mints what PHP opens: tests/Support/gateway_token_vectors.json
 * fixes them, and xc_fanout's internal/gateway passes the same file. This test
 * is the file's generator in reverse: Encryption must still produce and read
 * every byte of it, readToken's try order included.
 */
final class GatewayTokenVectorsTest extends TestCase {
	/** @var array<string, mixed> */
	private static array $rV;

	private string $rDir;

	public static function setUpBeforeClass(): void {
		self::$rV = json_decode((string) file_get_contents(dirname(__DIR__) . '/Support/gateway_token_vectors.json'), true);
	}

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/gwvec-' . getmypid() . '-' . bin2hex(random_bytes(4));
		mkdir($this->rDir, 0700, true);
	}

	protected function tearDown(): void {
		ViewerKey::useFile(null);
		StreamSecret::useFile(null);
		OpensslExtra::usePrevFile(null);
		array_map('unlink', glob($this->rDir . '/*') ?: []);
		rmdir($this->rDir);
	}

	public function testTheContextIsTheTestNodes(): void {
		$this->assertSame(OPENSSL_EXTRA, hex2bin(self::$rV['context']));
	}

	public function testSealedTokensOpenAndAreReproducedFromTheirNonce(): void {
		foreach (self::$rV['seal'] as $rCase) {
			[$rKey, $rContext, $rPlain] = [hex2bin($rCase['key']), hex2bin($rCase['context']), $rCase['plain']];
			$this->assertSame($rPlain, Encryption::open($rCase['token'], $rKey, $rContext), $rCase['kind']);
			$rRaw = Encryption::base64urlDecode($rCase['token']);
			$rTag = '';
			$rCipher = openssl_encrypt($rPlain, 'aes-256-gcm', hash_hmac('sha256', 'xc_vm stream token v2|' . $rContext, $rKey, true), OPENSSL_RAW_DATA, substr($rRaw, 0, 12), $rTag, '', 16);
			$this->assertSame($rCase['token'], Encryption::base64urlEncode(substr($rRaw, 0, 12) . $rCipher . $rTag), $rCase['kind'] . ': nonce ‖ ciphertext ‖ tag');
		}
	}

	public function testLegacyTokensAreMintedAndRead(): void {
		foreach (self::$rV['cbc'] as $rCase) {
			[$rKey, $rContext] = [hex2bin($rCase['key']), hex2bin($rCase['context'])];
			$this->assertSame($rCase['token'], Encryption::encrypt($rCase['plain'], $rKey, $rContext), $rCase['kind']);
			$this->assertSame($rCase['plain'], Encryption::decrypt($rCase['token'], $rKey, $rContext), $rCase['kind']);
		}
	}

	public function testReadTokensTryOrder(): void {
		foreach (self::$rV['read'] as $rCase) {
			$rKeys = self::$rV['keysets'][$rCase['keyset']];
			$this->keyset($rKeys);
			$rShared = isset($rKeys['shared'][0]) ? hex2bin($rKeys['shared'][0]['hex']) : '';
			$rOut = Encryption::readToken($rCase['token'], $rShared, OPENSSL_EXTRA, $rCase['legacy']);
			$this->assertSame($rCase['plain'] ?? false, $rOut, $rCase['name']);
		}
	}

	/** Point the node's three key sources at a keyset: its viewer keys, the replaced stream secret and OPENSSL_EXTRA. */
	private function keyset(array $rKeys): void {
		$rValue = static fn(?array $rEntry): ?string => $rEntry === null ? null : hex2bin($rEntry['hex']);
		file_put_contents($this->rDir . '/viewer', json_encode(['current' => $rValue($rKeys['viewer'][0]), 'previous' => $rValue($rKeys['viewer'][1] ?? null), 'previous_valid_until' => $rKeys['viewer'][1]['until'] ?? null]));
		ViewerKey::useFile($this->rDir . '/viewer');
		@unlink($this->rDir . '/secret');
		if (isset($rKeys['shared'][1])) {
			file_put_contents($this->rDir . '/secret', json_encode(['value' => $rValue($rKeys['shared'][1]), 'valid_until' => $rKeys['shared'][1]['until']]));
		}
		StreamSecret::useFile($this->rDir . '/secret');
		file_put_contents($this->rDir . '/ctx', json_encode(['value' => $rValue($rKeys['context'][1]), 'valid_until' => $rKeys['context'][1]['until']]));
		OpensslExtra::usePrevFile($this->rDir . '/ctx');
	}
}
