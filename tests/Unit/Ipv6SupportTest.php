<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\StatusCommand;
use XcVm\Core\Util\PublicAddress;

/**
 * IPv6 support: a bare IPv6 host is bracketed in a URL authority (RFC 3986),
 * and nginx is given a `listen [::]:` line only when the host has an IPv6
 * stack (so a kernel with IPv6 disabled is not handed a listen it cannot bind).
 */
final class Ipv6SupportTest extends TestCase {

	/** @return array<string, array{0: string, 1: string}> */
	public static function hosts(): array {
		return [
			'ipv6 literal' => ['2001:db8::1', '[2001:db8::1]'],
			'ipv6 loopback' => ['::1', '[::1]'],
			'ipv4' => ['192.0.2.10', '192.0.2.10'],
			'hostname' => ['node.example.com', 'node.example.com'],
			'already bracketed' => ['[2001:db8::1]', '[2001:db8::1]'],
			'empty' => ['', ''],
		];
	}

	/** @dataProvider hosts */
	public function testForUrlBracketsOnlyBareIpv6(string $rHost, string $rExpected): void {
		$this->assertSame($rExpected, PublicAddress::forUrl($rHost));
	}

	public function testNginxGetsNoIpv6ListenWithoutAnIpv6Stack(): void {
		$this->assertSame("listen 80;\n", StatusCommand::ipv6ListenFor("listen 80 reuseport;\n", false));
	}

	public function testNginxGetsAMatchingIpv6ListenWithAnIpv6Stack(): void {
		$this->assertSame(
			"listen 80;\nlisten [::]:80 ipv6only=on;\n",
			StatusCommand::ipv6ListenFor("listen 80 reuseport;\n", true)
		);
		$this->assertSame(
			"listen 443 ssl;\nlisten [::]:443 ssl ipv6only=on;\n",
			StatusCommand::ipv6ListenFor("listen 443 ssl reuseport;\n", true)
		);
	}

	public function testIpv6ListenIsIdempotentAndReversible(): void {
		$rDual = StatusCommand::ipv6ListenFor("listen 80 reuseport;\n", true);
		$this->assertSame($rDual, StatusCommand::ipv6ListenFor($rDual, true), 'applying it again changes nothing');
		$this->assertSame("listen 80;\n", StatusCommand::ipv6ListenFor($rDual, false), 'the [::] listen is dropped if the stack goes away');
	}

	/** The URL builders call the bracketing helper (behaviour above is drivable only through them). */
	public function testUrlBuildersBracketTheHost(): void {
		$rRepo = (string) file_get_contents(MAIN_HOME . 'Domain/Server/ServerRepository.php');
		$this->assertStringContainsString('PublicAddress::forUrl($rURL)', $rRepo, 'site/http/https/rtmp URLs');
		$this->assertStringContainsString('PublicAddress::forUrl($rDomain)', $rRepo, 'getPublicURL');
		$this->assertStringContainsString('PublicAddress::forUrl(escapeshellcmd((string) $rRow[\'server_ip\'])', $rRepo, 'the *_ip URLs');
		$rExt = (string) file_get_contents(MAIN_HOME . 'Domain/External/ExternalXtreamService.php');
		$this->assertStringContainsString('PublicAddress::forUrl($host)', $rExt, 'parse_url strips brackets; they are re-added');
	}
}
