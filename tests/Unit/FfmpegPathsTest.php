<?php

use XcVm\Streaming\Codec\FfmpegPaths;
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

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg(BIN_PATH . 'ffmpeg_bin'));
		@unlink(dirname(FFMPEG_BIN_40) . '/BUILD_INFO');
	}

	/** A version the node lacks takes the newest build of its major (8.0 until 8.1 is fetched), never a backup folder; another major falls back to 4.0. */
	public function testAVersionTheNodeLacksTakesTheNewestOfItsMajor() {
		foreach (['8.0', '7.1', '8.9.backup'] as $rDir) {
			mkdir(BIN_PATH . 'ffmpeg_bin/' . $rDir, 0755, true);
			touch(BIN_PATH . 'ffmpeg_bin/' . $rDir . '/ffmpeg');
			touch(BIN_PATH . 'ffmpeg_bin/' . $rDir . '/ffprobe');
		}
		FfmpegPaths::resolve('8.1', '8.1');
		$this->assertSame(BIN_PATH . 'ffmpeg_bin/8.0/ffmpeg', FfmpegPaths::cpu());
		$this->assertSame(BIN_PATH . 'ffmpeg_bin/8.0/ffprobe', FfmpegPaths::probe());
		$this->assertSame(BIN_PATH . 'ffmpeg_bin/8.0/ffmpeg', FfmpegPaths::gpu());

		mkdir(BIN_PATH . 'ffmpeg_bin/8.1');
		touch(BIN_PATH . 'ffmpeg_bin/8.1/ffmpeg');
		$this->resetFfmpegPaths();
		FfmpegPaths::resolve('8.1');
		$this->assertSame(BIN_PATH . 'ffmpeg_bin/8.1/ffmpeg', FfmpegPaths::cpu(), 'its own build once fetched');
		$this->resetFfmpegPaths();
		FfmpegPaths::resolve('8.0');
		$this->assertSame(BIN_PATH . 'ffmpeg_bin/8.0/ffmpeg', FfmpegPaths::cpu(), 'the setting not migrated yet keeps its build');
		$this->resetFfmpegPaths();
		FfmpegPaths::resolve('9.0');
		$this->assertSame(BIN_PATH . 'ffmpeg_bin/8.1/ffmpeg', FfmpegPaths::cpu(), 'no build of its major: the newest build there is');
	}

	/** A vendor build has its own id; one the node lacks takes the same minor first, then the newest of its major; anything else is refused. */
	public function testAVendorBuildIsItsOwnIdAndFallsBackWithinItsMinor() {
		foreach (['4.0-xui', '4.4'] as $rDir) {
			$this->build($rDir);
		}
		FfmpegPaths::resolve('4.0-xui');
		$this->assertSame(BIN_PATH . 'ffmpeg_bin/4.0-xui/ffmpeg', FfmpegPaths::cpu());
		$this->resetFfmpegPaths();
		FfmpegPaths::resolve('4.0-abc');
		$this->assertSame(BIN_PATH . 'ffmpeg_bin/4.0-xui/ffmpeg', FfmpegPaths::cpu(), 'the same minor before a newer one');

		exec('rm -rf ' . escapeshellarg(BIN_PATH . 'ffmpeg_bin/4.0-xui'));
		$this->resetFfmpegPaths();
		FfmpegPaths::resolve('4.0-xui');
		$this->assertSame(BIN_PATH . 'ffmpeg_bin/4.4/ffmpeg', FfmpegPaths::cpu(), 'a node without XUI\'s build takes ours');

		$this->resetFfmpegPaths();
		FfmpegPaths::resolve('4.4-x;rm');
		$this->assertSame(BIN_PATH . 'ffmpeg_bin/4.4/ffmpeg', FfmpegPaths::cpu(), 'a malformed id is never a path: the newest build');
		$this->assertTrue(FfmpegPaths::isBuildId('4.0-xui'));
		$this->assertFalse(FfmpegPaths::isBuildId("8.1\n"), 'no trailing newline');
		$this->assertFalse(FfmpegPaths::isBuildId('../8.1'));
	}

	/** The legacy ffmpeg (dts_legacy_ffmpeg) is XUI's 4.0 where the node has it, else the newest 4.x, else the configured build. */
	public function testTheLegacyBuildIsXuisWhereThereIsOne() {
		$this->build('7.1');
		FfmpegPaths::resolve('7.1');
		$this->assertSame(BIN_PATH . 'ffmpeg_bin/7.1/ffmpeg', FfmpegPaths::legacy(), 'no 4.x at all');
		$this->build('4.4', true);
		$this->assertSame(BIN_PATH . 'ffmpeg_bin/4.4/ffmpeg', FfmpegPaths::legacy());
		$this->build('4.0-xui');
		$this->assertSame(BIN_PATH . 'ffmpeg_bin/4.0-xui/ffmpeg', FfmpegPaths::legacy());
	}

	/** -nofix_dts is XUI's switch: only a 4.0 folder without BUILD_INFO takes it, read through the 4.0 link. */
	public function testOnlyXuisBuildTakesNofixDts() {
		$this->build('4.0-xui');
		$this->build('4.4', true);
		$rBin = BIN_PATH . 'ffmpeg_bin/';
		$this->assertTrue(FfmpegPaths::fixDts($rBin . '4.0-xui/ffmpeg'));
		$this->assertFalse(FfmpegPaths::fixDts($rBin . '4.4/ffmpeg'));
		symlink('4.0-xui', $rBin . '4.0');
		$this->assertTrue(FfmpegPaths::fixDts($rBin . '4.0/ffmpeg'), 'the alias of XUI\'s build');
		unlink($rBin . '4.0');
		symlink('4.4', $rBin . '4.0');
		$this->assertFalse(FfmpegPaths::fixDts($rBin . '4.0/ffmpeg'), 'the alias of ours');
		unlink($rBin . '4.0');
		$this->build('4.0', true);
		$this->assertFalse(FfmpegPaths::fixDts($rBin . '4.0/ffmpeg'), 'the old rebuilt 4.0 is stock ffmpeg');
		$this->build('4.00');
		$this->assertFalse(FfmpegPaths::fixDts($rBin . '4.00/ffmpeg'), 'only 4.0 itself or 4.0-xui');
		$this->build('4.0-custom');
		$this->assertFalse(FfmpegPaths::fixDts($rBin . '4.0-custom/ffmpeg'), 'an admin\'s own 4.0 is stock ffmpeg');
	}

	private function build(string $rDir, bool $rOurs = false): void {
		mkdir(BIN_PATH . 'ffmpeg_bin/' . $rDir, 0755, true);
		touch(BIN_PATH . 'ffmpeg_bin/' . $rDir . '/ffmpeg');
		touch(BIN_PATH . 'ffmpeg_bin/' . $rDir . '/ffprobe');
		if ($rOurs) {
			file_put_contents(BIN_PATH . 'ffmpeg_bin/' . $rDir . '/BUILD_INFO', "FFmpeg   : {$rDir}.5\n");
		}
	}

	private function resetFfmpegPaths() {
		$reflection = new ReflectionClass(FfmpegPaths::class);
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
