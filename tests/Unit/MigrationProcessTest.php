<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\MigrateCommand;

final class MigrationProcessTest extends TestCase {
	public function testAnExistingPidIsNotSufficientToIdentifyAMigration(): void {
		foreach (['unrelated-php-worker' => false, 'XC_VM[Migration]' => true] as $title => $expected) {
			$process = proc_open([PHP_BINARY, '-r', 'cli_set_process_title($argv[1]); echo getmypid(), "\n"; fflush(STDOUT); sleep(30);', $title], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
			$this->assertIsResource($process);
			try {
				$pid = (int) fgets($pipes[1]);
				$this->assertGreaterThan(1, $pid);
				$this->assertSame($expected, MigrateCommand::isRunning($pid));
			} finally {
				proc_terminate($process);
				fclose($pipes[1]);
				fclose($pipes[2]);
				proc_close($process);
			}
		}
	}
}
