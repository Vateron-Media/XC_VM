<?php

use XcVm\Streaming\Fanout\FanoutClient;
use PHPUnit\Framework\TestCase;

/**
 * @covers \XcVm\Streaming\Fanout\FanoutClient
 *
 * Exercises the pure source-config builder that feeds the xc_fanout daemon's
 * control API (ADR 0002, P2). buildSource() mirrors ProxyCommand's argument
 * extraction; the output shape must match the daemon's streamConfig JSON
 * (urls/ua/proxy/cookie/ffmpeg).
 */
final class FanoutClientTest extends TestCase {

	public function testBuildSourceDecodesStreamSourceArray() {
		$row = ['stream_source' => json_encode(['http://a/1.ts', 'http://b/2.m3u8'])];
		$src = FanoutClient::buildSource($row, []);

		$this->assertSame(['http://a/1.ts', 'http://b/2.m3u8'], $src['urls']);
		$this->assertArrayHasKey('ua', $src);
		$this->assertArrayHasKey('proxy', $src);
		$this->assertArrayHasKey('cookie', $src);
		$this->assertArrayHasKey('ffmpeg', $src);
	}

	public function testBuildSourceTrimsAndDropsEmptyUrls() {
		$row = ['stream_source' => json_encode(['  http://a/1.ts  ', '', '   '])];
		$src = FanoutClient::buildSource($row, []);

		$this->assertSame(['http://a/1.ts'], $src['urls']);
	}

	public function testBuildSourceDefaultsUserAgentWhenArgumentMissing() {
		$row = ['stream_source' => json_encode(['http://a/1.ts'])];
		$src = FanoutClient::buildSource($row, []);

		$this->assertSame('Mozilla/5.0', $src['ua']);
		$this->assertSame('', $src['proxy']);
		$this->assertSame('', $src['cookie']);
	}

	public function testBuildSourceFallsBackToArgumentDefaultUserAgent() {
		$row = ['stream_source' => json_encode(['http://a/1.ts'])];
		$args = ['user_agent' => ['value' => '', 'argument_default_value' => 'VLC/3.0']];
		$src = FanoutClient::buildSource($row, $args);

		$this->assertSame('VLC/3.0', $src['ua']);
	}

	public function testBuildSourcePrefersExplicitUserAgentValue() {
		$row = ['stream_source' => json_encode(['http://a/1.ts'])];
		$args = ['user_agent' => ['value' => 'MyPlayer/1.0', 'argument_default_value' => 'VLC/3.0']];
		$src = FanoutClient::buildSource($row, $args);

		$this->assertSame('MyPlayer/1.0', $src['ua']);
	}

	public function testBuildSourceExtractsProxyAndCookie() {
		$row = ['stream_source' => json_encode(['http://a/1.ts'])];
		$args = [
			'proxy'  => ['value' => '1.2.3.4:8080'],
			'cookie' => ['value' => 'sid=xyz'],
		];
		$src = FanoutClient::buildSource($row, $args);

		$this->assertSame('1.2.3.4:8080', $src['proxy']);
		$this->assertSame('sid=xyz', $src['cookie']);
	}

	public function testBuildSourceHandlesMissingOrInvalidStreamSource() {
		$this->assertSame([], FanoutClient::buildSource([], [])['urls']);
		$this->assertSame([], FanoutClient::buildSource(['stream_source' => ''], [])['urls']);
		$this->assertSame([], FanoutClient::buildSource(['stream_source' => 'not-json'], [])['urls']);
	}

	public function testRegisterRejectsEmptyUrlsWithoutTouchingDaemon() {
		// No daemon is running in tests; empty urls must short-circuit to false
		// before any socket call is attempted.
		$this->assertFalse(FanoutClient::register(42, ['urls' => []]));
	}

	public function testRegisterIngestReturnsNullWithoutDaemon() {
		// FANOUT_CTL_SOCK is not defined in the unit env, so registerIngest must
		// fail closed (null) — the caller then launches ffmpeg legacy-only.
		$this->assertNull(FanoutClient::registerIngest(42));
	}
}
