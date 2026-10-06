<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Util\StreamUtils;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Tests\Support\InstallSchema;

/**
 * StreamProcess::buildLive() fills a command template: fixed text with a
 * {TOKEN} wherever a value goes. Every token is exchanged for its value in
 * one pass, and a value is data: a user agent, a cookie, a source, a
 * transcode option or a push URL that happens to name a token reaches the
 * program as it was typed.
 *
 * The lines for ordinary values are kept here as they have always been
 * assembled (EXPECTED), and each token case is run by /bin/sh with a program
 * in ffmpeg's place that records what it received. The option templates are
 * the install schema's own rows (`streams_arguments`).
 */
final class AuditStreamCmdTemplateLiveTest extends TestCase {
	private const AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

	private const GPU_OPTIONS = '-hwaccel cuvid -hwaccel_device 0 -resize 1280x720 -deint 2 {INPUT_CODEC} -gpu 0 -drop_second_field 1';

	/** @var array<string, array<string, mixed>> The seeded argument rows, by argument_key. */
	private static array $rSeed = [];

	private string $rDir;
	private string $rProgram;

	public static function setUpBeforeClass(): void {
		foreach (['SERVER_ID' => 1, 'STREAMS_PATH' => '/tmp/xcvm-test-streams/', 'DELAY_PATH' => '/tmp/xcvm-test-delay/'] as $rName => $rValue) {
			if (!defined($rName)) {
				define($rName, $rValue);
			}
		}
		$rDb = new TestDb();
		$rDb->exec(InstallSchema::table('streams_arguments'));
		preg_match('/^INSERT INTO `streams_arguments` .*?;$/ms', (string) file_get_contents(MAIN_HOME . 'bin/install/database.sql'), $rInsert);
		$rDb->exec($rInsert[0]);
		$rDb->query('SELECT * FROM `streams_arguments` ORDER BY `id` ASC;');
		self::$rSeed = array_column($rDb->get_rows(), null, 'argument_key');
	}

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-template-live-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir, 0700, true);
		// Records its arguments, one per NUL.
		$this->rProgram = $this->rDir . 'program';
		file_put_contents($this->rProgram, "#!/bin/sh\nfor a in \"\$@\"; do printf '%s\\000' \"\$a\"; done > " . escapeshellarg($this->rDir . 'argv') . "\n");
		chmod($this->rProgram, 0755);
	}

	protected function tearDown(): void {
		array_map('unlink', glob($this->rDir . '*') ?: []);
		rmdir($this->rDir);
	}

	/**
	 * buildLive()'s input for a plain live restream, as startStream() hands it
	 * over. `arguments` (key => value) are the stream's own options: its rows
	 * and the fetch options they give. `push` are its external push URLs here.
	 */
	private static function data(array $rOverrides): array {
		$rData = [
			'stream' => [
				'stream_info' => [
					'custom_ffmpeg' => '', 'stream_all' => 0, 'custom_map' => '', 'type_key' => 'live', 'gen_timestamps' => 0, 'read_native' => 0,
					'enable_transcode' => 0, 'transcode_profile_id' => 0, 'transcode_attributes' => '[]', 'profile_options' => '[]',
					'delay_minutes' => 0, 'rtmp_output' => 0, 'external_push' => '[]',
				],
				'server_info' => ['parent_id' => 0, 'server_id' => 1],
				'stream_arguments' => [],
			],
			'settings' => ['ffmpeg_warnings' => 0, 'read_native_hls' => 0, 'dts_legacy_ffmpeg' => 0, 'ignore_keyframes' => 0],
			'servers' => [1 => ['rtmp_port' => 1935]],
			'streamID' => 42,
			'streamSource' => 'http://src.example:8080/live/user/pass/1.ts?token=abc&x=1',
			'fetchOptions' => '',
			'ffprobe' => ['container' => 'mpegts', 'codecs' => ['video' => ['codec_name' => 'h264'], 'audio' => ['codec_name' => 'aac']]],
			'protocol' => 'http',
			'source' => 'http://src.example:8080/live/user/pass/1.ts?token=abc&x=1',
			'segmentSettings' => ['seg_time' => 6, 'seg_list_size' => 8, 'seg_delete_threshold' => 4],
			'externalPush' => [],
			'probesize' => 1000000,
			'analyseDuration' => 500000,
			'llod' => false,
			'loopback' => false,
			'segmentStart' => 0,
			'delayActive' => false,
			'ffmpegCpu' => '/bin/ffmpeg',
			'ffmpegGpu' => '/bin/ffmpeg-gpu',
		];
		foreach (['stream_info', 'server_info'] as $rPart) {
			$rData['stream'][$rPart] = array_merge($rData['stream'][$rPart], $rOverrides[$rPart] ?? []);
		}
		$rData['settings'] = array_merge($rData['settings'], $rOverrides['settings'] ?? []);
		if (isset($rOverrides['arguments'])) {
			foreach ($rOverrides['arguments'] as $rKey => $rValue) {
				$rData['stream']['stream_arguments'][] = ['value' => $rValue] + self::$rSeed[$rKey];
			}
			$rData['fetchOptions'] = implode(' ', StreamUtils::getArguments($rData['stream']['stream_arguments'], 'http', 'fetch'));
		}
		if (isset($rOverrides['push'])) {
			$rData['stream']['stream_info']['external_push'] = json_encode([SERVER_ID => $rOverrides['push']]);
		}
		unset($rOverrides['stream_info'], $rOverrides['server_info'], $rOverrides['settings'], $rOverrides['arguments'], $rOverrides['push']);
		return array_merge($rData, $rOverrides);
	}

	private static function build(array $rOverrides): string {
		return (new ReflectionMethod(StreamProcess::class, 'buildLive'))->invoke(null, self::data($rOverrides));
	}

	/** A profile's options as the profile page stores them. */
	private static function profile(array $rOptions): array {
		return ['enable_transcode' => 1, 'transcode_profile_id' => 1, 'profile_options' => json_encode($rOptions)];
	}

	// ── ordinary values: the line is what it has always been ───────────────

	public static function ordinaryStreams(): array {
		$rEncode = ['-vcodec' => 'libx264', '-preset' => 'veryfast', '-profile:v' => 'main', '-acodec' => 'aac'];
		$rRates = [3 => ['cmd' => '-b:v 2500k', 'val' => 2500], 4 => ['cmd' => '-b:a 128k', 'val' => 128]];
		$rGpu = ['software_decoding' => 0, 'gpu' => ['val' => '1_0', 'cmd' => self::GPU_OPTIONS, 'device' => 0, 'resize' => '1280x720', 'deint' => 2], '-vcodec' => 'h264_nvenc', '-preset' => 'fast', '-profile:v' => 'high', '-acodec' => 'aac'];
		$rLogo = [16 => ['cmd' => '', 'val' => '/home/xc_vm/logos/my logo.png', 'pos' => '20:30'], 9 => ['cmd' => '', 'val' => '1280:720'], 17 => ['cmd' => '', 'val' => 1]];
		return [
			'a plain restream' => [[]],
			'the fetch options' => [['arguments' => ['user_agent' => self::AGENT, 'proxy' => '1.2.3.4:8080', 'cookie' => 'session=abc123; token=x.y-z', 'headers' => "Referer: https://example.com/\r\nOrigin: https://example.com"]]],
			'a loopback' => [['loopback' => true, 'server_info' => ['parent_id' => 2], 'streamSource' => 'http://10.0.0.2:8080/admin/live?stream=42', 'source' => 'http://10.0.0.2:8080/admin/live?stream=42']],
			'a loopback fed to the daemon' => [['loopback' => true, 'server_info' => ['parent_id' => 2], 'ingestSock' => '/run/ingest/42.sock', 'supervised' => true]],
			'a delayed stream' => [['delayActive' => true, 'segmentStart' => 3, 'stream_info' => ['delay_minutes' => 5]]],
			'an rtmp output and two pushes' => [['stream_info' => ['rtmp_output' => 1], 'push' => ['rtmp://a.rtmp.example.com/live2/abcd-1234', 'rtmp://push.example/app/key?x=1&y=2']]],
			'a custom command' => [['stream_info' => ['custom_ffmpeg' => '-user_agent "Mozilla/5.0" -reconnect 1 -i {STREAM_SOURCE} -c:v libx264 -preset veryfast -c:a aac'], 'arguments' => ['user_agent' => 'VLC/3.0.20']]],
			'a custom command naming every token' => [['stream_info' => ['custom_ffmpeg' => '{GPU} {FETCH_OPTIONS} {GEN_PTS} {READ_NATIVE} {CONCAT} {INPUT_CODEC} -i {STREAM_SOURCE} {LOGO} -c copy {MAP} {AAC_FILTER} {LLOD}', 'rtmp_output' => 1], 'llod' => true]],
			'a CPU profile' => [['stream_info' => self::profile($rEncode + $rRates + [9 => ['cmd' => '-vf scale=1280:720', 'val' => '1280:720'], 10 => ['cmd' => '-aspect 16:9', 'val' => '16:9'], 11 => ['cmd' => '-r 25', 'val' => 25], 15 => ['cmd' => '-threads 0', 'val' => 0], 17 => ['cmd' => '-vf yadif', 'val' => 1]])]],
			'a GPU profile' => [['stream_info' => self::profile($rGpu + $rRates)]],
			'a GPU profile, a source no hardware decoder takes' => [['stream_info' => self::profile($rGpu + $rRates), 'ffprobe' => ['container' => 'mpegts', 'codecs' => ['video' => ['codec_name' => 'av1'], 'audio' => ['codec_name' => 'aac']]]]],
			'a GPU profile decoding in software' => [['stream_info' => self::profile(['software_decoding' => 1, 'gpu' => ['val' => '1_0', 'cmd' => '', 'device' => 0], '-vcodec' => 'h264_nvenc', '-acodec' => 'aac'] + $rRates)]],
			'a profile with a logo' => [['stream_info' => self::profile($rEncode + $rRates + $rLogo)]],
			'a GPU profile with a logo' => [['stream_info' => self::profile($rGpu + $rLogo)]],
			'the options of the stream itself' => [['stream_info' => ['enable_transcode' => 1, 'transcode_profile_id' => -1, 'transcode_attributes' => json_encode($rEncode)], 'arguments' => ['user_agent' => self::AGENT, 'bitrate' => '2500', 'scaling' => '1280:-1', 'aspect' => '16:9', 'delogo' => 'x=0:y=0:w=100:h=77:band=10', 'logo' => '/home/xc_vm/logos/my logo.png']]],
			'low latency on demand' => [['llod' => true, 'stream_info' => self::profile($rEncode)]],
			'fed to the daemon' => [['ingestSock' => '/run/ingest/42.sock', 'supervised' => true]],
			'fed to the daemon, with an rtmp output' => [['ingestSock' => '/run/ingest/42.sock', 'supervised' => true, 'stream_info' => ['rtmp_output' => 1]]],
			'every stream of the source' => [['stream_info' => ['stream_all' => 1], 'ingestSock' => '/run/ingest/42.sock']],
			'a custom map' => [['stream_info' => ['custom_map' => '-map 0:v:0 -map 0:a:1'], 'ingestSock' => '/run/ingest/42.sock']],
			'a radio stream' => [['stream_info' => ['type_key' => 'radio_streams']]],
			'a created channel' => [['stream_info' => ['type_key' => 'created_live'], 'protocol' => '', 'streamSource' => '/home/xc_vm/content/created/42_.list', 'source' => '/home/xc_vm/content/created/42_.list']],
			'generated timestamps, read at the native rate' => [['stream_info' => ['gen_timestamps' => 1, 'read_native' => 1]]],
			'warnings, key frames ignored, an HLS source' => [['settings' => ['ffmpeg_warnings' => 1, 'ignore_keyframes' => 1, 'read_native_hls' => 1], 'ffprobe' => ['container' => 'hls,applehttp', 'codecs' => ['video' => ['codec_name' => 'hevc'], 'audio' => ['codec_name' => 'ac3']]]]],
		];
	}

	/** The line with this run's own directories named, so it reads the same wherever they are. */
	public static function ordinaryLine(array $rOverrides): string {
		return strtr(self::build($rOverrides), [STREAMS_PATH => '<streams>/', DELAY_PATH => '<delay>/']);
	}

	#[DataProvider('ordinaryStreams')]
	public function testAnOrdinaryStreamsLineIsUnchanged(array $rOverrides): void {
		$this->assertArrayHasKey($this->dataName(), self::EXPECTED);
		$this->assertSame(self::EXPECTED[$this->dataName()], self::ordinaryLine($rOverrides));
	}

	public function testEveryExpectedLineHasItsStream(): void {
		$this->assertSame(array_keys(self::ordinaryStreams()), array_keys(self::EXPECTED));
	}

	/** A profile's GPU options name the place of the input decoder themselves. */
	public function testAGpuProfileTakesItsInputDecoder(): void {
		$rLine = self::build(['stream_info' => self::profile(['gpu' => ['val' => '1_0', 'cmd' => self::GPU_OPTIONS, 'device' => 0], '-vcodec' => 'h264_nvenc'])]);
		$this->assertStringContainsString(' -hwaccel cuvid -hwaccel_device 0 -resize 1280x720 -deint 2 -c:v h264_cuvid -gpu 0 -drop_second_field 1 ', $rLine);
		$this->assertStringNotContainsString('{', $rLine);
	}

	// ── a value that names a token is still a value ────────────────────────

	/**
	 * What the program receives when a shell runs the line: built for the
	 * fanout supervisor, it has no redirect or background tail.
	 *
	 * @return list<string>
	 */
	private function received(array $rOverrides): array {
		$rLine = self::build($rOverrides + ['ffmpegCpu' => $this->rProgram, 'ffmpegGpu' => $this->rProgram, 'supervised' => true]);
		$rProcess = proc_open(['/bin/sh', '-c', $rLine], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes, $this->rDir);
		proc_close($rProcess);
		$rFile = $this->rDir . 'argv';
		$rArgv = file_exists($rFile) ? explode("\0", (string) file_get_contents($rFile), -1) : [];
		@unlink($rFile);
		return $rArgv;
	}

	/** A source a shell would act on, were it ever read outside its own quotes. */
	private function source(): string {
		return 'http://src.example/live.ts?a=$(touch ' . $this->rDir . 'ran-1)&b=`touch ' . $this->rDir . 'ran-2`&c=;touch ' . $this->rDir . 'ran-3;';
	}

	/** The stream with one value in one of the places a value takes. */
	private function streamWith(string $rPlace, string $rValue): array {
		$rSource = ['streamSource' => $this->source(), 'source' => $this->source(), 'llod' => true];
		$rOwn = ['enable_transcode' => 1, 'transcode_profile_id' => -1, 'transcode_attributes' => json_encode(['-vcodec' => 'libx264'])];
		return match ($rPlace) {
			'user_agent', 'headers' => ['arguments' => [$rPlace => $rValue]] + $rSource,
			'cookie' => ['arguments' => ['cookie' => 'a=' . $rValue]] + $rSource,
			'proxy' => ['arguments' => ['proxy' => 'http://user:' . $rValue . '@proxy.example:3128']] + $rSource,
			'source' => ['streamSource' => 'http://src.example/' . $rValue . '/live.ts', 'source' => 'http://src.example/' . $rValue . '/live.ts', 'llod' => true, 'stream_info' => self::profile(['-vcodec' => 'libx264', 16 => ['cmd' => '', 'val' => '/home/xc_vm/logos/logo.png', 'pos' => '10:10']])],
			'scaling', 'logo', 'delogo', 'aspect' => ['arguments' => [$rPlace => $rValue], 'stream_info' => $rOwn] + $rSource,
			'profile logo' => ['stream_info' => self::profile(['-vcodec' => 'libx264', 16 => ['cmd' => '', 'val' => $rValue, 'pos' => '10:10']])] + $rSource,
			'push' => ['push' => [$rValue]] + $rSource,
		};
	}

	public static function valuesNamingAToken(): array {
		return [
			'a user agent naming the source' => ['user_agent', '{STREAM_SOURCE}'],
			'a user agent with a word in braces' => ['user_agent', 'Player {MAP} 1.0'],
			'a user agent naming every token' => ['user_agent', '{FETCH_OPTIONS}{GEN_PTS}{STREAM_SOURCE}{MAP}{READ_NATIVE}{CONCAT}{AAC_FILTER}{GPU}{INPUT_CODEC}{LOGO}{LLOD}{TRANSCODE}{PUSH_0}'],
			'a cookie naming the source' => ['cookie', '{STREAM_SOURCE}'],
			'headers naming the source' => ['headers', "X-One: {STREAM_SOURCE}\r\nX-Two: {LLOD}"],
			'a proxy password naming the source' => ['proxy', '{STREAM_SOURCE}'],
			'a source naming tokens' => ['source', '{MAP}{AAC_FILTER}{LOGO}{LLOD}'],
			'a scaling naming the source' => ['scaling', '{STREAM_SOURCE}'],
			'a logo option naming the source' => ['logo', '/home/xc_vm/logos/{STREAM_SOURCE}.png'],
			'a delogo naming the fetch options' => ['delogo', '{FETCH_OPTIONS}{LLOD}'],
			'an aspect naming the source' => ['aspect', '{STREAM_SOURCE}'],
			'a profile logo naming a token' => ['profile logo', '/home/xc_vm/logos/{LLOD}.png'],
			'a push URL naming the source' => ['push', 'rtmp://push.example/live/{STREAM_SOURCE}'],
			'a push URL naming the fetch options' => ['push', 'rtmp://push.example/live/{FETCH_OPTIONS}{TRANSCODE}{LLOD}'],
		];
	}

	#[DataProvider('valuesNamingAToken')]
	public function testAValueNamingATokenReachesTheProgramAsTyped(string $rPlace, string $rValue): void {
		// What the line gives for a plain word in that place, with the word exchanged for the value.
		$rExpected = str_replace('VALUE', $rValue, $this->received($this->streamWith($rPlace, 'VALUE')));
		$this->assertContains('-i', $rExpected, 'the program ran');
		$this->assertSame($rExpected, $this->received($this->streamWith($rPlace, $rValue)));
		$this->assertSame([], glob($this->rDir . 'ran-*'), 'nothing but the program ran');
	}

	public function testAUserAgentAndACookieNamingTheSourceAreNotTheSource(): void {
		$rArgv = $this->received(['arguments' => ['user_agent' => '{STREAM_SOURCE}', 'cookie' => 'a={STREAM_SOURCE}'], 'streamSource' => $this->source(), 'source' => $this->source()]);
		$rOptions = [];
		foreach ($rArgv as $i => $rArgument) {
			if (in_array($rArgument, ['-user_agent', '-cookies', '-i'], true)) {
				$rOptions[$rArgument] = $rArgv[$i + 1];
			}
		}
		$this->assertSame(['-user_agent' => '{STREAM_SOURCE}', '-cookies' => StreamUtils::fixCookie('a={STREAM_SOURCE}'), '-i' => $this->source()], $rOptions);
		$this->assertSame([], glob($this->rDir . 'ran-*'), 'nothing but the program ran');
	}

	private const EXPECTED = [
		'a plain restream' => '/bin/ffmpeg -y -nostdin -hide_banner -loglevel error -err_detect ignore_err -thread_queue_size 1024    -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0  -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5 -probesize 1000000 -analyzeduration 500000 -progress "<streams>/42_.progress"  -i \'http://src.example:8080/live/user/pass/1.ts?token=abc&x=1\'  -max_muxing_queue_size 1024 -vcodec copy -sn -acodec copy   -individual_header_trailer 0 -f hls -hls_init_time 2 -hls_time 6 -hls_list_size 8 -hls_delete_threshold 4 -hls_flags delete_segments+discont_start+omit_endlist -hls_segment_type mpegts -hls_segment_filename "<streams>/42_%d.ts" "<streams>/42_.m3u8"  >/dev/null 2>><streams>/42.errors & echo $! > <streams>/42_.pid',
		'the fetch options' => "/bin/ffmpeg -y -nostdin -hide_banner -loglevel error -err_detect ignore_err -thread_queue_size 1024  -user_agent \"Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\" -http_proxy \"http://1.2.3.4:8080\" -cookies 'session=abc123; token=x.y-zpath=/;domain=;' -headers 'Referer: https://example.com/\r\nOrigin: https://example.com'  -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0  -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5 -probesize 1000000 -analyzeduration 500000 -progress \"<streams>/42_.progress\"  -i 'http://src.example:8080/live/user/pass/1.ts?token=abc&x=1'  -max_muxing_queue_size 1024 -vcodec copy -sn -acodec copy   -individual_header_trailer 0 -f hls -hls_init_time 2 -hls_time 6 -hls_list_size 8 -hls_delete_threshold 4 -hls_flags delete_segments+discont_start+omit_endlist -hls_segment_type mpegts -hls_segment_filename \"<streams>/42_%d.ts\" \"<streams>/42_.m3u8\"  >/dev/null 2>><streams>/42.errors & echo \$! > <streams>/42_.pid",
		'a loopback' => '/bin/ffmpeg -y -nostdin -hide_banner -loglevel error -err_detect ignore_err -thread_queue_size 1024   -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0  -probesize 1000000 -analyzeduration 500000 -progress "<streams>/42_.progress"  -i \'http://10.0.0.2:8080/admin/live?stream=42\'  -max_muxing_queue_size 1024 -vcodec copy -sn -acodec copy -map 0 -copy_unknown  -individual_header_trailer 0 -f hls -hls_init_time 2 -hls_time 6 -hls_list_size 8 -hls_delete_threshold 4 -hls_flags delete_segments+discont_start+omit_endlist -hls_segment_type mpegts -hls_segment_filename "<streams>/42_%d.ts" "<streams>/42_.m3u8"  >/dev/null 2>><streams>/42.errors & echo $! > <streams>/42_.pid',
		'a loopback fed to the daemon' => '/bin/ffmpeg -y -nostdin -hide_banner -loglevel error -err_detect ignore_err -thread_queue_size 1024   -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0  -probesize 1000000 -analyzeduration 500000 -progress "<streams>/42_.progress"  -i \'http://src.example:8080/live/user/pass/1.ts?token=abc&x=1\'  -max_muxing_queue_size 1024 -vcodec copy -sn -acodec copy -map 0 -copy_unknown  -f tee "[f=hls:hls_init_time=2:hls_time=6:hls_list_size=8:hls_delete_threshold=4:hls_flags=delete_segments+discont_start+omit_endlist:hls_segment_type=mpegts:hls_segment_filename=<streams>/42_%d.ts]<streams>/42_.m3u8|[f=mpegts:onfail=ignore:mpegts_flags=+initial_discontinuity]unix:/run/ingest/42.sock"',
		'a delayed stream' => '/bin/ffmpeg -y -nostdin -hide_banner -loglevel error -err_detect ignore_err -thread_queue_size 1024    -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0  -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5 -probesize 1000000 -analyzeduration 500000 -progress "<streams>/42_.progress"  -i \'http://src.example:8080/live/user/pass/1.ts?token=abc&x=1\'  -max_muxing_queue_size 1024 -vcodec copy -sn -acodec copy  -individual_header_trailer 0 -f hls -hls_time 6 -hls_list_size 30 -hls_delete_threshold 4 -start_number 3 -hls_flags delete_segments+discont_start+omit_endlist -hls_segment_type mpegts -hls_segment_filename "<delay>/42_%d.ts" "<delay>/42_.m3u8"  >/dev/null 2>><streams>/42.errors & echo $! > <streams>/42_.pid',
		'an rtmp output and two pushes' => '/bin/ffmpeg -y -nostdin -hide_banner -loglevel error -err_detect ignore_err -thread_queue_size 1024    -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0  -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5 -probesize 1000000 -analyzeduration 500000 -progress "<streams>/42_.progress"  -i \'http://src.example:8080/live/user/pass/1.ts?token=abc&x=1\'  -max_muxing_queue_size 1024 -vcodec copy -sn -acodec copy   -individual_header_trailer 0 -f hls -hls_init_time 2 -hls_time 6 -hls_list_size 8 -hls_delete_threshold 4 -hls_flags delete_segments+discont_start+omit_endlist -hls_segment_type mpegts -hls_segment_filename "<streams>/42_%d.ts" "<streams>/42_.m3u8" -vcodec copy -sn -acodec copy  -bsf:a aac_adtstoasc -f flv -flvflags no_duration_filesize rtmp://127.0.0.1:1935/live/42 -vcodec copy -sn -acodec copy  -bsf:a aac_adtstoasc -f flv -flvflags no_duration_filesize \'rtmp://a.rtmp.example.com/live2/abcd-1234\' -vcodec copy -sn -acodec copy  -bsf:a aac_adtstoasc -f flv -flvflags no_duration_filesize \'rtmp://push.example/app/key?x=1&y=2\'  >/dev/null 2>><streams>/42.errors & echo $! > <streams>/42_.pid',
		'a custom command' => '/bin/ffmpeg -y -nostdin -hide_banner -loglevel error -progress "<streams>/42_.progress" -user_agent "Mozilla/5.0" -reconnect 1 -i \'http://src.example:8080/live/user/pass/1.ts?token=abc&x=1\' -c:v libx264 -preset veryfast -c:a aac   -individual_header_trailer 0 -f hls -hls_init_time 2 -hls_time 6 -hls_list_size 8 -hls_delete_threshold 4 -hls_flags delete_segments+discont_start+omit_endlist -hls_segment_type mpegts -hls_segment_filename "<streams>/42_%d.ts" "<streams>/42_.m3u8"  >/dev/null 2>><streams>/42.errors & echo $! > <streams>/42_.pid',
		'a custom command naming every token' => '/bin/ffmpeg -y -nostdin -hide_banner -loglevel error -progress "<streams>/42_.progress"       -i \'http://src.example:8080/live/user/pass/1.ts?token=abc&x=1\'  -c copy   -strict experimental  -strict experimental -individual_header_trailer 0 -f hls -hls_init_time 2 -hls_time 6 -hls_list_size 8 -hls_delete_threshold 4 -hls_flags delete_segments+discont_start+omit_endlist -hls_segment_type mpegts -hls_segment_filename "<streams>/42_%d.ts" "<streams>/42_.m3u8"    -f flv -flvflags no_duration_filesize rtmp://127.0.0.1:1935/live/42  >/dev/null 2>><streams>/42.errors & echo $! > <streams>/42_.pid',
		'a CPU profile' => '/bin/ffmpeg -y -nostdin -hide_banner -loglevel error -err_detect ignore_err -thread_queue_size 1024    -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0  -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5 -probesize 1000000 -analyzeduration 500000 -progress "<streams>/42_.progress"  -i \'http://src.example:8080/live/user/pass/1.ts?token=abc&x=1\'  -max_muxing_queue_size 1024 -sn -threads 0 -vf yadif -aspect 16:9 -r 25 -b:a 128k -vf scale=1280:720 -b:v 2500k -acodec aac -profile:v main -preset veryfast -vcodec libx264   -individual_header_trailer 0 -f hls -hls_init_time 2 -hls_time 6 -hls_list_size 8 -hls_delete_threshold 4 -hls_flags delete_segments+discont_start+omit_endlist -hls_segment_type mpegts -hls_segment_filename "<streams>/42_%d.ts" "<streams>/42_.m3u8"  >/dev/null 2>><streams>/42.errors & echo $! > <streams>/42_.pid',
		'a GPU profile' => '/bin/ffmpeg-gpu -y -nostdin -hide_banner -loglevel error -err_detect ignore_err -thread_queue_size 1024 -hwaccel cuvid -hwaccel_device 0 -resize 1280x720 -deint 2 -c:v h264_cuvid -gpu 0 -drop_second_field 1   -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0  -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5 -probesize 1000000 -analyzeduration 500000 -progress "<streams>/42_.progress"  -i \'http://src.example:8080/live/user/pass/1.ts?token=abc&x=1\'  -max_muxing_queue_size 1024 -gpu 0 -b:a 128k -sn -b:v 2500k -acodec aac -profile:v high -preset fast -vcodec h264_nvenc   -individual_header_trailer 0 -f hls -hls_init_time 2 -hls_time 6 -hls_list_size 8 -hls_delete_threshold 4 -hls_flags delete_segments+discont_start+omit_endlist -hls_segment_type mpegts -hls_segment_filename "<streams>/42_%d.ts" "<streams>/42_.m3u8"  >/dev/null 2>><streams>/42.errors & echo $! > <streams>/42_.pid',
		'a GPU profile, a source no hardware decoder takes' => '/bin/ffmpeg-gpu -y -nostdin -hide_banner -loglevel error -err_detect ignore_err -thread_queue_size 1024 -hwaccel cuvid -hwaccel_device 0 -resize 1280x720 -deint 2  -gpu 0 -drop_second_field 1   -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0  -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5 -probesize 1000000 -analyzeduration 500000 -progress "<streams>/42_.progress"  -i \'http://src.example:8080/live/user/pass/1.ts?token=abc&x=1\'  -max_muxing_queue_size 1024 -gpu 0 -b:a 128k -sn -b:v 2500k -acodec aac -profile:v high -preset fast -vcodec h264_nvenc   -individual_header_trailer 0 -f hls -hls_init_time 2 -hls_time 6 -hls_list_size 8 -hls_delete_threshold 4 -hls_flags delete_segments+discont_start+omit_endlist -hls_segment_type mpegts -hls_segment_filename "<streams>/42_%d.ts" "<streams>/42_.m3u8"  >/dev/null 2>><streams>/42.errors & echo $! > <streams>/42_.pid',
		'a GPU profile decoding in software' => '/bin/ffmpeg-gpu -y -nostdin -hide_banner -loglevel error -err_detect ignore_err -thread_queue_size 1024    -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0  -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5 -probesize 1000000 -analyzeduration 500000 -progress "<streams>/42_.progress"  -i \'http://src.example:8080/live/user/pass/1.ts?token=abc&x=1\'  -max_muxing_queue_size 1024 -gpu 0 -sn -b:a 128k -acodec aac -b:v 2500k -vcodec h264_nvenc   -individual_header_trailer 0 -f hls -hls_init_time 2 -hls_time 6 -hls_list_size 8 -hls_delete_threshold 4 -hls_flags delete_segments+discont_start+omit_endlist -hls_segment_type mpegts -hls_segment_filename "<streams>/42_%d.ts" "<streams>/42_.m3u8"  >/dev/null 2>><streams>/42.errors & echo $! > <streams>/42_.pid',
		'a profile with a logo' => '/bin/ffmpeg -y -nostdin -hide_banner -loglevel error -err_detect ignore_err -thread_queue_size 1024    -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0  -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5 -probesize 1000000 -analyzeduration 500000 -progress "<streams>/42_.progress"  -i \'http://src.example:8080/live/user/pass/1.ts?token=abc&x=1\' -i \'/home/xc_vm/logos/my logo.png\' -filter_complex "[0:v]yadif,scale=1280:720[bg]; [1:v]scale=250:-1[logo]; [bg][logo]overlay=20:30" -max_muxing_queue_size 1024 -b:a 128k -sn -b:v 2500k -acodec aac -profile:v main -preset veryfast -vcodec libx264   -individual_header_trailer 0 -f hls -hls_init_time 2 -hls_time 6 -hls_list_size 8 -hls_delete_threshold 4 -hls_flags delete_segments+discont_start+omit_endlist -hls_segment_type mpegts -hls_segment_filename "<streams>/42_%d.ts" "<streams>/42_.m3u8"  >/dev/null 2>><streams>/42.errors & echo $! > <streams>/42_.pid',
		'a GPU profile with a logo' => '/bin/ffmpeg-gpu -y -nostdin -hide_banner -loglevel error -err_detect ignore_err -thread_queue_size 1024 -hwaccel cuvid -hwaccel_device 0 -resize 1280x720 -deint 2 -c:v h264_cuvid -gpu 0 -drop_second_field 1   -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0  -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5 -probesize 1000000 -analyzeduration 500000 -progress "<streams>/42_.progress"  -i \'http://src.example:8080/live/user/pass/1.ts?token=abc&x=1\' -i \'/home/xc_vm/logos/my logo.png\' -filter_complex "[0:v]yadif,scale=1280:720[bg]; [1:v]scale=250:-1[logo]; [bg][logo]overlay=20:30" -max_muxing_queue_size 1024 -gpu 0 -sn -acodec aac -preset fast -profile:v high -vcodec h264_nvenc   -individual_header_trailer 0 -f hls -hls_init_time 2 -hls_time 6 -hls_list_size 8 -hls_delete_threshold 4 -hls_flags delete_segments+discont_start+omit_endlist -hls_segment_type mpegts -hls_segment_filename "<streams>/42_%d.ts" "<streams>/42_.m3u8"  >/dev/null 2>><streams>/42.errors & echo $! > <streams>/42_.pid',
		'the options of the stream itself' => '/bin/ffmpeg -y -nostdin -hide_banner -loglevel error -err_detect ignore_err -thread_queue_size 1024  -user_agent "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"  -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0  -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5 -probesize 1000000 -analyzeduration 500000 -progress "<streams>/42_.progress"  -i \'http://src.example:8080/live/user/pass/1.ts?token=abc&x=1\'  -max_muxing_queue_size 1024 -i "/home/xc_vm/logos/my logo.png" -filter_complex "scale=1280:-1,delogo=x=0:y=0:w=100:h=77:band=10,overlay" -sn -acodec aac -profile:v main -preset veryfast -vcodec libx264 -aspect "16:9" -b:v 2500k   -individual_header_trailer 0 -f hls -hls_init_time 2 -hls_time 6 -hls_list_size 8 -hls_delete_threshold 4 -hls_flags delete_segments+discont_start+omit_endlist -hls_segment_type mpegts -hls_segment_filename "<streams>/42_%d.ts" "<streams>/42_.m3u8"  >/dev/null 2>><streams>/42.errors & echo $! > <streams>/42_.pid',
		'low latency on demand' => '/bin/ffmpeg -y -nostdin -hide_banner -loglevel error -err_detect ignore_err -thread_queue_size 1024    -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0  -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5 -fflags +discardcorrupt+nobuffer -probesize 1000000 -analyzeduration 500000 -progress "<streams>/42_.progress"  -i \'http://src.example:8080/live/user/pass/1.ts?token=abc&x=1\'  -max_muxing_queue_size 1024 -sn -acodec aac -preset veryfast -profile:v main -vcodec libx264  -tune zerolatency -strict experimental -individual_header_trailer 0 -f hls -hls_init_time 2 -hls_time 6 -hls_list_size 8 -hls_delete_threshold 4 -hls_flags delete_segments+discont_start+omit_endlist -hls_segment_type mpegts -hls_segment_filename "<streams>/42_%d.ts" "<streams>/42_.m3u8"  >/dev/null 2>><streams>/42.errors & echo $! > <streams>/42_.pid',
		'fed to the daemon' => '/bin/ffmpeg -y -nostdin -hide_banner -loglevel error -err_detect ignore_err -thread_queue_size 1024    -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0  -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5 -probesize 1000000 -analyzeduration 500000 -progress "<streams>/42_.progress"  -i \'http://src.example:8080/live/user/pass/1.ts?token=abc&x=1\'  -max_muxing_queue_size 1024 -vcodec copy -sn -acodec copy -map 0 -copy_unknown  -f tee "[f=hls:hls_init_time=2:hls_time=6:hls_list_size=8:hls_delete_threshold=4:hls_flags=delete_segments+discont_start+omit_endlist:hls_segment_type=mpegts:hls_segment_filename=<streams>/42_%d.ts]<streams>/42_.m3u8|[f=mpegts:onfail=ignore:mpegts_flags=+initial_discontinuity]unix:/run/ingest/42.sock"',
		'fed to the daemon, with an rtmp output' => '/bin/ffmpeg -y -nostdin -hide_banner -loglevel error -err_detect ignore_err -thread_queue_size 1024    -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0  -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5 -probesize 1000000 -analyzeduration 500000 -progress "<streams>/42_.progress"  -i \'http://src.example:8080/live/user/pass/1.ts?token=abc&x=1\'  -max_muxing_queue_size 1024 -vcodec copy -sn -acodec copy -map 0 -copy_unknown  -f tee "[f=hls:hls_init_time=2:hls_time=6:hls_list_size=8:hls_delete_threshold=4:hls_flags=delete_segments+discont_start+omit_endlist:hls_segment_type=mpegts:hls_segment_filename=<streams>/42_%d.ts]<streams>/42_.m3u8|[f=mpegts:onfail=ignore:mpegts_flags=+initial_discontinuity]unix:/run/ingest/42.sock" -vcodec copy -sn -acodec copy  -bsf:a aac_adtstoasc -f flv -flvflags no_duration_filesize rtmp://127.0.0.1:1935/live/42 ',
		'every stream of the source' => '/bin/ffmpeg -y -nostdin -hide_banner -loglevel error -err_detect ignore_err -thread_queue_size 1024    -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0  -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5 -probesize 1000000 -analyzeduration 500000 -progress "<streams>/42_.progress"  -i \'http://src.example:8080/live/user/pass/1.ts?token=abc&x=1\'  -max_muxing_queue_size 1024 -vcodec copy -sn -acodec copy -map 0 -copy_unknown  -f tee "[f=hls:hls_init_time=2:hls_time=6:hls_list_size=8:hls_delete_threshold=4:hls_flags=delete_segments+discont_start+omit_endlist:hls_segment_type=mpegts:hls_segment_filename=<streams>/42_%d.ts]<streams>/42_.m3u8|[f=mpegts:onfail=ignore:mpegts_flags=+initial_discontinuity]unix:/run/ingest/42.sock" >/dev/null 2>><streams>/42.errors & echo $! > <streams>/42_.pid',
		'a custom map' => '/bin/ffmpeg -y -nostdin -hide_banner -loglevel error -err_detect ignore_err -thread_queue_size 1024    -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0  -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5 -probesize 1000000 -analyzeduration 500000 -progress "<streams>/42_.progress"  -i \'http://src.example:8080/live/user/pass/1.ts?token=abc&x=1\'  -max_muxing_queue_size 1024 -vcodec copy -sn -acodec copy -map 0:v:0 -map 0:a:1 -copy_unknown  -f tee "[f=hls:hls_init_time=2:hls_time=6:hls_list_size=8:hls_delete_threshold=4:hls_flags=delete_segments+discont_start+omit_endlist:hls_segment_type=mpegts:hls_segment_filename=<streams>/42_%d.ts]<streams>/42_.m3u8|[f=mpegts:onfail=ignore:mpegts_flags=+initial_discontinuity]unix:/run/ingest/42.sock" >/dev/null 2>><streams>/42.errors & echo $! > <streams>/42_.pid',
		'a radio stream' => '/bin/ffmpeg -y -nostdin -hide_banner -loglevel error -err_detect ignore_err -thread_queue_size 1024    -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0  -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5 -probesize 1000000 -analyzeduration 500000 -progress "<streams>/42_.progress"  -i \'http://src.example:8080/live/user/pass/1.ts?token=abc&x=1\'  -max_muxing_queue_size 1024 -vcodec copy -sn -acodec copy -map 0:a?   -individual_header_trailer 0 -f hls -hls_init_time 2 -hls_time 6 -hls_list_size 8 -hls_delete_threshold 4 -hls_flags delete_segments+discont_start+omit_endlist -hls_segment_type mpegts -hls_segment_filename "<streams>/42_%d.ts" "<streams>/42_.m3u8"  >/dev/null 2>><streams>/42.errors & echo $! > <streams>/42_.pid',
		'a created channel' => '/bin/ffmpeg -y -nostdin -hide_banner -loglevel error -err_detect ignore_err -thread_queue_size 1024    -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0 -re -probesize 1000000 -analyzeduration 500000 -progress "<streams>/42_.progress" -safe 0 -f concat -i \'/home/xc_vm/content/created/42_.list\'  -max_muxing_queue_size 1024 -vcodec copy -sn -acodec copy   -individual_header_trailer 0 -f hls -hls_init_time 2 -hls_time 6 -hls_list_size 8 -hls_delete_threshold 4 -hls_flags delete_segments+discont_start+omit_endlist -hls_segment_type mpegts -hls_segment_filename "<streams>/42_%d.ts" "<streams>/42_.m3u8"  >/dev/null 2>><streams>/42.errors & echo $! > <streams>/42_.pid',
		'generated timestamps, read at the native rate' => '/bin/ffmpeg -y -nostdin -hide_banner -loglevel error -err_detect ignore_err -thread_queue_size 1024   -fflags +genpts -async 1 -re -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5 -probesize 1000000 -analyzeduration 500000 -progress "<streams>/42_.progress"  -i \'http://src.example:8080/live/user/pass/1.ts?token=abc&x=1\'  -max_muxing_queue_size 1024 -vcodec copy -sn -acodec copy   -individual_header_trailer 0 -f hls -hls_init_time 2 -hls_time 6 -hls_list_size 8 -hls_delete_threshold 4 -hls_flags delete_segments+discont_start+omit_endlist -hls_segment_type mpegts -hls_segment_filename "<streams>/42_%d.ts" "<streams>/42_.m3u8"  >/dev/null 2>><streams>/42.errors & echo $! > <streams>/42_.pid',
		'warnings, key frames ignored, an HLS source' => '/bin/ffmpeg -y -nostdin -hide_banner -loglevel warning -err_detect ignore_err -thread_queue_size 1024    -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0 -re -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5 -probesize 1000000 -analyzeduration 500000 -progress "<streams>/42_.progress"  -i \'http://src.example:8080/live/user/pass/1.ts?token=abc&x=1\'  -max_muxing_queue_size 1024 -vcodec copy -sn -acodec copy   -individual_header_trailer 0 -f hls -hls_init_time 2 -hls_time 6 -hls_list_size 8 -hls_delete_threshold 4 -hls_flags delete_segments+discont_start+omit_endlist+split_by_time -hls_segment_type mpegts -hls_segment_filename "<streams>/42_%d.ts" "<streams>/42_.m3u8"  >/dev/null 2>><streams>/42.errors & echo $! > <streams>/42_.pid',
	];
}
