<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\StreamProcess;

/**
 * The pure parts of handing a live stream to the xc_fanout supervisor: the
 * native remuxer command (buildNativeLive), which streams may use it
 * (isNativeEligible / isNativeSource), the policy and health the PHP monitor
 * obeyed (supervisorPolicy / supervisorHealth), and what a supervisor state
 * means for streams_servers (supervisedRowUpdate).
 */
final class StreamProcessSupervisionTest extends TestCase {

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

	private static function call(string $rMethod, ...$rArgs) {
		$m = new ReflectionMethod(StreamProcess::class, $rMethod);
		$m->setAccessible(true);
		return $m->invoke(null, ...$rArgs);
	}

	private function native(array $rOverrides = []): string {
		return self::call('buildNativeLive', array_merge([
			'streamID' => 42,
			'source' => 'http://src.example/live/u/p/9.ts',
			'arguments' => [],
			'segmentSettings' => ['seg_time' => 6, 'seg_list_size' => 8, 'seg_delete_threshold' => 4],
			'ingestSock' => '/run/fanout/ingest/42.sock',
			'settings' => ['ffmpeg_warnings' => 0, 'fanout_source_insecure' => 1],
			'binary' => '/home/xc_vm/bin/xc_fanout/xc_fanout',
		], $rOverrides));
	}

	// ── buildNativeLive ───────────────────────────────────────────

	public function testNativeCommandShape(): void {
		$c = $this->native();
		$this->assertStringStartsWith('/home/xc_vm/bin/xc_fanout/xc_fanout remux ', $c);
		$this->assertStringContainsString("-i 'http://src.example/live/u/p/9.ts'", $c);
		$this->assertStringContainsString("-ingest 'unix:/run/fanout/ingest/42.sock'", $c);
		$this->assertStringContainsString('-hls_time 6 ', $c);
		$this->assertStringContainsString('-hls_init_time 2 ', $c, "buildLive's fast first segment");
		$this->assertStringContainsString('-hls_list_size 8 ', $c);
		$this->assertStringContainsString('-hls_delete_threshold 4 ', $c);
		$this->assertStringContainsString("-progress '" . STREAMS_PATH . "42_.progress'", $c);
		$this->assertStringContainsString("-hls_segment_filename '" . STREAMS_PATH . "42_%d.ts'", $c);
		$this->assertStringEndsWith("'" . STREAMS_PATH . "42_.m3u8'", $c, 'the playlist is the one positional argument, last');
	}

	/** The daemon launches it: no redirect, background or pid tail may ride along. */
	public function testNativeCommandHasNoLaunchTail(): void {
		$c = $this->native();
		$this->assertStringNotContainsString('>', $c);
		$this->assertStringNotContainsString('&', $c);
		$this->assertStringNotContainsString('echo', $c);
	}

	/** The same fetch identity the daemon's own puller uses for the stream. */
	public function testNativeCommandCarriesFetchArguments(): void {
		$c = $this->native(['arguments' => [
			'user_agent' => ['value' => 'VLC/3.0', 'argument_default_value' => 'Mozilla/5.0'],
			'proxy' => ['value' => '10.0.0.1:3128'],
			'cookie' => ['value' => 'a=b'],
			'headers' => ['value' => "X-A: 1\r\nX-B: 2\r\n"],
		]]);
		$this->assertStringContainsString("-user_agent 'VLC/3.0'", $c);
		$this->assertStringContainsString("-http_proxy '10.0.0.1:3128'", $c);
		$this->assertStringContainsString('-cookies ', $c);
		$this->assertStringContainsString("-headers 'X-A: 1\r\nX-B: 2\r\n'", $c);

		$this->assertStringContainsString("-user_agent 'Mozilla/5.0'", $this->native(), 'no UA set: the puller default');
	}

	public function testNativeInsecureFollowsTheSetting(): void {
		$this->assertStringContainsString(' -insecure ', $this->native());
		$this->assertStringNotContainsString('-insecure', $this->native(['settings' => ['fanout_source_insecure' => 0]]));
	}

	/** A source URL is shell data: quotes in it must not escape the argument. */
	public function testNativeSourceIsShellEscaped(): void {
		$c = $this->native(['source' => "http://x/a'; rm -rf /;'.ts"]);
		$this->assertStringContainsString("-i 'http://x/a'\\''; rm -rf /;'\\''.ts'", $c);
	}

	// ── eligibility ───────────────────────────────────────────────

