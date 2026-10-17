<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\LbInstallFlow;
use XcVm\Core\Process\ProcessRunner;

final class FfmpegDirectoryProvisioningTest extends TestCase {
	public function testLoadBalancerPreparesTheDirectoryBeforeFetchingBuilds(): void {
		$rCommands = [];
		LbInstallFlow::runStartup(null, static function ($rConn, string $rCommand) use (&$rCommands): array {
			$rCommands[] = $rCommand;
			return ['output' => '', 'error' => ''];
		});
		$rPrepare = 'sudo install -d -o xc_vm -g xc_vm -m 0755 /home/xc_vm/bin/ffmpeg_bin';
		$this->assertSame($rPrepare, $rCommands[1]);
		$this->assertStringEndsWith('console.php ffmpeg', end($rCommands));
		$rCommands = [];
		LbInstallFlow::runStartup(null, static function ($rConn, string $rCommand) use (&$rCommands): array {
			$rCommands[] = $rCommand;
			return ['output' => '', 'error' => ''];
		}, false);
		$this->assertSame($rPrepare, $rCommands[1]);
		$this->assertCount(2, $rCommands, 'API-mode nodes prepare the folder without running database-backed commands');
	}

	public function testFailedDirectoryPreparationFailsTheServiceBoot(): void {
		$rDir = sys_get_temp_dir() . '/xcvm-ffmpeg-boot-' . bin2hex(random_bytes(4));
		mkdir($rDir);
		try {
			foreach (['whoami' => 'echo root', 'sudo' => 'exit 0', 'install' => 'exit 1'] as $rName => $rCode) {
				file_put_contents($rDir . '/' . $rName, "#!/bin/sh\n" . $rCode . "\n");
				chmod($rDir . '/' . $rName, 0755);
			}
			[$rExit] = ProcessRunner::capture(['env', 'PATH=' . $rDir . ':' . getenv('PATH'), '/bin/sh', MAIN_HOME . 'service', 'boot']);
			$this->assertSame(1, $rExit, 'a failed boot must not be reported as successful');
		} finally {
			foreach (glob($rDir . '/*') as $rFile) {
				unlink($rFile);
			}
			rmdir($rDir);
		}
	}
}
