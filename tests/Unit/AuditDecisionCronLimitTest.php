<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Process\ProcessManager;
use XcVm\Tests\Support\AgentUser;

/**
 * cron:servers and cron:streams have a time limit: a run of either that has
 * lasted longer than ten minutes (by its own start time) is ended by the next
 * run, which takes its place. A hung run (blocked on a dead database or
 * network connection) would otherwise keep a node's watchdog chain or stream
 * supervision stopped until someone killed it. Every other cron keeps its
 * lock while it runs, and nothing but the very process the lock names, by pid
 * and start time, is ever signalled.
 *
 * The limit here is two seconds: a process's age cannot be faked. Every holder
 * and every run is a child process of this test.
 */
final class AuditDecisionCronLimitTest extends TestCase {
	private const LIMIT = 2;

	private string $rDir;

	/** @var array<int, resource> pid => process */
	private array $rProcs = [];

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-cron-limit-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'logs', 0777, true);
		AgentUser::own($this->rDir . 'logs');
		// One run of a cron that takes its lock as a cron class does (CronTrait),
		// with a limit (0: none), then works for some seconds or, with "hang",
		// blocks in a read that never returns, as a run on a dead connection does.
		// "deaf" makes it ignore SIGTERM.
		file_put_contents($this->rDir . 'run.php', <<<'PHP'
			<?php
			require getenv('XCVM_TEST_AUTOLOAD');
			\XcVm\Core\Logging\FileLogger::setLogFile(getenv('XCVM_TEST_LOG'));
			define('CRONS_TMP_PATH', dirname(getenv('XCVM_TEST_LOG'), 2) . '/');
			if (($argv[3] ?? '') === 'deaf') {
				pcntl_signal(SIGTERM, SIG_IGN);
			}
			final class LimitTestCronJob {
				use \XcVm\Cli\CronTrait;

				public function start(int $rLimit): void {
					$this->initCron('XC_VM[LimitTest]', $rLimit);
				}
			}
			(new LimitTestCronJob())->start((int) $argv[1]);
			echo "TOOK\n";
			if ($argv[2] === 'hang') {
				fread(STDIN, 1);
			} else {
				sleep((int) $argv[2]);
			}
			PHP);
	}

	protected function tearDown(): void {
		foreach (array_keys($this->rProcs) as $rPid) {
			proc_terminate($this->rProcs[$rPid], 9);
			proc_close($this->rProcs[$rPid]);
		}
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function lock(): string {
		return $this->rDir . 'lock_' . md5('LimitTestCronJob');
	}

	/** @return array<string, string> */
	private function env(): array {
		return ['XCVM_TEST_AUTOLOAD' => MAIN_HOME . 'vendor/autoload.php', 'XCVM_TEST_LOG' => $this->rDir . 'logs/error_log.log'] + getenv();
	}

	/** A run that has the lock and is blocked: its pid. */
	private function holder(int $rLimit, string $rMode = ''): int {
		// stdin is a pipe this test keeps open and never writes to.
		$rProc = proc_open([PHP_BINARY, $this->rDir . 'run.php', (string) $rLimit, 'hang', $rMode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes, null, $this->env());
		$this->assertIsResource($rProc);
		$rPid = (int) proc_get_status($rProc)['pid'];
		$this->rProcs[$rPid] = $rProc;
		$this->assertSame("TOOK\n", fgets($rPipes[1]), 'the first run takes the lock');
		return $rPid;
	}

	/** A process that is no cron, older than the limit: its pid. */
	private function bystander(): int {
		$rProc = proc_open([PHP_BINARY, '-r', 'sleep(60);'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rPid = (int) proc_get_status($rProc)['pid'];
		$this->rProcs[$rPid] = $rProc;
		return $rPid;
	}

	/** The next run of the same cron: what it printed, "TOOK" when it got the lock. */
	private function nextRun(int $rLimit): string {
		$rProc = proc_open([PHP_BINARY, $this->rDir . 'run.php', (string) $rLimit, '0'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes, null, $this->env());
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]) . (string) stream_get_contents($rPipes[2]);
		fclose($rPipes[1]);
		fclose($rPipes[2]);
		proc_close($rProc);
		ProcessManager::clearCache();
		return trim($rOut);
	}

	/** The signal that ended a process of this test, 0 while it runs. */
	private function endedBy(int $rPid): int {
		for ($i = 0; $i < 50; $i++) {
			$rStatus = proc_get_status($this->rProcs[$rPid]);
			if (!$rStatus['running']) {
				return $rStatus['signaled'] ? (int) $rStatus['termsig'] : -1;
			}
			usleep(20000);
		}
		return 0;
	}

	private static function startOf(int $rPid): int {
		return (int) ProcessManager::resourceSample($rPid)['start'];
	}

	/** @return list<string> the messages the runs wrote to the panel's log */
	private function logged(): array {
		$rLog = $this->rDir . 'logs/error_log.log';
		clearstatcache(true, $rLog);
		$rLines = is_file($rLog) ? file($rLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
		return array_map(static fn(string $rLine): string => (string) json_decode((string) base64_decode($rLine), true)['message'], $rLines);
	}

	public function testAHungRunOverTheLimitIsEndedAndTheNextRunTakesItsPlace(): void {
		$rPid = $this->holder(self::LIMIT);
		$this->assertSame('Running...', $this->nextRun(self::LIMIT), 'within the limit the holder keeps its lock');
		$this->assertSame(0, $this->endedBy($rPid));

		sleep(self::LIMIT + 1);
		$this->assertSame('TOOK', $this->nextRun(self::LIMIT), 'past the limit the next run takes over');
		$this->assertSame(SIGTERM, $this->endedBy($rPid), 'the hung run, blocked in a read, is ended');
		$rLines = $this->logged();
		$this->assertCount(1, $rLines);
		$this->assertStringContainsString('XC_VM[LimitTest] (pid ' . $rPid . ')', $rLines[0]);
		$this->assertStringContainsString('was ended', $rLines[0]);
	}

	public function testAHolderThatIgnoresSigtermIsKilled(): void {
		$rPid = $this->holder(self::LIMIT, 'deaf');
		sleep(self::LIMIT + 1);
		$this->assertSame('TOOK', $this->nextRun(self::LIMIT));
		$this->assertSame(SIGKILL, $this->endedBy($rPid));
	}

	public function testAnotherCronKeepsItsLockHoweverLongItRuns(): void {
		$rPid = $this->holder(0);
		sleep(self::LIMIT + 1);
		$this->assertSame('Running...', $this->nextRun(0));
		$this->assertSame(0, $this->endedBy($rPid), 'a cron without a limit is never ended');
		$this->assertSame([], $this->logged());
	}

	public function testAPidHandedToAnotherProcessIsNeverSignalled(): void {
		$rPid = $this->bystander();
		sleep(self::LIMIT + 1);

		// The lock names that pid with another start time: its holder is gone.
		file_put_contents($this->lock(), $rPid . ' ' . (self::startOf($rPid) - 1));
		$this->assertSame('TOOK', $this->nextRun(self::LIMIT));
		$this->assertSame(0, $this->endedBy($rPid));

		// A lock of the previous release names a pid alone: who that is cannot be told.
		file_put_contents($this->lock(), (string) $rPid);
		$this->assertSame('Running...', $this->nextRun(self::LIMIT));
		$this->assertSame(0, $this->endedBy($rPid));
		$this->assertSame([], $this->logged());
	}

	public function testOnlyCronServersAndCronStreamsHaveTheTenMinuteLimit(): void {
		foreach (glob(MAIN_HOME . 'Cli/CronJobs/*.php') as $rFile) {
			$rSource = (string) file_get_contents($rFile);
			$rLimited = in_array(basename($rFile), ['ServersCronJob.php', 'StreamsCronJob.php'], true);
			if ($rLimited) {
				$this->assertSame(1, preg_match('/\$this->initCron\(\'[^\']+\', 600\);/', $rSource), basename($rFile) . ' takes its lock with the ten-minute limit');
			} else {
				$this->assertSame(0, preg_match('/initCron\(\'[^\']+\', \d|acquireCronLock\([^)]*,/', $rSource), basename($rFile) . ' keeps its lock while it runs');
			}
		}
	}
}
