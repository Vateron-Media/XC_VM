<?php

use PHPUnit\Framework\TestCase;

final class FfmpegPathsTest extends TestCase {
	protected function setUp(): void {
		$this->resetFfmpegPaths();
	}

	public function testResolveUsesExpectedBinariesForKnownVersion() {
		FfmpegPaths::resolve('8.0');
		$this->assertSame(FFMPEG_BIN_80, FfmpegPaths::cpu());
		$this->assertSame(FFMPEG_BIN_80, FfmpegPaths::gpu());
		$this->assertSame(FFPROBE_BIN_80, FfmpegPaths::probe());
	}

	public function testResolveFallsBackToLegacyVersion() {
		FfmpegPaths::resolve('unknown');
		$this->assertSame(FFMPEG_BIN_40, FfmpegPaths::cpu());
		$this->assertSame(FFMPEG_BIN_40, FfmpegPaths::gpu());
		$this->assertSame(FFPROBE_BIN_40, FfmpegPaths::probe());
	}

	private function resetFfmpegPaths() {
		$reflection = new ReflectionClass('FfmpegPaths');
		foreach (array('cpu', 'gpu', 'probe') as $propertyName) {
			$property = $reflection->getProperty($propertyName);
			$property->setAccessible(true);
			$property->setValue(null, null);
		}
		$resolved = $reflection->getProperty('resolved');
		$resolved->setAccessible(true);
		$resolved->setValue(null, false);
	}
}
