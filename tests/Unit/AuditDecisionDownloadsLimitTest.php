<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\TmpCronJob;

/**
 * `max_simultaneous_downloads` is the number of playlist downloads, and the
 * number of XMLTV downloads, a line may have running at the same moment on a
 * server: one more is refused for as long as that many run, and is admitted
 * the moment one of them ends, however it ends. Zero is no limit, and a
 * restreamer has none.
 *
 * A download belongs to a worker process that lives on after it, and
 * FLOOD_TMP_PATH is a constant, so every download here is a child PHP of its
 * own that starts and ends a download when told to.
 */
final class AuditDecisionDownloadsLimitTest extends TestCase {
	private string $rDir;

	/** @var list<array{0: resource, 1: array<int, resource>}> the worker processes, with their pipes */
	private array $rWorkers = [];

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-downloads-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'flood', 0777, true);
		file_put_contents(
			$this->rDir . 'worker.php',
			'<?php define("FLOOD_TMP_PATH", $argv[1]);'
			. ' require ' . var_export(MAIN_HOME . 'vendor/autoload.php', true) . ';'
			. ' $rUser = ["id" => (int) $argv[3], "is_restreamer" => (int) $argv[4]];'
			. ' while (($rLine = fgets(STDIN)) !== false) {'
			. '  $rWords = explode(" ", trim($rLine));'
			. '  if ($rWords[0] === "start") {'
			. '   echo (int) \XcVm\Core\Util\NetworkUtils::startDownload($argv[2], $rUser, getmypid(), (int) $argv[5]), "\n";'
			. '  } elseif ($rWords[0] === "stop") {'
			. '   \XcVm\Core\Util\NetworkUtils::stopDownload($argv[2], $rUser, getmypid(), (int) $argv[5]);'
			. '   echo "stopped\n";'
			// Downloads one after the other: each one admitted is written to the
			// log while it runs, "+" once it has begun and "-" before it ends.
			. '  } elseif ($rWords[0] === "churn") {'
			. '   for ($rTurn = 0; $rTurn < (int) $rWords[1]; $rTurn++) {'
			. '    if (\XcVm\Core\Util\NetworkUtils::startDownload($argv[2], $rUser, getmypid(), (int) $argv[5])) {'
			. '     file_put_contents(dirname($argv[1]) . "/running.log", "+", FILE_APPEND | LOCK_EX);'
			. '     usleep(random_int(0, 300));'
			. '     file_put_contents(dirname($argv[1]) . "/running.log", "-", FILE_APPEND | LOCK_EX);'
			. '     \XcVm\Core\Util\NetworkUtils::stopDownload($argv[2], $rUser, getmypid(), (int) $argv[5]);'
			. '    }'
			. '   }'
			. '   echo "done\n";'
			. '  }'
			. ' }'
		);
	}

	protected function tearDown(): void {
		foreach (array_keys($this->rWorkers) as $rWorker) {
			$this->kill($rWorker);
		}
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** A worker process of the panel, for the downloads of one kind of one line. */
	private function worker(int $rLimit, string $rType = 'playlist', int $rLine = 7, bool $rRestreamer = false, string $rFlood = 'flood/'): int {
		$rProc = proc_open(
			[PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', $this->rDir . 'worker.php', $this->rDir . $rFlood, $rType, (string) $rLine, (string) (int) $rRestreamer, (string) $rLimit],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $this->rDir . 'stderr.log', 'a']],
			$rPipes
		);
		$this->assertIsResource($rProc);
		// A worker that does not answer fails its test instead of holding up the suite.
		stream_set_timeout($rPipes[1], 60);
		$this->rWorkers[] = [$rProc, $rPipes];

		return array_key_last($this->rWorkers);
	}

	/** Tells a worker what to do and returns what it answered. */
	private function tell(int $rWorker, string $rWhat): string {
		fwrite($this->rWorkers[$rWorker][1][0], $rWhat . "\n");
		$rAnswer = fgets($this->rWorkers[$rWorker][1][1]);
		$this->assertIsString($rAnswer, 'the worker died: ' . @file_get_contents($this->rDir . 'stderr.log'));

		return trim($rAnswer);
	}

	/** The worker's process ends as it is, with whatever download it has running. */
	private function kill(int $rWorker): void {
		[$rProc, $rPipes] = $this->rWorkers[$rWorker];
		unset($this->rWorkers[$rWorker]);
		proc_terminate($rProc, 9);
		for ($rWait = 0; $rWait < 5000 && proc_get_status($rProc)['running']; $rWait++) {
			usleep(1000);
		}
		array_map('fclose', $rPipes);
		proc_close($rProc);
	}

	/**
	 * A download begins in a worker of its own.
	 *
	 * @return int|null the worker that runs it, or null when it was refused
	 */
	private function download(int $rLimit, string $rType = 'playlist', int $rLine = 7, bool $rRestreamer = false, string $rFlood = 'flood/'): ?int {
		$rWorker = $this->worker($rLimit, $rType, $rLine, $rRestreamer, $rFlood);
		if ($this->tell($rWorker, 'start') === '1') {
			return $rWorker;
		}
		$this->kill($rWorker);

		return null;
	}

	/** @return list<string> the files kept for the downloads */
	private function kept(): array {
		return array_map('basename', glob($this->rDir . 'flood/*') ?: []);
	}

	/**
	 * Does cron:tmp remove this file $rLater seconds from now? It removes a
	 * file of the directory that has gone maxAge() seconds without a change.
	 */
	private function swept(string $rName, int $rLater): bool {
		clearstatcache();

		return time() + $rLater - filemtime($this->rDir . 'flood/' . $rName) >= TmpCronJob::maxAge($rName);
	}

	public function testADownloadOverTheLimitIsRefusedWhileTheOthersRun(): void {
		$this->assertNotNull($this->download(2));
		$this->assertNotNull($this->download(2));
		$this->assertNull($this->download(2), 'a third download while two run');
		$this->assertNull($this->download(2), 'and the one after it');
	}

	public function testTheLimitHoldsHoweverLongAgoTheDownloadsBegan(): void {
		$this->assertNotNull($this->download(2));
		$this->assertNotNull($this->download(2));

		// Eleven seconds later, as the files kept for the downloads tell it.
		foreach (glob($this->rDir . 'flood/*') ?: [] as $rFile) {
			touch($rFile, filemtime($rFile) - 11);
		}

		$this->assertNull($this->download(2), 'a third download while the two still run');
	}

	public function testDownloadsThatBeginAtTheSameMomentAreCountedOneByOne(): void {
		$rWorkers = [];
		for ($rCount = 0; $rCount < 12; $rCount++) {
			$rWorkers[] = $this->worker(2);
		}
		foreach ($rWorkers as $rWorker) {
			fwrite($this->rWorkers[$rWorker][1][0], "start\n");
		}
		$rAdmitted = 0;
		foreach ($rWorkers as $rWorker) {
			$rAdmitted += (int) fgets($this->rWorkers[$rWorker][1][1]);
		}

		$this->assertSame(2, $rAdmitted);
	}

	public function testADownloadThatEndsMakesRoomAtOnce(): void {
		$rFirst = $this->download(2);
		$rSecond = $this->download(2);
		$this->assertNotNull($rFirst);
		$this->assertNotNull($rSecond);
		$this->assertNull($this->download(2));

		// The download ends; its worker lives on.
		$this->assertSame('stopped', $this->tell($rFirst, 'stop'));
		$rThird = $this->download(2);
		$this->assertNotNull($rThird, 'a download once one of the two has ended');
		$this->assertNull($this->download(2), 'and no more than that one');

		// The worker takes the line's next download itself.
		$this->assertSame('stopped', $this->tell($rThird, 'stop'));
		$this->assertSame('1', $this->tell($rFirst, 'start'));
		$this->assertNull($this->download(2));
	}

	public function testADownloadWhoseProcessIsKilledMakesRoomAtOnce(): void {
		$rFirst = $this->download(2);
		$this->assertNotNull($rFirst);
		$this->assertNotNull($this->download(2));
		$this->assertNull($this->download(2));

		$this->kill($rFirst);

		$this->assertNotNull($this->download(2), 'a download once the process of one of the two is gone');
		$this->assertNull($this->download(2), 'and no more than that one');
	}

	public function testNoMoreThanTheLimitRunWhileDownloadsComeAndGo(): void {
		$rWorkers = [];
		for ($rCount = 0; $rCount < 8; $rCount++) {
			$rWorkers[] = $this->worker(2);
		}
		foreach ($rWorkers as $rWorker) {
			fwrite($this->rWorkers[$rWorker][1][0], "churn 150\n");
		}
		foreach ($rWorkers as $rWorker) {
			$this->assertSame("done\n", fgets($this->rWorkers[$rWorker][1][1]), (string) @file_get_contents($this->rDir . 'stderr.log'));
		}

		$rRunning = 0;
		$rMost = 0;
		foreach (str_split((string) file_get_contents($this->rDir . 'running.log')) as $rMark) {
			$rRunning += $rMark === '+' ? 1 : -1;
			$rMost = max($rMost, $rRunning);
		}
		$this->assertGreaterThan(0, $rMost, 'downloads ran');
		$this->assertLessThanOrEqual(2, $rMost, 'the most downloads of the line running at one moment');
		$this->assertLessThanOrEqual(2, count($this->kept()), 'no more files than the line may have downloads');
	}

	public function testEachKindOfDownloadAndEachLineIsCountedOnItsOwn(): void {
		$this->assertNotNull($this->download(1, 'playlist', 7));
		$this->assertNull($this->download(1, 'playlist', 7));
		$this->assertNotNull($this->download(1, 'epg', 7), 'the line\'s XMLTV download beside its playlist download');
		$this->assertNull($this->download(1, 'epg', 7));
		$this->assertNotNull($this->download(1, 'playlist', 8), 'another line\'s playlist download');
		$this->assertNotNull($this->download(1, 'playlist', 78), 'and that of a line whose number begins with the same digit');
	}

	public function testAWorkerIsNotCountedAgainstItself(): void {
		$rWorker = $this->download(1);
		$this->assertNotNull($rWorker);
		$this->assertSame('1', $this->tell($rWorker, 'start'), 'the download a worker begins in place of its last');
		$this->assertNull($this->download(1), 'another download while that one runs');
		$this->assertSame('stopped', $this->tell($rWorker, 'stop'));
		$this->assertNotNull($this->download(1), 'and once it has ended');
	}

	public function testNoLimitAndARestreamerAreNotCounted(): void {
		foreach ([1, 2, 3] as $rNth) {
			$this->assertNotNull($this->download(0), 'download ' . $rNth . ' with no limit set');
			$this->assertNotNull($this->download(1, 'playlist', 9, true), 'download ' . $rNth . ' of a restreamer');
		}
		$this->assertSame([], $this->kept());
	}

	public function testNobodyIsRefusedWhereNoFileCanBeKept(): void {
		foreach ([1, 2, 3] as $rNth) {
			$this->assertNotNull($this->download(2, 'playlist', 7, false, 'missing/'), 'download ' . $rNth);
		}
	}

	public function testTheFileOfARunningDownloadOutlastsTheSweepOfTemporaryFiles(): void {
		$this->assertNotNull($this->download(2));
		$rKept = $this->kept();
		$this->assertCount(1, $rKept);

		// A download may run for hours.
		foreach ([0, 3600, 6 * 3600] as $rLater) {
			$this->assertFalse($this->swept($rKept[0], $rLater), $rLater . ' seconds into the download');
		}
	}

	public function testTheFilesOfDownloadsThatEndedAreTheSweepsToRemove(): void {
		$rFirst = $this->download(2);
		$rSecond = $this->download(2);
		$this->tell($rFirst, 'stop');
		$this->tell($rSecond, 'stop');
		// The file of a download whose process was killed is the line's next
		// download's, and is given back when that one ends.
		$this->kill($this->download(2));
		$this->tell($this->download(2), 'stop');

		$rKept = $this->kept();
		$this->assertNotSame([], $rKept);
		$this->assertLessThanOrEqual(2, count($rKept), 'no more files than the line may have downloads');
		foreach ($rKept as $rName) {
			$this->assertTrue($this->swept($rName, 600), $rName . ', ten minutes after the downloads ended');
		}
	}
}
