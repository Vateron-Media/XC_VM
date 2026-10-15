<?php

use PHPUnit\Framework\TestCase;
use XcVm\Streaming\Codec\FFprobeRunner;
use XcVm\Core\Cluster\DivergenceSink;

/**
 * An adaptive / multi-variant source (an HLS master playlist) must not poison
 * the stored stream info: the probe keeps the TOP rendition's resolution, and
 * drops the aggregate format.bit_rate so the per-viewer divergence check is
 * disabled (an unknown bitrate => expected() 0 => no false "below bitrate"
 * flag on a viewer that only ever pulls one rendition).
 */
final class AdaptiveProbeDivergenceTest extends TestCase {

	/** Raw ffprobe JSON for a master playlist: three video renditions + one audio, aggregate format bitrate. */
	private static function masterProbe(): array {
		return [
			'format' => ['format_name' => 'hls', 'filename' => 'master.m3u8', 'bit_rate' => 8400000, 'duration' => 0],
			'streams' => [
				['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 640, 'height' => 360],
				['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720],
				['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1920, 'height' => 1080],
				['codec_type' => 'audio', 'codec_name' => 'aac'],
			],
		];
	}

	public function testMasterPlaylistKeepsTheTopRenditionAndDropsAggregateBitrate(): void {
		$rOut = FFprobeRunner::parseFFProbe(self::masterProbe());
		$this->assertSame(1080, $rOut['codecs']['video']['height'], 'keeps the highest rendition, not the first (640x360)');
		$this->assertSame('aac', $rOut['codecs']['audio']['codec_name']);
		$this->assertTrue($rOut['adaptive'] ?? false, 'flagged adaptive');
		$this->assertNull($rOut['bitrate'], 'the aggregate format.bit_rate is dropped');
	}

	public function testHighestRenditionWinsRegardlessOfListOrder(): void {
		$rProbe = self::masterProbe();
		$rProbe['streams'] = array_reverse($rProbe['streams']); // 1080 listed first
		$rOut = FFprobeRunner::parseFFProbe($rProbe);
		$this->assertSame(1080, $rOut['codecs']['video']['height']);
	}

	public function testSingleVariantKeepsItsBitrateAndIsNotAdaptive(): void {
		$rOut = FFprobeRunner::parseFFProbe([
			'format' => ['format_name' => 'mpegts', 'filename' => 'x.ts', 'bit_rate' => 3000000, 'duration' => 0],
			'streams' => [
				['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1920, 'height' => 1080],
				['codec_type' => 'audio', 'codec_name' => 'aac'],
			],
		]);
		$this->assertSame(1080, $rOut['codecs']['video']['height']);
		$this->assertArrayNotHasKey('adaptive', $rOut, 'a single video stream is not adaptive');
		$this->assertSame(3000000, $rOut['bitrate'], 'single-variant bitrate is kept');
	}

	public function testUnknownBitrateDisablesDivergence(): void {
		// A dropped (null -> 0) bitrate yields expected()==0, which of() treats as "no flag".
		$this->assertSame(0, DivergenceSink::expected(0));
		$this->assertSame(0, DivergenceSink::of(500, DivergenceSink::expected(0)), 'healthy viewer is not flagged when the bitrate is unknown');
	}

	public function testRealBitrateStillFlagsAStarvedViewer(): void {
		// Guard: the fix must not neuter divergence for a normal single-variant stream.
		$rExpected = DivergenceSink::expected(8000); // 8000 kbit/s -> 920 KiB/s
		$this->assertGreaterThan(0, DivergenceSink::of(500, $rExpected), 'a genuinely slow viewer still diverges');
	}
}
