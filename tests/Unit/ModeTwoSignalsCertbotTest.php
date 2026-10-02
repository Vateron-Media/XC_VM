<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\SignalsCommand;
use XcVm\Cli\CronJobs\CertbotCronJob;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\EventSpool;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\NodeStateSink;
use XcVm\Core\Process\ProcessRunner;
use XcVm\Tests\Support\AgentUser;
use XcVm\Tests\Support\ReplicaFixture;

/**
 * The signals daemon, cron:certbot and the `certbot` command on a node in
 * mode 2 (api), which has no database or Redis of MAIN's (cluster plan,
 * section 10; ADR 0004, fourteenth Phase 7 increment): the daemon reads no
 * `signals` row and no Redis signal (kills come as `conn.kill_worker` and
 * `conn.drop`, cache jobs as `node.cache`, which cluster:exec runs), and the
 * certificate work keeps its own copy of the `certbot_ssl` it reported,
 * reloads its nginx itself and leaves the renewal to MAIN.
 *
 * Each path runs in a child PHP booted for real from the node replica, in a
 * throwaway deploy root, as ModeTwoPathsTest does: an xcvm_core stand-in logs
 * every connect it is asked for, the refusal (ConnectAudit) counts every
 * attempt, and `sudo`, `openssl`, `chown` and `crontab` are stand-ins first
 * on the child's PATH (the child checks they answer before it boots), as are
 * the deploy root's nginx and PHP binaries. `sudo certbot` answers what the
 * test says; `openssl` prints the fake certificate file it is given.
 */
final class ModeTwoSignalsCertbotTest extends TestCase {
	private string $rHome;

	private ReplicaFixture $rFixture;

	/** @var resource|null a process whose command line reads as nginx's master */
	private $rNginx = null;

	protected function setUp(): void {
		$this->rHome = sys_get_temp_dir() . '/xcvm-mode2-sc-' . bin2hex(random_bytes(4)) . '/';
		foreach (['config/cluster', 'tmp/cache', 'tmp/crons', 'tmp/logs', 'tmp/opened_cons', 'bin/nginx/conf', 'bin/nginx/sbin', 'bin/nginx_rtmp/sbin', 'bin/certbot/config/live/node.example', 'content/vod', 'storage', 'stub'] as $rDir) {
			mkdir($this->rHome . $rDir, 0777, true);
		}
		// As root, a node's audits and its kept state write as the owner of config/cluster/.
		AgentUser::own($this->rHome);
		$rLog = escapeshellarg($this->rHome . 'commands.log');
		$rStubs = [
			// certbot's dry run passes; the real run says what certbot.out holds.
			'sudo' => "echo \"sudo \$*\" >> " . $rLog . "\ncase \"\$*\" in\n\t*certbot*--dry-run*) echo 'The dry run was successful.' ;;\n\t*certbot*) cat " . escapeshellarg($this->rHome . 'certbot.out') . " 2>/dev/null ;;\nesac\n",
			// The certificate's fields, as `openssl x509 -serial -enddate -subject` prints them: the fake file holds them.
			'openssl' => "for rArg in \"\$@\"; do rLast=\$rArg; done\n[ -f \"\$rLast\" ] || exit 1\ncat \"\$rLast\"\n",
			'chown' => "echo \"chown \$*\" >> " . $rLog . "\n",
			// The boot writes the node's crontab: never this machine's.
			'crontab' => "echo \"crontab \$*\" >> " . escapeshellarg($this->rHome . 'crontab.log') . "\n",
		];
		foreach ($rStubs as $rTool => $rBody) {
			file_put_contents($this->rHome . 'stub/' . $rTool, "#!/bin/sh\n" . $rBody . "exit 0\n");
			chmod($this->rHome . 'stub/' . $rTool, 0755);
		}
		// The deploy root's own binaries: its nginx and the PHP its commands start.
		foreach (['nginx/sbin/nginx', 'nginx_rtmp/sbin/nginx_rtmp', 'php/bin/php'] as $rBinary) {
			@mkdir(dirname($this->rHome . 'bin/' . $rBinary), 0777, true);
			file_put_contents($this->rHome . 'bin/' . $rBinary, "#!/bin/sh\necho \"" . basename($rBinary) . " \$*\" >> " . $rLog . "\nexit 0\n");
			chmod($this->rHome . 'bin/' . $rBinary, 0755);
		}
		$this->rFixture = new ReplicaFixture($this->rHome . 'config/cluster/');
	}

