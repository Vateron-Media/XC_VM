<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Logging\DatabaseLogger;
use XcVm\Core\Logging\RefusalLog;

/**
 * RefusalLog — the refusals core answered without a client log (a stream id
 * that does not exist, credentials nothing matches) go into the client logs,
 * at most CAP a minute per address and without the query string, which on a
 * refused sign-in carries the password tried.
 */
final class RefusalLogTest extends TestCase {
	private string $rDir;
	private string $rLog;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-refusal-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir, 0777, true);
		$this->rLog = $this->rDir . 'client_request.log';
		RefusalLog::useDir($this->rDir);
		DatabaseLogger::setLogFile($this->rLog);
		DatabaseLogger::setEnabled(1);
		$_SERVER['QUERY_STRING'] = 'username=alice&password=guess';
		$_SERVER['HTTP_USER_AGENT'] = 'curl/8.5';
	}

	protected function tearDown(): void {
		RefusalLog::useDir(null);
		// DatabaseLogger keeps both statically and has no reset: back to its defaults.
		foreach (['logFile', 'enabled'] as $rProp) {
			(new ReflectionProperty(DatabaseLogger::class, $rProp))->setValue(null, null);
		}
		unset($_SERVER['QUERY_STRING'], $_SERVER['HTTP_USER_AGENT']);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** @return list<array<string, mixed>> */
	private function lines(): array {
		if (!is_file($this->rLog)) {
			return [];
		}
		return array_map(static fn(string $rLine): array => json_decode(base64_decode($rLine), true), file($this->rLog, FILE_IGNORE_NEW_LINES));
	}

	public function testTheTwoRefusalsAreLoggedWithoutTheQueryString(): void {
		RefusalLog::record('INVALID_STREAM_ID', '203.0.113.7');
		RefusalLog::record('INVALID_CREDENTIALS', '2001:db8::7');
		$rLines = $this->lines();
		$this->assertSame(['INVALID_STREAM_ID', 'INVALID_CREDENTIALS'], array_column($rLines, 'action'));
		$this->assertSame(['203.0.113.7', '2001:db8::7'], array_column($rLines, 'user_ip'));
		$this->assertSame(['', ''], array_column($rLines, 'query_string'), 'the password tried stays out');
		$this->assertSame('curl/8.5', $rLines[0]['user_agent']);
	}

	public function testOtherCodesAndBadAddressesAreLeftAlone(): void {
		RefusalLog::record('USER_EXPIRED', '203.0.113.7');
		RefusalLog::record('INVALID_STREAM_ID', 'not-an-ip');
		$this->assertSame([], $this->lines());
	}

	public function testAtMostCapAMinutePerAddress(): void {
		$rNow = 1800000000;
		for ($i = 0; $i < RefusalLog::CAP; $i++) {
			$this->assertTrue(RefusalLog::counted('203.0.113.7', $rNow));
		}
		$this->assertFalse(RefusalLog::counted('203.0.113.7', $rNow), 'past the cap');
		$this->assertFalse(RefusalLog::counted('203.0.113.7', $rNow + 1), 'still the same minute');
		$this->assertTrue(RefusalLog::counted('198.51.100.1', $rNow), 'another address has its own count');
		$this->assertTrue(RefusalLog::counted('203.0.113.7', $rNow - $rNow % 60 + 60), 'the next minute starts again');
	}

	public function testEverySiteRecordsIt(): void {
		$rRoot = MAIN_HOME;
		$this->assertStringContainsString('RefusalLog::record($rError);', (string) file_get_contents($rRoot . 'Core/Error/ErrorHandler.php'));
		$this->assertStringContainsString("RefusalLog::record('INVALID_CREDENTIALS');", (string) file_get_contents($rRoot . 'Public/Controllers/Api/PlayerApiController.php'));
		$this->assertStringContainsString("RefusalLog::record('INVALID_CREDENTIALS', \$rIP);", (string) file_get_contents($rRoot . 'Domain/User/RtmpViewerAuth.php'));
		$this->assertSame(2, substr_count((string) file_get_contents($rRoot . 'Ministra/PortalHandler.php'), 'RefusalLog::record("INVALID_CREDENTIALS"'));
	}
}
