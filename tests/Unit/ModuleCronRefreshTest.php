<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\RootSignalsCronJob;
use XcVm\Core\Process\ProcessRunner;

/**
 * root_signals brings xc_vm's module cron lines up to date every minute, so a
 * module installed or enabled from the panel gets its cron lines
 * (the anti-abuse shield's, which starts its honeypot) without a restart, and
 * a `startup` or `status` that wrote an older list is put right a minute
 * later. It keeps nothing of its last write: the crontab is what is compared.
 */
final class ModuleCronRefreshTest extends TestCase {
	public function testEveryRunChecksTheCrontab(): void {
		$rRuns = 0;
		$rWrite = static function () use (&$rRuns): bool {
			$rRuns++;
			return true;
		};
		$this->assertTrue(RootSignalsCronJob::refreshModuleCrons($rWrite));
		$this->assertTrue(RootSignalsCronJob::refreshModuleCrons($rWrite), 'the next minute too: nothing says "already done"');
		$this->assertSame(2, $rRuns);
		$this->assertFalse(RootSignalsCronJob::refreshModuleCrons(static fn(): bool => false), 'a crontab that could not be written');
	}

	/** Root writes no file where xc_vm could plant a link, and hashes nothing. */
	public function testNothingIsKeptOfTheLastWrite(): void {
		$rMethod = new ReflectionMethod(RootSignalsCronJob::class, 'refreshModuleCrons');
		$rSource = implode('', array_slice(file((string) $rMethod->getFileName()), $rMethod->getStartLine() - 1, $rMethod->getEndLine() - $rMethod->getStartLine() + 1));
		$this->assertStringNotContainsString('file_put_contents', $rSource);
		$this->assertStringNotContainsString('md5', $rSource);
	}

	/** Root starts only the fixed synchronizer as xc_vm; module code and jobs stay out of root's crontab. */
	public function testTheCheckDropsToXcVmForTheModuleCronSync(): void {
		$rSeen = [];
		ProcessRunner::useRunner(static function (array $rArgv, bool $rQuiet) use (&$rSeen): int {
			$rSeen = [$rArgv, $rQuiet];
			return 0;
		});
		try {
			$this->assertTrue(RootSignalsCronJob::refreshModuleCrons());
		} finally {
			ProcessRunner::useRunner(null);
		}
		$this->assertSame([['sudo', '-u', 'xc_vm', PHP_BIN, MAIN_HOME . 'console.php', 'module:cron-sync'], true], $rSeen);
	}

	public function testRootCrontabNeverLoadsOrContainsModuleEntries(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/StartupCommand.php');
		$rBody = substr($rSource, (int) strpos($rSource, 'function installRootCrontab('));
		$this->assertStringNotContainsString('ModuleLoader', $rBody);
		$this->assertStringNotContainsString('collectCronEntries', $rBody);
	}
}
