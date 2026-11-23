<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\UpdateCommand;
use XcVm\Core\Process\ProcessRunner;

final class UpdateRuntimeTransitionTest extends TestCase {
	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-runtime-transition-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir, 0700, true);
	}

	protected function tearDown(): void {
		ProcessRunner::useRunner(null);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** @return array{redis-server: string, python3-guessit: string} */
	private function runtimeTree(): array {
		mkdir($this->rDir . 'main/bin/redis', 0700, true);
		mkdir($this->rDir . 'main/Public/Controllers/Admin', 0700, true);
		file_put_contents($this->rDir . 'main/bin/redis/redis.conf', 'port 6379');
		file_put_contents($this->rDir . 'main/bin/redis/redis-server', 'old redis');
		file_put_contents($this->rDir . 'main/bin/guess', 'old guess');
		mkdir($this->rDir . 'system', 0700);
		return [
			'redis-server' => $this->rDir . 'system/redis-server',
			'python3-guessit' => $this->rDir . 'system/guessit',
		];
	}

	public function testPostUpdateInstallsRuntimesBeforeRemovingBundledCopies(): void {
		$rExecutables = $this->runtimeTree();
		$rCalls = [];
		ProcessRunner::useRunner(function (array $rArgv) use (&$rCalls, $rExecutables): int {
			$rCalls[] = $rArgv;
			if (in_array('install', $rArgv, true)) {
				foreach ($rExecutables as $rPath) {
					file_put_contents($rPath, '#!/bin/sh');
					chmod($rPath, 0755);
				}
			}
			return 0;
		});

		$this->assertTrue(UpdateCommand::ensureRuntimePackages($this->rDir . 'main', $rExecutables));
		$this->assertSame([
			['/usr/bin/env', 'DEBIAN_FRONTEND=noninteractive', 'apt-get', '-o', 'DPkg::Lock::Timeout=120', 'update', '-q'],
			['/usr/bin/env', 'DEBIAN_FRONTEND=noninteractive', 'apt-get', '-o', 'DPkg::Lock::Timeout=120', 'install', '-y', '-q', 'redis-server', 'python3-guessit'],
			['systemctl', 'disable', '--now', 'redis-server'],
		], $rCalls);
		$this->assertFileDoesNotExist($this->rDir . 'main/bin/redis/redis-server');
		$this->assertFileDoesNotExist($this->rDir . 'main/bin/guess');
	}

	public function testFailedPackageInstallKeepsBundledCopies(): void {
		$rExecutables = $this->runtimeTree();
		ProcessRunner::useRunner(static fn(array $rArgv): int => 100);

		$this->assertFalse(UpdateCommand::ensureRuntimePackages($this->rDir . 'main', $rExecutables));
		$this->assertFileExists($this->rDir . 'main/bin/redis/redis-server');
		$this->assertFileExists($this->rDir . 'main/bin/guess');
	}

	public function testRollbackRestoresRedisFromTheTargetArchive(): void {
		$rStage = $this->rDir . 'stage/bin/redis';
		mkdir($rStage, 0700, true);
		file_put_contents($rStage . '/redis-server', 'target redis');
		$rArchive = $this->rDir . 'target.tar.gz';
		exec('tar -czf ' . escapeshellarg($rArchive) . ' -C ' . escapeshellarg($this->rDir . 'stage') . ' .', $rOut, $rCode);
		$this->assertSame(0, $rCode);

		$this->assertTrue(UpdateCommand::restoreRollbackRedis($rArchive, $this->rDir . 'main'));
		$rRestored = $this->rDir . 'main/bin/redis/redis-server';
		$this->assertSame('target redis', file_get_contents($rRestored));
		$this->assertTrue(is_executable($rRestored));
	}
}
