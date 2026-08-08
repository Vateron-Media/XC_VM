<?php

use PHPUnit\Framework\TestCase;
use XcVm\Streaming\Delivery\SegmentReader;

/**
 * Unit tests for SegmentReader::selectSegments() — the duration-based prebuffer
 * selection. Guards the fix where an on-demand stream's short (2s) fast-start
 * segments used to yield only intval(prebuffer / seg_time) = 1 segment (~2s of
 * buffer) instead of prebuffer seconds' worth.
 */
final class SegmentReaderTest extends TestCase {

	/** @param array<int,array{0:int,1:float}> $segs [num, duration] pairs */
	private function playlist(array $segs, bool $withExtinf = true): string {
		$out = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:10\n#EXT-X-MEDIA-SEQUENCE:{$segs[0][0]}\n";
		foreach ($segs as [$num, $dur]) {
			if ($withExtinf) {
				$out .= sprintf("#EXTINF:%.6f,\n", $dur);
			}
			$out .= "564_{$num}.ts\n";
		}
		return $out;
	}

	// ── the fix: short on-demand segments ──────────────────────

	public function testShortStartupSegmentsGiveFullSecondsNotOneSegment(): void {
		// 5x 2s segments; prebuffer 10s. Old code: intval(10/10)=1 -> 1 seg (2s).
		$pl = $this->playlist([[1, 2.0], [2, 2.0], [3, 2.0], [4, 2.0], [5, 2.0]]);
		$out = SegmentReader::selectSegments($pl, 10, 10);
		$this->assertSame(['564_1.ts', '564_2.ts', '564_3.ts', '564_4.ts', '564_5.ts'], $out);
	}

	public function testPrebufferCappedByAvailableSegments(): void {
		// Want 100s but only 10s exists -> return everything, no error.
		$pl = $this->playlist([[1, 2.0], [2, 2.0], [3, 2.0], [4, 2.0], [5, 2.0]]);
		$this->assertCount(5, SegmentReader::selectSegments($pl, 100, 10));
	}

	// ── normal 10s segments ────────────────────────────────────

	public function testTenSecondSegmentsCountByDuration(): void {
		$pl = $this->playlist([[1, 10.0], [2, 10.0], [3, 10.0], [4, 10.0], [5, 10.0], [6, 10.0]]);
		$this->assertSame(['564_6.ts'], SegmentReader::selectSegments($pl, 10, 10));
		$this->assertSame(['564_4.ts', '564_5.ts', '564_6.ts'], SegmentReader::selectSegments($pl, 25, 10));
	}

	public function testMixedRampDurations(): void {
		// 567-style ramp: 2,2,2,2,8,10. Want >=15s from the end: 10+8=18 -> 2 segs.
		$pl = $this->playlist([[1, 2.0], [2, 2.0], [3, 2.0], [4, 2.0], [5, 8.0], [6, 10.0]]);
		$this->assertSame(['564_5.ts', '564_6.ts'], SegmentReader::selectSegments($pl, 15, 10));
	}

	// ── fallbacks / other modes ────────────────────────────────

	public function testCountFallbackWhenNoExtinf(): void {
		$pl = $this->playlist([[1, 0], [2, 0], [3, 0]], false); // bare .ts lines
		$this->assertSame(['564_3.ts'], SegmentReader::selectSegments($pl, 10, 10)); // intval(10/10)=1
		$this->assertSame(['564_1.ts', '564_2.ts', '564_3.ts'], SegmentReader::selectSegments($pl, 30, 10));
	}

	public function testMinusOneReturnsAll(): void {
		$pl = $this->playlist([[1, 10.0], [2, 10.0]]);
		$this->assertSame(['564_1.ts', '564_2.ts'], SegmentReader::selectSegments($pl, -1, 10));
	}

	public function testZeroReturnsLastSegmentIndex(): void {
		$pl = $this->playlist([[1, 10.0], [2, 10.0], [7, 10.0]]);
		$this->assertSame('7', SegmentReader::selectSegments($pl, 0, 10));
	}

	public function testNoSegmentsReturnsNull(): void {
		$this->assertNull(SegmentReader::selectSegments("#EXTM3U\n#EXT-X-VERSION:3\n", 10, 10));
	}

	// ── playlistBufferedSeconds (on-demand prebuffer gate) ─────

	public function testPlaylistBufferedSecondsSumsExtinf(): void {
		// 567-style ramp: 2+2+8+10 = 22s buffered.
		$tmp = tempnam(sys_get_temp_dir(), 'xcvmpl');
		file_put_contents($tmp, $this->playlist([[1, 2.0], [2, 2.0], [3, 8.0], [4, 10.0]]));
		$this->assertEqualsWithDelta(22.0, SegmentReader::playlistBufferedSeconds($tmp), 0.001);
		unlink($tmp);
	}

	public function testPlaylistBufferedSecondsMissingOrNoExtinfIsZero(): void {
		$this->assertSame(0.0, SegmentReader::playlistBufferedSeconds('/nonexistent/xcvm-none.m3u8'));
		$tmp = tempnam(sys_get_temp_dir(), 'xcvmpl');
		file_put_contents($tmp, $this->playlist([[1, 0], [2, 0]], false)); // bare .ts, no #EXTINF
		$this->assertSame(0.0, SegmentReader::playlistBufferedSeconds($tmp));
		unlink($tmp);
	}
}
