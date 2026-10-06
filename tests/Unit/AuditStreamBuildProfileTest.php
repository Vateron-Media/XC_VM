<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Tests\Support\InstallSchema;

/**
 * What the profile page saves is what a stream on that profile runs.
 *
 * A profile that deinterlaces and scales without a logo has one input, the
 * source, and one filter chain on it: a second input belongs to a profile
 * that names a logo. A GPU profile takes its preset and video profile from
 * the selects of its own codec, whichever way it decodes.
 *
 * The form is saved in a child PHP into the install schema's `profiles`
 * table, and the stored options are run through buildLive() and /bin/sh with
 * a program in ffmpeg's place that records what it received.
 */
final class AuditStreamBuildProfileTest extends TestCase {
	/** The profile page's fields, as it posts them empty. */
	private const FORM = [
		'profile_name' => 'Profile', 'gpu_device' => '0', 'software_decoding' => '0', 'resize' => '', 'deint' => '0',
		'video_codec_gpu' => '', 'preset_' => '', 'preset_h264' => '', 'preset_hevc' => '', 'video_profile_' => '', 'video_profile_h264' => '', 'video_profile_hevc' => '',
		'video_codec_cpu' => '', 'preset_cpu' => '', 'video_profile_cpu' => '', 'audio_codec' => '',
		'video_bitrate' => '', 'audio_bitrate' => '', 'min_tolerance' => '', 'max_tolerance' => '', 'buffer_size' => '', 'crf_value' => '',
		'aspect_ratio' => '', 'framerate' => '', 'samplerate' => '', 'audio_channels' => '', 'threads' => '',
		'logo_path' => '', 'logo_pos' => '', 'scaling' => '',
	];

	private const CPU = ['video_codec_cpu' => 'libx264', 'preset_cpu' => 'veryfast', 'audio_codec' => 'aac'];

