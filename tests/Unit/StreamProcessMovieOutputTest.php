<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\StreamProcess;

/**
 * Unit tests for the VOD output helpers extracted from startMovie:
 * resolveOutputMap() and subtitleCodecForContainer().
 */
final class StreamProcessMovieOutputTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1);
		}
		if (!defined('STREAMS_PATH')) {
			define('STREAMS_PATH', '/tmp/xcvm-test-streams/');
		}
	}

	/** Invoke a private static StreamProcess method via reflection. */
	private function call(string $method, ...$args) {
		$m = new ReflectionMethod(StreamProcess::class, $method);
		$m->setAccessible(true);
		return $m->invoke(null, ...$args);
	}

	// ── resolveOutputMap ───────────────────────────────────────

	public function testDefaultMapCopiesEverything(): void {
		$this->assertSame('-map 0 -copy_unknown ', $this->call('resolveOutputMap', '', 0));
		$this->assertSame('-map 0 -copy_unknown ', $this->call('resolveOutputMap', null, 0));
	}

	public function testCustomMapWins(): void {
		$this->assertSame(
			'-map 0:1 -map 0:0 -copy_unknown ',
			$this->call('resolveOutputMap', '-map 0:1 -map 0:0', 0)
		);
	}

	public function testRemoveSubtitlesWhenNoCustomMap(): void {
		$this->assertSame('-map 0:a -map 0:v', $this->call('resolveOutputMap', '', 1));
	}

	public function testCustomMapTakesPriorityOverRemoveSubtitles(): void {
		// custom map set AND remove_subtitles == 1 → the custom map still wins.
		$this->assertSame('-map 0:v -copy_unknown ', $this->call('resolveOutputMap', '-map 0:v', 1));
	}

	// ── subtitleCodecForContainer ──────────────────────────────

	public function testSubtitleCodecPerContainer(): void {
		$this->assertSame('mov_text', $this->call('subtitleCodecForContainer', 'mp4'));
		$this->assertSame('srt', $this->call('subtitleCodecForContainer', 'mkv'));
		$this->assertSame('copy', $this->call('subtitleCodecForContainer', 'ts'));
		$this->assertSame('copy', $this->call('subtitleCodecForContainer', ''));
	}

	// ── resolveChannelSource ───────────────────────────────────

	public function testPlainSourceIsLocal(): void {
		[$id, $path] = $this->call('resolveChannelSource', '/movies/a.mp4', []);
		$this->assertSame(SERVER_ID, $id);
		$this->assertSame('/movies/a.mp4', $path);
	}

	public function testLocalServerReferenceIsNotRewritten(): void {
		[$id, $path] = $this->call('resolveChannelSource', 's:' . SERVER_ID . ':/movies/a.mp4', []);
		$this->assertSame(SERVER_ID, $id);
		$this->assertSame('/movies/a.mp4', $path);
	}

	public function testRemoteServerRewritesToGetFileApiUrl(): void {
		$servers = [2 => ['api_url' => 'http://node2/api?k=1']];
		[$id, $path] = $this->call('resolveChannelSource', 's:2:/movies/a.mp4', $servers);
		$this->assertSame(2, $id);
		$this->assertStringStartsWith('http://node2/api?k=1&action=getFile&filename=', $path);
		$this->assertStringContainsString(urlencode('/movies/a.mp4'), $path);
	}

	public function testRemoteServerMissingKeepsRawPath(): void {
		[$id, $path] = $this->call('resolveChannelSource', 's:9:/movies/a.mp4', []);
		$this->assertSame(9, $id);
		$this->assertSame('/movies/a.mp4', $path);
	}

	public function testColonsInPathArePreserved(): void {
		// explode(':', …, 3) keeps everything after the second colon intact.
		[$id, $path] = $this->call('resolveChannelSource', 's:9:http://host/a:b:c', []);
		$this->assertSame(9, $id);
		$this->assertSame('http://host/a:b:c', $path);
	}

	// ── buildHlsMpegtsOutput ───────────────────────────────────

	public function testHlsMpegtsOutputAssembly(): void {
		$seg = ['seg_time' => 6, 'seg_list_size' => 8, 'seg_delete_threshold' => 4];
		$out = $this->call('buildHlsMpegtsOutput', '{MAP} {LLOD}', $seg, '+split_by_time', 2, 569);

		$this->assertStringStartsWith('{MAP} {LLOD} ', $out);
		$this->assertStringContainsString('-hls_init_time 2 ', $out);
		$this->assertStringContainsString('-hls_time 6 ', $out);
		$this->assertStringContainsString('-hls_list_size 8 ', $out);
		$this->assertStringContainsString('-hls_delete_threshold 4 ', $out);
		$this->assertStringContainsString('-hls_flags delete_segments+discont_start+omit_endlist+split_by_time', $out);
		$this->assertStringContainsString('-hls_segment_type mpegts', $out);
		$this->assertStringContainsString(STREAMS_PATH . '569_%d.ts', $out);
		$this->assertStringContainsString(STREAMS_PATH . '569_.m3u8', $out);
	}

	public function testHlsOutputWithoutKeyframeFlag(): void {
		$seg = ['seg_time' => 10, 'seg_list_size' => 6, 'seg_delete_threshold' => 4];
		$out = $this->call('buildHlsMpegtsOutput', '{MAP}', $seg, '', 2, 1);
		// empty keyframe flag → nothing appended after omit_endlist.
		$this->assertStringContainsString('omit_endlist -hls_segment_type', $out);
	}

	// ── buildFlvOutput ─────────────────────────────────────────

	public function testFlvOutputWrapsRtmpTarget(): void {
		$out = $this->call('buildFlvOutput', '{MAP} {AAC_FILTER}', 'rtmp://127.0.0.1:1935/live/5?password=x');
		$this->assertSame(
			'{MAP} {AAC_FILTER} -f flv -flvflags no_duration_filesize rtmp://127.0.0.1:1935/live/5?password=x ',
			$out
		);
	}

	public function testFlvOutputWrapsPushTarget(): void {
		$out = $this->call('buildFlvOutput', '{MAP} {AAC_FILTER}', escapeshellarg('rtmp://push.example/app'));
		$this->assertSame(
			"{MAP} {AAC_FILTER} -f flv -flvflags no_duration_filesize 'rtmp://push.example/app' ",
			$out
		);
	}

	// ── resolveProbeSettings (first startStream extraction) ────

	private const PROBE_SETTINGS = ['stream_max_analyze' => 5000000, 'probesize' => 3000000, 'probe_extra_wait' => 5];

	public function testProbeSettingsOnDemandLlod(): void {
		[$probe, $analyze, $timeout] = $this->call('resolveProbeSettings', 1, 2000000, true, self::PROBE_SETTINGS);
		$this->assertSame(2000000, $probe);
		$this->assertSame('500000', $analyze);
		$this->assertSame(5, $timeout); // intval(500000/1e6)=0 + 5
	}

	public function testProbeSettingsOnDemandDefaultProbesize(): void {
		[$probe, $analyze, $timeout] = $this->call('resolveProbeSettings', 1, 0, false, self::PROBE_SETTINGS);
		$this->assertSame(1000000, $probe); // intval(0) ?: 1000000
		$this->assertSame('10000000', $analyze);
		$this->assertSame(15, $timeout); // intval(1e7/1e6)=10 + 5
	}

	public function testProbeSettingsGlobalWhenNotOnDemand(): void {
		[$probe, $analyze, $timeout] = $this->call('resolveProbeSettings', 0, 999, true, self::PROBE_SETTINGS);
		$this->assertSame(3000000, $probe);
		$this->assertSame(5000000, $analyze); // abs(intval(...))
		$this->assertSame(10, $timeout); // intval(5e6/1e6)=5 + 5
	}

	// ── rotateSourcesPastCurrent (source failover ordering) ────

	public function testPriorityBackupKeepsOrder(): void {
		$this->assertSame(['a', 'b', 'c'], $this->call('rotateSourcesPastCurrent', ['a', 'b', 'c'], 1, 'b'));
	}

	public function testEmptyCurrentKeepsOrder(): void {
		$this->assertSame(['a', 'b', 'c'], $this->call('rotateSourcesPastCurrent', ['a', 'b', 'c'], 0, ''));
	}

	public function testUnknownCurrentKeepsOrder(): void {
		$this->assertSame(['a', 'b', 'c'], $this->call('rotateSourcesPastCurrent', ['a', 'b', 'c'], 0, 'zzz'));
	}

	public function testCurrentInMiddleRotatesTriedToEnd(): void {
		// current 'b' (idx 1): 'c','d' lead, tried 'a','b' become fallbacks.
		$this->assertSame(['c', 'd', 'a', 'b'], $this->call('rotateSourcesPastCurrent', ['a', 'b', 'c', 'd'], 0, 'b'));
	}

	public function testCurrentFirstMovesItLast(): void {
		$this->assertSame(['b', 'c', 'd', 'a'], $this->call('rotateSourcesPastCurrent', ['a', 'b', 'c', 'd'], 0, 'a'));
	}

	public function testCurrentLastLeavesOrderUnchanged(): void {
		$this->assertSame(['a', 'b', 'c', 'd'], $this->call('rotateSourcesPastCurrent', ['a', 'b', 'c', 'd'], 0, 'd'));
	}

	// ── appendHeaderArgument (startStream header injection) ─────

	public function testHeaderAppendedToExistingHeadersArg(): void {
		$args = [
			['argument_key' => 'user_agent', 'value' => 'VLC'],
			['argument_key' => 'headers', 'value' => 'X-A:1'],
		];
		$out = $this->call('appendHeaderArgument', $args, 'X-XC_VM-Detect:1');
		$this->assertCount(2, $out, 'appended, not added');
		$this->assertSame("X-A:1\r\nX-XC_VM-Detect:1", $out[1]['value']);
		$this->assertSame('VLC', $out[0]['value'], 'other args untouched');
	}

	public function testHeaderArgCreatedWhenAbsent(): void {
		$args = [['argument_key' => 'user_agent', 'value' => 'VLC']];
		$out = $this->call('appendHeaderArgument', $args, 'X-XC_VM-Prebuffer:1');
		$this->assertCount(2, $out);
		$new = $out[array_key_last($out)];
		$this->assertSame('headers', $new['argument_key']);
		$this->assertSame('X-XC_VM-Prebuffer:1', $new['value']);
		$this->assertSame('fetch', $new['argument_cat']);
		$this->assertSame("-headers '%s\r\n'", $new['argument_cmd']);
	}

	public function testHeaderAppendedToEveryHeadersArg(): void {
		// mirrors the original loop: every 'headers' entry receives the line.
		$args = [
			['argument_key' => 'headers', 'value' => 'A'],
			['argument_key' => 'headers', 'value' => 'B'],
		];
		$out = $this->call('appendHeaderArgument', $args, 'X:1');
		$this->assertSame("A\r\nX:1", $out[0]['value']);
		$this->assertSame("B\r\nX:1", $out[1]['value']);
		$this->assertCount(2, $out, 'no new arg added');
	}

	public function testEmptyArgumentsGetsNewHeaderArg(): void {
		$out = $this->call('appendHeaderArgument', [], 'X:1');
		$this->assertCount(1, $out);
		$this->assertSame('headers', $out[0]['argument_key']);
		$this->assertSame('X:1', $out[0]['value']);
	}

	// ── resolveStreamCodecMeta (startStream codec metadata) ────

	public function testCodecMetaNonArrayReturnsDefaults(): void {
		$this->assertSame([0, null, null, null], $this->call('resolveStreamCodecMeta', null, false));
		$this->assertSame([0, null, null, null], $this->call('resolveStreamCodecMeta', 'not-json', false));
	}

	public function testCodecMetaWithoutCodecsKey(): void {
		$this->assertSame([0, null, null, null], $this->call('resolveStreamCodecMeta', ['container' => 'mpegts'], false));
	}

	public function testCodecMetaCompatibleH264Aac(): void {
		$probe = ['codecs' => [
			'video' => ['codec_name' => 'h264', 'height' => 1080],
			'audio' => ['codec_name' => 'aac'],
		]];
		[$compat, $audio, $video, $res] = $this->call('resolveStreamCodecMeta', $probe, false);
		$this->assertSame(1, $compat);
		$this->assertSame('aac', $audio);
		$this->assertSame('h264', $video);
		$this->assertSame(1080, $res);
	}

	public function testCodecMetaResolutionSnapsToNearest(): void {
		$probe = ['codecs' => ['video' => ['codec_name' => 'h264', 'height' => 700]]];
		[, , , $res] = $this->call('resolveStreamCodecMeta', $probe, false);
		$this->assertSame(720, $res); // 700 -> nearest of 240..2160
	}

	public function testCodecMetaHevcGatedByAllowFlag(): void {
		$probe = ['codecs' => [
			'video' => ['codec_name' => 'hevc', 'height' => 2160],
			'audio' => ['codec_name' => 'ac3'],
		]];
		$this->assertSame(0, $this->call('resolveStreamCodecMeta', $probe, false)[0]); // hevc not allowed
		$this->assertSame(1, $this->call('resolveStreamCodecMeta', $probe, true)[0]);  // hevc allowed
	}

	public function testCodecMetaIncompatibleStillReportsCodecs(): void {
		$probe = ['codecs' => [
			'video' => ['codec_name' => 'mpeg2video', 'height' => 576],
			'audio' => ['codec_name' => 'aac'],
		]];
		[$compat, $audio, $video, $res] = $this->call('resolveStreamCodecMeta', $probe, false);
		$this->assertSame(0, $compat);        // mpeg2video not whitelisted
		$this->assertSame('mpeg2video', $video);
		$this->assertSame('aac', $audio);
		$this->assertSame(576, $res);
	}
}
