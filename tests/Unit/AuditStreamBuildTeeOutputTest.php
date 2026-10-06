<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\StreamProcess;

/**
 * A live stream fed to the fanout daemon writes its HLS and the daemon's feed
 * through one tee output. An RTMP output or an external push is a further
 * output of the same command: its options are words of their own, and the tee
 * target ends at the daemon's socket.
 *
 * Each line is run by /bin/sh with a program in ffmpeg's place that records
 * what it received.
 */
final class AuditStreamBuildTeeOutputTest extends TestCase {
	private string $rDir;
	private string $rProgram;

	public static function setUpBeforeClass(): void {
		foreach (['SERVER_ID' => 1, 'STREAMS_PATH' => '/tmp/xcvm-test-streams/', 'DELAY_PATH' => '/tmp/xcvm-test-delay/'] as $rName => $rValue) {
			if (!defined($rName)) {
				define($rName, $rValue);
			}
		}
	}

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-tee-output-' . bin2hex(random_bytes(4)) . '/';
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
	 * What the program receives for a stream fed to the daemon, built for the
	 * fanout supervisor (no redirect or background tail).
	 *
	 * @return list<string>
	 */
	private function received(array $rStreamInfo): array {
		$rLine = (new ReflectionMethod(StreamProcess::class, 'buildLive'))->invoke(null, [
			'stream' => [
				'stream_info' => $rStreamInfo + [
					'custom_ffmpeg' => '', 'stream_all' => 0, 'custom_map' => '', 'type_key' => 'live', 'gen_timestamps' => 0, 'read_native' => 0,
					'enable_transcode' => 0, 'transcode_profile_id' => 0, 'transcode_attributes' => '[]', 'profile_options' => '[]',
					'delay_minutes' => 0, 'rtmp_output' => 0, 'external_push' => '[]',
				],
				'server_info' => ['parent_id' => 0, 'server_id' => SERVER_ID],
				'stream_arguments' => [],
			],
			'settings' => ['ffmpeg_warnings' => 0, 'read_native_hls' => 0, 'dts_legacy_ffmpeg' => 0, 'ignore_keyframes' => 0],
			'servers' => [SERVER_ID => ['rtmp_port' => 1935]],
			'streamID' => 42,
			'streamSource' => 'http://src.example:8080/live/1.ts',
			'fetchOptions' => '',
			'ffprobe' => ['container' => 'mpegts', 'codecs' => ['video' => ['codec_name' => 'h264'], 'audio' => ['codec_name' => 'aac']]],
			'protocol' => 'http',
			'source' => 'http://src.example:8080/live/1.ts',
			'segmentSettings' => ['seg_time' => 6, 'seg_list_size' => 8, 'seg_delete_threshold' => 4],
			'externalPush' => [],
			'probesize' => 1000000,
			'analyseDuration' => 500000,
			'llod' => false,
			'loopback' => false,
			'segmentStart' => 0,
			'delayActive' => false,
			'ffmpegCpu' => $this->rProgram,
			'ffmpegGpu' => $this->rProgram,
			'ingestSock' => '/run/ingest/42.sock',
			'supervised' => true,
		]);
		$rProcess = proc_open(['/bin/sh', '-c', $rLine], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes, $this->rDir);
		proc_close($rProcess);
		$rFile = $this->rDir . 'argv';
		return file_exists($rFile) ? explode("\0", (string) file_get_contents($rFile), -1) : [];
	}

	public static function furtherOutputs(): array {
		$rGpu = ['enable_transcode' => 1, 'transcode_profile_id' => 1, 'profile_options' => json_encode(['software_decoding' => 1, 'gpu' => ['val' => '1_0', 'cmd' => '', 'device' => 0], '-vcodec' => 'h264_nvenc', '-acodec' => 'aac'])];
		return [
			'an rtmp output' => [['rtmp_output' => 1], '-vcodec', ['rtmp://127.0.0.1:1935/live/42']],
			'an external push' => [['external_push' => json_encode([1 => ['rtmp://push.example/app/key?x=1&y=2']])], '-vcodec', ['rtmp://push.example/app/key?x=1&y=2']],
			'an rtmp output and a push' => [['rtmp_output' => 1, 'external_push' => json_encode([1 => ['rtmp://push.example/app/key']])], '-vcodec', ['rtmp://127.0.0.1:1935/live/42', 'rtmp://push.example/app/key']],
			'an rtmp output of a GPU profile' => [['rtmp_output' => 1] + $rGpu, '-gpu', ['rtmp://127.0.0.1:1935/live/42']],
		];
	}

	/**
	 * @param string       $rNext    The first option of the output after the tee.
	 * @param list<string> $rTargets The targets of the outputs after the tee, in order.
	 */
	#[DataProvider('furtherOutputs')]
	public function testAnOutputAfterTheTeeIsAnOutputOfItsOwn(array $rStreamInfo, string $rNext, array $rTargets): void {
		$rArgv = $this->received($rStreamInfo);
		$rTee = array_search('tee', $rArgv, true);
		$this->assertNotFalse($rTee, 'the program ran with a tee output');

		// The tee target is one argument and ends at the daemon's socket.
		$this->assertStringEndsWith(']unix:/run/ingest/42.sock', $rArgv[$rTee + 1]);
		$this->assertSame($rNext, $rArgv[$rTee + 2]);

		// Every further output keeps its own format and target.
		$rFound = [];
		foreach ($rArgv as $i => $rArgument) {
			if ($rArgument === 'no_duration_filesize') {
				$rFound[] = $rArgv[$i + 1];
			}
		}
		$this->assertSame($rTargets, $rFound);
		$this->assertSame(count($rTargets), count(array_keys($rArgv, 'flv', true)));
	}

	/** With no further output the line ends at the tee target's closing quote, as it always has. */
	public function testATeeOutputAloneEndsAtItsClosingQuote(): void {
		$rArgv = $this->received([]);
		$this->assertSame('tee', $rArgv[count($rArgv) - 2]);
		$this->assertStringEndsWith(']unix:/run/ingest/42.sock', (string) end($rArgv));
	}
}
