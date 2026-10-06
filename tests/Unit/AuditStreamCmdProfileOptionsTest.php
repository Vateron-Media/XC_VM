<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Tests\Support\InstallSchema;

/**
 * A transcode profile's free-text values (scaling, aspect ratio, the GPU
 * resize) reach ffmpeg as they were typed, each inside the one argument its
 * option takes: the profile page stores them quoted for the shell
 * (ProfileService::process), and the logo filter quotes the scaling it reads
 * from the profile (StreamProcess::buildLogoFilterOptions). Its codecs, preset
 * and video profile are names, stored and written as plain text: a profile
 * is saved only when each is one (a video profile may carry its level).
 *
 * The form is saved in a child PHP into the install schema's `profiles`
 * table, and the stored options are run through buildLive() and /bin/sh with
 * a program in ffmpeg's place that records what it received. What it
 * receives for ordinary values is kept here as it has always been (EXPECTED).
 */
final class AuditStreamCmdProfileOptionsTest extends TestCase {
	/** The profile page's fields, as it posts them empty. */
	private const FORM = [
		'profile_name' => 'Profile', 'gpu_device' => '0', 'software_decoding' => '0', 'resize' => '', 'deint' => '0',
		'video_codec_gpu' => '', 'preset_' => '', 'preset_h264' => '', 'preset_hevc' => '', 'video_profile_' => '', 'video_profile_h264' => '', 'video_profile_hevc' => '',
		'video_codec_cpu' => '', 'preset_cpu' => '', 'video_profile_cpu' => '', 'audio_codec' => '',
		'video_bitrate' => '', 'audio_bitrate' => '', 'min_tolerance' => '', 'max_tolerance' => '', 'buffer_size' => '', 'crf_value' => '',
		'aspect_ratio' => '', 'framerate' => '', 'samplerate' => '', 'audio_channels' => '', 'threads' => '',
		'logo_path' => '', 'logo_pos' => '', 'scaling' => '',
	];

	private const CPU = ['video_codec_cpu' => 'libx264', 'preset_cpu' => 'veryfast', 'video_profile_cpu' => 'main', 'audio_codec' => 'aac', 'video_bitrate' => '2500'];

	private const GPU = ['gpu_device' => '1_0', 'video_codec_gpu' => 'h264_nvenc', 'preset_h264' => 'fast', 'video_profile_h264' => 'high', 'audio_codec' => 'aac'];

	private const LOGO = ['logo_path' => '/home/xc_vm/logos/my logo.png', 'logo_pos' => '20:30'];

	private string $rDir;

	private TestDb $rDb;

