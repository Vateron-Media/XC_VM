<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Server\ProxyIdentity;

/**
 * proxy_api.php used to trust the posted server_id: any proxy could report
 * for any server, collect that server's signals (run as root by the proxy)
 * and write its whitelist_ips, which feeds the LB /api source allowlist. The
 * identity is now the proxy at the request's source address.
 */
final class ProxyIdentityTest extends TestCase {

	/** @return array<string, array<string, mixed>> */
	private function proxies(): array {
		$rA = ['id' => 5, 'server_type' => 1, 'server_ip' => '203.0.113.5', 'private_ip' => '10.0.0.5'];
		$rB = ['id' => 6, 'server_type' => 1, 'server_ip' => '203.0.113.6', 'private_ip' => ''];
		return ['203.0.113.5' => $rA, '10.0.0.5' => $rA, '203.0.113.6' => $rB];
	}

	public function testTheSourceAddressNamesTheServer(): void {
		$this->assertSame(5, ProxyIdentity::resolve($this->proxies(), '203.0.113.5', null));
		$this->assertSame(5, ProxyIdentity::resolve($this->proxies(), '10.0.0.5', '5'));
		$this->assertSame(6, ProxyIdentity::resolve($this->proxies(), '203.0.113.6', ''));
	}

	public function testClaimingAnotherServerIsRefused(): void {
		$this->assertNull(ProxyIdentity::resolve($this->proxies(), '203.0.113.6', '5'));
		$this->assertNull(ProxyIdentity::resolve($this->proxies(), '203.0.113.6', 1), 'not even MAIN');
	}

	public function testUnknownSourceIsRefused(): void {
		$this->assertNull(ProxyIdentity::resolve($this->proxies(), '198.51.100.1', '5'));
		$rLb = ['198.51.100.2' => ['id' => 2, 'server_type' => 0]];
		$this->assertNull(ProxyIdentity::resolve($rLb, '198.51.100.2', '2'), 'an LB is not a proxy');
	}

	public function testSeenAddressesAreValidIpsOnly(): void {
		$this->assertSame(['10.0.0.5', '2001:db8::1'], ProxyIdentity::seenAddresses(['10.0.0.5', 'x; rm', '2001:db8::1', '10.0.0.5', ['nested']]));
		$this->assertSame([], ProxyIdentity::seenAddresses('10.0.0.5'));
	}

	public function testProxyApiNoLongerWritesWhitelistIps(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Public/admin/proxy_api.php');
		$this->assertStringNotContainsString('`whitelist_ips` = ?', $rSource);
		$this->assertStringNotContainsString("intval(\$_POST['server_id'])", $rSource);
		$this->assertStringContainsString('ProxyIdentity::resolve(', $rSource);
	}
}
