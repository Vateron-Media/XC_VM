<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ModuleCronSyncCommand;
use XcVm\Cli\Commands\StartupCommand;
use XcVm\Cli\CronJobs\RootSignalsCronJob;
use XcVm\Core\Process\ProcessRunner;

/** root_signals keeps root's fixed crontab and xc_vm's validated module jobs current. */
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

	/** Root starts only the fixed synchronizer as xc_vm; module code stays out of root. */
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
		$this->assertStringContainsString('StartupCommand::installRootCrontab(true)', (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/RootSignalsCronJob.php'));
		$this->assertStringContainsString('if ($rQuiet && $rListed !== 0)', (string) file_get_contents(MAIN_HOME . 'Cli/Commands/StartupCommand.php'), 'an unreadable crontab is not rewritten each minute');
	}

	/** One writer of root's crontab at a time; it never reads module PHP. */
	public function testAWriterAtWorkKeepsTheOthersOut(): void {
		$rLock = new ReflectionMethod(StartupCommand::class, 'crontabLock');
		$rHeld = $rLock->invoke(null, false);
		$this->assertIsResource($rHeld);
		$this->assertNull($rLock->invoke(null, false), 'held: the every-minute check comes back, it does not queue');
		fclose($rHeld);
		$rAgain = $rLock->invoke(null, false);
		$this->assertIsResource($rAgain, 'released: the next writer has it');
		fclose($rAgain);
	}

	public function testRootCrontabNeverLoadsOrContainsModuleEntries(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/StartupCommand.php');
		$rBody = substr($rSource, (int) strpos($rSource, 'function installRootCrontab('));
		$this->assertStringNotContainsString('ModuleLoader', $rBody);
		$this->assertStringNotContainsString('collectCronEntries', $rBody);
		$rConsole = (string) file_get_contents(MAIN_HOME . 'console.php');
		$this->assertStringContainsString('if (posix_geteuid() !== 0)', $rConsole, 'root CLI commands never load or boot writable modules');
	}

	public function testSyncReplacesOnlyMarkedModuleJobs(): void {
		$this->assertSame(
			['0 * * * * core # XC_VM', '*/5 * * * * new # XC_VM_MODULE'],
			ModuleCronSyncCommand::merge(
				['0 * * * * core # XC_VM', '* * * * * old # XC_VM_MODULE'],
				['*/5 * * * * new # XC_VM_MODULE']
			)
		);
	}
}
