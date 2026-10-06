<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Util\StreamUtils;

/**
 * A stream's cookie is completed before ffmpeg reads it (StreamUtils::fixCookie):
 * ffmpeg's -cookies takes the text of a Set-Cookie field, and sends a cookie
 * only when its `path` and `domain` fit the request. A cookie typed without
 * its last `;` is completed exactly as the same cookie typed with it, so its
 * value reaches the source as it was typed.
 *
 * The text for a cookie that has its last `;` (blanks after it aside) is kept
 * here as it has always been. That includes a `path` or `domain` typed after
 * a space: it is not taken as the cookie's own, and the empty `domain=` added
 * behind it is what makes ffmpeg send the cookie to a source addressed with a
 * port.
 *
 * The node's `probe` action hands ffprobe the same text, so a probe tells
 * what the stream will get. The action ends its request, so it runs in a
 * child PHP, with a program in ffprobe's place that records what it received.
 */
final class AuditDecisionCookieTest extends TestCase {
	private const URL = 'http://203.0.113.5:8080/live/user/pass/1.m3u8?token=abc&x=1';

	private string $rDir = '';

	protected function tearDown(): void {
		if ($this->rDir !== '') {
			exec('rm -rf ' . escapeshellarg($this->rDir));
		}
	}

	public static function withoutTheLastSemicolon(): array {
		return [
			'one pair' => ['session=abc123', 'session=abc123;path=/;domain=;'],
			'two pairs' => ['session=abc123; token=x.y-z', 'session=abc123; token=x.y-z;path=/;domain=;'],
			'a value with an equals sign' => ['token=YWJj==', 'token=YWJj==;path=/;domain=;'],
			'a path of its own' => ['session=abc123;path=/live', 'session=abc123;path=/live;domain=;'],
			'a path typed after a space' => ['session=abc123; path=/', 'session=abc123; path=/;path=/;domain=;'],
			'a domain of its own' => ['session=abc123;domain=example.com', 'session=abc123;domain=example.com;path=/;'],
			'a path and a domain of its own' => ['session=abc123;path=/;domain=example.com', 'session=abc123;path=/;domain=example.com;'],
			'a path and a domain typed after a space' => ['session=abc123; path=/; domain=example.com', 'session=abc123; path=/; domain=example.com;path=/;domain=;'],
			'a space after the value' => ['session=abc123 ', 'session=abc123 ;path=/;domain=;'],
		];
	}

	#[DataProvider('withoutTheLastSemicolon')]
	public function testACookieTypedWithoutItsLastSemicolonIsCompletedAsTheSameCookieWithIt(string $rTyped, string $rExpected): void {
		$this->assertSame($rExpected, StreamUtils::fixCookie($rTyped));
		$this->assertSame($rExpected, StreamUtils::fixCookie($rTyped . ';'));
	}

	public static function asItAlwaysWas(): array {
		return [
			'one pair' => ['session=abc123;', 'session=abc123;path=/;domain=;'],
			'two pairs' => ['session=abc123; token=x.y-z;', 'session=abc123; token=x.y-z;path=/;domain=;'],
			'a space after the last semicolon' => ['session=abc123; ', 'session=abc123; path=/;domain=;'],
			'two pairs and a space after the last semicolon' => ['session=abc123; token=x.y-z; ', 'session=abc123; token=x.y-z; path=/;domain=;'],
			'a path of its own' => ['session=abc123;path=/live;', 'session=abc123;path=/live;domain=;'],
			'a domain of its own' => ['session=abc123;domain=example.com;', 'session=abc123;domain=example.com;path=/;'],
			'a path and a domain of its own' => ['session=abc123;path=/;domain=example.com;', 'session=abc123;path=/;domain=example.com;'],
			'a path and a domain in capitals' => ['session=abc123;Path=/;Domain=example.com;', 'session=abc123;Path=/;Domain=example.com;'],
			'a path and a domain typed after a space' => ['session=abc123; path=/; domain=example.com;', 'session=abc123; path=/; domain=example.com;path=/;domain=;'],
			'a completed cookie' => ['session=abc123;path=/;domain=;', 'session=abc123;path=/;domain=;'],
			'a value ending in zero' => ['id=10', 'id=10;path=/;domain=;'],
			'no cookie' => ['', ';path=/;domain=;'],
		];
	}

