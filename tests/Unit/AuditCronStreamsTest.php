<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Core\Logging\FileLogger;
use XcVm\Domain\Stream\NodeStreams;
use XcVm\Tests\Support\InstallSchema;

/** A DatabaseHandler over a connection the test opened (the panel's comes from the xcvm_core extension). */
final class AuditCronStreamsDb extends DatabaseHandler {
	public function __construct(\PDO $rPdo) {
		$this->dbh = $rPdo;
	}
}

/**
 * cron:cleanup and cron:streams act on the lists of this node's streams: a
 * file, a TV archive, a monitor or a producer no list names goes. So a list
 * is acted on only when it was read: a read MAIN's database did not answer
 * is unknown (null), never empty, and the pass that asked leaves everything
 * as it is. A list that was read and is empty stays what it is (a server
 * with no streams), and what it leaves behind still goes.
 *
 * cron:streams' last check (kill_rogue_ffmpeg) judges only the producers
 * that were there when the pass began: one started since is the next
 * pass's to judge. And a monitor is not a leftover while its stream's row
 * names it and shows nothing started yet: it is starting the stream.
 *
 * The crons run in a child PHP with a `ps` stand-in first on its PATH, which
 * names this test's own processes only, so nothing else on the machine can
 * be killed; the child checks that before it runs.
 */
final class AuditCronStreamsTest extends TestCase {
	private const TABLES = ['streams', 'streams_servers', 'streams_types', 'lines_live'];

	private string $rHome;

	private TestDb $rDb;

	/** @var list<resource> the processes the stand-in names */
	private array $rProcs = [];

