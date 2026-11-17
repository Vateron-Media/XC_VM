<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\ServersCronJob;
use XcVm\Cli\DaemonTrait;
use XcVm\Core\Process\ProcessManager;

/**
 * @covers \XcVm\Cli\CronJobs\ServersCronJob::reviveFanoutSync
 *
 * fanout_sync is the only thing that closes a daemon-served TS connection
 * (pid 0). `service` started it once and nothing brought it back: a generation
 * that ended outside its own respawn (a database restart ended it) left every
 * viewer who disconnected "online" until the line was refused at its limit.
 * cron:servers now starts it whenever it is not running, as it does the other
 * daemons, and never beside a running one.
 *
 * The process list is a directory laid out as /proc, read by the same
 * ProcessManager::findProcessPIDs() the cron uses.
 */
final class ServersCronFanoutSyncTest extends TestCase {
	private string $rProc;

	private int $rNextPID;

	protected function setUp(): void {
		$this->rProc = sys_get_temp_dir() . '/xcvm-fanout-sync-proc-' . bin2hex(random_bytes(4));
		mkdir($this->rProc, 0700, true);
		// findProcessPIDs() leaves its own pid out: none of the stand-ins has it.
		$this->rNextPID = intval(getmypid()) + 1;
	}

	protected function tearDown(): void {
		foreach (glob($this->rProc . '/*/cmdline') ?: [] as $rFile) {
			unlink($rFile);
			rmdir(dirname($rFile));
		}
		rmdir($this->rProc);
	}

	/** A running process, as /proc/<pid>/cmdline names it (NUL between arguments). */
	private function process(string ...$rArgv): void {
		$rDir = $this->rProc . '/' . $this->rNextPID++;
		mkdir($rDir);
		file_put_contents($rDir . '/cmdline', implode("\0", $rArgv) . "\0");
	}

	/**
	 * One cron pass over the stand-in process list.
	 *
	 * @return array{0: bool, 1: list<string>} what reviveFanoutSync() answered, and the commands it started
	 */
	private function revive(): array {
		$rStarted = [];
		$rAnswer = (new ServersCronJob())->reviveFanoutSync(
			fn(array $rTerms): bool => count(ProcessManager::findProcessPIDs($rTerms, 1, $this->rProc)) > 0,
			static function (string $rCommand) use (&$rStarted): void {
				$rStarted[] = $rCommand;
			}
		);
		return [$rAnswer, $rStarted];
	}

	/** The other daemons, and what merely looks like the reconciler: none of them is it. */
	private function others(): void {
		$this->process('XC_VM[Signals]');
		$this->process('XC_VM[Queue]');
		$this->process(PHP_BIN, MAIN_HOME . 'console.php', 'watchdog');
		$this->process(MAIN_HOME . 'bin/xc_fanout/xc_fanout');
		$this->process('bash', MAIN_HOME . 'bin/xc_fanout/run.sh');
	}

	public function testAMissingReconcilerIsStartedOnce(): void {
		$this->others();
		[$rAnswer, $rStarted] = $this->revive();
		$this->assertTrue($rAnswer);
		$this->assertSame([PHP_BIN . ' ' . MAIN_HOME . 'console.php fanout_sync > /dev/null 2>/dev/null &'], $rStarted, 'as `service` starts it, detached');
	}

	public function testARunningReconcilerIsLeftAlone(): void {
		$this->others();
		// The title the daemon gives itself (setProcessTitle).
		$this->process('XC_VM[FanoutSync]');
		$this->assertSame([false, []], $this->revive());
	}

	public function testAReconcilerThatHasNotSetItsTitleYetCountsAsRunning(): void {
		$this->others();
		// Just started, still booting: only its command line names it.
		$this->process(PHP_BIN, MAIN_HOME . 'console.php', 'fanout_sync');
		$this->assertSame([false, []], $this->revive());
	}

	/**
	 * The second restartDaemon() waits before its successor exists: the shell
	 * that will start it quotes the command's name, so it is not taken for the
	 * daemon and the cron starts one. The two then meet at the daemon's lock
	 * (the last test below), which is what keeps them from running together.
	 */
	public function testTheShellAboutToStartASuccessorIsNotTakenForTheDaemon(): void {
		$this->others();
		$this->process('sh', '-c', '(sleep 1; ' . PHP_BIN . ' ' . MAIN_HOME . "console.php 'fanout_sync') > '" . MAIN_HOME . "tmp/daemon_fanout_sync.log' 2>&1 &");
		[$rAnswer, $rStarted] = $this->revive();
		$this->assertTrue($rAnswer);
		$this->assertCount(1, $rStarted);
	}

	public function testTheCronAsksEveryMinuteWithTheOtherDaemons(): void {
		$rCron = (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/ServersCronJob.php');
		$rLoad = substr($rCron, (int) strpos($rCron, 'private function loadCron(): void {'));
		$this->assertStringContainsString('$this->reviveFanoutSync();', $rLoad);
		// After the checks every daemon's revival waits for, and with them: not behind a setting or a server role.
		$this->assertMatchesRegularExpression('/\n\t\tif \(!ProcessManager::isAnyProcessRunning\(\[\'XC_VM\[Queue\]\', \'console\.php queue\'\]\)\) \{\n[^\n]+\n\t\t\}\n\n(?:\t\t\/\/[^\n]*\n)+\t\t\$this->reviveFanoutSync\(\);\n/', $rLoad);
	}

	/**
	 * Whatever starts a second reconciler, the daemon's own lock ends it: the
	 * second restartDaemon() waits before its successor exists is the one
	 * moment the cron can start one beside it.
	 */
	public function testASecondReconcilerEndsAtTheDaemonLock(): void {
		$rDaemon = static fn(): object => new class {
			use DaemonTrait;

			public function lock(string $rName): bool {
				return $this->acquireDaemonLock($rName);
			}

			public function lockFile(): string {
				return (string) stream_get_meta_data($this->rDaemonLockHandle)['uri'];
			}
		};
		$rName = 'fanout_sync_test_' . bin2hex(random_bytes(4));
		$rFirst = $rDaemon();
		$rSecond = $rDaemon();

		$this->assertTrue($rFirst->lock($rName));
		$rFile = $rFirst->lockFile();
		try {
			ob_start();
			$rRefused = !$rSecond->lock($rName);
			$rSaid = (string) ob_get_clean();
			$this->assertTrue($rRefused, 'the holder keeps running, the newcomer ends');
			$this->assertStringContainsString('Daemon already running', $rSaid);
			$this->assertSame((string) getmypid(), file_get_contents($rFile), 'the lock still names its holder');
		} finally {
			unset($rFirst, $rSecond);
			@unlink($rFile);
		}
	}
}
