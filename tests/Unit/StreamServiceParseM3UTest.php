<?php

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for StreamService::parseM3U().
 *
 * Validates the parsing behaviour and the import array field extraction
 * logic that the stream import pipeline relies on.
 */
final class StreamServiceParseM3UTest extends TestCase {
	// ── helpers ────────────────────────────────────────────────────────────

	/** Shortcut: parse inline M3U string via StreamService. */
	private function parse(string $m3u): \M3uParser\M3uData {
		return StreamService::parseM3U($m3u, false);
	}

	/** Build import array from a single parsed entry (mirrors the logic in process()). */
	private function buildImportArray(\M3uParser\M3uData $data): ?array {
		foreach ($data as $result) {
			$tags = $result->getExtTags();
			$tag  = $tags[0] ?? null;
			$url  = $result->getPath();

			if (!$url) {
				return null;
			}

			return [
				'stream_source'       => [$url],
				'stream_icon'         => $tag ? ($tag->getAttribute('tvg-logo') ?: ($tag->getAttribute('logo') ?: '')) : '',
				'stream_display_name' => $tag
					? ($tag->getTitle() ?: basename(parse_url($url, PHP_URL_PATH) ?: $url))
					: basename(parse_url($url, PHP_URL_PATH) ?: $url),
			];
		}

		return null;
	}

	// ── parseM3U tests ─────────────────────────────────────────────────────

	public function testParsesBasicExtInfEntry(): void {
		$m3u = "#EXTM3U\n#EXTINF:-1 tvg-id=\"ch1\",Channel One\nhttp://example.com/stream.m3u8\n";

		$data = $this->parse($m3u);

		$this->assertCount(1, $data);
	}

	public function testParsesUrlCorrectly(): void {
		$m3u = "#EXTM3U\n#EXTINF:-1,Test\nhttp://example.com/live.m3u8\n";

		$data  = $this->parse($m3u);
		$entry = iterator_to_array($data)[0];

		$this->assertSame('http://example.com/live.m3u8', $entry->getPath());
	}

	public function testEmptyM3UReturnsEmptyResult(): void {
		$data = $this->parse("#EXTM3U\n");

		$this->assertCount(0, $data);
	}

	public function testParsesMultipleEntries(): void {
		$m3u = "#EXTM3U\n"
			. "#EXTINF:-1,Ch1\nhttp://example.com/1.m3u8\n"
			. "#EXTINF:-1,Ch2\nhttp://example.com/2.m3u8\n"
			. "#EXTINF:-1,Ch3\nhttp://example.com/3.m3u8\n";

		$data = $this->parse($m3u);

		$this->assertCount(3, $data);
	}

	// ── import array field extraction ──────────────────────────────────────

	public function testImportArrayContainsTvgLogo(): void {
		$m3u = "#EXTM3U\n#EXTINF:-1 tvg-logo=\"http://example.com/logo.png\",Channel\nhttp://example.com/stream.m3u8\n";

		$arr = $this->buildImportArray($this->parse($m3u));

		$this->assertSame('http://example.com/logo.png', $arr['stream_icon']);
	}

	public function testImportArrayFallsBackToLogoAttribute(): void {
		$m3u = "#EXTM3U\n#EXTINF:-1 logo=\"http://example.com/fallback.png\",Channel\nhttp://example.com/stream.m3u8\n";

		$arr = $this->buildImportArray($this->parse($m3u));

		$this->assertSame('http://example.com/fallback.png', $arr['stream_icon']);
	}

	public function testImportArrayIconEmptyWhenNoLogoAttributes(): void {
		$m3u = "#EXTM3U\n#EXTINF:-1,Channel\nhttp://example.com/stream.m3u8\n";

		$arr = $this->buildImportArray($this->parse($m3u));

		$this->assertSame('', $arr['stream_icon']);
	}

	public function testImportArrayUsesExtInfTitle(): void {
		$m3u = "#EXTM3U\n#EXTINF:-1,My Channel Name\nhttp://example.com/stream.m3u8\n";

		$arr = $this->buildImportArray($this->parse($m3u));

		$this->assertSame('My Channel Name', $arr['stream_display_name']);
	}

	public function testImportArrayFallsBackToUrlBasenameWhenNoTitle(): void {
		// No EXTINF — entry has no tags.
		$m3u = "#EXTM3U\nhttp://example.com/channel-name.m3u8\n";

		$arr = $this->buildImportArray($this->parse($m3u));

		$this->assertSame('channel-name.m3u8', $arr['stream_display_name']);
	}

	public function testImportArrayTvgLogoTakesPrecedenceOverLogo(): void {
		$m3u = "#EXTM3U\n#EXTINF:-1 tvg-logo=\"http://example.com/tvg.png\" logo=\"http://example.com/logo.png\",Channel\nhttp://example.com/stream.m3u8\n";

		$arr = $this->buildImportArray($this->parse($m3u));

		$this->assertSame('http://example.com/tvg.png', $arr['stream_icon']);
	}
}
