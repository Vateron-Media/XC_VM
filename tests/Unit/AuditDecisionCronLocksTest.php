<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\TmpCronJob;
use XcVm\Core\Process\ProcessManager;
use XcVm\Tests\Support\AgentUser;

/**
 * A cron's lock is its own for as long as that very process runs. cron:tmp
 * removed every lock ten minutes old, so a second copy of a long cron started
 * beside the first; and the lock code ended a holder thirty minutes old, which
 * a long backup or clean-up is. Now the sweep leaves the lock of a running
 * cron, the lock names its holder by pid and start time (a pid the system has
 * handed to another process is not the holder), nothing in the lock code ends
 * a holder, and a run that finds one over an hour old says so in the panel's
 * log.
 *
 * Every holder here is a child process of this test, and so is every run that
 * asks for a lock: acquireCronLock() exits when the lock is held.
 */
final class AuditDecisionCronLocksTest extends TestCase {
	private string $rDir;

	/** @var array<int, resource> pid => process */
	private array $rProcs = [];

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-cron-locks-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'logs', 0777, true);
		// The logs directory is xc_vm's on a panel: nobody's here, when the suite runs as root.
		AgentUser::own($this->rDir . 'logs');
		// One run of a cron: its title, the lock, then its work (seconds of sleep).
		// With "cron" for the lock it takes it as a cron class does (CronTrait).
		file_put_contents($this->rDir . 'run.php', <<<'PHP'
			<?php
			require getenv('XCVM_TEST_AUTOLOAD');
			\XcVm\Core\Logging\FileLogger::setLogFile(getenv('XCVM_TEST_LOG'));
			if ($argv[1] === 'cron') {
				define('CRONS_TMP_PATH', dirname(getenv('XCVM_TEST_LOG'), 2) . '/');
				final class LockTestCronJob {
					use \XcVm\Cli\CronTrait;

					public function start(): void {
						$this->initCron('XC_VM[LockTest]');
					}
				}
				(new LockTestCronJob())->start();
			} else {
				cli_set_process_title('XC_VM[LockTest]');
				\XcVm\Core\Process\ProcessManager::acquireCronLock($argv[1]);
			}
			echo "TOOK\n";
			sleep((int) $argv[2]);
			PHP);
	}

	protected function tearDown(): void {
		foreach (array_keys($this->rProcs) as $rPid) {
			$this->end($rPid);
		}
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** @return array<string, string> */
	private function env(): array {
		return ['XCVM_TEST_AUTOLOAD' => MAIN_HOME . 'vendor/autoload.php', 'XCVM_TEST_LOG' => $this->rDir . 'logs/error_log.log'] + getenv();
	}

	/** A run that has the lock and is still at work: its pid. */
	private function holder(string $rLock): int {
		$rProc = proc_open([PHP_BINARY, $this->rDir . 'run.php', $rLock, '60'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes, null, $this->env());
		$this->assertIsResource($rProc);
		$rPid = (int) proc_get_status($rProc)['pid'];
		$this->rProcs[$rPid] = $rProc;
		$this->assertSame("TOOK\n", fgets($rPipes[1]), 'the first run takes the lock');
		return $rPid;
	}

	/** A process that is no cron: its pid. */
	private function bystander(): int {
		$rProc = proc_open([PHP_BINARY, '-r', 'sleep(60);'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rPid = (int) proc_get_status($rProc)['pid'];
		$this->rProcs[$rPid] = $rProc;
		return $rPid;
	}

	/** End a process of this test and collect it, so that nothing of it is left in /proc. */
	private function end(int $rPid): void {
		proc_terminate($this->rProcs[$rPid], 9);
		proc_close($this->rProcs[$rPid]);
		unset($this->rProcs[$rPid]);
		ProcessManager::clearCache();
	}

	/** The next run of the same cron: what it printed, "TOOK" when it got the lock. */
	private function nextRun(string $rLock): string {
		$rProc = proc_open([PHP_BINARY, $this->rDir . 'run.php', $rLock, '0'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes, null, $this->env());
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]) . (string) stream_get_contents($rPipes[2]);
		fclose($rPipes[1]);
		fclose($rPipes[2]);
		proc_close($rProc);
		// A signal sent by that run has arrived by now.
		usleep(100000);
		return trim($rOut);
	}

	private static function alive(int $rPid): bool {
		$rStat = @file_get_contents('/proc/' . $rPid . '/stat');
		return is_string($rStat) && !preg_match('/\) Z /', $rStat);
	}

	private static function startOf(int $rPid): int {
		return (int) ProcessManager::resourceSample($rPid)['start'];
	}

	/** @return list<array<string, mixed>> the lines the runs wrote to the panel's log */
	private function logged(): array {
		$rLog = $this->rDir . 'logs/error_log.log';
		clearstatcache(true, $rLog);
		$rLines = is_file($rLog) ? file($rLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
		return array_map(static fn(string $rLine): array => json_decode((string) base64_decode($rLine), true), $rLines);
	}

	public function testALockNamesItsHolderByPidAndStartTime(): void {
		$rLock = $this->rDir . 'lock_a';
		$rPid = $this->holder($rLock);
		$this->assertSame($rPid . ' ' . self::startOf($rPid), file_get_contents($rLock));
		$this->assertSame($rPid, ProcessManager::cronLockHolder($rLock));
		// The previous release, and server:diagnose, read the pid as the leading number.
		$this->assertSame($rPid, (int) trim((string) file_get_contents($rLock)));
	}

	public function testARunningHolderKeepsItsLockHoweverOldAndIsNeverEnded(): void {
		$rLock = $this->rDir . 'lock_a';
		$rPid = $this->holder($rLock);
		touch($rLock, time() - 7200);
		$this->assertSame('Running...', $this->nextRun($rLock));
		$this->assertTrue(self::alive($rPid), 'the lock code never ends the holder');
		$this->assertStringStartsWith($rPid . ' ', (string) file_get_contents($rLock), 'the lock is still the holder\'s');
	}

	public function testAPidHandedToAnotherProcessIsNotTheHolder(): void {
		$rLock = $this->rDir . 'lock_a';
		$rPid = $this->bystander();
		file_put_contents($rLock, $rPid . ' ' . (self::startOf($rPid) - 1));
		$this->assertSame('TOOK', $this->nextRun($rLock), 'the holder is gone: its lock is taken at once');
		$this->assertTrue(self::alive($rPid));

		// The same pid with its own start time is the holder.
		file_put_contents($rLock, $rPid . ' ' . self::startOf($rPid));
		$this->assertSame('Running...', $this->nextRun($rLock));
	}

	public function testALockWhoseHolderIsGoneIsTakenAtOnce(): void {
		$rLock = $this->rDir . 'lock_a';
		file_put_contents($rLock, '999999999 123');
		$this->assertSame('TOOK', $this->nextRun($rLock));
		// That run ended and left its lock behind.
		$this->assertSame('TOOK', $this->nextRun($rLock));
	}

	public function testAHolderThatHasEndedAndWaitsToBeCollectedHoldsNothing(): void {
		$rLock = $this->rDir . 'lock_' . md5('SomeCronJob');
		$rPid = $this->holder($rLock);
		touch($rLock, time() - 7200);
		// Ended, and its parent (this test) has not collected it: it is still in /proc.
		posix_kill($rPid, 9);
		for ($i = 0; $i < 100 && self::alive($rPid); $i++) {
			usleep(20000);
		}
		$this->assertFileExists('/proc/' . $rPid . '/stat');
		$this->assertFalse(self::alive($rPid));

		$this->assertFalse(TmpCronJob::keepsLock($rLock), 'the sweep removes its lock by age');
		$this->assertSame('TOOK', $this->nextRun($rLock), 'and the next run takes it at once');
	}

	public function testALockOfThePreviousReleaseIsJudgedByItsPidAsBefore(): void {
		$rLock = $this->rDir . 'lock_a';
		$rPid = $this->bystander();
		file_put_contents($rLock, (string) $rPid);
		$this->assertSame('Running...', $this->nextRun($rLock), 'a pid alone holds the lock while the lock is fresh');
		touch($rLock, time() - 1801);
		$this->assertSame('TOOK', $this->nextRun($rLock), 'and no longer once it is thirty minutes old');
		$this->assertTrue(self::alive($rPid), 'the process it named is not ended');
	}

	public function testEachRunThatFindsAHolderOverAnHourOldSaysSoInThePanelLog(): void {
		$rLock = $this->rDir . 'lock_a';
		$rPid = $this->holder($rLock);
		touch($rLock, time() - 3000);
		$this->assertSame('Running...', $this->nextRun($rLock));
		$this->assertSame([], $this->logged(), 'under an hour: nothing to report');

		$rSince = time() - 3700;
		touch($rLock, $rSince);
		$this->assertSame('Running...', $this->nextRun($rLock));
		$this->assertSame('Running...', $this->nextRun($rLock));
		$rLines = $this->logged();
		$this->assertCount(2, $rLines, 'one line for each run');
		$this->assertSame('cron', $rLines[0]['type']);
		$this->assertStringContainsString('XC_VM[LockTest] (pid ' . $rPid . ')', $rLines[0]['message']);
		$this->assertStringContainsString(date('Y-m-d H:i:s', $rSince), $rLines[0]['message']);
		$this->assertStringContainsString('nothing ends it', $rLines[0]['message']);
		// cron:errors keys a row of Panel Logs on the text: the runs of one pass are one row there.
		$this->assertSame([$rLines[0]['message'], $rLines[0]['extra']], [$rLines[1]['message'], $rLines[1]['extra']]);
		$this->assertTrue(self::alive($rPid));
	}

	public function testARootRunWritesThatLineAsTheOwnerOfTheLogsDirectory(): void {
		if (!AgentUser::root()) {
			$this->markTestSkipped('needs root, as the root crons run');
		}
		$rLock = $this->rDir . 'lock_a';
		$this->holder($rLock);
		touch($rLock, time() - 3700);
		$rLog = $this->rDir . 'logs/error_log.log';

		$this->assertSame('Running...', $this->nextRun($rLock));
		$this->assertCount(1, $this->logged());
		$this->assertSame(AgentUser::UID, fileowner($rLog), 'the panel\'s own processes add to that file');

		// It writes there with that owner's rights and no more: a file the
		// owner cannot write stays as it is, whatever the path leads to.
		unlink($rLog);
		file_put_contents($this->rDir . 'elsewhere', '');
		symlink($this->rDir . 'elsewhere', $rLog);
		$this->assertStringStartsWith('Running...', $this->nextRun($rLock));
		$this->assertSame('', file_get_contents($this->rDir . 'elsewhere'));

		// A logs directory that is root's own: the line goes to the run's stderr.
		unlink($rLog);
		chown($this->rDir . 'logs', 0);
		$rOut = $this->nextRun($rLock);
		$this->assertStringContainsString('has held its cron lock since', $rOut);
		$this->assertStringStartsWith('Running...', $rOut);
		$this->assertSame([], $this->logged());
	}

	public function testARunThatFindsTheLockHeldLeavesItToItsHolder(): void {
		$rLock = $this->rDir . 'lock_' . md5('LockTestCronJob');
		$rPid = $this->holder('cron');
		$this->assertStringStartsWith($rPid . ' ', (string) file_get_contents($rLock));
		$this->assertSame('Running...', $this->nextRun('cron'));
		$this->assertFileExists($rLock, 'the run that was turned away removes nothing at its end');
		$this->assertSame('Running...', $this->nextRun('cron'), 'so the run after it is turned away as well');

		// A run that had the lock removes it at its end.
		$this->end($rPid);
		$this->assertSame('TOOK', $this->nextRun('cron'));
		$this->assertFileDoesNotExist($rLock);
	}

	public function testTheSweepLeavesTheLockOfARunningCronAndRemovesTheRestByAge(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/TmpCronJob.php');
		$this->assertMatchesRegularExpression(
			'/if \(is_file\(\$fullPath\)[^\n{]*&& !self::keepsLock\(\$fullPath\)\) \{\n\s*unlink\(\$fullPath\);/',
			$rSource,
			'the sweep removes no file by its age without asking whether it is the lock of a running cron'
		);

		$rLock = $this->rDir . 'lock_' . md5('SomeCronJob');
		$rPid = $this->holder($rLock);
		touch($rLock, time() - 7200);
		$this->assertTrue(TmpCronJob::keepsLock($rLock), 'two hours old, and its cron still runs');

		// A daemon's flock file is no cron lock: it is swept by its age, as it was.
		copy($rLock, $this->rDir . 'daemon_watchdog.lock');
		$this->assertFalse(TmpCronJob::keepsLock($this->rDir . 'daemon_watchdog.lock'));

		// So is a lock of the previous release, which names a pid alone.
		$rOld = $this->rDir . 'lock_' . md5('OtherCronJob');
		file_put_contents($rOld, (string) $rPid);
		$this->assertFalse(TmpCronJob::keepsLock($rOld));

		// The cron ends without removing its lock: a leftover like any other.
		$this->end($rPid);
		$this->assertFalse(TmpCronJob::keepsLock($rLock));
	}
}
