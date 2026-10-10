<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\StartupCommand;
use XcVm\Cli\CronJobs\RootSignalsCronJob;

/**
 * root_signals brings root's crontab up to date with the modules every
 * minute, so a module installed or enabled from the panel gets its cron lines
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

	/** The every-minute check says nothing when the list is there: cron would mail it each minute. */
	public function testTheCheckIsQuietWhenNothingChanges(): void {
		$rParameters = (new ReflectionMethod(StartupCommand::class, 'installRootCrontab'))->getParameters();
		$this->assertSame('rQuiet', $rParameters[0]->getName());
		$this->assertFalse($rParameters[0]->getDefaultValue(), 'startup and status still say it');
		$this->assertStringContainsString('StartupCommand::installRootCrontab(true)', (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/RootSignalsCronJob.php'));
		$this->assertStringContainsString('if ($rQuiet && $rListed !== 0)', (string) file_get_contents(MAIN_HOME . 'Cli/Commands/StartupCommand.php'), 'an unreadable crontab is not rewritten each minute');
	}

	/** One writer of root's crontab at a time, from reading the modules to replacing the list. */
	public function testAWriterAtWorkKeepsTheOthersOut(): void {
		$rLock = new ReflectionMethod(StartupCommand::class, 'crontabLock');
		$rHeld = $rLock->invoke(null, false);
		$this->assertIsResource($rHeld);
		$this->assertNull($rLock->invoke(null, false), 'held: the every-minute check comes back, it does not queue');
		fclose($rHeld);
		$rAgain = $rLock->invoke(null, false);
		$this->assertIsResource($rAgain, 'released: the next writer has it');
		fclose($rAgain);

		$rSource = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/StartupCommand.php');
		$rBody = substr($rSource, (int) strpos($rSource, 'function installRootCrontab('));
		$this->assertLessThan(strpos($rBody, 'loadAll()'), strpos($rBody, 'self::crontabLock(!$rQuiet)'), 'taken before the modules are read');
	}
}