	protected function setUp(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 5);
		}
		$this->rHome = sys_get_temp_dir() . '/xcvm-cron-streams-' . bin2hex(random_bytes(4)) . '/';
		foreach (['cache', 'stub', 'streams', 'archive/9', 'created'] as $rDir) {
			mkdir($this->rHome . $rDir, 0777, true);
		}
		// No replica here: the lists are MAIN's database's.
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, new FileCache($this->rHome . 'cache/'));
		FileLogger::setLogFile($this->rHome . 'error.log');
		$this->rDb = new TestDb();
		file_put_contents($this->rHome . 'stub/ps', "#!/bin/sh\n"
			. 'if [ -f ' . escapeshellarg($this->rHome . 'start_producer') . ' ] && [ ! -f ' . escapeshellarg($this->rHome . 'started.pid') . " ]; then\n"
			. "\tsleep 60 >/dev/null 2>&1 &\n"
			. "\techo \$! > " . escapeshellarg($this->rHome . 'started.pid') . "\n"
			. "\techo \"xc_vm \$! 0.0 0.0 producer " . $this->rHome . 'streams/8_.m3u8" >> ' . escapeshellarg($this->rHome . 'ps.out') . "\n"
			. "fi\n"
			. 'cat ' . escapeshellarg($this->rHome . 'ps.out') . "\n");
		chmod($this->rHome . 'stub/ps', 0755);
		file_put_contents($this->rHome . 'ps.out', '');
	}

	protected function tearDown(): void {
		foreach ($this->rProcs as $rProc) {
			proc_terminate($rProc, 9);
			proc_close($rProc);
		}
		if (($rStarted = $this->started()) > 0) {
			posix_kill($rStarted, 9);
		}
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, null);
		FileLogger::setLogFile(null);
		exec('rm -rf ' . escapeshellarg($this->rHome));
	}

	/** The streams' tables as an install has them, empty. */
	private function tables(): void {
		foreach (self::TABLES as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
	}

	/** A database as the panel's own handler reads it: a statement that fails answers false. */
	private function handler(): AuditCronStreamsDb {
		return new AuditCronStreamsDb(TestDb::connect($this->rDb->schema()));
	}

	/** @return array<string, mixed> each list cron:cleanup and cron:streams act on */
	private function lists(): array {
		$rDb = $this->handler();
		return [
			'files' => NodeStreams::fileStreams($rDb),
			'archives' => NodeStreams::archives($rDb),
			'created' => NodeStreams::createdIDs($rDb),
			'live (Redis)' => NodeStreams::liveChecks(true, $rDb),
			'live (MySQL)' => NodeStreams::liveChecks(false, $rDb),
			'on demand' => NodeStreams::onDemandIDs($rDb),
		];
	}

	/** A process of this test's, as `ps` lists it under $rName: its pid. */
	private function process(string $rName): int {
		$rProc = proc_open(['sleep', '60'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$this->rProcs[] = $rProc;
		$rPid = (int) proc_get_status($rProc)['pid'];
		file_put_contents($this->rHome . 'ps.out', 'xc_vm ' . $rPid . ' 0.0 0.0 ' . $rName . "\n", FILE_APPEND);
		return $rPid;
	}

	/** A stream's monitor as the panel starts one (a PHP process titled XC_VM[<id>]), of this test's: its pid. */
	private function monitor(int $rStreamID): int {
		$rProc = proc_open([PHP_BINARY, '-r', 'cli_set_process_title("XC_VM[' . $rStreamID . ']"); sleep(60);'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$this->rProcs[] = $rProc;
		$rPid = (int) proc_get_status($rProc)['pid'];
		for ($i = 0; $i < 100 && trim((string) @file_get_contents('/proc/' . $rPid . '/cmdline')) !== 'XC_VM[' . $rStreamID . ']'; $i++) {
			usleep(20000);
		}
		$this->assertSame('XC_VM[' . $rStreamID . ']', trim((string) @file_get_contents('/proc/' . $rPid . '/cmdline')));
		file_put_contents($this->rHome . 'ps.out', 'xc_vm ' . $rPid . ' 0.0 0.0 XC_VM[' . $rStreamID . "]\n", FILE_APPEND);
		return $rPid;
	}

	/** The producer the stand-in started under the pass, or 0. */
	private function started(): int {
		return (int) @file_get_contents($this->rHome . 'started.pid');
	}

	private static function alive(int $rPid): bool {
		$rStat = @file_get_contents('/proc/' . $rPid . '/stat');
		return is_string($rStat) && !preg_match('/\) Z /', $rStat);
	}

	private static function gone(int $rPid): bool {
		for ($i = 0; $i < 50 && self::alive($rPid); $i++) {
			usleep(20000);
		}
		return !self::alive($rPid);
	}

	/**
	 * Run a cron's pass against the test's database, in a child PHP.
	 *
	 * @param string $rDropAt a statement's first words: the streams' tables go right before it runs
	 * @return string what the pass printed
	 */
	private function pass(string $rCron, string $rDropAt = ''): string {
		$rScript = $this->rHome . 'pass.php';
		file_put_contents($rScript, <<<'PHP'
			<?php
			use XcVm\Cli\CronJobs\CleanupCronJob;
			use XcVm\Cli\CronJobs\StreamsCronJob;
			use XcVm\Core\Cluster\NodeFlows;
			use XcVm\Core\Cluster\NodeLease;
			use XcVm\Core\Cluster\NodeRole;
			use XcVm\Core\Config\SettingsManager;
			use XcVm\Core\Database\DatabaseHandler;
			use XcVm\Core\Logging\FileLogger;
			use XcVm\Infrastructure\Database\DatabaseFactory;

			$rHome = getenv('XCVM_TEST_HOME');
			// The pass kills what `ps` names: only ever with the stand-in's list.
			if (trim((string) shell_exec('command -v ps')) !== $rHome . 'stub/ps') {
				echo "UNSAFE: ps is not the stand-in\n";
				exit(3);
			}
			define('CACHE_TMP_PATH', $rHome . 'cache/');
			require getenv('XCVM_TEST_BOOTSTRAP');
			define('SERVER_ID', 5);
			foreach (['STREAMS_PATH' => 'streams/', 'ARCHIVE_PATH' => 'archive/', 'CREATED_PATH' => 'created/', 'SIGNALS_TMP_PATH' => 'signals/', 'CONS_TMP_PATH' => 'cons/'] as $rName => $rDir) {
				define($rName, $rHome . $rDir);
			}
			FileLogger::setLogFile($rHome . 'error.log');
			// Mode 0, not MAIN: no agent's files, no fence, no replica.
			NodeFlows::usePath($rHome . 'flows.json');
			NodeLease::usePath($rHome . 'lease_state.json', $rHome . 'fence.json');
			NodeRole::useServers(static fn(): array => []);
			SettingsManager::set(['cleanup' => 1, 'check_vod' => 0, 'redis_handler' => 0, 'kill_rogue_ffmpeg' => 1, 'fanout_enabled' => 0]);
			$db = new class(TestDb::connect(getenv('XCVM_TEST_SCHEMA'))) extends DatabaseHandler {
				public function __construct(\PDO $rPdo) {
					$this->dbh = $rPdo;
				}

				public function query(string $query, mixed $buffered = false) {
					// The database goes from under a running pass, as a restore drops it.
					$rAt = (string) getenv('XCVM_TEST_DROP_AT');
					if ($rAt !== '' && str_starts_with($query, $rAt)) {
						$this->dbh->exec('DROP TABLE `streams_servers`');
					}
					return parent::query(...func_get_args());
				}
			};
			DatabaseFactory::set($db);
			$rJob = $argv[1] === 'cleanup' ? new CleanupCronJob() : new StreamsCronJob();
			(new ReflectionMethod($rJob, 'loadCron'))->invoke($rJob);
			echo "PASS DONE\n";
			PHP);
		$rEnv = TestDb::env() + [
			'XCVM_TEST_HOME' => $this->rHome, 'XCVM_TEST_BOOTSTRAP' => dirname(__DIR__) . '/bootstrap.php', 'XCVM_TEST_SCHEMA' => $this->rDb->schema(),
			'XCVM_TEST_DROP_AT' => $rDropAt, 'PATH' => $this->rHome . 'stub:' . getenv('PATH'),
		];
		// The processes the stand-in names began before the pass, by more than a clock tick.
		usleep(50000);
		$rProc = proc_open([PHP_BINARY, $rScript, $rCron], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes, $this->rHome, $rEnv);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]) . (string) stream_get_contents($rPipes[2]);
		fclose($rPipes[1]);
		fclose($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rOut);
		$this->assertStringContainsString('PASS DONE', $rOut);
		return $rOut;
	}

	// ── the lists ────────────────────────────────────────────────────

	public function testAListMainsDatabaseDidNotAnswerIsUnknown(): void {
		// The database as a restore leaves it for a while: created, its tables not yet.
		foreach ($this->lists() as $rList => $rAnswer) {
			$this->assertNull($rAnswer, $rList);
		}
	}

	public function testAListThatWasReadAndIsEmptyIsEmpty(): void {
		$this->tables();
		foreach ($this->lists() as $rList => $rAnswer) {
			$this->assertSame([], $rAnswer, $rList);
		}
	}

	// ── cron:cleanup ─────────────────────────────────────────────────

	/** @return list<string> a stream's file, a TV archive's segment and a created channel's file */
	private function files(): array {
		$rFiles = [$this->rHome . 'streams/7_.m3u8', $this->rHome . 'archive/9/2020-01-01:10-00.ts', $this->rHome . 'created/3_.list'];
		foreach ($rFiles as $rFile) {
			file_put_contents($rFile, 'x');
		}
		return $rFiles;
	}

	public function testCleanupDeletesNothingByAListItCouldNotRead(): void {
		$rFiles = $this->files();
		$rOut = $this->pass('cleanup');
		$this->assertStringNotContainsString('Deleting', $rOut);
		foreach ($rFiles as $rFile) {
			$this->assertFileExists($rFile);
		}
	}

	public function testCleanupStillDeletesWhatAnEmptyListLeavesBehind(): void {
		$this->tables();
		$rFiles = $this->files();
		$this->pass('cleanup');
		foreach ($rFiles as $rFile) {
			$this->assertFileDoesNotExist($rFile);
		}
		$this->assertDirectoryDoesNotExist($this->rHome . 'archive/9');
	}

	// ── cron:streams ─────────────────────────────────────────────────

	public function testTheStreamsPassKillsNothingByAListItCouldNotRead(): void {
		$rMonitor = $this->process('XC_VM[77]');
		$rProducer = $this->process('ffmpeg -i http://src.example/a ' . $this->rHome . 'streams/78_.m3u8');
		file_put_contents($this->rHome . 'streams/77_.m3u8', 'x');
		$rOut = $this->pass('streams');
		$this->assertStringNotContainsString('Kill Stream ID', $rOut);
		$this->assertStringNotContainsString('Kill Roque PID', $rOut);
		$this->assertTrue(self::alive($rMonitor), 'the monitor');
		$this->assertTrue(self::alive($rProducer), 'the producer');
		$this->assertFileExists($this->rHome . 'streams/77_.m3u8');
	}

	public function testTheStreamsPassKillsNothingWhenItsOnDemandStreamsCouldNotBeRead(): void {
		$this->tables();
		// An on-demand stream nobody watches: its monitor waits, and only the on-demand list names it.
		$this->rDb->exec("INSERT INTO `streams` (`id`, `type`, `stream_display_name`) VALUES (77, 1, 'On demand')");
		$this->rDb->exec('INSERT INTO `streams_servers` (`stream_id`, `server_id`, `on_demand`) VALUES (77, 5, 1)');
		$rMonitor = $this->process('XC_VM[77]');
		$rOut = $this->pass('streams', 'SELECT `stream_id` FROM `streams_servers` WHERE `on_demand` = 1');
		$this->assertStringNotContainsString('Kill Stream ID', $rOut);
		$this->assertTrue(self::alive($rMonitor), 'the monitor');
	}

	public function testTheStreamsPassStillStopsWhatAnEmptyListLeavesBehind(): void {
		$this->tables();
		$rMonitor = $this->process('XC_VM[77]');
		$rProducer = $this->process('ffmpeg -i http://src.example/a ' . $this->rHome . 'streams/78_.m3u8');
		file_put_contents($this->rHome . 'streams/77_.m3u8', 'x');
		$rOut = $this->pass('streams');
		$this->assertStringContainsString('Kill Stream ID: 77', $rOut);
		$this->assertTrue(self::gone($rMonitor), 'the monitor of a stream this server does not have');
		$this->assertFileDoesNotExist($this->rHome . 'streams/77_.m3u8');
		$this->assertStringContainsString('Kill Roque PID: ' . $rProducer, $rOut);
		$this->assertTrue(self::gone($rProducer), 'a producer no stream owns');
	}

	public function testAMonitorThatHasNotStartedItsStreamYetIsLeftToStartIt(): void {
		$this->tables();
		// A stopped stream started a moment ago: its monitor is in its row, and no list names
		// the stream until the monitor has probed its sources and started a producer.
		$rMonitor = $this->monitor(77);
		$this->rDb->exec("INSERT INTO `streams` (`id`, `type`, `stream_display_name`) VALUES (77, 1, 'Starting')");
		$this->rDb->exec('INSERT INTO `streams_servers` (`stream_id`, `server_id`, `monitor_pid`) VALUES (77, 5, ' . $rMonitor . ')');
		file_put_contents($this->rHome . 'streams/77_.monitor', (string) $rMonitor);
		$rOut = $this->pass('streams');
		$this->assertStringNotContainsString('Kill Stream ID', $rOut);
		$this->assertTrue(self::alive($rMonitor), 'the monitor');
		$this->assertFileExists($this->rHome . 'streams/77_.monitor');
	}

	public function testAMonitorItsStreamsRowDoesNotAccountForIsStillStopped(): void {
		$this->tables();
		// Stopped from the panel: the row names no monitor, and one is left.
		$rStopped = $this->monitor(77);
		$this->rDb->exec("INSERT INTO `streams` (`id`, `type`, `stream_display_name`) VALUES (77, 1, 'Stopped')");
		$this->rDb->exec('INSERT INTO `streams_servers` (`stream_id`, `server_id`) VALUES (77, 5)');
		// Past its start (the row has a producer) and no list names it: a stream that
		// has become a direct source since.
		$rDirect = $this->monitor(78);
		$this->rDb->exec("INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `direct_source`) VALUES (78, 1, 'Direct', 1)");
		$this->rDb->exec('INSERT INTO `streams_servers` (`stream_id`, `server_id`, `monitor_pid`, `pid`) VALUES (78, 5, ' . $rDirect . ', 4194000)');
		$rOut = $this->pass('streams');
		$this->assertStringContainsString('Kill Stream ID: 77', $rOut);
		$this->assertTrue(self::gone($rStopped), 'the monitor of a stopped stream');
		$this->assertStringContainsString('Kill Stream ID: 78', $rOut);
		$this->assertTrue(self::gone($rDirect), 'the monitor of a stream no list names');
	}

	public function testAProducerStartedUnderThePassIsLeftToTheNextOne(): void {
		$this->tables();
		$rOld = $this->process('ffmpeg -i http://src.example/a ' . $this->rHome . 'streams/7_.m3u8');
		// The stand-in starts a producer when the pass first asks it, after the streams were read.
		touch($this->rHome . 'start_producer');
		$rOut = $this->pass('streams');
		$rStarted = $this->started();
		$this->assertGreaterThan(0, $rStarted, $rOut);
		$this->assertStringContainsString('Kill Roque PID: ' . $rOld, $rOut, 'a producer from before the pass is still judged');
		$this->assertTrue(self::gone($rOld));
		$this->assertStringNotContainsString('Kill Roque PID: ' . $rStarted, $rOut);
		$this->assertTrue(self::alive($rStarted), 'the producer started under the pass');
	}
}
