<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\NginxPortsFile;

final class NginxPortsFileTest extends TestCase {
	public function testRepairReadsLatestPortsAfterWaitingForWriter(): void {
		$rPath = tempnam(sys_get_temp_dir(), 'xcvm-ports-');
		$rHandle = fopen($rPath, 'c+');
		$rProcess = null;
		$rPipes = [];
		try {
			file_put_contents($rPath, 'listen 80;');
			$this->assertTrue(flock($rHandle, LOCK_EX));
			$rCode = <<<'CODE'
require $argv[1];
echo "ready\n";
flush();
\XcVm\Core\Config\NginxPortsFile::update($argv[2], static function (string $rConfig): string {
	return \XcVm\Cli\Commands\StatusCommand::ipv6ListenFor($rConfig, true);
});
CODE;
			$rProcess = proc_open([PHP_BINARY, '-r', $rCode, MAIN_HOME . 'vendor/autoload.php', $rPath], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
			$this->assertIsResource($rProcess);
			$this->assertSame("ready\n", fgets($rPipes[1]));
			// A repair must stay blocked while another writer holds the file.
			$rRead = [$rPipes[1]];
			$rWrite = $rExcept = [];
			$this->assertSame(0, stream_select($rRead, $rWrite, $rExcept, 0, 100000));
			$this->assertTrue(ftruncate($rHandle, 0));
			fwrite($rHandle, 'listen 47995;');
			fflush($rHandle);
			flock($rHandle, LOCK_UN);
			stream_get_contents($rPipes[1]);
			$rErrors = stream_get_contents($rPipes[2]);
			$this->assertSame(0, proc_close($rProcess), $rErrors);
			$rProcess = null;
			$this->assertSame("listen 47995;\nlisten [::]:47995 ipv6only=on;\n", file_get_contents($rPath));
			$this->assertFalse(NginxPortsFile::update($rPath, static fn(string $rConfig): string => $rConfig));
			$this->assertTrue(NginxPortsFile::write($rPath, 'listen 8080;'));
			$this->assertSame('listen 8080;', file_get_contents($rPath));
		} finally {
			fclose($rHandle);
			if (is_resource($rProcess)) {
				proc_terminate($rProcess);
				proc_close($rProcess);
			}
			foreach ($rPipes as $rPipe) {
				if (is_resource($rPipe)) {
					fclose($rPipe);
				}
			}
			unlink($rPath);
		}
	}

	public function testTransformFailurePreservesFileAndReleasesLock(): void {
		$rPath = tempnam(sys_get_temp_dir(), 'xcvm-ports-');
		try {
			file_put_contents($rPath, 'listen 80;');
			try {
				NginxPortsFile::update($rPath, static function (string $rConfig): string {
					throw new RuntimeException('transform failed');
				});
				$this->fail('Expected transform failure');
			} catch (RuntimeException $rError) {
				$this->assertSame('transform failed', $rError->getMessage());
			}
			$this->assertSame('listen 80;', file_get_contents($rPath));
			$rHandle = fopen($rPath, 'r+');
			$this->assertTrue(flock($rHandle, LOCK_EX | LOCK_NB));
			fclose($rHandle);
		} finally {
			unlink($rPath);
		}
	}
}