	protected function tearDown(): void {
		if (is_resource($this->rNginx)) {
			proc_terminate($this->rNginx, 9);
			proc_close($this->rNginx);
		}
		ProcessRunner::useRunner(null);
		NodeFlows::usePath(null);
		NodeRole::useMainBuild(null);
		EventSpool::useDir(null);
		exec('rm -rf ' . escapeshellarg($this->rHome));
	}

	private function flows(?int $rMode, int $rFlows = 255, string $rState = 'active'): void {
		$rFile = $this->rHome . 'config/cluster/flows.json';
		@unlink($rFile);
		if ($rMode !== null) {
			file_put_contents($rFile, json_encode(['mode' => $rMode, 'flows' => $rFlows, 'state' => $rState]));
		}
	}

	/** The same flows for this process, read again at once (NodeFlows keeps what it read for 5 s). */
	private function here(?int $rMode, int $rFlows = 255, string $rState = 'active'): void {
		$this->flows($rMode, $rFlows, $rState);
		NodeFlows::usePath($this->rHome . 'config/cluster/flows.json');
		NodeRole::useMainBuild(false);
	}

	/**
	 * A node in mode 2 with every flow, its replica applied from disk as
	 * `service` does at boot; its own row says HTTPS on for node.example.
	 *
	 * @param array<string, string|null> $rSettings MAIN's settings the replica carries
	 */
	private function node(array $rSettings = []): void {
		$this->flows(2);
		$this->rFixture->node($rSettings);
		$rNode = json_decode((string) file_get_contents($this->rFixture->dir() . 'node.json'), true)['data'];
		$this->rFixture->whole('node', ['enable_https' => 1, 'domain_name' => 'node.example,192.0.2.5'] + $rNode);
		[$rCode, $rOut] = $this->child(['cluster:apply', '--from-disk'], dirname(__DIR__, 2) . '/src/console.php');
		$this->assertSame(0, $rCode, $rOut);
		@unlink($this->rHome . 'commands.log');
	}

