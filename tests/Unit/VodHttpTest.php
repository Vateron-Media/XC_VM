<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Vod\VodHttp;

final class VodHttpTest extends TestCase {
	protected function tearDown(): void { VodHttp::useResolver(null); VodHttp::useTransport(null); }

	public function testRejectsInternalAndReservedTargetsBeforeConnecting(): void {
		$calls = 0;
		VodHttp::useTransport(static function () use (&$calls) { $calls++; return true; });
		foreach (['127.0.0.1', '10.1.1.1', '172.16.0.1', '192.168.1.1', '169.254.169.254', '100.64.1.1', '224.0.0.1', '198.18.0.1', '192.0.2.1', '[::1]', '[::ffff:127.0.0.1]', '[64:ff9b::a9fe:a9fe]', '[fc00::1]', '[fe80::1]', '[ff02::1]', '[2001:db8::1]'] as $host) {
			try { VodHttp::get('http://' . $host . '/', [], static fn($chunk): int => strlen($chunk)); $this->fail('Accepted ' . $host); }
			catch (InvalidArgumentException) { $this->addToAssertionCount(1); }
		}
		$this->assertSame(0, $calls);
	}

	public function testMixedDnsAndRedirectToMetadataAreBlocked(): void {
		VodHttp::useResolver(static fn(): array => ['93.184.216.34', '10.0.0.1']);
		try { VodHttp::addresses('mixed.example'); $this->fail('Mixed DNS accepted'); }
		catch (InvalidArgumentException) { $this->addToAssertionCount(1); }
		VodHttp::useResolver(static fn(): array => ['93.184.216.34']);
		$calls = 0;
		VodHttp::useTransport(function ($url, $options) use (&$calls): bool {
			$calls++;
			$this->assertFalse($options[CURLOPT_FOLLOWLOCATION]);
			$this->assertSame('', $options[CURLOPT_PROXY]);
			$this->assertSame(['public.example:80:93.184.216.34'], $options[CURLOPT_RESOLVE]);
			$options[CURLOPT_HEADERFUNCTION](null, "HTTP/1.1 302 Found\r\n");
			$options[CURLOPT_HEADERFUNCTION](null, "Location: http://169.254.169.254/latest/meta-data/\r\n");
			return true;
		});
		try { VodHttp::get('http://PUBLIC.example/list', [], static fn($chunk): int => strlen($chunk)); $this->fail('Unsafe redirect accepted'); }
		catch (InvalidArgumentException) { $this->assertSame(1, $calls); }
	}

	public function testRedirectsPinFreshDnsAndResolveRelativeUrls(): void {
		$resolved = [];
		VodHttp::useResolver(static function ($host) use (&$resolved): array { $resolved[] = $host; return ['93.184.216.34']; });
		$calls = [];
		VodHttp::useTransport(function ($url, $options) use (&$calls): bool {
			$calls[] = $url;
			if (count($calls) === 1) {
				$options[CURLOPT_HEADERFUNCTION](null, "HTTP/1.1 302 Found\r\n");
				$options[CURLOPT_HEADERFUNCTION](null, "Location: ../new/list.m3u?token=abc\r\n");
			} else {
				$options[CURLOPT_HEADERFUNCTION](null, "HTTP/1.1 200 OK\r\n");
				$options[CURLOPT_HEADERFUNCTION](null, "Content-Type: text/plain\r\n");
				$this->assertSame(4, $options[CURLOPT_WRITEFUNCTION](null, 'body'));
			}
			return true;
		});
		$body = '';
		$reply = VodHttp::get('http://public.example/old/list.m3u', [], static function ($chunk) use (&$body): int { $body .= $chunk; return strlen($chunk); });
		$this->assertTrue($reply['ok']);
		$this->assertSame(['http://public.example/old/list.m3u', 'http://public.example/new/list.m3u?token=abc'], $calls);
		$this->assertSame(['public.example', 'public.example'], $resolved);
		$this->assertSame('body', $body);
	}
}
