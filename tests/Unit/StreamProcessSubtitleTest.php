<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\StreamProcess;

/**
 * Unit tests for StreamProcess::buildSubtitleImport() — the VOD subtitle
 * import/metadata builder extracted from startMovie.
 *
 * Guards the fix for the nested-loop $i reuse that imported only the first
 * subtitle while emitting -map metadata for every file.
 */
final class StreamProcessSubtitleTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1);
		}
	}

	/**
	 * Invoke the private static buildSubtitleImport() via reflection.
	 *
	 * @return array{0:string,1:string} [$import, $metadata]
	 */
	private function build(string $json, array $servers = []): array {
		$m = new ReflectionMethod(StreamProcess::class, 'buildSubtitleImport');
		$m->setAccessible(true);
		return $m->invoke(null, $json, $servers);
	}

	public function testNoSubtitlesReturnsEmptyStrings(): void {
		[$import, $meta] = $this->build('');
		$this->assertSame('', $import);
		$this->assertSame('', $meta);

		[$import, $meta] = $this->build('{"files":[]}');
		$this->assertSame('', $import);
		$this->assertSame('', $meta);
	}

	public function testSingleLocalSubtitleImportedAndMapped(): void {
		$json = json_encode([
			'location' => SERVER_ID,
			'files'    => ['/subs/en.srt'],
			'charset'  => ['UTF-8'],
			'names'    => ['English'],
		]);
		[$import, $meta] = $this->build($json);

		$this->assertSame(1, substr_count($import, '-sub_charenc'));
		$this->assertStringContainsString('/subs/en.srt', $import);
		$this->assertSame(1, substr_count($meta, '-map '));
		$this->assertStringContainsString('-map 1 ', $meta);
		$this->assertStringContainsString('title=English', $meta);
	}

	/**
	 * The bug fix: every subtitle must be imported AND mapped. The old nested
	 * loop imported only the first file while mapping all of them, so this
	 * previously failed (import count was 1, not 3).
	 */
	public function testAllSubtitlesAreImportedAndMapped(): void {
		$json = json_encode([
			'location' => SERVER_ID,
			'files'    => ['/subs/en.srt', '/subs/fr.srt', '/subs/de.srt'],
			'charset'  => ['UTF-8', 'UTF-8', 'UTF-8'],
			'names'    => ['English', 'French', 'German'],
		]);
		[$import, $meta] = $this->build($json);

		// All three imported (the old nested-loop code produced only one).
		$this->assertSame(3, substr_count($import, '-sub_charenc'), 'all subtitles imported');
		$this->assertStringContainsString('/subs/en.srt', $import);
		$this->assertStringContainsString('/subs/fr.srt', $import);
		$this->assertStringContainsString('/subs/de.srt', $import);

		// Each mapped to its own input index 1..N.
		$this->assertSame(3, substr_count($meta, '-map '));
		$this->assertStringContainsString('-map 1 ', $meta);
		$this->assertStringContainsString('-map 2 ', $meta);
		$this->assertStringContainsString('-map 3 ', $meta);
	}

	public function testRemoteSubtitleUsesServerApiUrl(): void {
		$servers = [2 => ['api_url' => 'http://node2/api?key=abc']];
		$json = json_encode([
			'location' => 2,
			'files'    => ['remote.srt'],
			'charset'  => ['UTF-8'],
			'names'    => ['Remote'],
		]);
		[$import] = $this->build($json, $servers);

		$this->assertStringContainsString('http://node2/api?key=abc', $import);
		$this->assertStringContainsString('action=getFile', $import);
		$this->assertStringContainsString('filename=', $import);
	}
}
