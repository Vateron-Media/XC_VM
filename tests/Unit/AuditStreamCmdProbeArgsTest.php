<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The node's `probe` action (InternalApiController::probeStream) hands
 * ffprobe the user agent MAIN sent with the request as it was typed, and the
 * cookie completed as a stream start completes it (StreamUtils::fixCookie),
 * each as one argument: what the stream itself will send once it is saved
 * with them.
 *
 * The action ends its request, so it runs in a child PHP, with a program in
 * ffprobe's place that records what it received.
 */
final class AuditStreamCmdProbeArgsTest extends TestCase {
	private const URL = 'http://203.0.113.5:8080/live/user/pass/1.m3u8?token=abc&x=1';

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-probe-args-' . bin2hex(random_bytes(4)) . '/';
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
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * Run the action with this request.
	 *
	 * @return array{0: list<string>, 1: string} what ffprobe received (none when it did not run), and the action's answer
	 */
	private function probe(array $rRequest): array {
		$rEnv = ['XCVM_TEST_BOOTSTRAP' => dirname(__DIR__) . '/bootstrap.php', 'XCVM_TEST_PROGRAM' => $this->rDir . 'program', 'XCVM_TEST_REQUEST' => json_encode($rRequest), 'PATH' => (string) getenv('PATH')];
		$rProc = proc_open([PHP_BINARY, $this->rDir . 'probe.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes, $this->rDir, $rEnv);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		fclose($rPipes[1]);
		proc_close($rProc);
		$rFile = $this->rDir . 'argv';
		$rArgv = file_exists($rFile) ? explode("\0", (string) file_get_contents($rFile), -1) : [];
		@unlink($rFile);
		return [$rArgv, $rOut];
	}

	/** What ffprobe receives for a request with these options, in the order the action writes them. */
	private static function expected(array $rOptions): array {
		return array_merge(['-probesize', '5000000', '-analyzeduration', '2000000'], $rOptions, ['-headers', "X-XC_VM-Prebuffer:1\r\n", '-i', self::URL, '-v', 'quiet', '-print_format', 'json', '-show_streams', '-show_format']);
	}

	public static function values(): array {
		return [
			'a plain user agent' => ['user_agent', '-user_agent', 'VLC/3.0.20 LibVLC/3.0.20'],
			'a browser user agent' => ['user_agent', '-user_agent', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'],
			'a user agent with an apostrophe' => ['user_agent', '-user_agent', "O'Brien Player/1.0"],
			'a user agent with two apostrophes' => ['user_agent', '-user_agent', "Player' -an 'x"],
			'a user agent with shell syntax' => ['user_agent', '-user_agent', 'Player "1.0" $HOME $(echo ran) `echo ran`; echo ran \\ #x'],
			'a plain cookie' => ['cookies', '-cookies', 'session=abc123', 'session=abc123;path=/;domain=;'],
			'a cookie with several values' => ['cookies', '-cookies', 'session=abc123; token=x&y=(1); path=/; domain=.example.com;', 'session=abc123; token=x&y=(1); path=/; domain=.example.com;path=/;domain=;'],
			'a cookie with an apostrophe' => ['cookies', '-cookies', "name=it's; path=/;", "name=it's; path=/;path=/;domain=;"],
			'a cookie with two apostrophes' => ['cookies', '-cookies', "name=a' -an 'b", "name=a' -an 'b;path=/;domain=;"],
			'a cookie with shell syntax' => ['cookies', '-cookies', 'name="q" $(echo ran) `echo ran` | echo ran', 'name="q" $(echo ran) `echo ran` | echo ran;path=/;domain=;'],
		];
	}

	/** A user agent arrives as typed; a cookie arrives as its fourth column, the text a stream start gives ffmpeg. */
	#[DataProvider('values')]
	public function testTheValueReachesFfprobeAsOneArgument(string $rField, string $rOption, string $rValue, ?string $rReceived = null): void {
		[$rArgv, $rOut] = $this->probe(['url' => self::URL, $rField => $rValue]);
		$this->assertSame(self::expected([$rOption, $rReceived ?? $rValue]), $rArgv);
		$this->assertSame(['result' => true, 'data' => ['streams' => []]], json_decode($rOut, true));
	}

	/** The options beside them, and the order of them all, are as they were. */
	public function testEveryOptionOfARequestIsOneArgument(): void {
		[$rArgv] = $this->probe(['url' => self::URL, 'user_agent' => "Mozilla/5.0 (it's)", 'http_proxy' => '1.2.3.4:8080', 'cookies' => 'a=(1); b=2', 'headers' => "Referer: https://example.com/\r\nOrigin: https://example.com"]);
		$this->assertSame([
			'-probesize', '5000000', '-analyzeduration', '2000000',
			'-user_agent', "Mozilla/5.0 (it's)", '-http_proxy', 'http://1.2.3.4:8080', '-cookies', 'a=(1); b=2;path=/;domain=;',
			'-headers', "Referer: https://example.com/\r\nOrigin: https://example.com\r\nX-XC_VM-Prebuffer:1\r\n",
			'-i', self::URL, '-v', 'quiet', '-print_format', 'json', '-show_streams', '-show_format',
		], $rArgv);
	}

	public function testARequestWithoutThemNamesNeither(): void {
		[$rArgv] = $this->probe(['url' => self::URL]);
		$this->assertSame(self::expected([]), $rArgv);
	}
}
