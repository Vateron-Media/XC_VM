<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cache\RedisCache;
use XcVm\Core\Cluster\ConnectAudit;
use XcVm\Core\Cluster\LbDatabaseAccessException;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\SettingsAudit;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Core\Database\LazyDatabaseHandler;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Infrastructure\Redis\RedisManager;
use XcVm\Tests\Support\AgentUser;

/**
 * The mode-2 refusal (cluster plan, section 10, step 1): on a node in API
 * mode (mode 2, active or quarantined, by its agent's flows.json) every
 * connect to MAIN's MySQL or Redis is refused with LbDatabaseAccessException
 * before anything is opened, whichever path asks for it: the boot, a lazy
 * handle's first query, a direct handle, a graceful reconnect, Redis. The
 * attempt is still counted, with its site, so the Cluster Nodes page shows
 * what is left. MAIN, mode 0 and mode 1 connect as before; mode 1 is counted.
 *
 * The real boots run in a child PHP, in a throwaway deploy root, with an
 * xcvm_core stand-in that logs each connect it is asked for.
 */
final class DbConnectRefusalTest extends TestCase {
	private const NOW = 1800000000; // 2027-01-15 08:00 UTC: the in-process counts' day

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-refusal-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'config/cluster', 0777, true);
		AgentUser::own($this->rDir); // as root, the audit writes as this tree's owner
		NodeFlows::usePath($this->rDir . 'config/cluster/flows.json');
		NodeRole::useMainBuild(false);
		ConnectAudit::useDir($this->rDir . 'storage/cluster/sql_audit/');
		SettingsAudit::useDir($this->rDir . 'storage/cluster/settings_misses/', $this->rDir . 'config/cluster/');
		ConnectAudit::useClock(self::NOW);
		DatabaseFactory::reset();
	}

	protected function tearDown(): void {
		NodeFlows::usePath(null);
		NodeRole::useMainBuild(null);
		NodeRole::resetAudit();
		ConnectAudit::useDir(false);
		ConnectAudit::useClock(null);
		SettingsAudit::useDir(false);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function flows(?int $rMode, string $rState = 'active'): void {
		$rFile = $this->rDir . 'config/cluster/flows.json';
		@unlink($rFile);
		if ($rMode !== null) {
			file_put_contents($rFile, json_encode(['mode' => $rMode, 'flows' => 255, 'state' => $rState]));
		}
	}

	/** Run $rConnect, which must be refused, and return the refusal. */
	private function refused(callable $rConnect): LbDatabaseAccessException {
		try {
			$rConnect();
		} catch (LbDatabaseAccessException $e) {
			return $e;
		}
		$this->fail('not refused');
	}

	// ── Who refuses ──────────────────────────────────────────────────

	public function testOnlyAnApiNodeRefusesAndEveryEnrolledNodeCounts(): void {
		foreach ([[null, false, false], [0, false, false], [1, false, true], [2, true, true]] as [$rMode, $rRefuses, $rAudits]) {
			$this->flows($rMode);
			$this->assertSame($rRefuses, NodeRole::refusesConnects(), 'mode ' . var_export($rMode, true));
			$this->assertSame($rAudits, NodeRole::auditConnects(), 'mode ' . var_export($rMode, true));
		}
		$this->flows(2, 'quarantined');
		$this->assertTrue(NodeRole::refusesConnects(), 'a quarantined node keeps its mode');
		foreach (['enrolling', 'revoked', ''] as $rState) {
			$this->flows(2, $rState);
			$this->assertFalse(NodeRole::refusesConnects(), 'a node MAIN does not count as active: ' . $rState);
		}
		// The flip takes effect at the next connect, in a process that already ran.
		$this->flows(1);
		$this->assertFalse(NodeRole::refusesConnects());
		$this->flows(2);
		$this->assertTrue(NodeRole::refusesConnects());
	}

	public function testMainNeverRefusesEvenWithAStrayFlowsFile(): void {
		$this->flows(2);
		NodeRole::useMainBuild(null);
		$this->assertTrue(NodeRole::mainBuild(), 'this checkout ships the cluster API, as MAIN\'s build does');
		$this->assertFalse(NodeRole::refusesConnects());
		NodeRole::useMainBuild(false);
		$this->assertTrue(NodeRole::refusesConnects());
	}

	// ── Every path, in this process ──────────────────────────────────

	public function testModeTwoRefusesEveryConnectPathBeforeItOpens(): void {
		$this->flows(2);
		// No xcvm_core here: a path that got past the refusal would fail on \XC_VM.
		$rLine = __LINE__ + 1;
		$e = $this->refused(static fn() => new DatabaseHandler());
		$this->assertSame(ConnectAudit::SQL, $e->rKind);
		$this->assertStringEndsWith('DbConnectRefusalTest.php:' . $rLine, $e->rSite, 'the caller, not the connect machinery');
		$this->assertStringContainsString('mode 2', $e->getMessage());

		$rLine = __LINE__ + 1;
		$this->assertStringEndsWith(':' . $rLine, $this->refused(static fn() => DatabaseFactory::open())->rSite);
		$this->assertNull(DatabaseFactory::get(), 'nothing kept as the process\'s handle');

		$rLazy = new LazyDatabaseHandler(); // opens nothing yet
		$rLazyLine = __LINE__ + 1;
		$this->assertStringEndsWith(':' . $rLazyLine, $this->refused(static fn() => $rLazy->query('SELECT 1'))->rSite, 'a lazy handle: its first query');
		$this->refused(static fn() => $rLazy->db_connect(false, true)); // graceful or not: a refusal is no outage to wait out
		$this->refused(static fn() => $rLazy->reconnect());
		$this->refused(static fn() => $rLazy->db_explicit_connect('10.0.0.1', 3306, 'xc_vm', 'u', 'p'));

		$e = $this->refused(static fn() => RedisManager::connect());
		$this->assertSame(ConnectAudit::REDIS, $e->rKind);
		$this->assertStringContainsString('Redis', $e->getMessage());
		RedisManager::closeInstance(); // whatever an earlier test left: the singleton connects afresh
		$this->refused(static fn() => RedisManager::instance());
		$rCache = new RedisCache('10.0.0.1');
		$rLine = __LINE__ + 1;
		$this->assertStringEndsWith('DbConnectRefusalTest.php:' . $rLine, $this->refused(static fn() => $rCache->connect())->rSite, 'RedisCache is connect machinery, not the caller');

		// Each attempt is counted, marked refused in the log.
		$rDay = json_decode((string) file_get_contents($this->rDir . 'storage/cluster/sql_audit/' . gmdate('Ymd', self::NOW) . '.json'), true);
		$this->assertSame([6, 3], [$rDay['sql'], $rDay['redis']]);
		$rLog = array_map(static fn(string $rLine): array => json_decode($rLine, true), file($this->rDir . 'storage/cluster/sql_audit/' . gmdate('Ymd', self::NOW) . '.ndjson', FILE_IGNORE_NEW_LINES));
		$this->assertCount(9, $rLog);
		$this->assertSame([1], array_values(array_unique(array_column($rLog, 'r'))));
		$this->assertSame(1, array_sum(array_map(static fn(string $rSite): int => (int) str_starts_with($rSite, 'sql ') * (int) str_ends_with($rSite, 'DbConnectRefusalTest.php:' . $rLazyLine), array_keys($rDay['sites']))), 'the lazy handle\'s query, as its own site');
	}

	public function testTheRefusalIsCountedEvenWithTheAuditForcedOff(): void {
		$this->flows(2);
		NodeRole::resetAudit(false);
		$this->refused(static fn() => new DatabaseHandler());
		$this->assertFileExists($this->rDir . 'storage/cluster/sql_audit/' . gmdate('Ymd', self::NOW) . '.json', 'a refusal is what the page must show');
	}

	// ── The real boot, in a child PHP ────────────────────────────────

	/**
	 * Run $rCode after the real bootstrap's autoloader, in a throwaway deploy
	 * root whose xcvm_core logs each connect it is asked for (and answers it
	 * with a connection to the test server when $rEnv sets XCVM_TEST_PDO=1).
	 *
	 * @return array{0: int, 1: string, 2: list<string>} exit code, output, the connects xcvm_core saw
	 */
	private function child(string $rCode, array $rArgs = [], array $rEnv = []): array {
		$rPrepend = $this->rDir . 'prepend.php';
		file_put_contents($rPrepend, <<<'PHP'
			<?php
			define('MAIN_HOME', getenv('XCVM_TEST_HOME'));
			final class XC_VM {
				public static function config_server(): array {
					return ['server_id' => 5];
				}

				public static function db_connect(bool $rMigrate = false) {
					file_put_contents(MAIN_HOME . 'connects.log', "sql\n", FILE_APPEND);
					return getenv('XCVM_TEST_PDO') === '1' ? new PDO(getenv('XCVM_TEST_DB_DSN'), getenv('XCVM_TEST_DB_USER') ?: null, getenv('XCVM_TEST_DB_PASS') ?: null) : false;
				}

				public static function redis_connect() {
					file_put_contents(MAIN_HOME . 'connects.log', "redis\n", FILE_APPEND);
					return null;
				}
			}
			PHP);
		$rScript = $this->rDir . 'child.php';
		file_put_contents($rScript, $rCode);
		@unlink($this->rDir . 'connects.log');
		$rProc = proc_open(array_merge([...xcvm_test_child_php(), '-d', 'auto_prepend_file=' . $rPrepend, $rScript], $rArgs), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes, $this->rDir, $rEnv + ['XCVM_TEST_HOME' => $this->rDir, 'XCVM_TEST_SRC' => MAIN_HOME, 'PATH' => (string) getenv('PATH')]);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]) . (string) stream_get_contents($rPipes[2]);
		fclose($rPipes[1]);
		fclose($rPipes[2]);
		$rCode = proc_close($rProc);
		$rConnects = is_file($this->rDir . 'connects.log') ? file($this->rDir . 'connects.log', FILE_IGNORE_NEW_LINES) : [];
		return [$rCode, $rOut, $rConnects];
	}

	/** A CLI boot (the CLI profile, as console.php runs it), then one Redis connect. */
	private function bootScript(): string {
		return <<<'PHP'
			<?php
			use XcVm\Core\Enum\BootContext;
			use XcVm\Infrastructure\Redis\RedisManager;

			require getenv('XCVM_TEST_SRC') . 'bootstrap.php';
			try {
				XC_Bootstrap::boot(BootContext::Cli);
				echo "booted\n";
			} catch (\Throwable $e) {
				echo get_class($e), ': ', $e->getMessage(), "\n";
			}
			try {
				var_export(RedisManager::connect());
			} catch (\Throwable $e) {
				echo get_class($e), "\n";
			}
			PHP;
	}

	/**
	 * The child's counts: its one day file, whichever UTC day it counted on
	 * (the child runs on the real clock).
	 *
	 * @return array<string, mixed>|null
	 */
	private function counted(): ?array {
		$rFiles = glob($this->rDir . 'storage/cluster/sql_audit/*.json') ?: [];
		$this->assertLessThanOrEqual(1, count($rFiles), 'one boot, one day file');
		$rDay = $rFiles === [] ? null : json_decode((string) file_get_contents($rFiles[0]), true);
		return is_array($rDay) ? $rDay : null;
	}

	public function testAnApiNodesBootIsRefusedWithoutAConnect(): void {
		// Mode 2, rebooted, nothing applied yet: ReplicaStage falls back to
		// DatabaseStage, and its connect is the fail-closed answer.
		$this->flows(2);
		[, $rOut, $rConnects] = $this->child($this->bootScript());
		$this->assertSame([], $rConnects, 'xcvm_core was never asked to connect');
		$this->assertStringContainsString(LbDatabaseAccessException::class . ': MySQL: refused', $rOut);
		$this->assertStringContainsString(LbDatabaseAccessException::class, substr($rOut, (int) strpos($rOut, "\n")), 'Redis too');
		$rDay = $this->counted();
		$this->assertSame([1, 1], [$rDay['sql'], $rDay['redis']]);
		$this->assertCount(1, array_filter(array_keys($rDay['sites']), static fn(string $rSite): bool => str_starts_with($rSite, 'sql ') && str_contains($rSite, 'Core/Bootstrap/Stage/DatabaseStage.php:')), 'the boot\'s site');
	}

	public function testMainModeZeroAndModeOneConnectAsBefore(): void {
		foreach ([null, 0, 1] as $rMode) {
			$this->flows($rMode);
			exec('rm -rf ' . escapeshellarg($this->rDir . 'storage'));
			[, $rOut, $rConnects] = $this->child($this->bootScript());
			$this->assertSame(['sql'], array_slice($rConnects, 0, 1), 'mode ' . var_export($rMode, true));
			$this->assertStringContainsString('Cannot connect to database', $rOut, 'as MAIN\'s database being down always did');
			$this->assertStringNotContainsString(LbDatabaseAccessException::class, $rOut);
			// Mode 1 counts the connect; MAIN and mode 0 write nothing.
			$this->assertSame($rMode === 1 ? 1 : null, $this->counted()['sql'] ?? null, 'mode ' . var_export($rMode, true));
		}
		// Redis too: connected (here: xcvm_core answers nothing), not refused.
		$this->flows(1);
		[, $rOut, $rConnects] = $this->child(<<<'PHP'
			<?php
			use XcVm\Infrastructure\Redis\RedisManager;

			require getenv('XCVM_TEST_SRC') . 'bootstrap.php';
			var_export(RedisManager::connect());
			PHP);
		$this->assertSame(['redis'], $rConnects);
		$this->assertSame('NULL', trim($rOut));
	}

	/**
	 * DatabaseFactory::open(), which the load balancer's direct connects
	 * take, keeps the handle it builds as the process's, as each of them did
	 * with DatabaseFactory::set().
	 */
	public function testOpenKeepsItsHandleAsTheProcesss(): void {
		[$rCode, $rOut, $rConnects] = $this->child(<<<'PHP'
			<?php
			use XcVm\Infrastructure\Database\DatabaseFactory;

			require getenv('XCVM_TEST_SRC') . 'vendor/autoload.php';
			$rDb = DatabaseFactory::open();
			echo json_encode([$rDb === DatabaseFactory::get(), $rDb->query('SELECT 1')]);
			PHP, [], ['XCVM_TEST_PDO' => '1'] + \TestDb::env());
		$this->assertSame(0, $rCode, $rOut);
		$this->assertSame(['sql'], $rConnects);
		$this->assertSame([true, true], json_decode($rOut, true));
	}
}