	private function plainStream(array $rOverrides = []): array {
		return array_merge([
			'type_key' => 'live_streams',
			'enable_transcode' => 0,
			'custom_ffmpeg' => '',
			'custom_map' => '',
			'rtmp_output' => 0,
			'external_push' => '',
			'gen_timestamps' => 0,
			'read_native' => 0,
		], $rOverrides);
	}

	public function testPlainCopyStreamIsNativeEligible(): void {
		$this->assertTrue(self::call('isNativeEligible', $this->plainStream(), []));
	}

	public function testAnythingNeedingFfmpegIsNotNativeEligible(): void {
		foreach ([
			'transcode' => ['enable_transcode' => 1],
			'custom ffmpeg' => ['custom_ffmpeg' => '-i x -c:v libx264 y'],
			'custom map' => ['custom_map' => '-map 0:0'],
			'rtmp output' => ['rtmp_output' => 1],
			'external push here' => ['external_push' => json_encode([1 => ['rtmp://push/x']])],
			'timestamp repair' => ['gen_timestamps' => 1],
			'realtime pacing' => ['read_native' => 1],
			'radio' => ['type_key' => 'radio_streams'],
			'created channel' => ['type_key' => 'created_live'],
		] as $rWhy => $rOverride) {
			$this->assertFalse(self::call('isNativeEligible', $this->plainStream($rOverride), []), $rWhy);
		}
		$this->assertTrue(self::call('isNativeEligible', $this->plainStream(['external_push' => json_encode([7 => ['rtmp://push/x']])]), []), "another server's push is not this one's");
		$this->assertFalse(self::call('isNativeEligible', $this->plainStream(), ['force_input_acodec' => ['value' => 'ac3']]), 'forced input codec');
	}

	public function testNativeSources(): void {
		foreach (['http://h/x.ts', 'https://h/x.m3u8', 'udp://239.0.0.1:1234', 'rtp://239.0.0.1:5000'] as $rURL) {
			$this->assertTrue(self::call('isNativeSource', $rURL), $rURL);
		}
		foreach (['rtmp://h/app/s', 'srt://h:9000', '/srv/film.mp4', 'https://www.youtube.com/watch?v=x'] as $rURL) {
			$this->assertFalse(self::call('isNativeSource', $rURL), $rURL);
		}
	}

	// ── policy / health ───────────────────────────────────────────

	public function testHealthMirrorsThePhpMonitor(): void {
		$h = self::call('supervisorHealth', ['fps_restart' => 1, 'fps_threshold' => 90, 'auto_restart' => json_encode(['days' => ['Monday'], 'at' => '04:30'])], ['seg_time' => 10, 'audio_restart_loss' => 1, 'fps_delay' => 60]);
		$this->assertSame(60, $h['stall_sec'], 'seg_time × 6');
		$this->assertSame(30, $h['audio_loss_sec']);
		$this->assertEqualsWithDelta(0.9, $h['fps_threshold'], 1e-9, '"FPS Threshold %" is a percentage; the daemon takes a fraction');
		$this->assertSame(60, $h['fps_grace_sec']);
		$this->assertSame(['days' => ['Monday'], 'at' => '04:30'], $h['auto_restart']);

		$off = self::call('supervisorHealth', ['fps_restart' => 0, 'fps_threshold' => 90, 'auto_restart' => ''], ['seg_time' => 10]);
		$this->assertSame(0, $off['fps_threshold'], 'fps_restart off');
		$this->assertSame(0, $off['audio_loss_sec']);
		$this->assertArrayNotHasKey('auto_restart', $off);
	}

	public function testPolicyMirrorsThePhpMonitor(): void {
		$p = self::call('supervisorPolicy', ['on_demand' => 1, 'parent_id' => 0], ['stop_failures' => 3, 'stream_fail_sleep' => 7, 'on_demand_failure_exit' => 1, 'priority_backup' => 1, 'seg_time' => 10], 2, 5);
		$this->assertSame(3, $p['stop_failures']);
		$this->assertSame(7, $p['stream_fail_sleep']);
		$this->assertTrue($p['on_demand']);
		$this->assertTrue($p['on_demand_failure_exit']);
		$this->assertSame(300, $p['priority_backup_sec']);
		$this->assertSame(5 + 30, $p['start_timeout_sec'], 'probe window + the playlist wait');

		$one = self::call('supervisorPolicy', ['on_demand' => 0, 'parent_id' => 0], ['priority_backup' => 1, 'seg_time' => 10], 1, 5);
		$this->assertSame(0, $one['priority_backup_sec'], 'nothing to switch back to with one source');
		$loop = self::call('supervisorPolicy', ['on_demand' => 0, 'parent_id' => 3], ['priority_backup' => 1, 'seg_time' => 10], 2, 5);
		$this->assertSame(0, $loop['priority_backup_sec'], 'a loopback has no sources of its own');
	}

