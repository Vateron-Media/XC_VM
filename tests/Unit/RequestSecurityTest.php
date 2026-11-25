<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Http\RequestSecurity;
use XcVm\Core\Util\NetworkUtils;

final class RequestSecurityTest extends TestCase {
	public function testHttpsUsesOnlyTheDirectConnectionOrATrustedProxyRewrite(): void {
		$this->assertTrue(NetworkUtils::isHttps(['HTTPS' => 'on']));
		$this->assertTrue(NetworkUtils::isHttps(['REQUEST_SCHEME' => 'https']));
		$this->assertTrue(NetworkUtils::isHttps([
			'REMOTE_ADDR' => '203.0.113.8',
			'XC_PEER_ADDR' => '10.0.0.2',
			'HTTP_X_FORWARDED_PROTO' => 'https',
		]));
		$this->assertFalse(NetworkUtils::isHttps([
			'REMOTE_ADDR' => '203.0.113.8',
			'XC_PEER_ADDR' => '203.0.113.8',
			'HTTP_X_FORWARDED_PROTO' => 'https',
		]));
	}

	public function testSameOriginWritesWorkDirectlyAndBehindATrustedProxy(): void {
		$this->assertTrue(RequestSecurity::sameOrigin([
			'HTTP_HOST' => 'panel.example:8080',
			'HTTP_ORIGIN' => 'http://panel.example:8080',
			'REMOTE_ADDR' => '203.0.113.8',
		]));
		$this->assertTrue(RequestSecurity::sameOrigin([
			'HTTP_HOST' => 'panel.example',
			'HTTP_REFERER' => 'https://panel.example/admin/settings',
			'REMOTE_ADDR' => '203.0.113.8',
			'XC_PEER_ADDR' => '10.0.0.2',
			'HTTP_X_FORWARDED_PROTO' => 'https',
		]));
	}

	public function testCrossSiteMissingAndSpoofedForwardingOriginsFailClosed(): void {
		$rBase = ['HTTP_HOST' => 'panel.example', 'REMOTE_ADDR' => '203.0.113.8'];
		$this->assertFalse(RequestSecurity::sameOrigin($rBase));
		$this->assertFalse(RequestSecurity::sameOrigin($rBase + ['HTTP_ORIGIN' => 'https://evil.example']));
		$this->assertFalse(RequestSecurity::sameOrigin($rBase + [
			'HTTP_ORIGIN' => 'https://panel.example',
			'XC_PEER_ADDR' => '203.0.113.8',
			'HTTP_X_FORWARDED_PROTO' => 'https',
		]));
	}

	public function testSessionCookieIsSecureOnlyForVerifiedHttps(): void {
		$this->assertFalse($this->cookieSecure(['REMOTE_ADDR' => '203.0.113.8']));
		$this->assertTrue($this->cookieSecure(['HTTPS' => 'on']));
		$this->assertTrue($this->cookieSecure([
			'REMOTE_ADDR' => '203.0.113.8',
			'XC_PEER_ADDR' => '10.0.0.2',
			'HTTP_X_FORWARDED_PROTO' => 'https',
		]));
	}

	/** @param array<string, string> $rServer */
	private function cookieSecure(array $rServer): bool {
		$rCode = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
			. '$_SERVER = ' . var_export($rServer, true) . ';'
			. 'ini_set("session.save_path", sys_get_temp_dir());'
			. 'XcVm\\Core\\Bootstrap\\Stage\\SessionStage::startSession();'
			. 'echo json_encode(session_get_cookie_params()); session_destroy();';
		$rProcess = proc_open([PHP_BINARY, '-r', $rCode], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProcess);
		$rOut = stream_get_contents($rPipes[1]);
		$rErr = stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProcess), $rErr);
		$rParams = json_decode((string) $rOut, true);
		$this->assertIsArray($rParams, (string) $rOut . $rErr);
		return (bool) $rParams['secure'];
	}
}
