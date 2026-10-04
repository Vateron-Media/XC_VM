<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\CleanupCronJob;
use XcVm\Cli\CronJobs\RootSignalsCronJob;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\LogSink;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Core\Cluster\RootPin;
use XcVm\Core\Cluster\StreamRuntime;
use XcVm\Tests\Support\AgentUser;
use XcVm\Tests\Support\FakeClusterCrypto;
use XcVm\Tests\Support\ReplicaFixture;

/**
 * The paths a node in mode 2 (api) still took to MAIN's database (cluster
 * plan, section 10; ADR 0004, tenth Phase 7 increment), each without one:
 * cron:root_signals' minute (its signals loop, the ramdisk, ports and
 * services checks from the replica, the crontab and sysctl checks), the
 * root actions' system log lines (a P1 `log.syslog` event, never before
 * acting on MAIN's database), the PHP-FPM restart, the watchdog, cron:cleanup's
 * stream checks, and cluster:apply's shadow comparison.
 *
 * Each runs in a child PHP booted for real from the node replica, in a
 * throwaway deploy root: an xcvm_core stand-in logs every connect it is
 * asked for, the refusal (ConnectAudit) counts every attempt, and `sudo`,
 * `crontab` and `ip` are stand-ins first on the child's PATH that log what
 * they were asked, so nothing reaches this machine's firewall, services or
 * crontab. The child checks that before it boots.
 */
final class ModeTwoPathsTest extends TestCase {
	private const NODE = '0f8fad5b-d9cb-469f-a165-70867728950e';

	private const NOW = 1800000000;

	private string $rHome;

	private ReplicaFixture $rFixture;

	/** @var resource|null a process whose command line reads as nginx's master */
	private $rNginx = null;

	protected function setUp(): void {
		$this->rHome = sys_get_temp_dir() . '/xcvm-mode2-' . bin2hex(random_bytes(4)) . '/';
		foreach (['config/cluster', 'tmp/cache', 'tmp/crons', 'tmp/flood', 'tmp/logs', 'bin/nginx/conf/ports', 'bin/nginx_rtmp/conf', 'content/streams', 'content/archive', 'content/created', 'content/vod', 'storage', 'stub'] as $rDir) {
			mkdir($this->rHome . $rDir, 0777, true);
		}
		// As root, a node's audits write as the owner of config/cluster/.
		AgentUser::own($this->rHome);
		foreach (['sudo', 'crontab', 'ip'] as $rTool) {
			file_put_contents($this->rHome . 'stub/' . $rTool, "#!/bin/sh\necho \"" . $rTool . " \$*\" >> " . escapeshellarg($this->rHome . 'commands.log') . "\n" . ($rTool === 'sudo' ? self::spoolCount($this->rHome) : '') . "exit 0\n");
			chmod($this->rHome . 'stub/' . $rTool, 0755);
		}
		// What the node runs today: two PHP-FPM pools (the replica says four),
		// the HTTP and RTMP ports as the replica has them, not its HTTPS port.
		file_put_contents($this->rHome . 'bin/daemons.sh', "#! /bin/bash\nstart-stop-daemon --start 1\nstart-stop-daemon --start 2\n");
		file_put_contents($this->rHome . 'bin/nginx/conf/ports/http.conf', 'listen 8080;');
		file_put_contents($this->rHome . 'bin/nginx/conf/ports/https.conf', '');
		file_put_contents($this->rHome . 'bin/nginx_rtmp/conf/port.conf', 'listen 8880;');
		foreach (['realip_xc_vm.conf', 'realip_cloudflare.conf', 'limit.conf', 'limit_queue.conf', 'ministra_legacy.conf', 'api_legacy.conf', 'viewer_api.conf'] as $rConf) {
			file_put_contents($this->rHome . 'bin/nginx/conf/' . $rConf, '');
		}
		// The hourly self-heals are not this test's: done a moment ago.
		foreach (['fanout_binary_check', 'xcvm_core_check', 'ytdlp_check', 'ffmpeg_check'] as $rStamp) {
			file_put_contents($this->rHome . 'tmp/crons/' . $rStamp, (string) time());
		}
		$this->rFixture = new ReplicaFixture($this->rHome . 'config/cluster/');
	}

	/**
	 * The sudo stand-in's second line: what it was asked, and how many P1
	 * spool files there were at that moment (sudo.log).
	 */
	private static function spoolCount(string $rHome): string {
		return 'echo "$* $(ls ' . escapeshellarg($rHome . 'config/cluster/spool/p1') . ' 2>/dev/null | wc -l)" >> ' . escapeshellarg($rHome . 'sudo.log') . "\n";
	}

	protected function tearDown(): void {
		if (is_resource($this->rNginx)) {
			proc_terminate($this->rNginx, 9);
			proc_close($this->rNginx);
		}
		NodeFlows::usePath(null);
		NodeRole::useMainBuild(null);
		RootPin::useDirs(null, null);
		exec('rm -rf ' . escapeshellarg($this->rHome));
	}

	private function flows(?int $rMode, int $rFlows = 255, string $rState = 'active'): void {
		$rFile = $this->rHome . 'config/cluster/flows.json';
		@unlink($rFile);
		if ($rMode !== null) {
			file_put_contents($rFile, json_encode(['mode' => $rMode, 'flows' => $rFlows, 'state' => $rState]));
		}
	}

	/**
	 * A node in mode 2 with every flow but $rOff, its replica applied from
	 * disk as `service` does at boot (so its processes boot from it).
	 *
	 * @param array<string, string|null> $rSettings MAIN's settings the replica carries
	 */
	private function node(array $rSettings = [], int $rOff = 0): void {
		$this->flows(2, 255 & ~$rOff);
		$this->rFixture->node($rSettings);
		$this->apply();
		@unlink($this->rHome . 'commands.log');
		@unlink($this->rHome . 'sudo.log');
	}

