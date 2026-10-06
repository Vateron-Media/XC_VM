<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Tests\Support\InstallSchema;

/**
 * cron:streams starts a monitor for a stream that has none. A producer of
 * that stream that is still running is the stream's own: the new monitor
 * takes it as it is (MonitorCommand reads its pid from `<id>_.pid`, or from
 * the stream's row), so the pass's last check (kill_rogue_ffmpeg) leaves it
 * alone. A producer no stream owns still goes.
 *
 * The pass runs in a child PHP with a `ps` stand-in first on its PATH, which
 * names this test's own processes only, so nothing else on the machine can
 * be killed; the child checks that before it runs. A stand-in takes PHP's
 * place for the monitor the pass starts, and records that it was asked.
 */
final class AuditStreamBuildCronAdoptTest extends TestCase {
	private string $rHome;

	private TestDb $rDb;

	/** @var list<resource> the processes the stand-in names */
	private array $rProcs = [];

	protected function setUp(): void {
		$this->rHome = sys_get_temp_dir() . '/xcvm-cron-adopt-' . bin2hex(random_bytes(4)) . '/';
		foreach (['cache', 'stub', 'streams'] as $rDir) {
			mkdir($this->rHome . $rDir, 0777, true);
		}
		file_put_contents($this->rHome . 'ps.out', '');
		file_put_contents($this->rHome . 'stub/ps', "#!/bin/sh\ncat " . escapeshellarg($this->rHome . 'ps.out') . "\n");
		file_put_contents($this->rHome . 'stub/monitor-php', "#!/bin/sh\necho \"\$@\" >> " . escapeshellarg($this->rHome . 'monitors') . "\n");
		chmod($this->rHome . 'stub/ps', 0755);
		chmod($this->rHome . 'stub/monitor-php', 0755);

		$this->rDb = new TestDb();
		foreach (['streams', 'streams_servers', 'streams_types', 'lines_live'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec("INSERT INTO `streams_types` (`type_id`, `type_name`, `type_key`, `type_output`, `live`) VALUES (1, 'Live Streams', 'live', 'live', 1)");
	}

	protected function tearDown(): void {
		foreach ($this->rProcs as $rProc) {
			proc_terminate($rProc, 9);
			proc_close($rProc);
		}
		exec('rm -rf ' . escapeshellarg($this->rHome));
	}

	/** A producer of this test's, as `ps` lists it writing a stream's playlist: its pid. */
	private function producer(int $rStreamID): int {
		// A PHP process, as a stream's PHP relay is: ProcessManager::isStreamRunning() takes it for a running producer.
		$rProc = proc_open([PHP_BINARY, '-r', 'sleep(60);'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$this->rProcs[] = $rProc;
		$rPid = (int) proc_get_status($rProc)['pid'];
		file_put_contents($this->rHome . 'ps.out', 'xc_vm ' . $rPid . ' 0.0 0.0 ffmpeg -i http://src.example/a ' . $this->rHome . 'streams/' . $rStreamID . "_.m3u8\n", FILE_APPEND);
		return $rPid;
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

	/** Run cron:streams' pass against the test's database, in a child PHP: what it printed. */
	private function pass(): string {
		$rScript = $this->rHome . 'pass.php';
		file_put_contents($rScript, <<<'PHP'
			<?php
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
			// The monitor the pass starts is the stand-in, never a real one.
			define('PHP_BIN', $rHome . 'stub/monitor-php');
			require getenv('XCVM_TEST_BOOTSTRAP');
			define('SERVER_ID', 5);
			foreach (['STREAMS_PATH' => 'streams/', 'SIGNALS_TMP_PATH' => 'signals/', 'CONS_TMP_PATH' => 'cons/'] as $rName => $rDir) {
				define($rName, $rHome . $rDir);
			}
			FileLogger::setLogFile($rHome . 'error.log');
			// Mode 0, not MAIN: no agent's files, no fence, no replica.
			NodeFlows::usePath($rHome . 'flows.json');
			NodeLease::usePath($rHome . 'lease_state.json', $rHome . 'fence.json');
			NodeRole::useServers(static fn(): array => []);
			SettingsManager::set(['redis_handler' => 0, 'kill_rogue_ffmpeg' => 1, 'fanout_enabled' => 0]);
			$db = new class(TestDb::connect(getenv('XCVM_TEST_SCHEMA'))) extends DatabaseHandler {
				public function __construct(\PDO $rPdo) {
					$this->dbh = $rPdo;
				}
			};
			DatabaseFactory::set($db);
			$rJob = new StreamsCronJob();
			(new ReflectionMethod($rJob, 'loadCron'))->invoke($rJob);
			echo "PASS DONE\n";
			PHP);
		$rEnv = TestDb::env() + [
			'XCVM_TEST_HOME' => $this->rHome, 'XCVM_TEST_BOOTSTRAP' => dirname(__DIR__) . '/bootstrap.php', 'XCVM_TEST_SCHEMA' => $this->rDb->schema(),
			'PATH' => $this->rHome . 'stub:' . getenv('PATH'),
		];
		// The processes the stand-in names began before the pass, by more than a clock tick.
		usleep(50000);
		$rProc = proc_open([PHP_BINARY, $rScript], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes, $this->rHome, $rEnv);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]) . (string) stream_get_contents($rPipes[2]);
		fclose($rPipes[1]);
		fclose($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rOut);
		$this->assertStringContainsString('PASS DONE', $rOut);
		return $rOut;
	}

	public static function whereTheProducersPidIsKept(): array {
		return [
			'in the pid file' => [true],
			'in the row alone' => [false],
		];
	}

	#[DataProvider('whereTheProducersPidIsKept')]
	public function testAProducerItsNewMonitorTakesOverIsNotALeftover(bool $rPidFile): void {
		// Stream 77 runs and its monitor is gone: the row names a producer and no monitor.
		$rProducer = $this->producer(77);
		$this->rDb->exec("INSERT INTO `streams` (`id`, `type`, `stream_display_name`) VALUES (77, 1, 'Running')");
		$this->rDb->exec('INSERT INTO `streams_servers` (`stream_id`, `server_id`, `pid`, `stream_status`) VALUES (77, 5, ' . $rProducer . ', 0)');
		if ($rPidFile) {
			file_put_contents($this->rHome . 'streams/77_.pid', (string) $rProducer);
		}
		// A producer no stream owns.
		$rLeftover = $this->producer(78);

		$rOut = $this->pass();

		$this->assertStringContainsString('Start monitor...', $rOut);
		$this->assertStringContainsString('console.php monitor 77 0', (string) @file_get_contents($this->rHome . 'monitors'), 'a monitor was started to take the stream as it runs');
		$this->assertStringNotContainsString('Kill Roque PID: ' . $rProducer, $rOut);
		$this->assertTrue(self::alive($rProducer), 'the producer the new monitor takes over');
		$this->assertStringContainsString('Kill Roque PID: ' . $rLeftover, $rOut);
		$this->assertTrue(self::gone($rLeftover), 'a producer no stream owns');
	}
}