	private const GPU = ['gpu_device' => '1_0', 'video_codec_gpu' => 'h264_nvenc', 'audio_codec' => 'aac'];

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
		$this->rDir = sys_get_temp_dir() . '/xcvm-build-profile-' . bin2hex(random_bytes(4)) . '/';
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
			echo "\nSAVED " . ProfileService::process(json_decode((string) file_get_contents(__DIR__ . '/form.json'), true))['status'] . "\n";
			PHP);
	}

	protected function tearDown(): void {
		array_map('unlink', glob($this->rDir . '*') ?: []);
		rmdir($this->rDir);
	}

	/**
	 * Save the profile page's form.
	 *
	 * @return array<int|string, mixed> The options the profile stores.
	 */
	private function save(array $rForm): array {
		file_put_contents($this->rDir . 'form.json', json_encode($rForm + self::FORM));
		$rEnv = TestDb::env() + ['XCVM_TEST_BOOTSTRAP' => dirname(__DIR__) . '/bootstrap.php', 'XCVM_TEST_SCHEMA' => $this->rDb->schema(), 'PATH' => (string) getenv('PATH')];
		$rProc = proc_open([PHP_BINARY, $this->rDir . 'save.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes, $this->rDir, $rEnv);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]) . (string) stream_get_contents($rPipes[2]);
		fclose($rPipes[1]);
		fclose($rPipes[2]);
		proc_close($rProc);
		$this->assertSame(1, preg_match('/^SAVED 1$/m', $rOut), $rOut);
		$this->rDb->query('SELECT `profile_options` FROM `profiles`;');
		return json_decode((string) $this->rDb->get_row()['profile_options'], true);
	}

	/**
	 * What the program receives for a live stream on a profile with these
	 * options, built for the fanout supervisor (no redirect or background tail).
	 *
	 * @return list<string>
	 */
	private function received(array $rOptions, int $rRtmpOutput = 0): array {
		$rLine = (new ReflectionMethod(StreamProcess::class, 'buildLive'))->invoke(null, [
			'stream' => [
				'stream_info' => [
					'custom_ffmpeg' => '', 'stream_all' => 0, 'custom_map' => '', 'type_key' => 'live', 'gen_timestamps' => 0, 'read_native' => 0,
					'enable_transcode' => 1, 'transcode_profile_id' => 1, 'transcode_attributes' => '[]', 'profile_options' => json_encode($rOptions),
					'delay_minutes' => 0, 'rtmp_output' => $rRtmpOutput, 'external_push' => '[]',
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
		// Each call reads what its own run recorded.
		if (file_exists($this->rDir . 'argv')) {
			unlink($this->rDir . 'argv');
		}
		$rProcess = proc_open(['/bin/sh', '-c', $rLine], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes, $this->rDir);
		proc_close($rProcess);
		$rFile = $this->rDir . 'argv';
		return file_exists($rFile) ? explode("\0", (string) file_get_contents($rFile), -1) : [];
	}

	// ── deinterlace and scaling without a logo ─────────────────────────────

	public static function profilesFilteringWithoutALogo(): array {
		return [
			'a CPU profile deinterlacing and scaling' => [self::CPU + ['yadif_filter' => '1', 'scaling' => '1280:720'], 'yadif,scale=1280:720'],
			'a CPU profile deinterlacing, with a scaling expression' => [self::CPU + ['yadif_filter' => '1', 'scaling' => 'trunc(iw/2)*2:-2'], 'yadif,scale=trunc(iw/2)*2:-2'],
			'a CPU profile deinterlacing, with a scaling that has shell syntax' => [self::CPU + ['yadif_filter' => '1', 'scaling' => '$(touch ran-1)" -an "`touch ran-2`\\'], 'yadif,scale=$(touch ran-1)" -an "`touch ran-2`\\'],
			'a GPU profile deinterlacing and resizing' => [self::GPU + ['deint' => '2', 'resize' => '1280x720'], 'yadif,scale=1280x720'],
			'a GPU profile decoding in software, deinterlacing and resizing' => [self::GPU + ['software_decoding' => '1', 'deint' => '2', 'resize' => '1280x720'], 'yadif,scale=1280x720'],
		];
	}

	#[DataProvider('profilesFilteringWithoutALogo')]
	public function testDeinterlaceAndScalingWithoutALogoAreOneFilterOnTheSource(array $rForm, string $rFilter): void {
		$rOptions = $this->save($rForm);
		$this->assertArrayNotHasKey(16, $rOptions, 'the logo entry belongs to a profile that names a logo');
		$rArgv = $this->received($rOptions);
		$this->assertSame(['http://src.example/live.ts'], self::following($rArgv, '-i'), 'the source is the only input');
		$this->assertSame([$rFilter], self::following($rArgv, '-vf'));
		$this->assertNotContains('-filter_complex', $rArgv);
		$this->assertSame([], glob($this->rDir . 'ran-*'), 'nothing but the program ran');
	}

	/**
	 * A profile saved earlier holds a logo entry with no path, its filter text
	 * in it and none in the scaling entry. It runs the command of one saved today.
	 */
	#[DataProvider('profilesFilteringWithoutALogo')]
	public function testAProfileStoredWithALogoEntryAndNoLogoRunsTheSameCommand(array $rForm, string $rFilter): void {
		$rOptions = $this->save($rForm);
		$rArgv = $this->received($rOptions);
		$rChain = 'yadif,scale=' . escapeshellcmd($rOptions[9]['val']);
		$rOptions[9]['cmd'] = '';
		$rOptions[16] = ['cmd' => ' -filter_complex "' . (empty($rForm['software_decoding']) ? '[0:v]' . $rChain . '[bg];[bg][1:v] ' : $rChain . ',') . '"'];
		$this->assertSame([$rFilter], self::following($rArgv, '-vf'));
		$this->assertSame($rArgv, $this->received($rOptions));
		$this->assertSame([], glob($this->rDir . 'ran-*'), 'nothing but the program ran');
	}

	/** The chain is an option of each output, so an RTMP output is filtered like the HLS one. */
	public function testEveryOutputOfTheStreamIsFiltered(): void {
		$rArgv = $this->received($this->save(self::CPU + ['yadif_filter' => '1', 'scaling' => '1280:720']), 1);
		$this->assertSame(['hls', 'flv'], self::following($rArgv, '-f'));
		$this->assertSame(['yadif,scale=1280:720', 'yadif,scale=1280:720'], self::following($rArgv, '-vf'));
	}

	/** With a logo the profile keeps its second input and the overlay. */
	public function testDeinterlaceAndScalingWithALogoOverlayIt(): void {
		$rArgv = $this->received($this->save(self::CPU + ['yadif_filter' => '1', 'scaling' => '1280:720', 'logo_path' => '/home/xc_vm/logos/logo.png', 'logo_pos' => '20:30']));
		$this->assertSame(['http://src.example/live.ts', '/home/xc_vm/logos/logo.png'], self::following($rArgv, '-i'));
		$this->assertSame(['[0:v]yadif,scale=1280:720[bg]; [1:v]scale=250:-1[logo]; [bg][logo]overlay=20:30'], self::following($rArgv, '-filter_complex'));
	}

	/** @return list<string> The argument after each occurrence of an option. */
	private static function following(array $rArgv, string $rOption): array {
		$rValues = [];
		foreach (array_keys($rArgv, $rOption, true) as $i) {
			$rValues[] = $rArgv[$i + 1] ?? '';
		}
		return $rValues;
	}

	// ── a GPU profile's preset and video profile ───────────────────────────

	public static function gpuCodecs(): array {
		// Both codecs' selects are posted: the page only hides the block of the other codec.
		$rSelects = ['preset_h264' => 'fast', 'video_profile_h264' => 'high -level 4.1', 'preset_hevc' => 'slow', 'video_profile_hevc' => 'main10 -level 5.1'];
		return [
			'HEVC, decoding in software' => [['video_codec_gpu' => 'hevc_nvenc', 'software_decoding' => '1'] + $rSelects, 'slow', 'main10 -level 5.1'],
			'HEVC, decoding in hardware' => [['video_codec_gpu' => 'hevc_nvenc'] + $rSelects, 'slow', 'main10 -level 5.1'],
			'H.264, decoding in software' => [['video_codec_gpu' => 'h264_nvenc', 'software_decoding' => '1'] + $rSelects, 'fast', 'high -level 4.1'],
			'H.264, decoding in hardware' => [['video_codec_gpu' => 'h264_nvenc'] + $rSelects, 'fast', 'high -level 4.1'],
		];
	}

	#[DataProvider('gpuCodecs')]
	public function testAGpuProfileTakesThePresetAndVideoProfileOfItsOwnCodec(array $rForm, string $rPreset, string $rVideoProfile): void {
		$rOptions = $this->save($rForm + self::GPU);
		$this->assertSame($rForm['video_codec_gpu'], $rOptions['-vcodec']);
		$this->assertSame([$rPreset, $rVideoProfile], [$rOptions['-preset'] ?? null, $rOptions['-profile:v'] ?? null]);
	}
}
