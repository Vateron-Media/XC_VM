<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\RootSignalsCronJob;

final class FanoutEncoderAllowlistTest extends TestCase {
	public function testOnlyInstalledBuildPathsEnterTheRootAllowlist(): void {
		$rBase = sys_get_temp_dir() . '/xcvm-fanout-encoders-' . bin2hex(random_bytes(4));
		foreach (['7.1', '7.1-gpu', 'not-a-build'] as $rBuild) {
			mkdir($rBase . '/ffmpeg_bin/' . $rBuild, 0775, true);
			file_put_contents($rBase . '/ffmpeg_bin/' . $rBuild . '/ffmpeg', '#!/bin/sh');
			chmod($rBase . '/ffmpeg_bin/' . $rBuild . '/ffmpeg', 0755);
		}
		try {
			$rMethod = new ReflectionMethod(RootSignalsCronJob::class, 'fanoutEncoderAllowlist');
			$rMethod->setAccessible(true);
			$rAllowlist = $rMethod->invoke(null, $rBase);
			$this->assertSame([
				'7.1-gpu' => $rBase . '/ffmpeg_bin/7.1-gpu/ffmpeg',
				'7.1' => $rBase . '/ffmpeg_bin/7.1/ffmpeg',
			], $rAllowlist);
		} finally {
			foreach (glob($rBase . '/ffmpeg_bin/*/ffmpeg') ?: [] as $rPath) {
				@unlink($rPath);
				@rmdir(dirname($rPath));
			}
			@rmdir($rBase . '/ffmpeg_bin');
			@rmdir($rBase);
		}
	}
}
