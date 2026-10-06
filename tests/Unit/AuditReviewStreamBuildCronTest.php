<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Tests\Support\InstallSchema;

/**
 * cron:streams reads this node's live streams at the top of its pass and
 * asks `ps` for the monitors at its end: a monitor no read named goes. A
 * monitor writes its stream's row whenever its sources answer, or do not,
 * so the row is read again for each such monitor: one its row names, whose
 * stream the read at the top would name now, started the stream under the
 * pass and is the next pass's to check. One whose stream no pass reads (a
 * direct source) is still a leftover.
 *
 * The row is read where the pass read its streams. On a node whose replica
 * owns the streams while its own store is not seeded (mode 1 with STREAMS
 * on, the agent stopped), both are MAIN's database, where the monitor
 * writes: the stream caches carry no runtime state.
 *
 * The pass runs in a child PHP with a `ps` stand-in first on its PATH, which
 * names this test's own processes only, so nothing else on the machine can
 * be killed; the child checks that before it runs.
 */
final class AuditReviewStreamBuildCronTest extends TestCase {
	private string $rHome;

	private TestDb $rDb;

	/** @var list<resource> the monitors the stand-in names */
	private array $rProcs = [];

	protected function setUp(): void {
		$this->rHome = sys_get_temp_dir() . '/xcvm-cron-start-' . bin2hex(random_bytes(4)) . '/';
		foreach (['cache', 'stub', 'streams'] as $rDir) {
			mkdir($this->rHome . $rDir, 0777, true);
		}
		$this->rDb = new TestDb();
		foreach (['streams', 'streams_servers', 'streams_types', 'lines_live', 'profiles', 'recordings', 'streams_arguments', 'streams_options'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec("INSERT INTO `streams_types` (`type_id`, `type_name`, `type_key`, `type_output`, `live`) VALUES (1, 'Live Streams', 'live', 'live', 1)");
		file_put_contents($this->rHome . 'stub/ps', "#!/bin/sh\ncat " . escapeshellarg($this->rHome . 'ps.out') . "\n");
		chmod($this->rHome . 'stub/ps', 0755);
		file_put_contents($this->rHome . 'ps.out', '');
	}

	protected function tearDown(): void {
		foreach ($this->rProcs as $rProc) {
			proc_terminate($rProc, 9);
			proc_close($rProc);
		}
		exec('rm -rf ' . escapeshellarg($this->rHome));
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
	 * Run cron:streams' pass against the test's database, in a child PHP.
	 *
	 * @param string $rWrite a statement run after the pass read its live streams and before it asks `ps`
	 * @param bool $rReplica the node's replica owns its streams and its own store is not seeded
	 * @return string what the pass printed
	 */
	private function pass(string $rWrite = '', bool $rReplica = false): string {
		$rScript = $this->rHome . 'pass.php';
		file_put_contents($rScript, <<<'PHP'
			<?php
			use XcVm\Cli\CronJobs\StreamsCronJob;
			use XcVm\Core\Cluster\AgentClient;
			use XcVm\Core\Cluster\EventSpool;
			use XcVm\Core\Cluster\NodeFlows;
			use XcVm\Core\Cluster\NodeLease;
			use XcVm\Core\Cluster\NodeRole;
			use XcVm\Core\Cluster\ReplicaApply;
			use XcVm\Core\Cluster\ReplicaStreamCache;
			use XcVm\Core\Cluster\StreamRecords;
			use XcVm\Core\Cluster\StreamRuntime;
			use XcVm\Core\Config\SettingsManager;
			use XcVm\Core\Database\DatabaseHandler;
			use XcVm\Core\Logging\FileLogger;
			use XcVm\Domain\Stream\StreamSource;
			use XcVm\Infrastructure\Database\DatabaseFactory;
			use XcVm\Tests\Support\ReplicaFixture;

			$rHome = getenv('XCVM_TEST_HOME');
			// The pass kills what `ps` names: only ever with the stand-in's list.
			if (trim((string) shell_exec('command -v ps')) !== $rHome . 'stub/ps') {
				echo "UNSAFE: ps is not the stand-in\n";
				exit(3);
			}
			define('CACHE_TMP_PATH', $rHome . 'cache/');
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
			SettingsManager::set(['redis_handler' => 0, 'kill_rogue_ffmpeg' => 0, 'fanout_enabled' => 0]);
			$db = new class(TestDb::connect(getenv('XCVM_TEST_SCHEMA'))) extends DatabaseHandler {
				public function __construct(\PDO $rPdo) {
					$this->dbh = $rPdo;
				}

				public function query(string $query, mixed $buffered = false) {
					// The read of the on-demand streams: the live streams were read, `ps` is asked next.
					$rWrite = (string) getenv('XCVM_TEST_WRITE');
					if ($rWrite !== '' && str_starts_with($query, 'SELECT `stream_id` FROM `streams_servers` WHERE `on_demand` = 1')) {
						$this->dbh->exec($rWrite);
					}
					return parent::query(...func_get_args());
				}
			};
			DatabaseFactory::set($db);
			if (getenv('XCVM_TEST_REPLICA') === '1') {
				// Mode 1 with STREAMS on, the stream caches built from MAIN's records, and no
				// agent running: nothing seeds the node's store, and it writes MAIN's database.
				mkdir($rHome . 'cluster/spool', 0777, true);
				$rFixture = new ReplicaFixture($rHome . 'cluster/');
				ReplicaApply::useDir($rFixture->dir());
				ReplicaApply::useConfigDir($rHome);
				EventSpool::useDir($rHome . 'cluster/spool/');
				StreamRuntime::useDir($rHome . 'cluster/runtime/');
				AgentClient::useSocket($rHome . 'no-agent.sock');
				NodeRole::useMainBuild(false);
				foreach (StreamRecords::data(5, StreamRecords::held(5, null)) as $rID => $rData) {
					$rFixture->stream($rID, $rData, 3);
				}
				$rFixture->streamsSince(7);
				file_put_contents($rHome . 'flows.json', json_encode(['mode' => 1, 'flows' => NodeFlows::STREAMS, 'state' => 'active']));
				NodeFlows::usePath($rHome . 'flows.json');
				ReplicaApply::run(false, 1800000000, 5, false);
				if (!ReplicaStreamCache::owned() || StreamSource::local()) {
					echo "NOT THE NODE ASKED FOR\n";
					exit(4);
				}
			}
			$rJob = new StreamsCronJob();
			(new ReflectionMethod($rJob, 'loadCron'))->invoke($rJob);
			echo "PASS DONE\n";
			PHP);
		$rEnv = TestDb::env() + [
			'XCVM_TEST_HOME' => $this->rHome, 'XCVM_TEST_BOOTSTRAP' => dirname(__DIR__) . '/bootstrap.php', 'XCVM_TEST_SCHEMA' => $this->rDb->schema(),
			'XCVM_TEST_WRITE' => $rWrite, 'XCVM_TEST_REPLICA' => $rReplica ? '1' : '', 'PATH' => $this->rHome . 'stub:' . getenv('PATH'),
		];
		$rProc = proc_open([PHP_BINARY, $rScript], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes, $this->rHome, $rEnv);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]) . (string) stream_get_contents($rPipes[2]);
		fclose($rPipes[1]);
		fclose($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rOut);
		$this->assertStringContainsString('PASS DONE', $rOut);
		return $rOut;
	}

	/** @return array<string, array{string, bool}> what a monitor writes to its stream's row once its sources were tried, and the node (pass()) */
	public static function starts(): array {
		$rOut = [];
		foreach ([
			'a source answered: the producer runs' => '`pid` = 4194000, `stream_status` = 2, `stream_started` = UNIX_TIMESTAMP()',
			'no source answered: the monitor tries again' => '`pid` = -1, `stream_status` = 1',
		] as $rName => $rWrote) {
			$rOut[$rName] = [$rWrote, false];
			$rOut[$rName . ', on a node whose replica owns its streams'] = [$rWrote, true];
		}
		return $rOut;
	}

	#[DataProvider('starts')]
	public function testAMonitorThatStartedItsStreamUnderThePassIsLeftToTheNextOne(string $rWrote, bool $rReplica): void {
		// Started a moment before the pass: the row names the monitor and nothing started, so the
		// pass's read of the live streams does not name the stream. Its sources are tried under the pass.
		$rMonitor = $this->monitor(77);
		$this->rDb->exec("INSERT INTO `streams` (`id`, `type`, `stream_display_name`) VALUES (77, 1, 'Starting')");
		$this->rDb->exec('INSERT INTO `streams_servers` (`stream_id`, `server_id`, `monitor_pid`) VALUES (77, 5, ' . $rMonitor . ')');
		file_put_contents($this->rHome . 'streams/77_.m3u8', 'x');
		$rOut = $this->pass('UPDATE `streams_servers` SET ' . $rWrote . ' WHERE `stream_id` = 77', $rReplica);
		// The case itself: the pass's read did not name the stream, and the row was written before `ps` was asked.
		$this->assertStringNotContainsString("\nStream ID: 77\n", "\n" . $rOut);
		$this->assertNotNull($this->rDb->pdo->query('SELECT `pid` FROM `streams_servers` WHERE `stream_id` = 77')->fetchColumn(), 'the row was written under the pass');
		$this->assertStringNotContainsString('Kill Stream ID', $rOut);
		$this->assertTrue(self::alive($rMonitor), 'the monitor');
		$this->assertFileExists($this->rHome . 'streams/77_.m3u8');
	}

	/** @return array<string, array{bool}> the node (pass()) */
	public static function nodes(): array {
		return ['a node that reads MAIN\'s database' => [false], 'a node whose replica owns its streams' => [true]];
	}

	#[DataProvider('nodes')]
	public function testAMonitorWhoseStreamNoPassReadsIsStillStopped(bool $rReplica): void {
		// Past its start (the row names the monitor and a producer), and a direct source since:
		// a live stream by its type, which the read of the live streams never names.
		$rMonitor = $this->monitor(78);
		$this->rDb->exec("INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `direct_source`) VALUES (78, 1, 'Direct', 1)");
		$this->rDb->exec('INSERT INTO `streams_servers` (`stream_id`, `server_id`, `monitor_pid`, `pid`) VALUES (78, 5, ' . $rMonitor . ', 4194000)');
		file_put_contents($this->rHome . 'streams/78_.m3u8', 'x');
		$rOut = $this->pass('', $rReplica);
		$this->assertStringContainsString('Kill Stream ID: 78', $rOut);
		$this->assertTrue(self::gone($rMonitor), 'the monitor');
		$this->assertFileDoesNotExist($this->rHome . 'streams/78_.m3u8');
	}
}
