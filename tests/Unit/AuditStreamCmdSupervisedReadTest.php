<?php

use PHPUnit\Framework\TestCase;
use XcVm\Tests\Support\InstallSchema;

/**
 * cron:streams brings the rows in step with the fanout supervisor before it
 * reads its streams (StreamProcess::reconcileSupervised), and releases every
 * stream the daemon supervises that no row names. A read MAIN's database did
 * not answer names no row: it is unknown, and nothing is released by it, and
 * no stream is taken for unwatched and given a monitor because of it.
 *
 * The pass runs in a child PHP against a stand-in for the daemon's control
 * socket, which reports stream 12 as supervised and records each request,
 * and with a stand-in for the PHP binary that records what it is asked to run.
 */
final class AuditStreamCmdSupervisedReadTest extends TestCase {
	private string $rHome;

	private TestDb $rDb;

	/** @var resource|null */
	private $rControl = null;

	protected function setUp(): void {
		$this->rHome = sys_get_temp_dir() . '/xcvm-cron-supervised-' . bin2hex(random_bytes(4)) . '/';
		foreach (['cache', 'stub', 'streams'] as $rDir) {
			mkdir($this->rHome . $rDir, 0777, true);
		}
		$this->rDb = new TestDb();
		// `ps` names nothing: the pass has no process to judge.
		file_put_contents($this->rHome . 'stub/ps', "#!/bin/sh\nexit 0\n");
		chmod($this->rHome . 'stub/ps', 0755);
		// A monitor the pass starts is recorded, never run.
		file_put_contents($this->rHome . 'started.log', '');
		file_put_contents($this->rHome . 'stub/php', "#!/bin/sh\necho \"\$@\" >> " . escapeshellarg($this->rHome . 'started.log') . "\n");
		chmod($this->rHome . 'stub/php', 0755);
		file_put_contents($this->rHome . 'control.log', '');
		file_put_contents($this->rHome . 'control.php', <<<'PHP'
			<?php
			$rServer = stream_socket_server('unix://' . $argv[1]);
			while ($rConn = @stream_socket_accept($rServer, 120)) {
				$rRequest = rtrim((string) fgets($rConn));
				while (($rLine = fgets($rConn)) !== false && rtrim($rLine) !== '') {
				}
				file_put_contents($argv[2], $rRequest . "\n", FILE_APPEND);
				if (str_starts_with($rRequest, 'GET /monitors/state')) {
					$rBody = json_encode(['accepting' => false, 'daemon_pid' => 1, 'features' => [], 'streams' => ['12' => ['running' => true, 'pid' => 0]]]);
					fwrite($rConn, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($rBody) . "\r\nConnection: close\r\n\r\n" . $rBody);
				} else {
					fwrite($rConn, "HTTP/1.1 204 No Content\r\nConnection: close\r\n\r\n");
				}
				fclose($rConn);
			}
			PHP);
		$this->rControl = proc_open([PHP_BINARY, $this->rHome . 'control.php', $this->rHome . 'control.sock', $this->rHome . 'control.log'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		for ($i = 0; $i < 100 && !file_exists($this->rHome . 'control.sock'); $i++) {
			usleep(20000);
		}
		$this->assertFileExists($this->rHome . 'control.sock');
	}

	protected function tearDown(): void {
		if (is_resource($this->rControl)) {
			proc_terminate($this->rControl, 9);
			proc_close($this->rControl);
		}
		exec('rm -rf ' . escapeshellarg($this->rHome));
	}

	/**
	 * @param bool $rSupervisedRowsFail The read of the supervised streams' rows fails; every other read answers.
	 * @return array{0: string, 1: string, 2: string} what the pass printed, what the daemon was asked, what was started
	 */
	private function pass(bool $rSupervisedRowsFail = false): array {
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
			if (trim((string) shell_exec('command -v ps')) !== $rHome . 'stub/ps') {
				echo "UNSAFE: ps is not the stand-in\n";
				exit(3);
			}
			define('CACHE_TMP_PATH', $rHome . 'cache/');
			define('FANOUT_CTL_SOCK', $rHome . 'control.sock');
			define('PHP_BIN', $rHome . 'stub/php');
			require getenv('XCVM_TEST_BOOTSTRAP');
			define('SERVER_ID', 5);
			foreach (['STREAMS_PATH' => 'streams/', 'SIGNALS_TMP_PATH' => 'signals/', 'CONS_TMP_PATH' => 'cons/'] as $rName => $rDir) {
				define($rName, $rHome . $rDir);
			}
			FileLogger::setLogFile($rHome . 'error.log');
			NodeFlows::usePath($rHome . 'flows.json');
			NodeLease::usePath($rHome . 'lease_state.json', $rHome . 'fence.json');
			NodeRole::useServers(static fn(): array => []);
			SettingsManager::set(['redis_handler' => 0, 'kill_rogue_ffmpeg' => 1, 'fanout_enabled' => 1]);
			$db = new class(TestDb::connect(getenv('XCVM_TEST_SCHEMA'))) extends DatabaseHandler {
				public function __construct(\PDO $rPdo) {
					$this->dbh = $rPdo;
				}

				public function query(string $query, mixed $buffered = false) {
					if (getenv('XCVM_TEST_SUPERVISED_ROWS_FAIL') && str_contains($query, '`stream_id` IN (')) {
						return false;
					}
					return parent::query(...func_get_args());
				}
			};
			DatabaseFactory::set($db);
			$rJob = new StreamsCronJob();
			(new ReflectionMethod($rJob, 'loadCron'))->invoke($rJob);
			echo "PASS DONE\n";
			PHP);
		$rEnv = TestDb::env() + [
			'XCVM_TEST_HOME' => $this->rHome, 'XCVM_TEST_BOOTSTRAP' => dirname(__DIR__) . '/bootstrap.php', 'XCVM_TEST_SCHEMA' => $this->rDb->schema(),
			'XCVM_TEST_SUPERVISED_ROWS_FAIL' => $rSupervisedRowsFail ? '1' : '', 'PATH' => $this->rHome . 'stub:' . getenv('PATH'),
		];
		$rProc = proc_open([PHP_BINARY, $rScript], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes, $this->rHome, $rEnv);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]) . (string) stream_get_contents($rPipes[2]);
		fclose($rPipes[1]);
		fclose($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rOut);
		$this->assertStringContainsString('PASS DONE', $rOut);
		// What the pass started runs in the background: give it the time to be recorded.
		usleep(300000);
		return [$rOut, (string) file_get_contents($this->rHome . 'control.log'), (string) file_get_contents($this->rHome . 'started.log')];
	}

	/** The tables the pass reads, with stream 12 as a hand-over leaves its row: the daemon's pid, a start in progress. */
	private function seedSupervisedStream(): void {
		foreach (['streams', 'streams_servers', 'streams_types', 'lines_live'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec("INSERT INTO `streams_types` (`type_id`, `type_name`, `type_key`, `type_output`, `live`) VALUES (1, 'Live Streams', 'live', 'live', 1)");
		$this->rDb->exec("INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `direct_source`) VALUES (12, 1, 'Twelve', 0)");
		$this->rDb->exec('INSERT INTO `streams_servers` (`stream_id`, `server_id`, `monitor_pid`, `stream_status`) VALUES (12, 5, 1, 2)');
	}

	public function testAPassThatCouldNotReadItsRowsReleasesNoSupervisedStream(): void {
		// The database as a restore leaves it for a while: created, its tables not yet.
		[$rOut, $rAsked] = $this->pass();
		$this->assertStringContainsString('GET /monitors/state', $rAsked, $rOut);
		$this->assertStringContainsString('could not be read', $rOut);
		$this->assertStringNotContainsString('DELETE /monitor/12', $rAsked, $rOut);
	}

	public function testAPassThatReadItsRowsStillReleasesASupervisedStreamNoRowNames(): void {
		foreach (['streams', 'streams_servers', 'streams_types', 'lines_live'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		[$rOut, $rAsked] = $this->pass();
		$this->assertStringContainsString('DELETE /monitor/12', $rAsked, $rOut);
	}

	public function testAPassThatReadItsRowsLeavesASupervisedStreamAsItIs(): void {
		$this->seedSupervisedStream();
		[$rOut, $rAsked, $rStarted] = $this->pass();
		$this->assertStringContainsString('Stream ID: 12', $rOut);
		$this->assertStringNotContainsString('DELETE /monitor/12', $rAsked, $rOut);
		$this->assertStringNotContainsString('Start monitor', $rOut);
		$this->assertSame('', $rStarted, $rOut);
	}

	/** The supervised rows not read, the streams read a moment later: which of them the daemon runs is unknown. */
	public function testAPassThatCouldNotReadTheSupervisedRowsStartsNoMonitor(): void {
		$this->seedSupervisedStream();
		[$rOut, $rAsked, $rStarted] = $this->pass(true);
		$this->assertStringContainsString('GET /monitors/state', $rAsked, $rOut);
		$this->assertStringContainsString('could not be read', $rOut);
		$this->assertStringNotContainsString('DELETE /monitor/12', $rAsked, $rOut);
		$this->assertStringNotContainsString('Start monitor', $rOut);
		$this->assertSame('', $rStarted, $rOut);
	}
}
