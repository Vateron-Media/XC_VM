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

	public function testChangedAllowlistRestartsFanoutOnlyAfterAtomicWrite(): void {
		$rSource = (string) file_get_contents(__DIR__ . '/../../src/Cli/CronJobs/RootSignalsCronJob.php');
		$this->assertStringNotContainsString('pkill ', $rSource);
		$rPath = sys_get_temp_dir() . '/xcvm-fanout-allowlist-' . bin2hex(random_bytes(4)) . '.json';
		$rRestarts = 0;
		$rRestart = static function () use (&$rRestarts): void { $rRestarts++; };
		try {
			$rMethod = new ReflectionMethod(RootSignalsCronJob::class, 'syncFanoutEncoderAllowlist');
			$rMethod->setAccessible(true);
			$this->assertTrue($rMethod->invoke(null, $rPath, ['7.1' => '/opt/ffmpeg'], $rRestart));
			$this->assertSame(1, $rRestarts);
			$this->assertSame('{"7.1":"/opt/ffmpeg"}', file_get_contents($rPath));
			$this->assertFalse($rMethod->invoke(null, $rPath, ['7.1' => '/opt/ffmpeg'], $rRestart));
			$this->assertSame(1, $rRestarts);
		} finally {
			@unlink($rPath);
		}
	}

	public function testRestartSignalsOnlyTheExactFanoutDaemonPid(): void {
		$rCmdlines = [
			101 => "/home/xc_vm/bin/xc_fanout/xc_fanout\0-sock\0/run/xc_vm/fanout.sock\0",
			102 => "/home/xc_vm/bin/xc_fanout/xc_fanout\0remux\0-config\0/tmp/stream.json\0",
			103 => "/tmp/xc_fanout\0-sock\0/run/xc_vm/fanout.sock\0",
		];
		$rKilled = [];
		$rMethod = new ReflectionMethod(RootSignalsCronJob::class, 'restartFanoutDaemon');
		$rMethod->setAccessible(true);
		$rMethod->invoke(
			null,
			array_keys($rCmdlines),
			static fn(int $rPid): string => $rCmdlines[$rPid],
			static function (int $rPid) use (&$rKilled): bool { $rKilled[] = $rPid; return true; }
		);
		$this->assertSame([101], $rKilled);
	}
}
