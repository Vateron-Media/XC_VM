<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Util\NetworkUtils;

/**
 * An IPv4-mapped IPv6 address ends at its IPv4 host, however it is written
 * (`::ffff:127.0.0.1`, `::ffff:7f00:1`, in full, in capitals): the probe
 * target guard judges the IPv4 address inside. Private LAN ranges stay
 * allowed in that form too.
 */
final class AuditModInstallProbeMappedAddressTest extends TestCase {
	/** @return array<string, array{0: string, 1: bool}> [address, is it one a probe may never reach] */
	public static function mappedAddresses(): array {
		return [
			'loopback, dotted'            => ['::ffff:127.0.0.1', true],
			'loopback, hex groups'        => ['::ffff:7f00:1', true],
			'loopback, written in full'   => ['0:0:0:0:0:ffff:7f00:1', true],
			'loopback, capitals'          => ['::FFFF:7F00:0001', true],
			'link-local metadata address' => ['::ffff:a9fe:a9fe', true],
			'unspecified'                 => ['::ffff:0:1', true],
			'multicast'                   => ['::ffff:e000:1', true],
			'a private LAN address'       => ['::ffff:a00:1', false],
			'a private LAN one, dotted'   => ['::ffff:10.0.0.1', false],
			'a public address'            => ['::ffff:808:808', false],
		];
	}

	#[DataProvider('mappedAddresses')]
	public function testAMappedAddressIsJudgedByTheIpv4AddressInside(string $rIP, bool $rRefused): void {
		$this->assertSame($rRefused, NetworkUtils::isLocalOrLinkLocalIP($rIP));
		$this->assertSame(!$rRefused, NetworkUtils::probeTargetAllowed('http://[' . $rIP . ']:8080/live/1.ts'));
	}

	public function testOtherIpv6AddressesAreJudgedAsBefore(): void {
		foreach (['::1', '::', 'fe80::1', 'ff02::1'] as $rIP) {
			$this->assertTrue(NetworkUtils::isLocalOrLinkLocalIP($rIP), $rIP);
		}
		foreach (['2001:db8::1', 'fd00::1'] as $rIP) {
			$this->assertFalse(NetworkUtils::isLocalOrLinkLocalIP($rIP), $rIP);
		}
	}
}
