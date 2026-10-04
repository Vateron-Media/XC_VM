<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Cluster\Crypto\ClusterUnavailableException;

/**
 * No extension, or one outside the API range, means no cluster crypto at all:
 * the factory throws and the node stays legacy. There is no PHP fallback.
 */
final class ClusterCryptoFailClosedTest extends TestCase {
	protected function tearDown(): void {
		ClusterCryptoFactory::useProbe(null);
	}

	/** @return iterable<string, array{0: ?array, 1: string}> */
	public static function refusals(): iterable {
		yield 'no extension' => [null, 'NO_EXTENSION'];
		yield 'no api field' => [['ext_version' => '3.0.0'], 'API_OUT_OF_RANGE'];
		yield 'too old' => [['api' => ClusterCryptoFactory::API_MIN - 1], 'API_OUT_OF_RANGE'];
		yield 'too new' => [['api' => ClusterCryptoFactory::API_MAX + 1], 'API_OUT_OF_RANGE'];
	}

	/** @dataProvider refusals */
	public function testRefuses(?array $rInfo, string $rReason): void {
		ClusterCryptoFactory::useProbe(fn() => $rInfo);
		$this->assertFalse(ClusterCryptoFactory::available());
		$this->assertSame($rReason, ClusterCryptoFactory::status()['reason']);
		$this->expectException(ClusterUnavailableException::class);
		ClusterCryptoFactory::create();
	}

	public function testInRange(): void {
		ClusterCryptoFactory::useProbe(fn() => ['api' => ClusterCryptoFactory::API_MAX, 'ext_version' => '3.1.0']);
		$this->assertSame(['available' => true, 'reason' => 'OK', 'api' => 1, 'ext_version' => '3.1.0', 'range' => '1–1'], ClusterCryptoFactory::status());
		$this->assertInstanceOf(ClusterCrypto::class, ClusterCryptoFactory::create());
	}

	public function testRealProbeWithoutTheExtension(): void {
		if (class_exists('XC_VM', false) && method_exists('XC_VM', 'cluster_session')) {
			$this->markTestSkipped('xcvm_core with the cluster API is loaded here');
		}
		$this->assertSame('NO_EXTENSION', ClusterCryptoFactory::status()['reason']);
	}

	public function testNoReferenceCryptoShips(): void {
		$rSrc = MAIN_HOME;
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rSrc . 'Core/Cluster', FilesystemIterator::SKIP_DOTS)) as $rFile) {
			$rCode = (string) file_get_contents($rFile->getPathname());
			$this->assertStringNotContainsString('sodium_crypto_sign_seed_keypair', $rCode, $rFile->getPathname() . ' must not sign as the panel');
			$this->assertStringNotContainsString('xcvm-node-token', $rCode, $rFile->getPathname() . ' must not compute tokens');
		}
	}
}
