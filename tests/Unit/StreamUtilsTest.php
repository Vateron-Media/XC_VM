<?php

use XcVm\Core\Process\ProcessRunner;
use XcVm\Core\Util\StreamUtils;
use PHPUnit\Framework\TestCase;

/**
 * @covers \XcVm\Core\Util\StreamUtils
 */
final class StreamUtilsTest extends TestCase {

	public function testCustomOrderPutsInputArgumentsFirst() {
		$this->assertSame(-1, StreamUtils::customOrder('-i input.ts', 'something'));
		$this->assertSame(1, StreamUtils::customOrder('-c:v libx264', '-i input.ts'));
	}

	public function testDetectXcVmMatchesKnownStreamPaths() {
		$this->assertTrue(StreamUtils::detectXC_VM('http://host/live/user/123'));
	}

	public function testDetectXcVmRejectsUnrelatedPaths() {
		$this->assertFalse(StreamUtils::detectXC_VM('http://host/dashboard'));
	}

	public function testSanitizeSegmentNameStripsSeparators() {
		$this->assertSame('seg_0.ts', StreamUtils::sanitizeSegmentName('seg_0.ts'));
		$this->assertSame('....etcpasswd', StreamUtils::sanitizeSegmentName('../../etc/passwd'));
		// URL-encoded traversal: "%2e%2e%2fpasswd" → "../passwd" → strip "/" → "..passwd".
		$this->assertSame('..passwd', StreamUtils::sanitizeSegmentName('%2e%2e%2fpasswd'));
	}

	public function testContainerMimeType() {
		$this->assertSame('video/mp4', StreamUtils::containerMimeType('mp4'));
		$this->assertSame('video/x-matroska', StreamUtils::containerMimeType('mkv'));
		$this->assertSame('video/mp2t', StreamUtils::containerMimeType('ts'));
		$this->assertSame('application/octet-stream', StreamUtils::containerMimeType('xyz'));
		$this->assertSame('application/octet-stream', StreamUtils::containerMimeType(''));
	}

	public function testTimeshiftStartTimestamp() {
		$this->assertSame(1700000000, StreamUtils::timeshiftStartTimestamp('1700000000'));
		$this->assertSame(mktime(13, 0, 0, 1, 1, 2025), StreamUtils::timeshiftStartTimestamp('20250101-13'));
		$this->assertSame(mktime(13, 30, 0, 1, 1, 2025), StreamUtils::timeshiftStartTimestamp('2025-01-01:13-30'));
	}

	public function testSegmentRetryBudget() {
		$this->assertSame(20, StreamUtils::segmentRetryBudget(10, 5));   // seg_time*2 wins (the fixed bug)
		$this->assertSame(30, StreamUtils::segmentRetryBudget(3, 30));   // configured wins
		$this->assertSame(20, StreamUtils::segmentRetryBudget(3, 0));    // 0 → default floor 20
	}

	public function testProxyUrlAddsHttpSchemeFfmpegNeeds() {
		$this->assertSame('', StreamUtils::proxyURL(''));
		$this->assertSame('http://1.2.3.4:8080', StreamUtils::proxyURL('1.2.3.4:8080'));
		$this->assertSame('http://u:p@h:1', StreamUtils::proxyURL('http://u:p@h:1'));
		$this->assertSame('socks5://h:1', StreamUtils::proxyURL('socks5://h:1'));
	}

	public function testStreamProxyArgumentReachesFfmpegWithHttpScheme() {
		$rProxy = ['argument_key' => 'proxy', 'argument_cat' => 'fetch', 'argument_wprotocol' => 'http', 'argument_type' => 'text', 'argument_cmd' => '-http_proxy "%s"'];
		$this->assertSame(['-http_proxy "http://9.9.9.9:3128"'], StreamUtils::getArguments([$rProxy + ['value' => '9.9.9.9:3128']], 'https', 'fetch'));
		$this->assertSame(['-http_proxy "http://u:p@h:1"'], StreamUtils::getArguments([$rProxy + ['value' => 'http://u:p@h:1']], 'https', 'fetch'));
	}

	public function testParseStreamUrlRunsYtDlpThroughTheStreamProxyWithoutAShell() {
		if (!defined('YOUTUBE_BIN')) {
			define('YOUTUBE_BIN', '/bin/yt-dlp');
		}
		$rCalls = [];
		ProcessRunner::useCapturer(function (array $rArgv) use (&$rCalls) {
			$rCalls[] = $rArgv;
			return [0, "https://media.example/live.m3u8\nhttps://media.example/second\n"];
		});
		try {
			$rPage = 'https://www.youtube.com/watch?v=x';
			$this->assertSame('https://media.example/live.m3u8', StreamUtils::parseStreamURL($rPage, '1.2.3.4:8080'));
			$this->assertSame(['--proxy', 'http://1.2.3.4:8080'], array_slice($rCalls[0], array_search('--proxy', $rCalls[0], true), 2));
			// The page URL is one argument, after --, never an option.
			$this->assertSame(['--', $rPage], array_slice($rCalls[0], -2));

			StreamUtils::parseStreamURL($rPage);
			$this->assertNotContains('--proxy', $rCalls[1]);

			// Not a platform page: yt-dlp is not run.
			$this->assertSame('http://iptv.example/live.ts', StreamUtils::parseStreamURL('http://iptv.example/live.ts', '1.2.3.4:8080'));
			$this->assertCount(2, $rCalls);
		} finally {
			ProcessRunner::useCapturer(null);
		}
	}

	public function testParseStreamUrlFallsBackFromMwebToAndroidVr() {
		if (!defined('YOUTUBE_BIN')) {
			define('YOUTUBE_BIN', '/bin/yt-dlp');
		}
		$rClients = [];
		$rMwebWorks = false;
		ProcessRunner::useCapturer(function (array $rArgv) use (&$rClients, &$rMwebWorks) {
			$rClients[] = $rClient = $rArgv[array_search('--extractor-args', $rArgv, true) + 1];
			return [0, ($rClient === 'youtube:player_client=mweb') === $rMwebWorks ? "https://media.example/{$rClient}.m3u8\n" : ''];
		});
		try {
			$rPage = 'https://www.youtube.com/watch?v=x';
			// No JS runtime: mweb prints nothing, android_vr resolves it.
			$this->assertSame('https://media.example/youtube:player_client=default,android_vr.m3u8', StreamUtils::parseStreamURL($rPage));
			$this->assertSame(['youtube:player_client=mweb', 'youtube:player_client=default,android_vr'], $rClients);

			// mweb resolves it: android_vr is never asked.
			$rClients = [];
			$rMwebWorks = true;
			$this->assertSame('https://media.example/youtube:player_client=mweb.m3u8', StreamUtils::parseStreamURL($rPage));
			$this->assertSame(['youtube:player_client=mweb'], $rClients);
		} finally {
			ProcessRunner::useCapturer(null);
		}
	}
}
