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
}