	/**
	 * Run $rScript (the scenario script by default) in the throwaway deploy root.
	 *
	 * @param list<string> $rArgs
	 * @return array{0: int, 1: string, 2: array<string, mixed>|null} exit code, output, the scenario's result
	 */
	private function child(array $rArgs, ?string $rScript = null, ?string $rStdin = null): array {
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
		$rIn = $this->rHome . 'stdin';
		file_put_contents($rIn, $rStdin ?? '');
		$rCommand = array_merge([PHP_BINARY, '-d', 'auto_prepend_file=' . $rPrepend, $rScript ?? $this->scenarios()], $rArgs);
		$rProc = proc_open($rCommand, [0 => ['file', $rIn, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes, $this->rHome, ['XCVM_TEST_HOME' => $this->rHome, 'XCVM_TEST_SRC' => dirname(__DIR__, 2) . '/src/', 'PATH' => $this->rHome . 'stub:' . getenv('PATH')]);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		fclose($rPipes[1]);
		fclose($rPipes[2]);
		$rCode = proc_close($rProc);
		$rResult = null;
		if (preg_match('/^RESULT:(.*)$/m', $rOut, $rMatch)) {
			$rResult = json_decode($rMatch[1], true);
		}
		return [$rCode, $rOut . $rErr, $rResult];
	}

	/** The scenario script: boots as a CLI command (from the replica), then runs one path. */
	private function scenarios(): string {
		$rScript = $this->rHome . 'scenario.php';
		file_put_contents($rScript, <<<'PHP'
			<?php
			namespace XcVm\Cli\Commands {
				// The certbot command runs as root; this child need not.
				function posix_getpwuid(int $rUid): array {
					return ['name' => 'root'];
				}
			}

			namespace {
				use XcVm\Cli\Commands\CertbotCommand;
				use XcVm\Cli\Commands\SignalsCommand;
				use XcVm\Cli\CronJobs\CertbotCronJob;
				use XcVm\Core\Cluster\ReplicaBoot;
				use XcVm\Core\Cluster\SignalDispatcher;
				use XcVm\Core\Enum\BootContext;

				// Nothing runs unless the stand-ins answer for sudo, openssl, chown and crontab.
				foreach (['sudo', 'openssl', 'chown', 'crontab'] as $rTool) {
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
						case 'signals':
							$rDaemon = new class extends SignalsCommand {
								public int $rRestarts = 0;

								public int $rSettingsChecks = 0;

								public int $rServersReads = 0;

								// Every pass checks, so the loop (which now stays up) reaches
								// its second check — and its stop — in a few passes rather
								// than in the daemon's refresh interval.
								protected function shouldRefreshSettings(): bool {
									return true;
								}

								// A pass's settings refresh (after its nginx check) and its servers refresh.
								// The third check stops the loop; otherwise only the stand-in
								// nginx exiting (after 60s) would.
								protected function hasFileChanged(): bool {
									return ++$this->rSettingsChecks >= 3 || parent::hasFileChanged();
								}

								protected function refreshServers(): array {
									$this->rServersReads++;
									return parent::refreshServers();
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
								$rResult['code'] = $rDaemon->execute([]);
							} finally {
								$rResult['output'] = (string) ob_get_clean();
							}
							$rResult['restarts'] = $rDaemon->rRestarts;
							$rResult['passes'] = [$rDaemon->rSettingsChecks, $rDaemon->rServersReads];
							break;
						case 'certbot_cron':
							ob_start();
							try {
								(new ReflectionMethod(CertbotCronJob::class, 'loadCron'))->invoke(new CertbotCronJob(), ($argv[2] ?? '') === '1');
							} finally {
								$rResult['output'] = (string) ob_get_clean();
							}
							break;
						case 'own_jobs':
							// A job the node queues for itself (a movie stopped here, a closed connection).
							$rResult['queued'] = [
								SignalDispatcher::cache(5, ['type' => 'delete_vod', 'id' => 7]),
								SignalDispatcher::cacheBatch(5, [['type' => 'delete_con', 'uuid' => str_repeat('ab', 16)], ['type' => 'delete_con', 'uuid' => '../../config/cluster/agent.json'], ['type' => 'update_line', 'id' => 3]]),
								SignalDispatcher::cache(5, ['type' => 'update_stream', 'id' => 3]),
							];
							break;
						case 'other_jobs':
							// A job for another server: a row, which mode 2 refuses.
							try {
								$rResult['queued'] = SignalDispatcher::cache(6, ['type' => 'delete_vod', 'id' => 8]);
							} catch (\Throwable $e) {
								$rResult['queued'] = get_class($e);
							}
							break;
						case 'certbot':
							ob_start();
							try {
								$rResult['code'] = (new CertbotCommand())->execute([base64_encode((string) json_encode(['action' => 'certbot_generate', 'domain' => ['node.example', '192.0.2.5']]))]);
							} finally {
								$rResult['output'] = (string) ob_get_clean();
							}
							break;
					}
				} catch (\Throwable $e) {
					$rResult['error'] = get_class($e) . ': ' . $e->getMessage();
				}
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

	/** The certbot_ssl values spooled as node.state, in order. */
	private function reportedCertificates(): array {
		$rOut = [];
		foreach ($this->spooled('p0') as $rEvent) {
			if (($rEvent['type'] ?? '') === 'node.state' && array_key_exists('certbot_ssl', $rEvent['d']['fields'])) {
				$rOut[] = json_decode((string) $rEvent['d']['fields']['certbot_ssl'], true);
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

	/**
	 * A certificate as the openssl stand-in reads it, under $rDir (with the
	 * chain and key certbot writes beside it); returns its fullchain path.
	 */
	private function certificate(string $rDir, string $rSerial, int $rExpires): string {
		@mkdir($rDir, 0777, true);
		file_put_contents($rDir . '/fullchain.pem', 'serial=' . $rSerial . "\nnotAfter=" . gmdate('M j H:i:s Y', $rExpires) . " GMT\nsubject=CN = node.example\n");
		file_put_contents($rDir . '/chain.pem', 'chain');
		file_put_contents($rDir . '/privkey.pem', 'key');
		return $rDir . '/fullchain.pem';
	}

	private function sslConf(string $rCertificate): void {
		file_put_contents($this->rHome . 'bin/nginx/conf/ssl.conf', 'ssl_certificate ' . $rCertificate . ";\nssl_certificate_key " . dirname($rCertificate) . "/privkey.pem;\n");
	}

	/**
	 * A `node.cache` command for this node, signed by the panel, as the
	 * agent hands it to cluster:exec.
	 *
	 * @param list<array<string, mixed>> $rJobs
	 */
	private function cacheCommand(array $rJobs): string {
		$rDoc = (string) json_encode(['v' => 1, 'type' => 'node.cache', 'exp' => time() + 600, 'iat' => time(), 'cmd_id' => bin2hex(random_bytes(16)), 'seq' => 1, 'node_uuid' => $this->rFixture->rUuid, 'gen' => 1, 'dedupe_key' => null, 'args' => ['jobs' => $rJobs]], JSON_UNESCAPED_SLASHES);
		return (string) json_encode(['doc' => $rDoc, 'sig' => Enc::b64url($this->rFixture->rCrypto->sign('cmd', $rDoc))]);
	}

	/** What the node keeps of what it reported (NodeStateSink::reported), as its file holds it. */
	private function kept(): ?array {
		$rKept = json_decode((string) @file_get_contents($this->rHome . 'config/cluster/node_state.json'), true);
		return is_array($rKept) ? $rKept : null;
	}

	// ── The signals daemon ───────────────────────────────────────────

	/**
	 * The daemon's passes, with Redis on in the settings: no ping of MAIN's
	 * database, no Redis, no `signals` row read; it refreshes its settings and
	 * servers from the replica and keeps passing in the one process until
	 * something asks it to stop (here the second settings refresh, whose nginx
	 * check the stand-in fails), and only then re-executes itself.
	 */
	public function testTheSignalsDaemonPassNeedsNeitherDatabaseNorRedis(): void {
		$this->node(['redis_handler' => '1']);
		$this->nginxRunning();
		[, $rOut, $rResult] = $this->child(['signals']);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertTrue($rResult['replica']);
		$this->assertNoConnect();
		$this->assertSame([0, 1], [$rResult['code'], $rResult['restarts']], $rResult['output']);
		[$rSettingsChecks, $rServersReads] = $rResult['passes'];
		$this->assertGreaterThan(1, $rSettingsChecks, 'the loop stays up across passes');
		$this->assertGreaterThanOrEqual(1, $rServersReads, 'and refreshed its servers from the replica');
		$this->assertStringNotContainsString('Redis', $rResult['output']);
	}

	/**
	 * Cache jobs reach a node in mode 2 as a signed `node.cache` command,
	 * which cluster:exec runs as the daemon ran the rows: a closed viewer's
	 * connection file and a deleted movie's files go, the rest stays. A
	 * job's keys may come in any order.
	 */
	public function testCacheJobsRunFromANodeCacheCommand(): void {
		$this->node();
		$rUUID = str_repeat('ab', 16);
		touch($this->rHome . 'tmp/opened_cons/' . $rUUID);
		touch($this->rHome . 'tmp/opened_cons/' . str_repeat('cd', 16));
		foreach (['7.mp4', '8.mkv', '8.srt', '9.ts', '10.mp4'] as $rFile) {
			touch($this->rHome . 'content/vod/' . $rFile);
		}
		$rIn = $this->cacheCommand([
			['type' => 'delete_con', 'uuid' => $rUUID],
			['type' => 'delete_vod', 'id' => 7],
			['type' => 'delete_vods', 'id' => [8]],
			['type' => 'drop_con', 'uuid' => 'no-fanout-here'],
			['id' => 10, 'type' => 'delete_vod'],
		]);
		[$rCode, $rOut] = $this->child(['cluster:exec'], dirname(__DIR__, 2) . '/src/console.php', $rIn);
		$this->assertSame(0, $rCode, $rOut);
		$this->assertSame(['result' => true, 'jobs' => 5], json_decode(trim($rOut), true), $rOut);
		$this->assertNoConnect();
		$this->assertFileDoesNotExist($this->rHome . 'tmp/opened_cons/' . $rUUID);
		$this->assertFileExists($this->rHome . 'tmp/opened_cons/' . str_repeat('cd', 16));
		$this->assertSame(['9.ts'], array_values(array_diff(scandir($this->rHome . 'content/vod/'), ['.', '..'])));
	}

	/**
	 * A `node.cache` with one job outside MAIN's form, or naming more than
	 * CacheJobs::MAX targets in all, is refused whole: its well-formed jobs
	 * before the bad one do not run either.
	 */
	public function testACacheCommandWithABadJobRunsNone(): void {
		$this->node();
		touch($this->rHome . 'content/vod/7.mp4');
		foreach ([
			'a path for a uuid' => [['type' => 'delete_vod', 'id' => 7], ['type' => 'delete_con', 'uuid' => '../x']],
			'too many targets' => [['type' => 'delete_vod', 'id' => 7], ['type' => 'delete_vods', 'id' => range(1, \XcVm\Core\Cluster\CacheJobs::MAX)]],
		] as $rWhy => $rJobs) {
			[$rCode, $rOut] = $this->child(['cluster:exec'], dirname(__DIR__, 2) . '/src/console.php', $this->cacheCommand($rJobs));
			$this->assertSame(2, $rCode, $rWhy . ': ' . $rOut);
			$this->assertSame('cluster:exec: bad cache jobs', trim($rOut), $rWhy);
			$this->assertFileExists($this->rHome . 'content/vod/7.mp4', $rWhy . ': nothing ran');
			$this->assertNoConnect();
		}
	}

	/**
	 * The jobs a node in mode 2 queues for itself (a movie it stopped, a
	 * connection it closed) would be rows its daemon never reads, refused
	 * at their write: they run where they are queued, only in the form
	 * MAIN sends, and make no connect. A cache rebuild (cron:cache_engine,
	 * MAIN's) is not started.
	 */
	public function testANodesOwnCacheJobsRunWhereTheyAreQueued(): void {
		$this->node();
		touch($this->rHome . 'tmp/opened_cons/' . str_repeat('ab', 16));
		touch($this->rHome . 'content/vod/7.mp4');
		touch($this->rHome . 'content/vod/8.mp4');
		[, $rOut, $rResult] = $this->child(['own_jobs']);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertNoConnect();
		$this->assertSame([true, true, true], $rResult['queued']);
		$this->assertFileDoesNotExist($this->rHome . 'content/vod/7.mp4');
		$this->assertFileExists($this->rHome . 'content/vod/8.mp4');
		$this->assertFileDoesNotExist($this->rHome . 'tmp/opened_cons/' . str_repeat('ab', 16));
		$this->assertFileExists($this->rHome . 'config/cluster/agent.json', 'a path is not a uuid');
		$this->assertSame([], preg_grep('/cache_engine/', $this->commands()), 'MAIN\'s cache rebuilds are not a node\'s');
	}

	/**
	 * A job a node in mode 2 queues for another server is a row there, which
	 * the refusal stops: it never runs against this node's own files, and
	 * xcvm_core is never asked for a connect.
	 */
	public function testAJobForAnotherServerIsNotRunHere(): void {
		$this->node();
		touch($this->rHome . 'content/vod/8.mp4');
		[, $rOut, $rResult] = $this->child(['other_jobs']);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertSame(\XcVm\Core\Cluster\LbDatabaseAccessException::class, $rResult['queued'], 'the row\'s write is refused');
		$this->assertFileExists($this->rHome . 'content/vod/8.mp4');
		$this->assertSame([], is_file($this->rHome . 'connects.log') ? file($this->rHome . 'connects.log', FILE_IGNORE_NEW_LINES) : [], 'xcvm_core was never asked to connect');
	}

	// ── cron:certbot ─────────────────────────────────────────────────

	/**
	 * The certificate nginx serves differs from what the node reported (it
	 * reported none yet): its record goes to MAIN as node.state through the
	 * spool, the node keeps its own copy, and it reloads its nginx itself
	 * (no `signals` row for root to read), logging root's RELOAD line
	 * through the spool. MAIN's panel logs are MAIN's own cron's to send.
	 * The next daily run finds its copy current, and reports the certificate
	 * again all the same, since MAIN may have cleared its record meanwhile
	 * (the admin's regenerate, its certbot_generate never reaching the
	 * node): nginx is not reloaded.
	 */
	public function testCronCertbotReportsItsCertificateAndReloadsItself(): void {
		$this->node();
		$rCert = $this->certificate($this->rHome . 'bin/certbot/config/live/node.example', '0A1B', time() + 60 * 86400);
		$this->sslConf($rCert);
		[, $rOut, $rResult] = $this->child(['certbot_cron']);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertNoConnect();
		$this->assertStringContainsString('Certificate valid, not due for renewal.', $rResult['output']);
		$rReported = $this->reportedCertificates();
		$this->assertCount(1, $rReported);
		$this->assertSame(['serial' => '0A1B', 'path' => dirname($rCert)], array_intersect_key($rReported[0], ['serial' => 1, 'path' => 1]));
		$this->assertSame(['certbot_ssl'], array_keys((array) $this->kept()));
		$this->assertSame('0A1B', json_decode((string) $this->kept()['certbot_ssl'], true)['serial'], 'the node keeps what it reported');
		$this->assertSame(['nginx_rtmp -s reload', 'nginx -s reload'], $this->commands(), 'reloaded here, without sudo');
		$rLines = array_map(static fn(array $rEvent): array => $rEvent['d']['rows'][0], $this->spooled('p1'));
		$this->assertSame([['RELOAD', 'NGINX services reloaded on request.']], array_map(static fn(array $rRow): array => [$rRow['type'], $rRow['error']], $rLines));

		@unlink($this->rHome . 'commands.log');
		[, $rOut, $rResult] = $this->child(['certbot_cron']);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertNoConnect();
		$this->assertStringContainsString('Reported ssl configuration to MAIN', $rResult['output']);
		$this->assertSame(['0A1B', '0A1B'], array_column($this->reportedCertificates(), 'serial'), 'reported again');
		$this->assertSame([], $this->commands(), 'nothing to reload');
		$this->assertSame(1, count($this->spooled('p1')), 'nor a RELOAD line');
	}

	/**
	 * A certificate due for renewal: a node in mode 2 queues no renewal for
	 * its root (MAIN sends it the `node.root certbot_generate`), and makes
	 * no connect.
	 */
	public function testCronCertbotLeavesTheRenewalToMain(): void {
		$this->node();
		$rCert = $this->certificate($this->rHome . 'bin/certbot/config/live/node.example', '0C', time() + 3 * 86400);
		$this->sslConf($rCert);
		[, $rOut, $rResult] = $this->child(['certbot_cron']);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertNoConnect();
		$this->assertStringContainsString('Certificate due for renewal.', $rResult['output']);
		$this->assertStringContainsString('MAIN sends the renewal', $rResult['output']);
		$this->assertSame([], preg_grep('/certbot/', $this->commands()), 'certbot is not run here');
	}

	/**
	 * nginx back on the default certificate while the node's record names
	 * the certbot one: the configuration is restored from the node's own
	 * copy and nginx reloaded, all without MAIN's database.
	 */
	public function testCronCertbotRestoresTheSslConfigurationFromItsOwnRecord(): void {
		$this->node();
		$rCert = $this->certificate($this->rHome . 'bin/certbot/config/live/node.example', '0D', time() + 60 * 86400);
		file_put_contents($this->rHome . 'config/cluster/node_state.json', json_encode(['certbot_ssl' => json_encode(['serial' => '0D', 'expiration' => time() + 60 * 86400, 'subject' => 'CN = node.example', 'path' => dirname($rCert)])]));
		$this->certificate($this->rHome . 'bin/nginx/conf/default', '01', time() + 3650 * 86400);
		file_put_contents($this->rHome . 'bin/nginx/conf/ssl.conf', "ssl_certificate server.crt;\nssl_certificate_key server.key;\n");
		[, $rOut, $rResult] = $this->child(['certbot_cron', '1']);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertNoConnect();
		$this->assertStringContainsString('Fixed ssl configuration file', $rResult['output']);
		$this->assertStringStartsWith('ssl_certificate ' . $rCert . ";\n", (string) file_get_contents($this->rHome . 'bin/nginx/conf/ssl.conf'));
		$this->assertSame(['nginx_rtmp -s reload', 'nginx -s reload'], $this->commands());
	}

	/**
	 * The reload in mode 2 starts each nginx from its argv list, with no
	 * shell: nginx_rtmp's `-s reload`, then nginx's, from the deploy root's
	 * binaries, their stderr the cron's as before, after root's line through
	 * the spool.
	 */
	public function testTheReloadStartsEachNginxFromItsArgvList(): void {
		EventSpool::useDir($this->rHome . 'config/cluster/spool/');
		$this->here(2);
		$rRuns = [];
		ProcessRunner::useRunner(static function (array $rArgv, bool $rQuiet) use (&$rRuns): int {
			$rRuns[] = [$rArgv, $rQuiet];
			return 0;
		});
		(new ReflectionMethod(CertbotCronJob::class, 'reloadNginx'))->invoke(new CertbotCronJob(), true, new stdClass());
		$this->assertSame([
			[[BIN_PATH . 'nginx_rtmp/sbin/nginx_rtmp', '-s', 'reload'], false],
			[[BIN_PATH . 'nginx/sbin/nginx', '-s', 'reload'], false],
		], $rRuns);
		$this->assertSame([['RELOAD', 'NGINX services reloaded on request.']], array_map(static fn(array $rEvent): array => [$rEvent['d']['rows'][0]['type'], $rEvent['d']['rows'][0]['error']], $this->spooled('p1')));
	}

	// ── The certbot command ──────────────────────────────────────────

	/**
	 * certbot says the certificate is not due: where MAIN has no record of
	 * the node's certificate (its row, or in mode 2 the node's copy, which
	 * the command forgets first as the admin's regenerate clears MAIN's),
	 * the command points nginx at the newest certificate certbot holds for
	 * the name and reports it through the spool, without MAIN's database.
	 * A copy from an earlier report does not stop it.
	 */
	public function testTheCertbotCommandsFailurePathNeedsNoDatabase(): void {
		$this->node();
		$rCert = $this->certificate($this->rHome . 'bin/certbot/config/live/node.example', '0E', time() + 60 * 86400);
		file_put_contents($this->rHome . 'bin/nginx/conf/ssl.conf', "ssl_certificate server.crt;\n");
		file_put_contents($this->rHome . 'certbot.out', "Certificate not yet due for renewal\n");
		[, $rOut, $rResult] = $this->child(['certbot']);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertSame(0, $rResult['code']);
		$this->assertNoConnect();
		$this->assertStringContainsString('Warning: Certificate not due for renewal!', $rResult['output']);
		$this->assertCount(2, preg_grep('/^sudo certbot .* certonly .* -d node\.example$/', $this->commands()), 'the dry run and the run, for the name only');
		$this->assertStringStartsWith('ssl_certificate ' . $rCert . ";\n", (string) file_get_contents($this->rHome . 'bin/nginx/conf/ssl.conf'));
		$this->assertSame(['0E'], array_column($this->reportedCertificates(), 'serial'));
		$this->assertSame('0E', json_decode((string) $this->kept()['certbot_ssl'], true)['serial']);
		$this->assertTrue(json_decode((string) file_get_contents($this->rHome . 'bin/certbot/logs/xc_vm.log'), true)['status']);
		$this->assertCount(1, preg_grep('/^php .*console\.php cron:certbot 1$/', $this->commands()), 'and it checks the result as before');

		file_put_contents($this->rHome . 'bin/nginx/conf/ssl.conf', "ssl_certificate server.crt;\n");
		[, $rOut, $rResult] = $this->child(['certbot']);
		$this->assertIsArray($rResult, $rOut);
		$this->assertArrayNotHasKey('error', $rResult, $rOut);
		$this->assertNoConnect();
		$this->assertStringStartsWith('ssl_certificate ' . $rCert . ";\n", (string) file_get_contents($this->rHome . 'bin/nginx/conf/ssl.conf'), 'repaired again');
		$this->assertSame(['0E', '0E'], array_column($this->reportedCertificates(), 'serial'));
	}

	// ── Everywhere else, as before ───────────────────────────────────

	/**
	 * Only a node in mode 2 stops reading MAIN's `signals` table and Redis
	 * signals; MAIN, mode 0 and mode 1 keep their loop.
	 */
	public function testOnlyAModeTwoNodeStopsReadingSignals(): void {
		foreach ([[null, true], [0, true], [1, true], [2, false]] as [$rMode, $rReads]) {
			$this->here($rMode);
			$this->assertSame($rReads, SignalsCommand::readsMainDatabase(), 'mode ' . var_export($rMode, true));
		}
		foreach (['quarantined' => false, 'revoked' => true, 'enrolling' => true] as $rState => $rReads) {
			$this->here(2, 255, $rState);
			$this->assertSame($rReads, SignalsCommand::readsMainDatabase(), $rState);
		}
		$this->here(2);
		NodeRole::useMainBuild(true);
		$this->assertTrue(SignalsCommand::readsMainDatabase(), 'MAIN, even with a stray flows.json');
	}

	/**
	 * A node keeps its own copy of the certificate record it reported only
	 * in mode 2, and only once the spool took the event; a field MAIN keeps
	 * no use for is not kept.
	 */
	public function testANodeInModeTwoKeepsWhatItReported(): void {
		EventSpool::useDir($this->rHome . 'config/cluster/spool/');

		$this->here(1);
		$this->assertTrue(NodeStateSink::state(['certbot_ssl' => '{"serial":"01"}']));
		$this->assertNull($this->kept(), 'mode 1 reads MAIN\'s row');

		$this->here(2, 255 & ~NodeFlows::TELEMETRY);
		$this->assertFalse(NodeStateSink::state(['certbot_ssl' => '{"serial":"02"}']), 'no event, and no row in mode 2');
		$this->assertNull(NodeStateSink::reported('certbot_ssl'));

		$this->here(2);
		$this->assertTrue(NodeStateSink::state(['certbot_ssl' => '{"serial":"03"}', 'governor' => 'performance']));
		$this->assertSame('{"serial":"03"}', NodeStateSink::reported('certbot_ssl'));
		$this->assertSame(['certbot_ssl'], array_keys((array) $this->kept()));
		$this->assertNull(NodeStateSink::reported('governor'));

		// The agent stopped: nothing spooled, so nothing kept.
		touch($this->rHome . 'config/cluster/flows.json', time() - EventSpool::STALE_AFTER - 5);
		$this->assertFalse(NodeStateSink::state(['certbot_ssl' => '{"serial":"04"}']));
		$this->assertSame('{"serial":"03"}', NodeStateSink::reported('certbot_ssl'));

		// Forgotten in mode 2 only (MAIN may have cleared its record).
		$this->here(1);
		NodeStateSink::forget('certbot_ssl');
		$this->assertSame('{"serial":"03"}', NodeStateSink::reported('certbot_ssl'));
		$this->here(2);
		NodeStateSink::forget('certbot_ssl', 'governor');
		$this->assertNull(NodeStateSink::reported('certbot_ssl'));
		$this->assertSame([], (array) $this->kept());
	}
}