	/** A cookie with its last `;`, a value ending in 0 and no cookie at all were completed right. */
	#[DataProvider('asItAlwaysWas')]
	public function testEveryOtherCookieIsCompletedAsItAlwaysWas(string $rTyped, string $rExpected): void {
		$this->assertSame($rExpected, StreamUtils::fixCookie($rTyped));
	}

	public function testCompletingACookieRaisesNothing(): void {
		$rRaised = [];
		$rTyped = '';
		set_error_handler(static function (int $rNo, string $rMessage) use (&$rRaised, &$rTyped): bool {
			$rRaised[$rTyped][] = $rMessage;
			return true;
		});
		try {
			foreach ([...array_values(self::withoutTheLastSemicolon()), ...array_values(self::asItAlwaysWas())] as [$rTyped]) {
				StreamUtils::fixCookie($rTyped);
			}
		} finally {
			restore_error_handler();
		}
		$this->assertSame([], $rRaised);
	}

	/**
	 * Run the node's probe action with this request.
	 *
	 * @return list<string> what ffprobe received
	 */
	private function probe(array $rRequest): array {
		$this->rDir = sys_get_temp_dir() . '/xcvm-probe-cookie-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir, 0700, true);
		// Records its arguments, one per NUL, and answers as ffprobe would.
		file_put_contents($this->rDir . 'program', "#!/bin/sh\nfor a in \"\$@\"; do printf '%s\\000' \"\$a\"; done > " . escapeshellarg($this->rDir . 'argv') . "\necho '{\"streams\": []}'\n");
		chmod($this->rDir . 'program', 0755);
		file_put_contents($this->rDir . 'probe.php', <<<'PHP'
			<?php
			use XcVm\Public\Controllers\Api\InternalApiController;

			require getenv('XCVM_TEST_BOOTSTRAP');
			$rSettings = ['stream_max_analyze' => 2000000, 'probesize' => 5000000, 'probe_extra_wait' => 3];
			$rFFPROBE = getenv('XCVM_TEST_PROGRAM');
			$rController = new InternalApiController();
			(new ReflectionMethod($rController, 'probeStream'))->invoke($rController, json_decode((string) getenv('XCVM_TEST_REQUEST'), true));
			PHP);
		$rEnv = ['XCVM_TEST_BOOTSTRAP' => dirname(__DIR__) . '/bootstrap.php', 'XCVM_TEST_PROGRAM' => $this->rDir . 'program', 'XCVM_TEST_REQUEST' => json_encode($rRequest), 'PATH' => (string) getenv('PATH')];
		$rProc = proc_open([PHP_BINARY, $this->rDir . 'probe.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes, $this->rDir, $rEnv);
		$this->assertIsResource($rProc);
		proc_close($rProc);
		$rFile = $this->rDir . 'argv';
		return file_exists($rFile) ? explode("\0", (string) file_get_contents($rFile), -1) : [];
	}

	public static function probedCookies(): array {
		return [
			'one pair' => ['session=abc123', 'session=abc123;path=/;domain=;'],
			'one pair and its semicolon' => ['session=abc123;', 'session=abc123;path=/;domain=;'],
			'two pairs' => ['session=abc123; token=x.y-z', 'session=abc123; token=x.y-z;path=/;domain=;'],
			'a path and a domain of its own' => ['session=abc123;path=/;domain=example.com;', 'session=abc123;path=/;domain=example.com;'],
			'shell syntax' => ["name=it's \"q\" $(echo ran) `echo ran` | echo ran", "name=it's \"q\" $(echo ran) `echo ran` | echo ran;path=/;domain=;"],
		];
	}

	#[DataProvider('probedCookies')]
	public function testAProbeHandsFfprobeTheCookieTheStreamWillHandFfmpeg(string $rTyped, string $rExpected): void {
		$this->assertSame($rExpected, StreamUtils::fixCookie($rTyped), 'what a stream start hands ffmpeg');
		$rArgv = $this->probe(['url' => self::URL, 'cookies' => $rTyped]);
		$this->assertSame(
			['-probesize', '5000000', '-analyzeduration', '2000000', '-cookies', $rExpected, '-headers', "X-XC_VM-Prebuffer:1\r\n", '-i', self::URL, '-v', 'quiet', '-print_format', 'json', '-show_streams', '-show_format'],
			$rArgv
		);
	}
}
