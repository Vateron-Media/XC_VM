<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Util\ImageResizeService;
use XcVm\Domain\External\ExternalXtreamService;

/**
 * The web player fetches from a server, and from image URLs, that a visitor
 * names. Such a fetch may only go to a public address, and the server sign-in
 * only to the API path of the server it was given.
 *
 * An IPv6 address that carries an IPv4 one (IPv4-mapped, IPv4-compatible,
 * NAT64, 6to4) ends at the IPv4 host, so it is judged by that address.
 */
final class AuditExternalFetchTargetTest extends TestCase {
	/** @return array<string, array{string}> */
	public static function internalHosts(): array {
		return [
			'loopback' => ['127.0.0.1'],
			'private' => ['10.0.0.5'],
			'link-local' => ['169.254.169.254'],
			'shared address space' => ['100.64.0.1'],
			'IPv6 loopback' => ['[::1]'],
			'IPv6 unique local' => ['[fc00::1]'],
			'mapped loopback' => ['[::ffff:127.0.0.1]'],
			'mapped loopback, hex form' => ['[::ffff:7f00:1]'],
			'mapped private' => ['[::ffff:10.0.0.5]'],
			'mapped link-local' => ['[::ffff:169.254.169.254]'],
			'mapped shared address space' => ['[::ffff:100.64.0.1]'],
			'compatible loopback' => ['[::127.0.0.1]'],
			'NAT64 loopback' => ['[64:ff9b::7f00:1]'],
			'NAT64 private' => ['[64:ff9b::a00:5]'],
			'6to4 loopback' => ['[2002:7f00:1::1]'],
			'6to4 private' => ['[2002:c0a8:101::1]'],
		];
	}

	/** @return array<string, array{string}> */
	public static function publicHosts(): array {
		return [
			'IPv4' => ['8.8.8.8'],
			'IPv6' => ['[2001:4860:4860::8888]'],
			'mapped public' => ['[::ffff:8.8.8.8]'],
			'NAT64 public' => ['[64:ff9b::808:808]'],
			'6to4 public' => ['[2002:808:808::1]'],
		];
	}

	#[DataProvider('internalHosts')]
	public function testAnInternalHostIsRefused(string $rHost): void {
		$this->assertNull($this->externalServerIps('http://' . $rHost . ':8080'), 'external server sign-in');
		$this->assertNull($this->imageIps('http://' . $rHost . '/logo.png'), 'image fetch');
	}

	#[DataProvider('publicHosts')]
	public function testAPublicHostIsKept(string $rHost): void {
		$rIP = trim($rHost, '[]');
		$this->assertSame([$rIP], $this->externalServerIps('http://' . $rHost . ':8080'), 'external server sign-in');
		$this->assertSame([$rIP], $this->imageIps('http://' . $rHost . '/logo.png'), 'image fetch');
	}

	public function testTheServerUrlCarriesNoQueryOrFragment(): void {
		// request() appends '/player_api.php?...': a query or fragment in front of
		// it would turn the API path into a parameter of some other path.
		$this->assertSame('http://example.com:8080/status', ExternalXtreamService::normalizeUrl('http://example.com:8080/status?x='));
		$this->assertSame('http://example.com/a', ExternalXtreamService::normalizeUrl('example.com/a#b'));
		$this->assertSame('http://example.com:8080', ExternalXtreamService::normalizeUrl('http://example.com:8080/?a=1#b'));
		$this->assertSame('', ExternalXtreamService::normalizeUrl('?a=1'));
	}

	public function testAPlainServerUrlIsKeptAsItWas(): void {
		$this->assertSame('http://example.com:8080', ExternalXtreamService::normalizeUrl(' http://example.com:8080/ '));
		$this->assertSame('http://example.com', ExternalXtreamService::normalizeUrl('example.com'));
		$this->assertSame('https://example.com/xc', ExternalXtreamService::normalizeUrl('https://example.com/xc/'));
		$this->assertSame('', ExternalXtreamService::normalizeUrl('  '));
	}

	/** @return list<string>|null */
	private function externalServerIps(string $rUrl): ?array {
		$rClass = new ReflectionClass(ExternalXtreamService::class);

		return $rClass->getMethod('resolvePublicIps')->invoke($rClass->newInstanceWithoutConstructor(), $rUrl);
	}

	/** @return list<string>|null */
	private function imageIps(string $rUrl): ?array {
		return (new ReflectionMethod(ImageResizeService::class, 'resolvePublicIps'))->invoke(null, $rUrl);
	}
}