	public static function setUpBeforeClass(): void {
		foreach (['SERVER_ID' => 1, 'STREAMS_PATH' => '/tmp/xcvm-test-streams/', 'DELAY_PATH' => '/tmp/xcvm-test-delay/'] as $rName => $rValue) {
			if (!defined($rName)) {
				define($rName, $rValue);
			}
		}
	}

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-profile-options-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir, 0700, true);
		// Records its arguments, one per NUL.
		file_put_contents($this->rDir . 'program', "#!/bin/sh\nfor a in \"\$@\"; do printf '%s\\000' \"\$a\"; done > " . escapeshellarg($this->rDir . 'argv') . "\n");
		chmod($this->rDir . 'program', 0755);
		$this->rDb = new TestDb();
		$this->rDb->exec(InstallSchema::table('profiles'));
		file_put_contents($this->rDir . 'save.php', <<<'PHP'
			<?php
			use XcVm\Core\Database\DatabaseHandler;
			use XcVm\Domain\Stream\ProfileService;
			use XcVm\Infrastructure\Database\DatabaseFactory;

			foreach (['STATUS_FAILURE' => 0, 'STATUS_SUCCESS' => 1, 'STATUS_INVALID_INPUT' => 34] as $rName => $rValue) {
				define($rName, $rValue);
			}
			require getenv('XCVM_TEST_BOOTSTRAP');
			DatabaseFactory::set(new class(TestDb::connect(getenv('XCVM_TEST_SCHEMA'))) extends DatabaseHandler {
				public function __construct(\PDO $rPdo) {
					$this->dbh = $rPdo;
				}
			});
			$rStatuses = [];
			foreach (json_decode((string) file_get_contents(__DIR__ . '/forms.json'), true) as $rForm) {
				$rStatuses[] = ProfileService::process($rForm)['status'];
			}
			echo "\nSAVED " . json_encode($rStatuses) . "\n";
			PHP);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * Post the profile page's form, once per form given.
	 *
	 * @param list<array<string, string>> $rForms
	 * @return list<int> The status each save answered.
	 */
	private function statuses(array $rForms): array {
		file_put_contents($this->rDir . 'forms.json', json_encode(array_map(static fn(array $rForm): array => $rForm + self::FORM, $rForms)));
		$rEnv = TestDb::env() + ['XCVM_TEST_BOOTSTRAP' => dirname(__DIR__) . '/bootstrap.php', 'XCVM_TEST_SCHEMA' => $this->rDb->schema(), 'PATH' => (string) getenv('PATH')];
		$rProc = proc_open([PHP_BINARY, $this->rDir . 'save.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes, $this->rDir, $rEnv);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]) . (string) stream_get_contents($rPipes[2]);
		fclose($rPipes[1]);
		fclose($rPipes[2]);
		proc_close($rProc);
		$this->assertSame(1, preg_match('/^SAVED (\[[\d,]*\])$/m', $rOut, $rMatch), $rOut);
		return json_decode($rMatch[1], true);
	}

	/**
	 * Save the profile page's form, in place of any profile before it.
	 *
	 * @return array<int|string, mixed> The options the profile now stores.
	 */
	private function save(array $rForm): array {
		$this->rDb->exec('DELETE FROM `profiles`');
		$this->assertSame([1], $this->statuses([$rForm]));
		$this->rDb->query('SELECT `profile_options` FROM `profiles`;');
		return json_decode((string) $this->rDb->get_row()['profile_options'], true);
	}

	/**
	 * What the program receives for a live stream with this profile, on a
	 * source with H.264 video: the line is built for the fanout supervisor,
	 * so it has no redirect or background tail.
	 *
	 * @return list<string>
	 */
	private function received(array $rForm): array {
		$rLine = (new ReflectionMethod(StreamProcess::class, 'buildLive'))->invoke(null, [
			'stream' => [
				'stream_info' => [
					'custom_ffmpeg' => '', 'stream_all' => 0, 'custom_map' => '', 'type_key' => 'live', 'gen_timestamps' => 0, 'read_native' => 0,
					'enable_transcode' => 1, 'transcode_profile_id' => 1, 'transcode_attributes' => '[]', 'profile_options' => json_encode($this->save($rForm)),
					'delay_minutes' => 0, 'rtmp_output' => 0, 'external_push' => '[]',
				],
				'server_info' => ['parent_id' => 0, 'server_id' => 1],
				'stream_arguments' => [],
			],
			'settings' => ['ffmpeg_warnings' => 0, 'read_native_hls' => 0, 'dts_legacy_ffmpeg' => 0, 'ignore_keyframes' => 0],
			'servers' => [1 => ['rtmp_port' => 1935]],
			'streamID' => 42, 'streamSource' => 'http://src.example/live.ts', 'source' => 'http://src.example/live.ts', 'protocol' => 'http', 'fetchOptions' => '',
			'ffprobe' => ['container' => 'mpegts', 'codecs' => ['video' => ['codec_name' => 'h264'], 'audio' => ['codec_name' => 'aac']]],
			'segmentSettings' => ['seg_time' => 6, 'seg_list_size' => 8, 'seg_delete_threshold' => 4],
			'externalPush' => [], 'probesize' => 1000000, 'analyseDuration' => 500000, 'llod' => false, 'loopback' => false, 'segmentStart' => 0, 'delayActive' => false,
			'ffmpegCpu' => $this->rDir . 'program', 'ffmpegGpu' => $this->rDir . 'program', 'supervised' => true,
		]);
		$rProcess = proc_open(['/bin/sh', '-c', $rLine], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes, $this->rDir);
		proc_close($rProcess);
		$rFile = $this->rDir . 'argv';
		$rArgv = file_exists($rFile) ? explode("\0", (string) file_get_contents($rFile), -1) : [];
		@unlink($rFile);
		return $rArgv;
	}

	// ── ordinary values: the program receives what it always has ───────────

	public static function ordinaryProfiles(): array {
		return [
			'a CPU profile with a scaling and an aspect' => [self::CPU + ['scaling' => '1280:720', 'aspect_ratio' => '16:9', 'framerate' => '25', 'threads' => '0']],
			'a CPU profile with a scaling expression' => [self::CPU + ['scaling' => 'trunc(iw/2)*2:-2', 'aspect_ratio' => '4:3']],
			'a CPU profile deinterlacing' => [self::CPU + ['yadif_filter' => '1']],
			'a GPU profile resizing' => [self::GPU + ['resize' => '1280x720']],
			'a GPU profile deinterlacing' => [self::GPU + ['deint' => '2']],
			'a CPU profile with a logo, a scaling and deinterlacing' => [self::CPU + self::LOGO + ['scaling' => '1280:720', 'yadif_filter' => '1']],
			'a CPU profile with a logo and a scaling expression' => [self::CPU + self::LOGO + ['scaling' => 'trunc(iw/2)*2:-2']],
			'a GPU profile with a logo and a resize' => [self::GPU + self::LOGO + ['resize' => '1280:720']],
			// The page's video profiles carry their level: two options from the one value.
			'a CPU profile with a level' => [['video_profile_cpu' => 'main -level 4.0'] + self::CPU],
			'a GPU profile with a level' => [['video_codec_gpu' => 'hevc_nvenc', 'preset_hevc' => 'slow', 'video_profile_hevc' => 'main10 -level 5.1'] + self::GPU],
		];
	}

	/** What the program receives, with this run's own directories named so it reads the same wherever they are. */
	private function ordinary(array $rForm): array {
		return str_replace([STREAMS_PATH, DELAY_PATH], ['<streams>/', '<delay>/'], $this->received($rForm));
	}

	#[DataProvider('ordinaryProfiles')]
	public function testAnOrdinaryProfileReachesTheProgramUnchanged(array $rForm): void {
		$this->assertArrayHasKey($this->dataName(), self::EXPECTED);
		$this->assertSame(self::EXPECTED[$this->dataName()], $this->ordinary($rForm));
	}

	public function testEveryExpectedProfileHasItsCase(): void {
		$this->assertSame(array_keys(self::ordinaryProfiles()), array_keys(self::EXPECTED));
	}

	// ── any value: one argument, as typed ──────────────────────────────────

	public static function freeTextValues(): array {
		return [
			'an aspect with a space' => [self::CPU, 'aspect_ratio', '16:9 -vn'],
			'an aspect with shell syntax' => [self::CPU, 'aspect_ratio', '16:9" \'q\' $HOME; echo ran'],
			'a scaling with a space' => [self::CPU, 'scaling', '1280:720 -an'],
			'a scaling expression in quotes' => [self::CPU, 'scaling', "'min(1280,iw)':-2"],
			'a scaling with one quote' => [self::CPU, 'scaling', "1280:720' -an"],
			'a GPU resize with a space' => [self::GPU, 'resize', '1280x720 -an'],
			// parseTranscode() gathers every -filter_complex "..." it finds in an option's text.
			'an aspect naming a filter' => [self::CPU, 'aspect_ratio', '16:9 -filter_complex "nullsrc"'],
			'a scaling naming a filter' => [self::CPU, 'scaling', '1280:720 -filter_complex "$(touch ran-1)"'],
			'a GPU resize naming a filter' => [self::GPU, 'resize', '1280x720 -filter_complex "nullsrc"'],
			'a scaling beside a logo, with a double quote' => [self::CPU + self::LOGO, 'scaling', '1280:720" -an "'],
			'a scaling beside a logo, with shell syntax' => [self::CPU + self::LOGO, 'scaling', '$(touch ran-1):`touch ran-2`\\'],
			'a scaling beside a logo, deinterlaced, with a double quote' => [self::CPU + self::LOGO + ['yadif_filter' => '1'], 'scaling', '1280:720" -an "'],
			'a GPU resize beside a logo, with shell syntax' => [self::GPU + self::LOGO, 'resize', '$(touch ran-1)" -an "'],
		];
	}

	#[DataProvider('freeTextValues')]
	public function testAFreeTextValueIsOneArgumentAsTyped(array $rForm, string $rField, string $rValue): void {
		// What the program receives for a plain word in that field, with the word exchanged for the value.
		$rExpected = str_replace('VALUE', $rValue, $this->received($rForm + [$rField => 'VALUE']));
		$this->assertContains('-i', $rExpected, 'the program ran');
		$this->assertSame($rExpected, $this->received($rForm + [$rField => $rValue]));
		$this->assertSame([], glob($this->rDir . 'ran-*'), 'nothing but the program ran');
	}

	// ── a codec, a preset, a video profile: a name, a profile with its level ─

	public static function valuesThatAreNotAName(): array {
		return [
			'a video codec with a space' => [self::CPU, 'video_codec_cpu', 'libx264 -an'],
			'an audio codec with a space' => [self::CPU, 'audio_codec', 'aac -vn'],
			'a preset with a space' => [self::CPU, 'preset_cpu', 'veryfast -f mpegts'],
			'a video profile with an option that is not its level' => [self::CPU, 'video_profile_cpu', 'main -an'],
			'a video profile with an option after its level' => [self::CPU, 'video_profile_cpu', 'main -level 4.0 -an'],
			'a GPU codec with a space' => [self::GPU, 'video_codec_gpu', 'h264_nvenc -an'],
			'a GPU preset with a space' => [self::GPU, 'preset_h264', 'fast -an'],
			'a GPU video profile with a space' => [self::GPU, 'video_profile_h264', 'high -an'],
			'a GPU codec with a space, decoding in software' => [self::GPU + ['software_decoding' => '1'], 'video_codec_gpu', 'h264_nvenc -an'],
			'an audio codec naming a filter' => [self::CPU, 'audio_codec', 'aac -filter_complex "nullsrc"'],
			'a video codec that ends its line' => [self::CPU, 'video_codec_cpu', "libx264\n"],
		];
	}

	#[DataProvider('valuesThatAreNotAName')]
	public function testACodecPresetOrVideoProfileThatIsNotANameIsNotSaved(array $rForm, string $rField, string $rValue): void {
		$this->assertSame([34], $this->statuses([[$rField => $rValue] + $rForm]));
		$this->rDb->query('SELECT COUNT(*) AS `saved` FROM `profiles`;');
		$this->assertSame(0, (int) $this->rDb->get_row()['saved']);
	}

	public function testEveryNameTheProfilePageOffersIsSaved(): void {
		// Each select of the page with the values it lists, and a form in which the save reads that select.
		$rUsedBy = [
			'video_codec_cpu' => [], 'preset_cpu' => [], 'video_profile_cpu' => [], 'audio_codec' => [],
			'video_codec_gpu' => self::GPU, 'preset_h264' => self::GPU, 'video_profile_h264' => self::GPU,
			'preset_hevc' => ['video_codec_gpu' => 'hevc_nvenc'] + self::GPU, 'video_profile_hevc' => ['video_codec_gpu' => 'hevc_nvenc'] + self::GPU,
		];
		$rView = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Public/Views/admin/profile.php');
		preg_match_all('/<select id="(' . implode('|', array_keys($rUsedBy)) . ')"[^>]*>\s*<\?php foreach \(\[(.*?)\] as /s', $rView, $rSelects, PREG_SET_ORDER);
		$this->assertSame(array_keys($rUsedBy), array_values(array_intersect(array_keys($rUsedBy), array_column($rSelects, 1))), 'every select was found');
		$rForms = [];
		foreach ($rSelects as [, $rField, $rList]) {
			preg_match_all("/'([^']*)' =>/", $rList, $rValues);
			foreach ($rValues[1] as $rValue) {
				$rForms[$rField . '=' . $rValue] = [$rField => $rValue] + $rUsedBy[$rField];
			}
		}
		$this->assertArrayHasKey('video_profile_hevc=rext -level 6.2', $rForms);
		$this->assertSame(array_fill_keys(array_keys($rForms), 1), array_combine(array_keys($rForms), $this->statuses(array_values($rForms))));
	}

	private const EXPECTED = [
		'a CPU profile with a scaling and an aspect' => ['-y', '-nostdin', '-hide_banner', '-loglevel', 'error', '-err_detect', 'ignore_err', '-thread_queue_size', '1024', '-start_at_zero', '-copyts', '-vsync', '0', '-correct_ts_overflow', '0', '-avoid_negative_ts', 'disabled', '-max_interleave_delta', '0', '-reconnect', '1', '-reconnect_streamed', '1', '-reconnect_delay_max', '5', '-probesize', '1000000', '-analyzeduration', '500000', '-progress', '<streams>/42_.progress', '-i', 'http://src.example/live.ts', '-max_muxing_queue_size', '1024', '-sn', '-threads', '0', '-vf', 'scale=1280:720', '-aspect', '16:9', '-r', '25', '-b:v', '2500k', '-acodec', 'aac', '-profile:v', 'main', '-preset', 'veryfast', '-vcodec', 'libx264', '-individual_header_trailer', '0', '-f', 'hls', '-hls_init_time', '2', '-hls_time', '6', '-hls_list_size', '8', '-hls_delete_threshold', '4', '-hls_flags', 'delete_segments+discont_start+omit_endlist', '-hls_segment_type', 'mpegts', '-hls_segment_filename', '<streams>/42_%d.ts', '<streams>/42_.m3u8'],
		'a CPU profile with a scaling expression' => ['-y', '-nostdin', '-hide_banner', '-loglevel', 'error', '-err_detect', 'ignore_err', '-thread_queue_size', '1024', '-start_at_zero', '-copyts', '-vsync', '0', '-correct_ts_overflow', '0', '-avoid_negative_ts', 'disabled', '-max_interleave_delta', '0', '-reconnect', '1', '-reconnect_streamed', '1', '-reconnect_delay_max', '5', '-probesize', '1000000', '-analyzeduration', '500000', '-progress', '<streams>/42_.progress', '-i', 'http://src.example/live.ts', '-max_muxing_queue_size', '1024', '-sn', '-aspect', '4:3', '-vf', 'scale=trunc(iw/2)*2:-2', '-b:v', '2500k', '-acodec', 'aac', '-profile:v', 'main', '-preset', 'veryfast', '-vcodec', 'libx264', '-individual_header_trailer', '0', '-f', 'hls', '-hls_init_time', '2', '-hls_time', '6', '-hls_list_size', '8', '-hls_delete_threshold', '4', '-hls_flags', 'delete_segments+discont_start+omit_endlist', '-hls_segment_type', 'mpegts', '-hls_segment_filename', '<streams>/42_%d.ts', '<streams>/42_.m3u8'],
		'a CPU profile deinterlacing' => ['-y', '-nostdin', '-hide_banner', '-loglevel', 'error', '-err_detect', 'ignore_err', '-thread_queue_size', '1024', '-start_at_zero', '-copyts', '-vsync', '0', '-correct_ts_overflow', '0', '-avoid_negative_ts', 'disabled', '-max_interleave_delta', '0', '-reconnect', '1', '-reconnect_streamed', '1', '-reconnect_delay_max', '5', '-probesize', '1000000', '-analyzeduration', '500000', '-progress', '<streams>/42_.progress', '-i', 'http://src.example/live.ts', '-max_muxing_queue_size', '1024', '-vf', 'yadif', '-sn', '-b:v', '2500k', '-acodec', 'aac', '-profile:v', 'main', '-preset', 'veryfast', '-vcodec', 'libx264', '-individual_header_trailer', '0', '-f', 'hls', '-hls_init_time', '2', '-hls_time', '6', '-hls_list_size', '8', '-hls_delete_threshold', '4', '-hls_flags', 'delete_segments+discont_start+omit_endlist', '-hls_segment_type', 'mpegts', '-hls_segment_filename', '<streams>/42_%d.ts', '<streams>/42_.m3u8'],
		'a GPU profile resizing' => ['-y', '-nostdin', '-hide_banner', '-loglevel', 'error', '-err_detect', 'ignore_err', '-thread_queue_size', '1024', '-hwaccel', 'cuvid', '-hwaccel_device', '0', '-resize', '1280x720', '-c:v', 'h264_cuvid', '-gpu', '0', '-drop_second_field', '1', '-start_at_zero', '-copyts', '-vsync', '0', '-correct_ts_overflow', '0', '-avoid_negative_ts', 'disabled', '-max_interleave_delta', '0', '-reconnect', '1', '-reconnect_streamed', '1', '-reconnect_delay_max', '5', '-probesize', '1000000', '-analyzeduration', '500000', '-progress', '<streams>/42_.progress', '-i', 'http://src.example/live.ts', '-max_muxing_queue_size', '1024', '-gpu', '0', '-sn', '-vf', 'scale=1280x720', '-acodec', 'aac', '-profile:v', 'high', '-preset', 'fast', '-vcodec', 'h264_nvenc', '-individual_header_trailer', '0', '-f', 'hls', '-hls_init_time', '2', '-hls_time', '6', '-hls_list_size', '8', '-hls_delete_threshold', '4', '-hls_flags', 'delete_segments+discont_start+omit_endlist', '-hls_segment_type', 'mpegts', '-hls_segment_filename', '<streams>/42_%d.ts', '<streams>/42_.m3u8'],
		'a GPU profile deinterlacing' => ['-y', '-nostdin', '-hide_banner', '-loglevel', 'error', '-err_detect', 'ignore_err', '-thread_queue_size', '1024', '-hwaccel', 'cuvid', '-hwaccel_device', '0', '-deint', '2', '-c:v', 'h264_cuvid', '-gpu', '0', '-drop_second_field', '1', '-start_at_zero', '-copyts', '-vsync', '0', '-correct_ts_overflow', '0', '-avoid_negative_ts', 'disabled', '-max_interleave_delta', '0', '-reconnect', '1', '-reconnect_streamed', '1', '-reconnect_delay_max', '5', '-probesize', '1000000', '-analyzeduration', '500000', '-progress', '<streams>/42_.progress', '-i', 'http://src.example/live.ts', '-max_muxing_queue_size', '1024', '-gpu', '0', '-sn', '-vf', 'yadif', '-acodec', 'aac', '-profile:v', 'high', '-preset', 'fast', '-vcodec', 'h264_nvenc', '-individual_header_trailer', '0', '-f', 'hls', '-hls_init_time', '2', '-hls_time', '6', '-hls_list_size', '8', '-hls_delete_threshold', '4', '-hls_flags', 'delete_segments+discont_start+omit_endlist', '-hls_segment_type', 'mpegts', '-hls_segment_filename', '<streams>/42_%d.ts', '<streams>/42_.m3u8'],
		'a CPU profile with a logo, a scaling and deinterlacing' => ['-y', '-nostdin', '-hide_banner', '-loglevel', 'error', '-err_detect', 'ignore_err', '-thread_queue_size', '1024', '-start_at_zero', '-copyts', '-vsync', '0', '-correct_ts_overflow', '0', '-avoid_negative_ts', 'disabled', '-max_interleave_delta', '0', '-reconnect', '1', '-reconnect_streamed', '1', '-reconnect_delay_max', '5', '-probesize', '1000000', '-analyzeduration', '500000', '-progress', '<streams>/42_.progress', '-i', 'http://src.example/live.ts', '-i', '/home/xc_vm/logos/my logo.png', '-filter_complex', '[0:v]yadif,scale=1280:720[bg]; [1:v]scale=250:-1[logo]; [bg][logo]overlay=20:30', '-max_muxing_queue_size', '1024', '-sn', '-b:v', '2500k', '-acodec', 'aac', '-profile:v', 'main', '-preset', 'veryfast', '-vcodec', 'libx264', '-individual_header_trailer', '0', '-f', 'hls', '-hls_init_time', '2', '-hls_time', '6', '-hls_list_size', '8', '-hls_delete_threshold', '4', '-hls_flags', 'delete_segments+discont_start+omit_endlist', '-hls_segment_type', 'mpegts', '-hls_segment_filename', '<streams>/42_%d.ts', '<streams>/42_.m3u8'],
		'a CPU profile with a logo and a scaling expression' => ['-y', '-nostdin', '-hide_banner', '-loglevel', 'error', '-err_detect', 'ignore_err', '-thread_queue_size', '1024', '-start_at_zero', '-copyts', '-vsync', '0', '-correct_ts_overflow', '0', '-avoid_negative_ts', 'disabled', '-max_interleave_delta', '0', '-reconnect', '1', '-reconnect_streamed', '1', '-reconnect_delay_max', '5', '-probesize', '1000000', '-analyzeduration', '500000', '-progress', '<streams>/42_.progress', '-i', 'http://src.example/live.ts', '-i', '/home/xc_vm/logos/my logo.png', '-filter_complex', '[0:v]scale=trunc(iw/2)*2:-2[bg]; [1:v]scale=250:-1[logo]; [bg][logo]overlay=20:30', '-max_muxing_queue_size', '1024', '-sn', '-b:v', '2500k', '-acodec', 'aac', '-profile:v', 'main', '-preset', 'veryfast', '-vcodec', 'libx264', '-individual_header_trailer', '0', '-f', 'hls', '-hls_init_time', '2', '-hls_time', '6', '-hls_list_size', '8', '-hls_delete_threshold', '4', '-hls_flags', 'delete_segments+discont_start+omit_endlist', '-hls_segment_type', 'mpegts', '-hls_segment_filename', '<streams>/42_%d.ts', '<streams>/42_.m3u8'],
		'a GPU profile with a logo and a resize' => ['-y', '-nostdin', '-hide_banner', '-loglevel', 'error', '-err_detect', 'ignore_err', '-thread_queue_size', '1024', '-hwaccel', 'cuvid', '-hwaccel_device', '0', '-resize', '1280:720', '-c:v', 'h264_cuvid', '-gpu', '0', '-drop_second_field', '1', '-start_at_zero', '-copyts', '-vsync', '0', '-correct_ts_overflow', '0', '-avoid_negative_ts', 'disabled', '-max_interleave_delta', '0', '-reconnect', '1', '-reconnect_streamed', '1', '-reconnect_delay_max', '5', '-probesize', '1000000', '-analyzeduration', '500000', '-progress', '<streams>/42_.progress', '-i', 'http://src.example/live.ts', '-i', '/home/xc_vm/logos/my logo.png', '-filter_complex', '[0:v]scale=1280:720[bg]; [1:v]scale=250:-1[logo]; [bg][logo]overlay=20:30', '-max_muxing_queue_size', '1024', '-gpu', '0', '-sn', '-acodec', 'aac', '-preset', 'fast', '-profile:v', 'high', '-vcodec', 'h264_nvenc', '-individual_header_trailer', '0', '-f', 'hls', '-hls_init_time', '2', '-hls_time', '6', '-hls_list_size', '8', '-hls_delete_threshold', '4', '-hls_flags', 'delete_segments+discont_start+omit_endlist', '-hls_segment_type', 'mpegts', '-hls_segment_filename', '<streams>/42_%d.ts', '<streams>/42_.m3u8'],
		'a CPU profile with a level' => ['-y', '-nostdin', '-hide_banner', '-loglevel', 'error', '-err_detect', 'ignore_err', '-thread_queue_size', '1024', '-start_at_zero', '-copyts', '-vsync', '0', '-correct_ts_overflow', '0', '-avoid_negative_ts', 'disabled', '-max_interleave_delta', '0', '-reconnect', '1', '-reconnect_streamed', '1', '-reconnect_delay_max', '5', '-probesize', '1000000', '-analyzeduration', '500000', '-progress', '<streams>/42_.progress', '-i', 'http://src.example/live.ts', '-max_muxing_queue_size', '1024', '-sn', '-b:v', '2500k', '-acodec', 'aac', '-profile:v', 'main', '-level', '4.0', '-preset', 'veryfast', '-vcodec', 'libx264', '-individual_header_trailer', '0', '-f', 'hls', '-hls_init_time', '2', '-hls_time', '6', '-hls_list_size', '8', '-hls_delete_threshold', '4', '-hls_flags', 'delete_segments+discont_start+omit_endlist', '-hls_segment_type', 'mpegts', '-hls_segment_filename', '<streams>/42_%d.ts', '<streams>/42_.m3u8'],
		'a GPU profile with a level' => ['-y', '-nostdin', '-hide_banner', '-loglevel', 'error', '-err_detect', 'ignore_err', '-thread_queue_size', '1024', '-hwaccel', 'cuvid', '-hwaccel_device', '0', '-c:v', 'h264_cuvid', '-gpu', '0', '-drop_second_field', '1', '-start_at_zero', '-copyts', '-vsync', '0', '-correct_ts_overflow', '0', '-avoid_negative_ts', 'disabled', '-max_interleave_delta', '0', '-reconnect', '1', '-reconnect_streamed', '1', '-reconnect_delay_max', '5', '-probesize', '1000000', '-analyzeduration', '500000', '-progress', '<streams>/42_.progress', '-i', 'http://src.example/live.ts', '-max_muxing_queue_size', '1024', '-gpu', '0', '-sn', '-acodec', 'aac', '-preset', 'slow', '-profile:v', 'main10', '-level', '5.1', '-vcodec', 'hevc_nvenc', '-individual_header_trailer', '0', '-f', 'hls', '-hls_init_time', '2', '-hls_time', '6', '-hls_list_size', '8', '-hls_delete_threshold', '4', '-hls_flags', 'delete_segments+discont_start+omit_endlist', '-hls_segment_type', 'mpegts', '-hls_segment_filename', '<streams>/42_%d.ts', '<streams>/42_.m3u8'],
	];
}
