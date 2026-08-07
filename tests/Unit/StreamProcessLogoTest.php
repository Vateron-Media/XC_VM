<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\StreamProcess;

/**
 * Unit tests for the shared ffmpeg-command helpers extracted from
 * createChannelItem/startMovie/startStream: buildLogoFilterOptions() and
 * applyDefaultCopyCodecs(). Locks in their behaviour so the deduplication
 * cannot silently drift.
 */
final class StreamProcessLogoTest extends TestCase {

	/** @param array<mixed> $attrs (by ref — attr 16 is consumed on success) */
	private function buildLogo(array &$attrs, bool $loopback): string {
		$m = new ReflectionMethod(StreamProcess::class, 'buildLogoFilterOptions');
		$m->setAccessible(true);
		return $m->invokeArgs(null, [&$attrs, $loopback]);
	}

	/** @param array<mixed> $attrs (by ref) */
	private function copyCodecs(array &$attrs): void {
		$m = new ReflectionMethod(StreamProcess::class, 'applyDefaultCopyCodecs');
		$m->setAccessible(true);
		$m->invokeArgs(null, [&$attrs]);
	}

	// ── buildLogoFilterOptions ─────────────────────────────────

	public function testNoLogoReturnsEmptyAndKeepsAttributes(): void {
		$attrs = ['-acodec' => 'copy'];
		$this->assertSame('', $this->buildLogo($attrs, false));
		$this->assertSame(['-acodec' => 'copy'], $attrs);
	}

	public function testLoopbackNeverOverlaysLogo(): void {
		$attrs = [16 => ['val' => '/logo.png']];
		$this->assertSame('', $this->buildLogo($attrs, true));
		$this->assertArrayHasKey(16, $attrs, 'attr 16 not consumed when skipped');
	}

	public function testBasicLogoBuildsFilterAndConsumesAttr16(): void {
		$attrs = [16 => ['val' => '/logo.png'], 'keep' => 1];
		$out = $this->buildLogo($attrs, false);

		$this->assertStringContainsString("-i '/logo.png'", $out);
		$this->assertStringContainsString('[1:v]scale=250:-1[logo]', $out);
		$this->assertStringContainsString('[0:v][logo]overlay=10:main_h-overlay_h-10', $out);
		// attr 16 consumed so parseTranscode won't re-emit it as a plain flag.
		$this->assertArrayNotHasKey(16, $attrs);
		$this->assertArrayHasKey('keep', $attrs);
	}

	public function testCustomPositionUsed(): void {
		$attrs = [16 => ['val' => '/l.png', 'pos' => '25:40']];
		$out = $this->buildLogo($attrs, false);
		$this->assertStringContainsString('overlay=25:40', $out);
	}

	public function testDefaultPositionSentinelFallsBack(): void {
		// '10:10' is the UI "unset" sentinel → falls back to the default anchor.
		$attrs = [16 => ['val' => '/l.png', 'pos' => '10:10']];
		$out = $this->buildLogo($attrs, false);
		$this->assertStringContainsString('overlay=10:main_h-overlay_h-10', $out);
	}

	public function testDeinterlaceAndScaleChain(): void {
		$attrs = [
			16 => ['val' => '/l.png'],
			17 => [],                    // deinterlace flag present
			9  => ['val' => '1280:720'], // scale
		];
		$out = $this->buildLogo($attrs, false);
		$this->assertStringContainsString('[0:v]yadif,scale=1280:720[bg]', $out);
		$this->assertStringContainsString('[bg][logo]overlay=', $out);
	}

	public function testEmptyScaleValueSkipsPreFilterChain(): void {
		$attrs = [16 => ['val' => '/l.png'], 9 => ['val' => '']];
		$out = $this->buildLogo($attrs, false);
		$this->assertStringNotContainsString('[bg]', $out);
		$this->assertStringContainsString('[0:v][logo]overlay=', $out);
	}

	// ── applyDefaultCopyCodecs ─────────────────────────────────

	public function testCopyCodecsDefaultWhenAbsent(): void {
		$attrs = [];
		$this->copyCodecs($attrs);
		$this->assertSame('copy', $attrs['-acodec']);
		$this->assertSame('copy', $attrs['-vcodec']);
	}

	public function testCopyCodecsPreserveExisting(): void {
		$attrs = ['-acodec' => 'aac', '-vcodec' => 'libx264'];
		$this->copyCodecs($attrs);
		$this->assertSame('aac', $attrs['-acodec']);
		$this->assertSame('libx264', $attrs['-vcodec']);
	}

	// ── resolveGpuInputCodec (pure path only — the GPU path probes ffprobe) ──

	public function testGpuInputCodecEmptyWhenNoGpu(): void {
		$m = new ReflectionMethod(StreamProcess::class, 'resolveGpuInputCodec');
		$m->setAccessible(true);
		// No GPU options → returns '' without ever probing the source.
		$this->assertSame('', $m->invoke(null, '', '/some/source.ts'));
	}
}
