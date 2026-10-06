<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Util\StreamUtils;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Streaming\Codec\FFprobeRunner;
use XcVm\Tests\Support\InstallSchema;

/**
 * A stream option (user agent, proxy, cookie, headers, forced audio codec,
 * the transcode options) and a probed source reach ffmpeg / ffprobe as the
 * value that was stored: one argument, whatever characters it holds. The
 * command lines are run by a shell, so every value is quoted for the place
 * it takes in its option's template.
 *
 * The templates are the install schema's own rows (`streams_arguments`), and
 * each line is run by /bin/sh with a program that records what it received.
 */
final class AuditShellArgsStreamTest extends TestCase {
	/** @var array<string, array<string, mixed>> The seeded argument rows, by argument_key. */
	private static array $rSeed = [];

	private string $rDir;
	private string $rProgram;

	public static function setUpBeforeClass(): void {
		$rDb = new TestDb();
		$rDb->exec(InstallSchema::table('streams_arguments'));
		preg_match('/^INSERT INTO `streams_arguments` .*?;$/ms', (string) file_get_contents(MAIN_HOME . 'bin/install/database.sql'), $rInsert);
		$rDb->exec($rInsert[0]);
		$rDb->query('SELECT * FROM `streams_arguments` ORDER BY `id` ASC;');
		self::$rSeed = array_column($rDb->get_rows(), null, 'argument_key');
	}

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-shell-args-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir, 0700, true);
		// Records its arguments, one per NUL, and answers as ffprobe would.
		$this->rProgram = $this->rDir . 'program';
		file_put_contents($this->rProgram, "#!/bin/sh\nfor a in \"\$@\"; do printf '%s\\000' \"\$a\"; done > " . escapeshellarg($this->rDir . 'argv') . "\necho '{}'\n");
		chmod($this->rProgram, 0755);
	}

	protected function tearDown(): void {
		array_map('unlink', glob($this->rDir . '*') ?: []);
		rmdir($this->rDir);
	}

	/** @return list<string> What the program last received. */
	private function received(): array {
		$rFile = $this->rDir . 'argv';
		$rArgv = file_exists($rFile) ? explode("\0", (string) file_get_contents($rFile), -1) : [];
		@unlink($rFile);
		return $rArgv;
	}

	/**
	 * The arguments a program receives when a shell runs it with this text
	 * after its name, as ffmpeg and ffprobe are run.
	 *
	 * @return list<string>
	 */
	private function argv(string $rArguments): array {
		$rProcess = proc_open(['/bin/sh', '-c', $this->rProgram . ' ' . $rArguments], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		proc_close($rProcess);
		return $this->received();
	}

	/** The option's row as a stream carries it: the definition and the stored value. */
	private static function row(string $rKey, string $rValue): array {
		return ['value' => $rValue] + self::$rSeed[$rKey];
	}

	/** The command-line text the options give, as StreamProcess joins it. */
	private static function line(array $rRows): string {
		if ($rRows[0]['argument_cat'] == 'transcode') {
			return implode(' ', StreamUtils::parseTranscode(StreamUtils::getArguments($rRows, 'http', 'transcode')));
		}
		return implode(' ', StreamUtils::getArguments($rRows, 'http', 'fetch'));
	}

	/** The same text from the template alone, for a value a shell reads as plain text. */
	private static function plainLine(array $rRow, string $rValue): string {
		$rText = sprintf($rRow['argument_cmd'], $rValue);
		return $rRow['argument_cat'] == 'transcode' ? implode(' ', StreamUtils::parseTranscode([$rText])) : $rText;
	}

	/** The value the option hands on: a cookie and a proxy are completed first. */
	private static function handedOn(string $rKey, string $rValue): string {
		return match ($rKey) {
			'cookie' => StreamUtils::fixCookie($rValue),
			'proxy' => StreamUtils::proxyURL($rValue),
			default => $rValue,
		};
	}

	public static function ordinaryValues(): array {
		$rAgent = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
		$rHeaders = "Referer: https://example.com/\r\nOrigin: https://example.com";
		return [
			'user agent' => ['user_agent', $rAgent, ['-user_agent', $rAgent]],
			'proxy as ip:port' => ['proxy', '1.2.3.4:8080', ['-http_proxy', 'http://1.2.3.4:8080']],
			'proxy as a URL' => ['proxy', 'http://user:pass@proxy.example:3128', ['-http_proxy', 'http://user:pass@proxy.example:3128']],
			'cookie' => ['cookie', 'session=abc123; token=x.y-z', ['-cookies', StreamUtils::fixCookie('session=abc123; token=x.y-z')]],
			'headers' => ['headers', $rHeaders, ['-headers', $rHeaders]],
			'forced audio codec' => ['force_input_acodec', 'aac', ['-acodec', 'aac']],
			'video bitrate' => ['bitrate', '2500', ['-b:v', '2500k']],
			'audio bitrate' => ['audio_bitrate', '128', ['-b:a', '128k']],
			'minimum bitrate' => ['minimum_bitrate', '1000', ['-minrate', '1000k']],
			'maximum bitrate' => ['maximum_bitrate', '4000', ['-maxrate', '4000k']],
			'buffer size' => ['bufsize', '8000', ['-bufsize', '8000k']],
			'crf' => ['crf', '23', ['-crf', '23']],
			'frame rate' => ['video_frame_rate', '25', ['-r', '25']],
			'sample rate' => ['audio_sample_rate', '48000', ['-ar', '48000']],
			'audio channels' => ['audio_channels', '2', ['-ac', '2']],
			'threads' => ['threads', '0', ['-threads', '0']],
			'scaling' => ['scaling', '1280:-1', ['-filter_complex', 'scale=1280:-1']],
			'scaling, an expression' => ['scaling', "'min(1280,iw)':-2", ['-filter_complex', "scale='min(1280,iw)':-2"]],
			'scaling, an escaped comma' => ['scaling', 'min(1280\\,iw):-2', ['-filter_complex', 'scale=min(1280\\,iw):-2']],
			'aspect' => ['aspect', '16:9', ['-aspect', '16:9']],
			'delogo' => ['delogo', 'x=0:y=0:w=100:h=77:band=10', ['-filter_complex', 'delogo=x=0:y=0:w=100:h=77:band=10']],
			'logo' => ['logo', '/home/xc_vm/logos/my logo.png', ['-i', '/home/xc_vm/logos/my logo.png', '-filter_complex', 'overlay']],
		];
	}

	#[DataProvider('ordinaryValues')]
	public function testAnOrdinaryValueReachesTheProgramAsItAlwaysDid(string $rKey, string $rValue, array $rExpected): void {
		$rRow = self::row($rKey, $rValue);
		$rReceived = $this->argv(self::line([$rRow]));
		$this->assertSame($rExpected, $rReceived);
		$rPlain = self::plainLine($rRow, self::handedOn($rKey, $rValue));
		$this->assertSame($this->argv($rPlain), $rReceived, 'the same arguments as the template filled in directly');
		// The two templates that leave their value unquoted gain quotes, and a
		// backslash is doubled inside "..."; every other line is the same text.
		if (!in_array($rKey, ['force_input_acodec', 'aspect'], true) && !str_contains($rValue, '\\')) {
			$this->assertSame($rPlain, self::line([$rRow]));
		}
	}

	public function testEverySeededTextOptionIsCovered(): void {
		$rText = array_keys(array_filter(self::$rSeed, static fn(array $rRow): bool => $rRow['argument_type'] == 'text'));
		$this->assertEqualsCanonicalizing($rText, array_values(array_unique(array_column(self::ordinaryValues(), 0))), 'a new streams_arguments row: add an ordinary value for it here');
		$rFree = array_keys(array_filter(self::$rSeed, static fn(array $rRow): bool => $rRow['argument_type'] == 'text' && str_contains($rRow['argument_cmd'], '%s')));
		$this->assertEqualsCanonicalizing($rFree, array_values(array_unique(array_column(self::anyValues(), 0))), 'a new free-text streams_arguments row: add a value for it here');
	}

	/** The header lines StreamProcess adds keep their line breaks, and its own template its trailing one. */
	public function testAddedHeaderLinesReachTheProgramWithTheirLineBreaks(): void {
		$rAppend = new ReflectionMethod(StreamProcess::class, 'appendHeaderArgument');
		$rOwn = $rAppend->invoke(null, [self::row('headers', 'Referer: https://example.com/')], 'X-XC_VM-Prebuffer:1');
		$this->assertSame(['-headers', "Referer: https://example.com/\r\nX-XC_VM-Prebuffer:1"], $this->argv(self::line($rOwn)));
		$rAdded = $rAppend->invoke(null, [self::row('user_agent', 'VLC/3.0.20 LibVLC/3.0.20')], 'X-XC_VM-Detect:1');
		$this->assertSame(['-user_agent', 'VLC/3.0.20 LibVLC/3.0.20', '-headers', "X-XC_VM-Detect:1\r\n"], $this->argv(self::line($rAdded)));
	}

	public static function anyValues(): array {
		return [
			'user agent' => ['user_agent', 'Mozilla "5.0" (it\'s) $HOME $(echo ran) `echo ran`; echo ran \\ 100%s'],
			'proxy' => ['proxy', 'http://user:p"a$(echo ran)\'s`echo ran`@proxy.example:3128'],
			'cookie' => ['cookie', 'name=it\'s "a" $(echo ran) `echo ran`'],
			'headers' => ['headers', "X-Name: O'Brien \"q\" \$(echo ran)\r\nX-Two: `echo ran`"],
			'forced audio codec' => ['force_input_acodec', 'aac -vn; echo ran $(echo ran)'],
			'aspect' => ['aspect', '16:9 -vn; echo ran $(echo ran)'],
			'aspect naming a filter' => ['aspect', '16:9 -filter_complex "$(echo ran)"'],
			'scaling' => ['scaling', '1280:-1" ; echo ran ; "'],
			'scaling, with dollar signs' => ['scaling', '$(echo ran):`echo ran`\\'],
			'delogo' => ['delogo', 'x=0:y=0" ; echo ran ; "$HOME'],
			'logo' => ['logo', '/home/xc_vm/logos/my "logo" $(echo ran) `echo ran`.png'],
		];
	}

	#[DataProvider('anyValues')]
	public function testAValueIsOneArgumentWhateverItHolds(string $rKey, string $rValue): void {
		$rRow = self::row($rKey, $rValue);
		// What the template gives for a plain word, with the word exchanged for the value.
		$rExpected = str_replace('VALUE', self::handedOn($rKey, $rValue), $this->argv(self::plainLine($rRow, 'VALUE')));
		$this->assertSame($rExpected, $this->argv(self::line([$rRow])));
	}

	/** Every byte a value can hold, in each place a template puts a value: quoted "...", quoted '...', unquoted, and a filter. */
	public function testEveryByteOfAValueIsKept(): void {
		$rBytes = implode('', array_map('chr', range(1, 255)));
		foreach (['user_agent', 'headers', 'force_input_acodec', 'scaling'] as $rKey) {
			$rRow = self::row($rKey, $rBytes);
			$this->assertSame(str_replace('VALUE', $rBytes, $this->argv(self::plainLine($rRow, 'VALUE'))), $this->argv(self::line([$rRow])), $rKey);
		}
	}

	public function testTwoFilterValuesStayOneFilterArgument(): void {
		$rRows = [self::row('scaling', '1280:-1" ; echo ran ; "'), self::row('delogo', 'x=0:y=0:w=`echo ran`')];
		$this->assertSame(['-filter_complex', 'scale=1280:-1" ; echo ran ; ",delogo=x=0:y=0:w=`echo ran`'], $this->argv(self::line($rRows)));
	}

	/** A numeric option's template takes the number alone. */
	public function testANumericOptionTakesOnlyItsNumber(): void {
		$this->assertSame(['-b:v', '2500k'], $this->argv(self::line([self::row('bitrate', '2500; echo ran')])));
		$this->assertSame(['-crf', '0'], $this->argv(self::line([self::row('crf', '$(echo ran)')])));
	}

	/** The transcode options a profile stores come out of parseTranscode() as they always did. */
	public function testProfileOptionsAreAssembledAsBefore(): void {
		$this->assertSame(
			['-preset veryfast', '-acodec aac', '-vcodec libx264', '-vf yadif', '-vf scale=1280:720', '-b:v 2500k'],
			StreamUtils::parseTranscode([3 => ['cmd' => '-b:v 2500k', 'val' => 2500], 9 => ['cmd' => '-vf scale=1280:720', 'val' => '1280:720'], 17 => ['cmd' => '-vf yadif', 'val' => 1], '-vcodec' => 'libx264', '-acodec' => 'aac', '-preset' => 'veryfast', 'gpu' => ['cmd' => '-hwaccel cuvid', 'device' => 0], 'software_decoding' => 0])
		);
		$this->assertSame(
			['-i "/home/xc_vm/logo one.png"', '-filter_complex "scale=trunc\\(iw/2\\)\\*2:-2,yadif,overlay"', '-sn', '-acodec copy', '-vcodec libx264', '-b:v 2500k'],
			StreamUtils::parseTranscode(['-filter_complex "scale=trunc\\(iw/2\\)\\*2:-2"', '-b:v 2500k', '-filter_complex "yadif"', '-i "/home/xc_vm/logo one.png" -filter_complex "overlay"', '-vcodec' => 'libx264', '-acodec' => 'copy', '-sn' => ''])
		);
		$this->assertSame(
			['-filter_complex "scale=\'min(1280,iw)\':-2"', '-aspect 16:9'],
			StreamUtils::parseTranscode(["-filter_complex \"scale='min(1280,iw)':-2\"", '-aspect 16:9'])
		);
	}

	public static function sources(): array {
		return [
			'a URL' => ['http://src.example:8080/live/user/pass/1.m3u8?token=abc&x=1'],
			'a file with spaces' => ['/home/xc_vm/content/vod/My Movie (2020) [1080p].mkv'],
			'an rtmp source with its options' => ['rtmp://src.example/app/stream live=1 timeout=10'],
			'a URL with quotes and dollar signs' => ['http://src.example/a.m3u8?x=$(echo ran)&y="q"&z=`echo ran`\\'],
			'a file with a quote' => ["/home/xc_vm/content/vod/It's a \"Movie\"; echo ran.mkv"],
		];
	}

	#[DataProvider('sources')]
	public function testTheProbedSourceIsOneArgument(string $rSource): void {
		$rBefore = [$GLOBALS['rSettings'] ?? null, $GLOBALS['rFFPROBE'] ?? null];
		$GLOBALS['rSettings'] = ['stream_max_analyze' => 2000000, 'probesize' => 5000000, 'probe_extra_wait' => 3];
		$GLOBALS['rFFPROBE'] = $this->rProgram;
		try {
			$this->assertSame([], FFprobeRunner::probeStream($rSource, ['-user_agent "VLC/3.0.20 LibVLC/3.0.20"'], '', false), 'ffprobe answered');
		} finally {
			[$GLOBALS['rSettings'], $GLOBALS['rFFPROBE']] = $rBefore;
		}
		$this->assertSame(['-probesize', '5000000', '-analyzeduration', '2000000', '-user_agent', 'VLC/3.0.20 LibVLC/3.0.20', '-i', $rSource, '-v', 'quiet', '-print_format', 'json', '-show_streams', '-show_format'], $this->received());
	}
}