	/**
	 * Run $rScript (the scenario script by default) in the throwaway deploy root.
	 *
	 * @param list<string> $rArgs
	 * @param array<string, string> $rEnv
	 * @return array{0: int, 1: string, 2: array<string, mixed>|null} exit code, output, the scenario's result
	 */
	private function child(array $rArgs, ?string $rScript = null, array $rEnv = []): array {
		$rPrepend = $this->rHome . 'prepend.php';
		file_put_contents($rPrepend, <<<'PHP'
			<?php
			// A throwaway deploy root, and an xcvm_core whose MAIN database and Redis never answer.
			define('MAIN_HOME', getenv('XCVM_TEST_HOME'));
			final class XC_VM {
				public static function config_server(): array {
					return ['server_id' => 5];
				}

				public static function db_connect(bool $rMigrate = false) {
					file_put_contents(MAIN_HOME . 'connects.log', "sql\n", FILE_APPEND);
					return false;
				}

				public static function redis_connect() {
					file_put_contents(MAIN_HOME . 'connects.log', "redis\n", FILE_APPEND);
					return null;
				}
			}
			PHP);
		@unlink($this->rHome . 'connects.log');
		$rCommand = array_merge([...xcvm_test_child_php(), '-d', 'auto_prepend_file=' . $rPrepend, $rScript ?? $this->scenarios()], $rArgs);
		$rProc = proc_open($rCommand, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes, $this->rHome, $rEnv + ['XCVM_TEST_HOME' => $this->rHome, 'XCVM_TEST_SRC' => MAIN_HOME, 'PATH' => $this->rHome . 'stub:' . getenv('PATH')]);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]) . (string) stream_get_contents($rPipes[2]);
		fclose($rPipes[1]);
		fclose($rPipes[2]);
		$rCode = proc_close($rProc);
		$rResult = null;
		if (preg_match('/^RESULT:(.*)$/m', $rOut, $rMatch)) {
			$rResult = json_decode($rMatch[1], true);
		}
		return [$rCode, $rOut, $rResult];
	}

	/** The scenario script: boots as a CLI command (from the replica), then runs one path. */
	private function scenarios(): string {
		$rScript = $this->rHome . 'scenario.php';
		file_put_contents($rScript, <<<'PHP'
			<?php
			use XcVm\Cli\Commands\ClusterRootCommand;
			use XcVm\Cli\Commands\StatusCommand;
			use XcVm\Cli\Commands\WatchdogCommand;
			use XcVm\Cli\CronJobs\CleanupCronJob;
			use XcVm\Cli\CronJobs\RootSignalsCronJob;
			use XcVm\Cli\CronJobs\ServersCronJob;
			use XcVm\Cli\CronJobs\StreamsCronJob;
			use XcVm\Cli\CronJobs\VodCronJob;
			use XcVm\Core\Cluster\ReplicaBoot;
			use XcVm\Core\Cluster\RootPin;
			use XcVm\Core\Enum\BootContext;
			use XcVm\Core\Http\RequestManager;

			// Nothing runs unless the stand-ins answer for sudo, crontab and ip.
			foreach (['sudo', 'crontab', 'ip'] as $rTool) {
				if (trim((string) shell_exec('command -v ' . $rTool)) !== getenv('XCVM_TEST_HOME') . 'stub/' . $rTool) {
					echo "\nRESULT:" . json_encode(['error' => 'unsafe: ' . $rTool . ' is not the stand-in']) . "\n";
					exit(1);
				}
			}
			require getenv('XCVM_TEST_SRC') . 'bootstrap.php';
			$rResult = [];
			register_shutdown_function(static function () use (&$rResult): void {
				echo "\nRESULT:" . json_encode($rResult) . "\n";
			});
			try {
				XC_Bootstrap::boot(BootContext::Cli);
				$rResult['replica'] = ReplicaBoot::active();
				switch ($argv[1] ?? '') {
					case 'root_signals':
						$rJob = new class extends RootSignalsCronJob {
							/** @var list<array<string, mixed>> */
							public array $rRan = [];

							public function executeAction(array $rData, array $rServers, object $db): void {
								$this->rRan[] = $rData;
							}
						};
						foreach ([0, 1] as $i) {
							ob_start();
							try {
								(new ReflectionMethod(RootSignalsCronJob::class, 'loadCron'))->invoke($rJob);
							} finally {
								$rResult['output'][$i] = (string) ob_get_clean();
							}
							$rResult['ran'][$i] = $rJob->rRan;
							$rJob->rRan = [];
						}
						break;
					case 'php_fpm':
						// The restart ends the cron with exit(), as it always did.
						(new ReflectionMethod(RootSignalsCronJob::class, 'loadCron'))->invoke(new RootSignalsCronJob());
						$rResult['exited'] = false;
						break;
					case 'root_actions':
						RootPin::useDirs(getenv('XCVM_TEST_PIN'), getenv('XCVM_TEST_INBOX'));
						$rResult['done'] = ClusterRootCommand::drain([ClusterRootCommand::class, 'runAction'], (int) getenv('XCVM_TEST_NOW'));
						break;
					case 'watchdog':
						$rDog = new class extends WatchdogCommand {
							public int $rRestarts = 0;

							private int $rPasses = 0;

							// The loop stays up until something stops it (a code
							// change, nginx gone, the database gone), so the pass
							// under test ends here as a deploy would end it.
							protected function shouldRefreshSettings(): bool {
								return true;
							}

							protected function hasFileChanged(): bool {
								return ++$this->rPasses > 1;
							}

							protected function assertRunAsXcVm(): bool {
								return true;
							}

							protected function acquireDaemonLock(string $rLockName): bool {
								return true;
							}

							protected function killStaleProcesses(string $rPattern): void {
							}

							protected function setProcessTitle(string $rTitle): void {
							}

							protected function restartDaemon(string $rCommandName): void {
								$this->rRestarts++;
							}
						};
						ob_start();
						try {
							$rResult['code'] = $rDog->execute([]);
						} finally {
							$rResult['output'] = (string) ob_get_clean();
						}
						$rResult['restarts'] = $rDog->rRestarts;
						break;
					case 'status_servers':
						// Only the servers status reads: the rest of status changes the host.
						$rServers = (new ReflectionMethod(StatusCommand::class, 'getServers'))->invoke(new StatusCommand());
						$rResult['servers'] = array_map(static fn(array $rServer): int => (int) $rServer['is_main'], $rServers);
						break;
					case 'servers':
						ob_start();
						try {
							(new ReflectionMethod(ServersCronJob::class, 'loadCron'))->invoke(new ServersCronJob());
						} finally {
							$rResult['output'] = (string) ob_get_clean();
						}
						break;
					case 'cleanup':
						ob_start();
						try {
							(new ReflectionMethod(CleanupCronJob::class, 'loadCron'))->invoke(new CleanupCronJob());
						} finally {
							$rResult['output'] = (string) ob_get_clean();
						}
						break;
					case 'relay_vod':
						// The VOD relay endpoint, as a child server asks it for a movie.
						$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
						RequestManager::set(['stream' => $argv[2], 'password' => 'stream-pass']);
						$rResult['served'] = true;
						require getenv('XCVM_TEST_SRC') . 'Public/admin/vod.php';
						$rResult['served'] = false;
						break;
					case 'vod':
						ob_start();
						try {
							(new ReflectionMethod(VodCronJob::class, 'loadCron'))->invoke(new VodCronJob());
						} finally {
							$rResult['output'] = (string) ob_get_clean();
						}
						break;
					case 'streams':
						// It kills what `ps` names: only ever with the stand-in's empty list.
						if (trim((string) shell_exec('command -v ps')) !== getenv('XCVM_TEST_HOME') . 'stub_ps/ps') {
							$rResult['error'] = 'unsafe: ps is not the stand-in';
							break;
						}
						ob_start();
						try {
							(new ReflectionMethod(StreamsCronJob::class, 'loadCron'))->invoke(new StreamsCronJob());
						} finally {
							$rResult['output'] = (string) ob_get_clean();
						}
						break;
				}
			} catch (\Throwable $e) {
				$rResult['error'] = get_class($e) . ': ' . $e->getMessage();
			}
			PHP);
		return $rScript;
	}

	/**
	 * No connect to MAIN: xcvm_core was never asked for one, and none was
	 * even attempted (the refusal counts each attempt, refused or not).
	 */
	private function assertNoConnect(): void {
		$this->assertSame([], is_file($this->rHome . 'connects.log') ? file($this->rHome . 'connects.log', FILE_IGNORE_NEW_LINES) : [], 'xcvm_core was never asked to connect');
		$rAttempts = 0;
		foreach (glob($this->rHome . 'storage/cluster/sql_audit/*.json') ?: [] as $rFile) {
			$rDay = json_decode((string) file_get_contents($rFile), true);
			$rAttempts += (int) ($rDay['sql'] ?? 0) + (int) ($rDay['redis'] ?? 0);
		}
		$this->assertSame(0, $rAttempts, 'no connect attempted, refused or not');
	}

	/** @return list<string> what the stand-ins were asked, in order */
	private function commands(): array {
		return is_file($this->rHome . 'commands.log') ? file($this->rHome . 'commands.log', FILE_IGNORE_NEW_LINES) : [];
	}

	/** @return list<array{0: string, 1: int}> what sudo was asked, with the P1 spool files there were then */
	private function sudoSpooled(): array {
		$rOut = [];
		foreach (is_file($this->rHome . 'sudo.log') ? file($this->rHome . 'sudo.log', FILE_IGNORE_NEW_LINES) : [] as $rLine) {
			if (preg_match('/^(.*) +(\d+)$/', $rLine, $rMatch)) {
				$rOut[] = [$rMatch[1], (int) $rMatch[2]];
			}
		}
		return $rOut;
	}

	/**
	 * A node's replica applied from disk again, as `service` or the agent
	 * does after it stored a section.
	 */
	private function apply(): void {
		[$rCode, $rOut] = $this->child(['cluster:apply', '--from-disk'], MAIN_HOME . 'console.php');
		$this->assertSame(0, $rCode, $rOut);
	}

	/** @return list<array<string, mixed>> the spooled events of a lane, in file order */
	private function spooled(string $rLane): array {
		$rFiles = glob($this->rHome . 'config/cluster/spool/' . $rLane . '/*.ndjson') ?: [];
		sort($rFiles);
		$rOut = [];
		foreach ($rFiles as $rFile) {
			foreach (array_filter(explode("\n", (string) file_get_contents($rFile))) as $rLine) {
				$rOut[] = json_decode($rLine, true);
			}
		}
		return $rOut;
	}

	/** A process whose command line reads as nginx's master (ProcessManager::isNginxRunning). */
	private function nginxRunning(): void {
		$this->rNginx = proc_open([PHP_BINARY, '-r', 'sleep(60);', '--', 'nginx: master process'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		$this->assertIsResource($this->rNginx);
		for ($i = 0; $i < 50 && !str_contains((string) @file_get_contents('/proc/' . proc_get_status($this->rNginx)['pid'] . '/cmdline'), 'nginx: master'); $i++) {
			usleep(20000);
		}
	}

	// ── cron:root_signals ────────────────────────────────────────────

	/**
	 * The minute's root work, on a node that reads no `signals` row: the
	 * iptables sync from the replica's blocklist, the ramdisk, ports and
	 * services checked against the replica's own row once it changed (as a
	 * signal row of each kind made them run), and the crontab against the
	 * replica's jobs. The server IP rewrite is MAIN's alone: `ip` is never
	 * asked.
	 */
	public function testTheRootSignalsMinuteReadsTheReplicaNotMainsDatabase(): void {
		$this->node();
		[, $rOut, $rResult] = $this->child(['root_signals']);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertTrue($rResult['replica']);
		$this->assertNoConnect();

		// The replica's node row: four services, HTTPS on 8443, a ramdisk.
		$this->assertSame([
			['action' => 'enable_ramdisk'],
			['action' => 'set_port', 'type' => 1, 'ports' => [8443], 'reload' => true],
			['action' => 'set_services', 'count' => 4, 'reload' => true],
		], $rResult['ran'][0]);
		$this->assertSame([], $rResult['ran'][1], 'checked once per change of the replica, not every minute');
		$this->assertStringContainsString('Updating Crons...', $rResult['output'][0], 'the replica\'s jobs, not MAIN\'s table');
		$this->assertContains('sudo iptables -I INPUT -s 203.0.113.1 -j DROP', $this->commands(), 'the blocklist from the replica');
		$this->assertSame([], preg_grep('/^ip /', $this->commands()), 'no server IP check on a node');
		// This fixture's node has every flow, the data plane included, but MAIN
		// is no node and still reads the node's files with getFile (a source
		// probe, the certbot log): the legacy `/api` stays served.
		$this->assertSame('set $api_legacy 1;', trim((string) file_get_contents($this->rHome . 'bin/nginx/conf/api_legacy.conf')));
		// In mode 2 the viewer APIs, which would read the line from MAIN's
		// database, answer 404 (D16).
		$this->assertSame('set $viewer_api 0;', trim((string) file_get_contents($this->rHome . 'bin/nginx/conf/viewer_api.conf')));
	}

	/**
	 * Without the data plane the legacy `/api` stays served, whatever else the
	 * node has moved: MAIN still reaches it that way for a relay's sources, a
	 * cross-server VOD pull and a created channel.
	 */
	public function testTheLegacyApiStaysServedWithoutTheDataPlane(): void {
		$this->node([], NodeFlows::DATAPLANE);
		[, $rOut, $rResult] = $this->child(['root_signals']);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertSame('set $api_legacy 1;', trim((string) file_get_contents($this->rHome . 'bin/nginx/conf/api_legacy.conf')));
	}

	/**
	 * The checks follow the replica: MAIN moves the node's HTTPS port while
	 * it is in mode 2, the agent stores the new `node` section and the apply
	 * rebuilds the servers cache, so the next minute checks the ports again
	 * (and only that minute).
	 */
	public function testTheChecksRunAgainWhenTheReplicasRowChanges(): void {
		$this->node();
		[, $rOut, $rResult] = $this->child(['root_signals']);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertContains(['action' => 'set_port', 'type' => 1, 'ports' => [8443], 'reload' => true], $rResult['ran'][0]);
		$this->assertSame([], $rResult['ran'][1]);

		$rNode = json_decode((string) file_get_contents($this->rFixture->dir() . 'node.json'), true)['data'];
		$this->rFixture->whole('node', ['https_broadcast_port' => 9443] + $rNode);
		$this->apply();
		[, $rOut, $rResult] = $this->child(['root_signals']);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertNoConnect();
		$this->assertContains(['action' => 'set_port', 'type' => 1, 'ports' => [9443], 'reload' => true], $rResult['ran'][0], 'the new ETags run the checks against the new row');
		$this->assertSame([], $rResult['ran'][1]);
	}

	/**
	 * A replica that does not own the crontab (its section refused) leaves
	 * the crontab as it is in mode 2: MAIN's `crontab` table is never read,
	 * and the minute goes on to its sysctl check and actions.
	 */
	public function testTheCrontabIsLeftAsItIsWhenTheReplicaDoesNotOwnIt(): void {
		$this->flows(2);
		$this->rFixture->node();
		$this->rFixture->whole('crontab', ['jobs' => 'not a list']);
		$this->apply();
		touch($this->rHome . 'tmp/crontab');
		[, $rOut, $rResult] = $this->child(['root_signals']);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertNoConnect();
		$this->assertStringNotContainsString('Checking crontab', $rResult['output'][0]);
		$this->assertFileExists($this->rHome . 'tmp/crontab', 'the crontab left as it is');
		$this->assertNotSame([], $rResult['ran'][0], 'the checks\' actions still run');
	}

	/**
	 * A suspected PHP-FPM crash restarts the services: its line reaches
	 * MAIN's system log through the spool, and the restart happens.
	 */
	public function testThePhpFpmRestartLogsThroughTheSpool(): void {
		$this->node(['restart_php_fpm' => '1']);
		$this->nginxRunning();
		[, $rOut, $rResult] = $this->child(['php_fpm']);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertArrayNotHasKey('exited', $rResult, 'the cron ends after the restart, as before');
		$this->assertNoConnect();
		$this->assertSame(['sudo systemctl stop xc_vm', 'sudo systemctl start xc_vm'], array_values(preg_grep('/systemctl/', $this->commands())));
		$rEvents = $this->spooled('p1');
		$this->assertSame(['log.syslog'], array_values(array_unique(array_column($rEvents, 'type'))));
		$this->assertSame(['server_id' => 5, 'type' => 'PHP-FPM', 'error' => 'Restarted PHP-FPM instances due to a suspected crash.', 'username' => 'root', 'ip' => 'localhost', 'database' => null], array_diff_key($rEvents[0]['d']['rows'][0], ['date' => 1]));
	}

	// ── Root actions ─────────────────────────────────────────────────

	/**
	 * Reboot, restart and stop logged to MAIN's database before acting, so
	 * in mode 2 the refusal stopped them and cluster:root reported them
	 * failed. Now each acts, and its line goes to the spool first. An update
	 * and a rollback start the updater too, which reports its status
	 * through the agent (NodeStateSink::status) rather than MAIN's row.
	 */
	public function testRootActionsActAndLogThroughTheSpool(): void {
		$this->node();
		$rCrypto = new FakeClusterCrypto();
		$rPin = $this->rHome . 'rootpin/';
		mkdir($rPin . 'etc', 0755, true);
		mkdir($rPin . 'inbox', 0700, true);
		RootPin::useDirs($rPin . 'etc/', $rPin . 'inbox/');
		$this->assertTrue(RootPin::write($rCrypto->info()['panel_sign_pub'], self::NODE));
		foreach ([['action' => 'reboot'], ['action' => 'restart_services'], ['action' => 'stop_services'], ['action' => 'flush'], ['action' => 'update', 'version' => '9.9.9'], ['action' => 'rollback', 'version' => '2.0.0']] as $i => $rArgs) {
			$rDoc = (string) json_encode(['v' => 1, 'type' => 'node.root', 'action' => $rArgs['action'], 'exp' => self::NOW + 600, 'iat' => self::NOW, 'cmd_id' => bin2hex(random_bytes(16)), 'seq' => $i + 1, 'node_uuid' => self::NODE, 'gen' => 1, 'dedupe_key' => null, 'args' => (object) array_diff_key($rArgs, ['action' => 0])]);
			file_put_contents($rPin . 'inbox/' . ($i + 1) . '.json', json_encode(['doc' => $rDoc, 'sig' => Enc::b64url($rCrypto->sign('cmd', $rDoc))]));
		}
		[, $rOut, $rResult] = $this->child(['root_actions'], null, ['XCVM_TEST_PIN' => $rPin . 'etc/', 'XCVM_TEST_INBOX' => $rPin . 'inbox/', 'XCVM_TEST_NOW' => (string) self::NOW]);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertNoConnect();

		$this->assertSame([true, true, true, true, true, true], array_column($rResult['done'], 'ok'), $rOut);
		foreach ([1, 2, 3, 4, 5, 6] as $rSeq) {
			$this->assertTrue(json_decode((string) file_get_contents($rPin . 'inbox/' . $rSeq . '.done'), true)['ok'], 'cluster:root reports it done: ' . $rSeq);
		}
		// Each acted.
		$this->assertSame(['sudo reboot', 'sudo systemctl stop xc_vm', 'sudo systemctl start xc_vm', 'sudo systemctl stop xc_vm', 'sudo iptables -F', 'sudo ip6tables -F'], array_values(preg_grep('/^sudo (reboot|systemctl|ip6?tables -F)/', $this->commands())));
		// Reboot, restart and stop spool their line before they act, so a
		// reboot's survives it; the flush logs once it flushed.
		$this->assertSame([['reboot', 1], ['systemctl stop xc_vm', 2], ['systemctl start xc_vm', 2], ['systemctl stop xc_vm', 3], ['iptables -F', 3]], array_values(array_filter($this->sudoSpooled(), static fn(array $rCall): bool => (bool) preg_match('/^(reboot|systemctl|iptables -F)/', $rCall[0]))));
		// An update and a rollback start the updater: it reports its status
		// through the agent now (NodeStateSink::status), not MAIN's row. It is
		// started detached (ProcessRunner::start), so its stand-in may still be
		// writing, in either order.
		for ($rTry = 0; $rTry < 50 && count(preg_grep('/console\.php update/', $this->commands())) < 2; $rTry++) {
			usleep(100000);
		}
		$this->assertEqualsCanonicalizing(['console.php update update 9.9.9', 'console.php update rollback 2.0.0'], array_values(array_map(static fn(string $rLine): string => (string) preg_replace('/^.*(console\.php update .*)$/', '$1', $rLine), preg_grep('/console\.php update/', $this->commands()))));
		// Each logged through the spool, in order, as root on this node.
		$rRows = array_map(static fn(array $rEvent): array => $rEvent['d']['rows'][0], $this->spooled('p1'));
		$this->assertSame(['log.syslog'], array_values(array_unique(array_column($this->spooled('p1'), 'type'))));
		$this->assertSame([
			['REBOOT', 'System rebooted on request.'],
			['RESTART', 'XC_VM services restarted on request.'],
			['STOP', 'XC_VM services stopped on request.'],
			['FLUSH', 'Flushed blocked IP\'s from iptables.'],
			['UPDATE', 'Updating XC_VM...'],
			['UPDATE', 'Rolling back XC_VM to 2.0.0...'],
		], array_map(static fn(array $rRow): array => [$rRow['type'], $rRow['error']], $rRows));
		$this->assertSame([5], array_values(array_unique(array_column($rRows, 'server_id'))));
	}

	// ── The watchdog ─────────────────────────────────────────────────

	/**
	 * One pass of the watchdog, with Redis on in the settings: no ping of
	 * MAIN's database and no wait for it, no Redis, no capacity read, and
	 * its sample for the agent written.
	 */
	public function testTheWatchdogPassNeedsNeitherDatabaseNorRedis(): void {
		$this->node(['redis_handler' => '1']);
		$this->watchdogPass();
	}

	/**
	 * Without TELEMETRY too the pass never writes the servers row (nor
	 * counts `lines_live`, with Redis off): it writes its sample and ends.
	 */
	public function testAWatchdogWithoutTelemetryStillLeavesTheRowToMain(): void {
		$this->node([], NodeFlows::TELEMETRY);
		$this->watchdogPass();
	}

	private function watchdogPass(): void {
		$this->nginxRunning();
		file_put_contents($this->rHome . 'tmp/watchdog_devices.json', json_encode(['t' => time(), 'devices' => new stdClass()]));
		[, $rOut, $rResult] = $this->child(['watchdog']);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertNoConnect();
		$this->assertSame([0, 1], [$rResult['code'], $rResult['restarts']]);
		$this->assertStringNotContainsString('waiting', $rResult['output']);
		$this->assertStringNotContainsString('Not running', $rResult['output'], 'the pass reached its settings refresh');
		$this->assertFileExists($this->rHome . 'config/cluster/local.json', 'what PHP samples, for the agent');
	}

	// ── status ───────────────────────────────────────────────────────

	/**
	 * status reads the servers from the replica in mode 2: MAIN's `servers`
	 * table was its one read there, and refused, so none of its node work
	 * ran (the permissions, nginx's config, root's crontab, the file limits).
	 */
	public function testStatusReadsTheServersFromTheReplica(): void {
		$this->node();
		[, $rOut, $rResult] = $this->child(['status_servers']);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertNoConnect();
		$this->assertSame(0, $rResult['servers'][5] ?? null, 'this node, not MAIN');
	}

	// ── cron:servers ─────────────────────────────────────────────────

	/**
	 * The minute's node work needs no database with TELEMETRY on: the counts
	 * that fed the servers_stats row are not taken (they were refused, and
	 * the inventory never went), and the inventory goes to MAIN as an event.
	 */
	public function testTheServersMinuteSendsItsInventoryWithoutMainsDatabase(): void {
		$this->node();
		$this->nginxRunning();
		[, $rOut, $rResult] = $this->child(['servers']);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertNoConnect();
		$rInventory = array_values(array_filter($this->spooled('p1'), static fn(array $rEvent): bool => $rEvent['type'] === 'node.inventory'));
		$this->assertCount(1, $rInventory, $rResult['output']);
		$this->assertArrayHasKey('xc_vm_version', $rInventory[0]['d']['fields'], 'MAIN learns the node\'s version');
	}

	// ── cron:cleanup ─────────────────────────────────────────────────

	/**
	 * The stream, archive and VOD checks need this node's streams, which
	 * no replica section carries yet (R2): skipped, and nothing deleted,
	 * where an empty list would have deleted every file.
	 */
	public function testCleanupSkipsTheStreamChecks(): void {
		$this->node(['cleanup' => '1', 'check_vod' => '1']);
		file_put_contents($this->rHome . 'content/streams/7_.m3u8', 'x');
		mkdir($this->rHome . 'content/archive/9');
		file_put_contents($this->rHome . 'content/created/3_.list', 'x');
		[, $rOut, $rResult] = $this->child(['cleanup']);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertNoConnect();
		$this->assertFileExists($this->rHome . 'content/streams/7_.m3u8');
		$this->assertDirectoryExists($this->rHome . 'content/archive/9');
		$this->assertFileExists($this->rHome . 'content/created/3_.list');
		$this->assertStringNotContainsString('Deleting', $rResult['output']);
	}

	/**
	 * The streams this node holds, as the agent stores its R2 section: live
	 * 7; movie 8; live 9 whose TV archive (a day) it records; created
	 * channel 3 from two sources; and each $rOver's `streams` columns.
	 *
	 * @param array<int, array<string, mixed>> $rOver
	 */
	private function streams(array $rOver = []): void {
		$rStreams = [7 => [], 8 => ['type' => 2, 'target_container' => 'mp4'], 9 => ['tv_archive_server_id' => 5, 'tv_archive_duration' => 1], 3 => ['type' => 3, 'stream_source' => '["s:5:/media/a.mp4","s:5:/media/b.mp4"]']];
		$rTypes = [2 => ['live' => 0, 'type_id' => 2, 'type_key' => 'movie', 'type_name' => 'Movies', 'type_output' => 'movie'], 3 => ['live' => 1, 'type_id' => 3, 'type_key' => 'created_live', 'type_name' => 'Created Live', 'type_output' => 'live']];
		foreach ($rOver + $rStreams as $rID => $rStream) {
			$rStream += $rStreams[$rID] ?? [];
			$rData = ReplicaFixture::streamData($rID, 5, $rStream);
			$rData['type'] = $rTypes[$rStream['type'] ?? 1] ?? $rData['type'];
			$this->rFixture->stream($rID, $rData);
		}
		$this->rFixture->streamsSince(9);
	}

	/**
	 * The node's own store as a seed in mode 1 left it: $rStreams' runtime
	 * columns, $rRecordings' statuses, the node user's; $rUnsent's columns
	 * as a node in mode 2 kept them while its agent took no event.
	 *
	 * @param array<int, array<string, mixed>> $rStreams
	 * @param array<int, int> $rRecordings
	 * @param array<int, list<string>> $rUnsent
	 */
	private function runtime(array $rStreams, array $rRecordings = [], array $rUnsent = []): void {
		$rDir = $this->rHome . 'config/cluster/runtime/';
		@mkdir($rDir . 'streams', 0700, true);
		@mkdir($rDir . 'recordings', 0700, true);
		foreach ($rStreams as $rID => $rFields) {
			file_put_contents($rDir . 'streams/' . $rID . '.json', json_encode(['id' => $rID, 'ssid' => $rID, 'fields' => $rFields, 'unsent' => $rUnsent[$rID] ?? []]));
		}
		if ($rUnsent !== []) {
			file_put_contents($rDir . 'unsent', json_encode(['at' => time(), 'token' => 'kept-in-mode-2']));
		}
		foreach ($rRecordings as $rID => $rStatus) {
			file_put_contents($rDir . 'recordings/' . $rID . '.json', json_encode(['id' => $rID, 'status' => $rStatus]));
		}
		file_put_contents($rDir . 'seeded', json_encode(['at' => time(), 'server_id' => 5, 'streams' => count($rStreams)]));
		AgentUser::own($rDir);
	}

	/** @return array<string, mixed> what the node's store keeps for a stream */
	private function kept(int $rID): array {
		return json_decode((string) file_get_contents($this->rHome . 'config/cluster/runtime/streams/' . $rID . '.json'), true)['fields'];
	}

	/**
	 * With its R2 streams section applied and its own store seeded, a node
	 * in mode 2 checks its files against its streams from the replica: the
	 * files of streams it does not hold go, its TV archive keeps its
	 * retention, a movie whose file is gone and a channel whose list is gone
	 * are marked from the store's state (kept there and reported to MAIN),
	 * and nothing reaches MAIN's database.
	 */
	public function testCleanupChecksItsStreamsFromTheReplica(): void {
		$this->streams();
		$this->node(['cleanup' => '1', 'check_vod' => '1']);
		// 70 and recording 9: no longer held, kept long ago; 71: held since the section was read, kept just now.
		$this->runtime([8 => ['pid' => 4000, 'stream_status' => 0], 3 => ['cchannel_rsources' => '["s:5:/media/a.mp4","s:5:/media/b.mp4"]'], 70 => ['pid' => 1], 71 => ['pid' => 2]], [9 => 1]);
		touch($this->rHome . 'config/cluster/runtime/streams/70.json', time() - StreamRuntime::PRUNE_GRACE - 60);
		touch($this->rHome . 'config/cluster/runtime/recordings/9.json', time() - StreamRuntime::PRUNE_GRACE - 60);
		foreach (['7_.m3u8', '70_.m3u8', '70_1.ts'] as $rFile) {
			file_put_contents($this->rHome . 'content/streams/' . $rFile, 'x');
		}
		mkdir($this->rHome . 'content/archive/9');
		mkdir($this->rHome . 'content/archive/90');
		$rOld = gmdate('Y-m-d:H-i', time() - 3 * 86400) . '.ts';
		$rRecent = gmdate('Y-m-d:H-i', time() - 120) . '.ts';
		file_put_contents($this->rHome . 'content/archive/9/' . $rOld, 'x');
		file_put_contents($this->rHome . 'content/archive/9/' . $rRecent, 'x');
		file_put_contents($this->rHome . 'content/created/30_abc.ts', 'x');
		file_put_contents($this->rHome . 'content/created/3_abc.ts', 'x');

		[, $rOut, $rResult] = $this->child(['cleanup']);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertTrue($rResult['replica']);
		$this->assertNoConnect();
		$this->assertTrue((new ReflectionMethod(CleanupCronJob::class, 'streamChecks'))->isProtected());
		$this->assertFileExists($this->rHome . 'content/streams/7_.m3u8', 'a stream it holds');
		$this->assertFileDoesNotExist($this->rHome . 'content/streams/70_.m3u8', 'a stream it does not hold');
		$this->assertFileDoesNotExist($this->rHome . 'content/streams/70_1.ts');
		$this->assertDirectoryDoesNotExist($this->rHome . 'content/archive/90');
		$this->assertFileDoesNotExist($this->rHome . 'content/archive/9/' . $rOld, 'past its retention');
		$this->assertFileExists($this->rHome . 'content/archive/9/' . $rRecent);
		$this->assertFileDoesNotExist($this->rHome . 'content/created/30_abc.ts');
		$this->assertFileExists($this->rHome . 'content/created/3_abc.ts');
		$this->assertStringContainsString('BAD MOVIE', $rResult['output']);
		$this->assertStringContainsString('BAD CHANNEL', $rResult['output']);
		$this->assertSame(1, $this->kept(8)['stream_status'], 'the store follows the node\'s writes');
		$this->assertSame('[]', $this->kept(3)['cchannel_rsources']);
		$rEvents = array_column($this->spooled('p0'), 'd');
		$this->assertContains(['ssid' => 8, 'fields' => ['stream_status' => 1]], $rEvents, 'and MAIN hears them');
		$this->assertContains(['ssid' => 3, 'fields' => ['cchannel_rsources' => '[]']], $rEvents);
		// The store pruned to what the node holds, but for what was kept in the last minutes.
		$this->assertFileDoesNotExist($this->rHome . 'config/cluster/runtime/streams/70.json');
		$this->assertFileDoesNotExist($this->rHome . 'config/cluster/runtime/recordings/9.json');
		foreach ([3, 8, 71] as $rID) {
			$this->assertFileExists($this->rHome . 'config/cluster/runtime/streams/' . $rID . '.json');
		}
	}

	/**
	 * A section the agent's files do not hold whole (a record stored since
	 * the apply that does not read): every check that prunes by its list is
	 * skipped, never run against a partial one, and the store is not pruned.
	 */
	public function testCleanupSkipsTheChecksWhenTheSectionIsNotWhole(): void {
		$this->streams();
		$this->node(['cleanup' => '1', 'check_vod' => '1']);
		$this->runtime([8 => ['pid' => 4000, 'stream_status' => 0], 70 => ['pid' => 1]]);
		touch($this->rHome . 'config/cluster/runtime/streams/70.json', time() - StreamRuntime::PRUNE_GRACE - 60);
		file_put_contents($this->rFixture->dir() . 'streams/71.json', '{"etag":');
		foreach (['7_.m3u8', '70_.m3u8'] as $rFile) {
			file_put_contents($this->rHome . 'content/streams/' . $rFile, 'x');
		}
		mkdir($this->rHome . 'content/archive/90');
		file_put_contents($this->rHome . 'content/created/30_abc.ts', 'x');

		[, $rOut, $rResult] = $this->child(['cleanup']);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertTrue($rResult['replica']);
		$this->assertNoConnect();
		$this->assertStringNotContainsString('Deleting', $rResult['output']);
		$this->assertStringNotContainsString('BAD MOVIE', $rResult['output']);
		$this->assertFileExists($this->rHome . 'content/streams/70_.m3u8');
		$this->assertDirectoryExists($this->rHome . 'content/archive/90');
		$this->assertFileExists($this->rHome . 'content/created/30_abc.ts');
		$this->assertFileExists($this->rHome . 'config/cluster/runtime/streams/70.json', 'not pruned by a partial list');
	}

	/**
	 * cron:streams in mode 2 once the agent is back: what the node's store
	 * kept unsent while it took no event goes to MAIN (a `stream.state` keyed
	 * by stream and server), and the marker goes with it. No connect.
	 */
	public function testTheStreamsMinuteSendsWhatTheStoreKeptUnsent(): void {
		$this->streams();
		$this->node(['redis_handler' => '0', 'kill_rogue_ffmpeg' => '0']);
		$this->runtime([7 => ['bitrate' => 3000, 'stream_info' => '{}']], [], [7 => ['bitrate']]);
		// `ps` answers with nothing to kill.
		mkdir($this->rHome . 'stub_ps');
		file_put_contents($this->rHome . 'stub_ps/ps', "#!/bin/sh\necho 'root 1 0.0 grep XC_VM'\n");
		chmod($this->rHome . 'stub_ps/ps', 0755);

		[, $rOut, $rResult] = $this->child(['streams'], null, ['PATH' => $this->rHome . 'stub_ps:' . $this->rHome . 'stub:' . getenv('PATH')]);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertNoConnect();
		$this->assertContains(['stream_id' => 7, 'server_id' => 5, 'fields' => ['bitrate' => 3000]], array_column($this->spooled('p0'), 'd'));
		$this->assertFileDoesNotExist($this->rHome . 'config/cluster/runtime/unsent');
		$this->assertSame([], json_decode((string) file_get_contents($this->rHome . 'config/cluster/runtime/streams/7.json'), true)['unsent']);
	}

	/**
	 * A node fenced past its drain releases its producers (plan, section 9,
	 * FENCED): cron:streams kills a running stream's producer and its
	 * thumbnail worker, starts nothing, and keeps the stream's record as it
	 * was, so the first pass after the fence lifts starts them again. No
	 * connect.
	 */
	public function testAFencedNodeReleasesItsProducersAndKeepsTheirRecords(): void {
		$this->streams();
		$this->node(['redis_handler' => '0', 'kill_rogue_ffmpeg' => '0', 'lb_lease_fence' => '1', 'lb_fence_drain_min' => '0']);
		// The producer: a process whose command line names the stream's playlist.
		$rProducer = proc_open([PHP_BINARY, '-r', 'sleep(60);', $this->rHome . 'content/streams/7_.m3u8'], [], $rPipes);
		$rPid = (int) proc_get_status($rProducer)['pid'];
		// Its thumbnail worker: a PHP process titled as ThumbnailCommand titles it.
		$rThumb = proc_open([PHP_BINARY, '-r', 'cli_set_process_title("Thumbnail[7]"); sleep(60);'], [], $rPipes);
		$rThumbPid = (int) proc_get_status($rThumb)['pid'];
		try {
			file_put_contents($this->rHome . 'content/streams/7_.pid', (string) $rPid);
			$this->runtime([7 => ['pid' => $rPid, 'monitor_pid' => null, 'stream_status' => 0, 'stream_started' => time() - 60, 'vframes_pid' => $rThumbPid]]);
			// The agent's lease, past its exp and its drain (0 min) on MAIN's clock.
			$rNowMs = (int) round(microtime(true) * 1000);
			file_put_contents($this->rHome . 'config/cluster/lease_state.json', (string) json_encode(['exp' => intdiv($rNowMs, 1000) - 60, 'iat' => intdiv($rNowMs, 1000) - 3600, 'gen' => 1, 'server_id' => 5, 'anchor_ms' => $rNowMs, 'wrote_at_ms' => $rNowMs]));
			mkdir($this->rHome . 'stub_ps');
			file_put_contents($this->rHome . 'stub_ps/ps', "#!/bin/sh\necho 'root 1 0.0 grep XC_VM'\n");
			chmod($this->rHome . 'stub_ps/ps', 0755);

			[, $rOut, $rResult] = $this->child(['streams'], null, ['PATH' => $this->rHome . 'stub_ps:' . $this->rHome . 'stub:' . getenv('PATH')]);
			$this->assertIsArray($rResult, $rOut);
			$this->assertArrayNotHasKey('error', $rResult, $rOut);
			$this->assertNoConnect();
			$this->assertStringContainsString('Fenced: releasing the producer', $rResult['output']);
			$this->assertStringNotContainsString('Start monitor', $rResult['output']);
			for ($i = 0; $i < 50 && proc_get_status($rProducer)['running']; $i++) {
				usleep(20000);
			}
			$this->assertFalse(proc_get_status($rProducer)['running'], 'the producer is released');
			for ($i = 0; $i < 50 && proc_get_status($rThumb)['running']; $i++) {
				usleep(20000);
			}
			$this->assertStringContainsString('Fenced: releasing its Thumbnail worker', $rResult['output']);
			$this->assertFalse(proc_get_status($rThumb)['running'], 'its thumbnail worker goes with it');
			$this->assertStringNotContainsString('Start Thumbnail', $rResult['output']);
			$this->assertSame($rPid, $this->kept(7)['pid'], 'the record stays as it was, for the restart');
		} finally {
			proc_terminate($rProducer, 9);
			proc_close($rProducer);
			proc_terminate($rThumb, 9);
			proc_close($rThumb);
		}
	}

	/**
	 * cron:vod in mode 2 from the replica and the store: a movie whose
	 * analysis is due is analysed (its file is gone: broken), a channel with
	 * sources left is queued through the agent (MAIN owns the queue table, and
	 * no agent answers here), and a recording the node started is not started
	 * again although its record still says scheduled. No connect.
	 */
	public function testVodChecksItsStreamsFromTheReplica(): void {
		$rNow = time();
		$rRecording = ['id' => 1, 'stream_id' => 7, 'created_id' => 0, 'category_id' => '[]', 'bouquets' => '[]', 'title' => 'Match', 'description' => null, 'stream_icon' => null, 'start' => $rNow - 60, 'end' => $rNow + 3600, 'source_id' => 5, 'archive' => 0, 'status' => 0];
		$this->streams();
		$rData = ReplicaFixture::streamData(7, 5);
		$rData['recordings'] = [ReplicaSections::typed($rRecording, ReplicaSections::RECORDING_FIELDS)];
		$this->rFixture->stream(7, $rData);
		$this->node();
		$this->runtime([8 => ['pid' => 4000, 'to_analyze' => 1, 'stream_status' => 0]], [1 => 1]);

		[, $rOut, $rResult] = $this->child(['vod']);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertNoConnect();
		$this->assertStringContainsString('Needs Updating!', $rResult['output']);
		$this->assertStringContainsString('BROKEN', $rResult['output']);
		$this->assertStringNotContainsString('Start recording', $rResult['output'], 'the node\'s own status wins over its record\'s');
		$this->assertSame(['pid' => 4000, 'to_analyze' => 0, 'stream_status' => 1], $this->kept(8));
		$this->assertContains(['ssid' => 8, 'fields' => ['to_analyze' => 0, 'stream_status' => 1]], array_column($this->spooled('p0'), 'd'));
	}

	/**
	 * The VOD relay endpoint in mode 2: the movie's row from the replica and
	 * its producer from the store, the file served, and MAIN's database
	 * never opened (it opened it at once). A stream that is no movie is not
	 * served.
	 */
	public function testTheVodRelayServesFromTheReplica(): void {
		$this->streams();
		$this->node();
		$this->runtime([8 => ['pid' => 4000, 'stream_status' => 0]]);
		file_put_contents($this->rHome . 'content/vod/8.mp4', 'MOVIE-BYTES');
		[, $rOut, $rResult] = $this->child(['relay_vod', '8.mp4']);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertStringContainsString('MOVIE-BYTES', $rOut);
		$this->assertNoConnect();
		[, $rOut, $rResult] = $this->child(['relay_vod', '7.ts']);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertFalse($rResult['served'], 'nothing served, as for a stream MAIN\'s row did not have');
		$this->assertStringNotContainsString('MOVIE-BYTES', $rOut);
		$this->assertNoConnect();
	}

	// ── cluster:apply ────────────────────────────────────────────────

	/**
	 * With CONFIG off the apply only compares; in mode 2 it does not compare
	 * with MAIN's crontab or RTMP publishers, and says so.
	 */
	public function testTheShadowApplyOfAModeTwoNodeComparesNothingWithMain(): void {
		$this->node([], NodeFlows::CONFIG);
		[$rCode, $rOut] = $this->child(['cluster:apply'], MAIN_HOME . 'console.php');
		$this->assertSame(0, $rCode, $rOut);
		$this->assertNoConnect();
		$rReport = json_decode($rOut, true);
		$this->assertIsArray($rReport, $rOut);
		$this->assertSame('shadow', $rReport['crontab']['mode']);
		$this->assertSame(['crontab', 'rtmp_ips'], $rReport['unchecked']);
		$this->assertArrayNotHasKey('missing', $rReport['crontab']);
		$this->assertArrayNotHasKey('rtmp_ips', $rReport['diff']);
		$this->assertSame(['missing' => 0, 'extra' => 1], $rReport['diff']['blocked_ips'], 'the node\'s own caches are still compared');
	}

	// ── Everywhere else, as before ───────────────────────────────────

	/**
	 * Only a node in mode 2 stops reading MAIN's `signals` table; MAIN,
	 * mode 0 and mode 1 keep their loop (root actions MAIN queued before
	 * COMMANDS, or while it lacked root's pin, still run there).
	 */
	public function testOnlyAModeTwoNodeStopsReadingSignals(): void {
		NodeFlows::usePath($this->rHome . 'config/cluster/flows.json');
		NodeRole::useMainBuild(false);
		foreach ([[null, true], [0, true], [1, true], [2, false]] as [$rMode, $rReads]) {
			$this->flows($rMode);
			$this->assertSame($rReads, RootSignalsCronJob::readsMainDatabase(), 'mode ' . var_export($rMode, true));
		}
		foreach (['quarantined' => false, 'revoked' => true, 'enrolling' => true] as $rState => $rReads) {
			$this->flows(2, 255, $rState);
			$this->assertSame($rReads, RootSignalsCronJob::readsMainDatabase(), $rState);
		}
		$this->flows(2);
		NodeRole::useMainBuild(true);
		$this->assertTrue(RootSignalsCronJob::readsMainDatabase(), 'MAIN, even with a stray flows.json');
	}

	/**
	 * The seam R2 fills: cron:cleanup checks its streams everywhere but mode
	 * 2 without its replica's streams and its own store.
	 */
	public function testCleanupChecksItsStreamsEverywhereButModeTwo(): void {
		NodeFlows::usePath($this->rHome . 'config/cluster/flows.json');
		NodeRole::useMainBuild(false);
		$rSeam = new ReflectionMethod(CleanupCronJob::class, 'streamChecks');
		foreach ([[null, true], [0, true], [1, true], [2, false]] as [$rMode, $rChecks]) {
			$this->flows($rMode);
			$this->assertSame($rChecks, $rSeam->invoke(new CleanupCronJob()), 'mode ' . var_export($rMode, true));
		}
		$rSource = (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/CleanupCronJob.php');
		$this->assertLessThan(strpos($rSource, '$db->query('), strpos($rSource, 'if (!$this->streamChecks())'), 'the seam comes before the first query');
	}

	/**
	 * Every system log line root writes goes through LogSink::syslog()
	 * first, with its own type, and keeps its row as it was where the
	 * node writes MAIN's database (MAIN, mode 0, mode 1 with LOGS off).
	 */
	public function testEveryRootSyslogLineIsHandedToTheAgentFirst(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/RootSignalsCronJob.php');
		$rInserts = substr_count($rSource, 'INSERT INTO `mysql_syslog`');
		$this->assertGreaterThanOrEqual(15, $rInserts);
		preg_match_all('/if \(!LogSink::syslog\(\'([A-Z_-]+)\',[^\n]*\) \{\n\t+\$db->query\("INSERT INTO `mysql_syslog`\(`server_id`, `type`, `error`, `username`, `ip`, `database`, `date`\) VALUES\(\?, \'([A-Z_-]+)\', /', $rSource, $rGuarded, PREG_SET_ORDER);
		$this->assertCount($rInserts, $rGuarded, 'no line written to MAIN\'s database without asking the agent first');
		foreach ($rGuarded as [, $rEvent, $rRow]) {
			$this->assertSame($rRow, $rEvent);
			$this->assertContains($rEvent, LogSink::SYSLOG_TYPES);
		}
	}
}