	// ── reconcile ─────────────────────────────────────────────────

	private function row(array $rOverrides = []): array {
		return array_merge([
			'pid' => null, 'monitor_pid' => 900, 'stream_status' => 2, 'current_source' => 'http://a/1.ts',
			'stream_started' => 1000, 'stream_info' => null, 'audio_codec' => null, 'video_codec' => null,
			'resolution' => null, 'bitrate' => null, 'compatible' => 0,
		], $rOverrides);
	}

	private function update(array $rRow, array $rState): array {
		return self::call('supervisedRowUpdate', $rRow, array_merge(['daemon_pid' => 900], $rState), false, 2000);
	}

	public function testConfirmedStartIsUpAndDated(): void {
		$set = $this->update($this->row(), ['running' => true, 'confirmed' => true, 'pid' => 4321, 'uptime_ms' => 5000, 'source' => 'http://a/1.ts']);
		$this->assertSame(0, $set['stream_status']);
		$this->assertSame(4321, $set['pid']);
		$this->assertSame(1995, $set['stream_started'], 'when the running producer came up');
		$this->assertArrayNotHasKey('current_source', $set, 'unchanged columns are not rewritten');
	}

	public function testStatusFollowsThePhpMonitorsMeaning(): void {
		$this->assertSame(2, $this->update($this->row(['stream_status' => 0]), ['running' => true, 'confirmed' => false, 'pid' => 5])['stream_status'], 'launched, not confirmed');
		$this->assertSame(1, $this->update($this->row(), ['running' => false, 'failures' => 2])['stream_status'], 'between failed starts');
		$this->assertSame(1, $this->update($this->row(), ['running' => false, 'gave_up' => true])['stream_status'], 'gave up');
		$this->assertArrayNotHasKey('stream_status', $this->update($this->row(), ['running' => false, 'failures' => 0]), 'a first start still pending stays "starting"');
		$this->assertNull($this->update($this->row(['pid' => 77, 'stream_status' => 0]), ['running' => false, 'failures' => 1])['pid'], 'nothing running, no pid');
	}

	public function testMetadataFromTheBytes(): void {
		$set = $this->update($this->row(), ['running' => true, 'confirmed' => true, 'pid' => 1, 'meta' => ['video_codec' => 'h264', 'audio_codec' => 'aac', 'height' => 1088, 'bitrate_kbps' => 4500]]);
		$this->assertSame('h264', $set['video_codec']);
		$this->assertSame('aac', $set['audio_codec']);
		$this->assertSame(1, $set['compatible']);
		$this->assertSame(1080, $set['resolution'], 'snapped to the nearest standard height');
		$this->assertSame(4500, $set['bitrate']);
	}

	/** Unknown is left unknown: a correct value is never overwritten with a blank. */
	public function testUnknownMetadataKeepsWhatThePanelHas(): void {
		$rRow = $this->row(['video_codec' => 'hevc', 'audio_codec' => 'ac3', 'resolution' => 2160, 'bitrate' => 9000, 'stream_status' => 0, 'pid' => 1]);
		$set = $this->update($rRow, ['running' => true, 'confirmed' => true, 'pid' => 1, 'meta' => []]);
		foreach (['video_codec', 'audio_codec', 'resolution', 'bitrate', 'compatible'] as $rCol) {
			$this->assertArrayNotHasKey($rCol, $set, $rCol);
		}
		$audioOnly = $this->update($rRow, ['running' => true, 'confirmed' => true, 'pid' => 1, 'meta' => ['audio_codec' => 'aac']]);
		$this->assertArrayNotHasKey('video_codec', $audioOnly, 'a known video codec is not blanked by an audio-only reading');
	}

	public function testNothingChangedWritesNothing(): void {
		$rRow = $this->row(['pid' => 4321, 'stream_status' => 0]);
		$this->assertSame([], $this->update($rRow, ['running' => true, 'confirmed' => true, 'pid' => 4321, 'source' => 'http://a/1.ts']));
	}
}
