<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\StreamProcess;

/**
 * Characterisation tests for StreamProcess::buildLive() — the pure live ffmpeg
 * command assembler extracted from startStream. These lock the string structure
 * per branch (simple / custom_ffmpeg / loopback / delay / rtmp) and, crucially,
 * assert no unresolved {TOKEN} survives — the most likely extraction defect.
 *
 * The authoritative equivalence oracle is the shadow-diff wired into startStream
 * (both strings computed on real traffic, divergence logged); these fixtures
 * guard against future drift.
 */
final class StreamProcessBuildLiveTest extends TestCase {

	public static function setUpBeforeClass(): void {
		foreach ([
			'SERVER_ID' => 1,
			'STREAMS_PATH' => '/tmp/xcvm-test-streams/',
			'DELAY_PATH' => '/tmp/xcvm-test-delay/',
			'FFMPEG_BIN_40' => '/bin/ffmpeg40',
			'FFPROBE_BIN_40' => '/bin/ffprobe40',
		] as $k => $v) {
			if (!defined($k)) {
				define($k, $v);
			}
		}
	}

	/** Base $data for a plain live restream; override per test. */
	private function data(array $overrides = []): array {
		$base = [
			'stream' => [
				'stream_info' => [
					'custom_ffmpeg' => '',
					'stream_all' => 0,
					'custom_map' => '',
					'type_key' => 'live',
					'gen_timestamps' => 0,
					'read_native' => 0,
					'enable_transcode' => 0,
					'transcode_profile_id' => 0,
					'transcode_attributes' => '[]',
					'profile_options' => '[]',
					'delay_minutes' => 0,
					'rtmp_output' => 0,
				],
				'server_info' => ['parent_id' => 0, 'server_id' => 1],
				'stream_arguments' => [],
			],
			'settings' => [
				'ffmpeg_warnings' => 0,
				'read_native_hls' => 0,
				'dts_legacy_ffmpeg' => 0,
				'ignore_keyframes' => 0,
				'live_streaming_pass' => 'secret',
			],
			'servers' => [1 => ['rtmp_port' => 1935]],
			'streamID' => 42,
			'streamSource' => 'http://src.example/live.ts',
			'fetchOptions' => '',
			'ffprobe' => ['container' => 'mpegts', 'codecs' => ['video' => ['codec_name' => 'h264'], 'audio' => ['codec_name' => 'aac']]],
			'protocol' => 'http',
			'source' => 'http://src.example/live.ts',
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
		// shallow-merge stream_info / settings so tests can tweak single keys
		if (isset($overrides['stream_info'])) {
			$base['stream']['stream_info'] = array_merge($base['stream']['stream_info'], $overrides['stream_info']);
			unset($overrides['stream_info']);
		}
		if (isset($overrides['server_info'])) {
			$base['stream']['server_info'] = array_merge($base['stream']['server_info'], $overrides['server_info']);
			unset($overrides['server_info']);
		}
		return array_merge($base, $overrides);
	}

	private function build(array $overrides = []): string {
		$m = new ReflectionMethod(StreamProcess::class, 'buildLive');
		$m->setAccessible(true);
		return $m->invoke(null, $this->data($overrides));
	}

	// ── the key invariant: every placeholder is resolved ───────

	public function testNoUnresolvedPlaceholderRemains(): void {
		foreach ([
			[],                                                  // simple
			['loopback' => true],                                // loopback
			['delayActive' => true, 'segmentStart' => 3, 'stream_info' => ['delay_minutes' => 5]],
			['stream_info' => ['rtmp_output' => 1]],             // rtmp
			['stream_info' => ['custom_ffmpeg' => '-i in -c copy out']],
		] as $ov) {
			$out = $this->build($ov);
			$this->assertStringNotContainsString('{', $out, 'unresolved placeholder in: ' . $out);
		}
	}

	// ── simple live ────────────────────────────────────────────

	public function testSimpleLiveStructure(): void {
		$out = $this->build();
		$this->assertStringStartsWith('/bin/ffmpeg ', $out, 'cpu bin (no gpu)');
		$this->assertStringContainsString('-progress "' . STREAMS_PATH . '42_.progress"', $out);
		$this->assertStringContainsString("-i 'http://src.example/live.ts'", $out);
		$this->assertStringContainsString(STREAMS_PATH . '42_%d.ts', $out, 'hls segments');
		$this->assertStringContainsString('>/dev/null 2>>' . STREAMS_PATH . '42.errors', $out);
		$this->assertStringContainsString('echo $! > ' . STREAMS_PATH . '42_.pid', $out);
	}

	// ── supervised (launched by the fanout daemon) ─────────────

	/** The daemon is the parent: the shell's redirect/background/pid tail must go. */
	public function testSupervisedOmitsTheLaunchTail(): void {
		$out = $this->build(['supervised' => true]);
		$this->assertStringNotContainsString('>/dev/null', $out);
		$this->assertStringNotContainsString('echo $!', $out);
		$this->assertStringNotContainsString(' & ', $out);
		$this->assertStringContainsString(STREAMS_PATH . '42_.m3u8', $out, 'still names its playlist (adopt_match)');
	}

	/** A supervised loopback feeds the daemon, which confirms and judges it by those bytes. */
	public function testSupervisedLoopbackTeesIntoTheDaemon(): void {
		$out = $this->build(['loopback' => true, 'ingestSock' => '/run/ingest/42.sock', 'supervised' => true]);
		$this->assertStringContainsString('-f tee', $out);
		$this->assertStringContainsString('unix:/run/ingest/42.sock', $out);
		$this->assertStringContainsString('-map 0 -copy_unknown', $out);
	}

	/** With no daemon ingest (daemon unreachable) a loopback stays on-disk only. */
	public function testLoopbackWithoutIngestStaysOnDiskOnly(): void {
		$this->assertStringNotContainsString('-f tee', $this->build(['loopback' => true]));
	}

	/** A self-launched (unsupervised) loopback feeds the daemon too — clients are daemon-only. */
	public function testUnsupervisedLoopbackTeesIntoTheDaemon(): void {
		$out = $this->build(['loopback' => true, 'ingestSock' => '/run/ingest/42.sock']);
		$this->assertStringContainsString('-f tee', $out);
		$this->assertStringContainsString('unix:/run/ingest/42.sock', $out);
	}

	// ── LLOD ───────────────────────────────────────────────────

	/** +nobuffer is a demuxer flag: it belongs before -i, not among the output options. */
	public function testLlodLatencyFlagsSitOnTheInput(): void {
		$out = $this->build(['llod' => true]);
		$input = substr($out, 0, (int) strpos($out, ' -i '));
		$this->assertStringContainsString('-fflags +discardcorrupt+nobuffer', $input);
		$this->assertStringNotContainsString('-fflags nobuffer', $out);
	}

	/** A stream copy gets no encoder tune; x264 gets zerolatency; NVENC its own option. */
	public function testLlodTuneMatchesTheEncoder(): void {
		$this->assertStringNotContainsString('-tune', $this->build(['llod' => true]));

		$x264 = $this->build(['llod' => true, 'stream_info' => ['enable_transcode' => 1, 'transcode_profile_id' => 1, 'profile_options' => json_encode(['-vcodec' => 'libx264'])]]);
		$this->assertStringContainsString('-tune zerolatency', $x264);

		$nvenc = $this->build(['llod' => true, 'stream_info' => ['enable_transcode' => 1, 'transcode_profile_id' => 1, 'profile_options' => json_encode(['-vcodec' => 'h264_nvenc'])]]);
		$this->assertStringNotContainsString('-tune zerolatency', $nvenc, 'NVENC rejects the x264 tune value');
		$this->assertStringContainsString('-zerolatency 1', $nvenc);
	}

	// ── custom_ffmpeg branch ───────────────────────────────────

	public function testCustomFfmpegBypassesTemplate(): void {
		$out = $this->build(['stream_info' => ['custom_ffmpeg' => '-i INPUT -c:v libx264 OUTPUT']]);
		$this->assertStringContainsString('-i INPUT -c:v libx264 OUTPUT', $out);
		// custom path emits neither gen_pts input flags nor a fetch-options slot
		$this->assertStringNotContainsString('-thread_queue_size', $out);
	}

	// ── loopback ───────────────────────────────────────────────

	public function testLoopbackHasNoReconnectAndNoLlod(): void {
		$out = $this->build(['loopback' => true, 'llod' => true]);
		$this->assertStringNotContainsString('-reconnect', $out, 'loopback source is not http-reconnect');
		$this->assertStringNotContainsString('-tune zerolatency', $out, 'LLOD options suppressed on loopback');
	}

	// ── delay resume ───────────────────────────────────────────

	public function testDelayUsesDelayPathAndStartNumber(): void {
		$out = $this->build(['delayActive' => true, 'segmentStart' => 3, 'stream_info' => ['delay_minutes' => 5]]);
		$this->assertStringContainsString('-start_number 3', $out);
		$this->assertStringContainsString(DELAY_PATH . '42_%d.ts', $out);
		$this->assertStringContainsString('-f hls', $out);
	}

	// ── rtmp output ────────────────────────────────────────────

	public function testRtmpOutputAppendsFlvTarget(): void {
		$out = $this->build(['stream_info' => ['rtmp_output' => 1]]);
		$this->assertStringContainsString('rtmp://127.0.0.1:1935/live/42?password=secret', $out);
		$this->assertStringContainsString('-f flv', $out);
	}
}
