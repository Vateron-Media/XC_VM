<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\RootSignalsCronJob;

/**
 * The automatic server_ip rewrite took the first inet entry of the
 * interface. On OpenVZ/Virtuozzo venet0 lists 127.0.0.1 first and the public
 * address on the venet0:0 alias, so MAIN's server_ip kept reverting to
 * 127.0.0.1.
 */
final class RootSignalsPickInterfaceIPTest extends TestCase {

	public function testSkipsLoopbackAndTakesTheAliasAddress(): void {
		$rAddrInfo = [
			['family' => 'inet', 'local' => '127.0.0.1', 'prefixlen' => 32, 'scope' => 'host', 'label' => 'venet0'],
			['family' => 'inet', 'local' => '203.0.113.10', 'prefixlen' => 32, 'scope' => 'global', 'label' => 'venet0:0'],
		];
		$this->assertSame('203.0.113.10', RootSignalsCronJob::pickInterfaceIP($rAddrInfo));
	}

	public function testKeepsPrivateAddressesAndIgnoresIPv6(): void {
		$rAddrInfo = [
			['family' => 'inet6', 'local' => 'fe80::1', 'scope' => 'link'],
			['family' => 'inet', 'local' => '10.0.0.5', 'scope' => 'global'],
		];
		$this->assertSame('10.0.0.5', RootSignalsCronJob::pickInterfaceIP($rAddrInfo));
	}

	public function testNullWhenOnlyLoopback(): void {
		$this->assertNull(RootSignalsCronJob::pickInterfaceIP([['family' => 'inet', 'local' => '127.0.0.1']]));
		$this->assertNull(RootSignalsCronJob::pickInterfaceIP([]));
	}
}
