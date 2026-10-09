<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Bootstrap\BootKernel;
use XcVm\Core\Bootstrap\BootStageInterface;
use XcVm\Core\Bootstrap\BootState;
use XcVm\Core\Bootstrap\StageProfiles;
use XcVm\Core\Bootstrap\Stage\ConfigStage;
use XcVm\Core\Bootstrap\Stage\ConstantsStage;
use XcVm\Core\Bootstrap\Stage\ContainerPopulateStage;
use XcVm\Core\Bootstrap\Stage\DatabaseStage;
use XcVm\Core\Bootstrap\Stage\FloodProtectionStage;
use XcVm\Core\Bootstrap\Stage\HealthCheckStage;
use XcVm\Core\Bootstrap\Stage\HostVerificationStage;
use XcVm\Core\Bootstrap\Stage\LegacyCoreStage;
use XcVm\Core\Bootstrap\Stage\ProcessTitleStage;
use XcVm\Core\Bootstrap\Stage\ReplicaStage;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\ReplicaBoot;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Enum\BootContext;
use XcVm\Infrastructure\Bootstrap\WebApiBootstrap;
use XcVm\Tests\Support\AgentUser;
use XcVm\Tests\Support\ReplicaFixture;

/**
 * Booting from the node replica (cluster plan, section 10, step 2; section 9,
 * "Storage and boot"). A node in mode 2, and one in mode 1 with the CONFIG
 * flow on, boots its CLI processes and web API endpoints through
 * ReplicaStage, from the caches its replica built, once an apply built them
 * since the reboot, and its streaming entry points take a lazy handle; in
 * mode 1 whatever still needs MAIN's database connects on first use, counted
 * at its site, never refused. `cluster:apply` boots that way in every mode,
 * so `service` builds the caches at boot while MAIN is unreachable. Mode 0
 * nodes, mode 1 without CONFIG, and MAIN, boot exactly as before.
 *
 * The boots themselves run in a child PHP (the real console.php and
 * bootstrap), in a throwaway deploy root, with an xcvm_core stand-in whose
 * MAIN database never answers (or is a schema on the test server) and logs each connect.
 */
final class ReplicaBootTest extends TestCase {
	private string $rHome;

	private ReplicaFixture $rFixture;

	/** MAIN's database, while a test holds one (mainDb()). */
	private ?\TestDb $rMainDb = null;

	/** The CLI profile of a mode 0 or 1 node, as before ReplicaStage. */
	private const LEGACY_CLI = [
		ConstantsStage::class, ConfigStage::class, FloodProtectionStage::class, HostVerificationStage::class,
		DatabaseStage::class, LegacyCoreStage::class, ProcessTitleStage::class, ContainerPopulateStage::class, HealthCheckStage::class,
	];

	protected function setUp(): void {
		$this->rHome = sys_get_temp_dir() . '/xcvm-boot-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rHome . 'config/cluster', 0777, true);
		mkdir($this->rHome . 'tmp/cache', 0777, true);
		// As root, a node's audits write as the owner of config/cluster/.
		AgentUser::own($this->rHome);
		// LegacyInitializer regenerates the xc_vm crontab once per boot: a
		// `crontab` first on the child's PATH logs it, never the test user's.
		mkdir($this->rHome . 'stub');
		file_put_contents($this->rHome . 'stub/crontab', "#!/bin/sh\necho \"crontab \$*\" >> " . escapeshellarg($this->rHome . 'crontab.log') . "\nfor f; do if [ -f \"\$f\" ]; then cat \"\$f\" >> " . escapeshellarg($this->rHome . 'crontab.log') . "; fi; done\nexit 0\n");
		chmod($this->rHome . 'stub/crontab', 0755);
		$this->rFixture = new ReplicaFixture($this->rHome . 'config/cluster/');
		NodeFlows::usePath($this->rHome . 'config/cluster/flows.json');
	}

	protected function tearDown(): void {
		NodeFlows::usePath(null);
		NodeRole::useMainBuild(null);
		NodeRole::resetAudit();
		ReplicaBoot::reset();
		ServiceContainer::resetInstance();
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, null);
		exec('rm -rf ' . escapeshellarg($this->rHome));
	}

	private function flows(?int $rMode, int $rFlows = 255, string $rState = 'active'): void {
		$rFile = $this->rHome . 'config/cluster/flows.json';
		if ($rMode === null) {
			@unlink($rFile);
			return;
		}
		file_put_contents($rFile, json_encode(['mode' => $rMode, 'flows' => $rFlows, 'state' => $rState]));
	}

	/** @return list<class-string> */
	private function cliProfile(array $rOptions = []): array {
		return array_map('get_class', StageProfiles::for(BootContext::Cli, BootKernel::resolve(BootContext::Cli, $rOptions + ['process' => 'XC_VM[Console]'])));
	}

	/** @return list<class-string> */
	private function webApiStages(): array {
		return array_map('get_class', WebApiBootstrap::coreStages(true));
	}

	// ── Which boot ───────────────────────────────────────────────────

	public function testModeZeroModeOneWithoutConfigAndMainBootExactlyAsBefore(): void {
		// No file (MAIN, a legacy node), mode 0 whatever its flows, mode 1 with
		// every flow but CONFIG, and a node MAIN does not count as active.
		foreach ([[null], [0, 0], [0, 255], [1, 31], [1, 255, 'enrolling'], [1, 255, 'revoked'], [2, 255, 'enrolling'], [2, 255, 'revoked']] as $rCase) {
			$this->flows(...$rCase);
			$this->assertFalse(ReplicaBoot::wanted(), json_encode($rCase));
			$this->assertFalse(BootKernel::resolve(BootContext::Cli)['replica'], json_encode($rCase));
			$this->assertSame(self::LEGACY_CLI, $this->cliProfile(), json_encode($rCase));
			$this->assertSame([DatabaseStage::class, LegacyCoreStage::class], $this->webApiStages(), json_encode($rCase));
		}
		// A caller that asks for the database keeps it.
		foreach ([[1, 63], [2]] as $rCase) {
			$this->flows(...$rCase);
			$this->assertSame(self::LEGACY_CLI, $this->cliProfile(['replica' => false]));
		}
	}

	public function testModeOneWithConfigAndModeTwoBootThroughReplicaStage(): void {
		// Mode 1 with CONFIG (bit 32), active or quarantined; mode 2 whatever its flows.
		foreach ([[1, 63, 'active'], [1, 32, 'quarantined'], [2, 255, 'active'], [2, 255, 'quarantined'], [2, 31, 'active']] as $rCase) {
			$this->flows(...$rCase);
			$this->assertTrue(ReplicaBoot::wanted(), json_encode($rCase));
			$this->assertSame(ReplicaBoot::WHEN_READY, BootKernel::resolve(BootContext::Cli)['replica'], json_encode($rCase));
			$rProfile = $this->cliProfile();
			$this->assertSame([ConstantsStage::class, ConfigStage::class, FloodProtectionStage::class, HostVerificationStage::class, ReplicaStage::class, ProcessTitleStage::class, ContainerPopulateStage::class, HealthCheckStage::class], $rProfile, json_encode($rCase));
			$this->assertSame([ReplicaStage::class], $this->webApiStages(), json_encode($rCase));
			$rStage = WebApiBootstrap::coreStages(true)[0];
			$this->assertSame(ReplicaBoot::WHEN_READY, (new \ReflectionProperty(ReplicaStage::class, 'rWhen'))->getValue($rStage), 'the web API waits for an apply too');
		}
		// Only the CLI profile: the admin UI (MAIN's) and streaming boots keep theirs.
		$this->assertNull(BootKernel::resolve(BootContext::Admin)['replica']);
	}

	public function testOnlyModeTwoRefusesMainWhereModeOneBootsFromItsReplica(): void {
		NodeRole::useMainBuild(false);
		$this->flows(1, 63);
		$this->assertTrue(ReplicaBoot::wanted());
		$this->assertFalse(ReplicaBoot::apiMode());
		$this->assertFalse(NodeRole::refusesConnects(), 'mode 1 never refuses');
		$this->assertTrue(NodeRole::auditConnects(), 'it counts');
		foreach (['active', 'quarantined'] as $rState) {
			$this->flows(2, 31, $rState);
			$this->assertTrue(ReplicaBoot::apiMode());
			$this->assertTrue(NodeRole::refusesConnects(), 'mode 2 refuses, CONFIG or not');
		}

		// What a process booted from the replica may still read from MAIN's
		// database (ReplicaStage starts the boot with its option).
		$this->flows(1, 63);
		ReplicaBoot::start(ReplicaBoot::WHEN_READY);
		$this->assertSame([true, true], [ReplicaBoot::active(), ReplicaBoot::hybrid()], 'mode 1: lazily, counted');
		ReplicaBoot::start(ReplicaBoot::ALWAYS);
		$this->assertSame([true, false], [ReplicaBoot::active(), ReplicaBoot::hybrid()], 'cluster:apply: never');
		$this->flows(2);
		ReplicaBoot::start(ReplicaBoot::WHEN_READY);
		$this->assertSame([true, false], [ReplicaBoot::active(), ReplicaBoot::hybrid()], 'mode 2: never (refused)');
		ReplicaBoot::reset();
		$this->assertSame([false, false], [ReplicaBoot::active(), ReplicaBoot::hybrid()]);

		// The refusal is asked at each read, as at each connect: a daemon that
		// booted in mode 1 keeps the caches once the node is switched to mode
		// 2, and reads MAIN's database again once it is back in mode 1.
		$this->flows(1, 63);
		ReplicaBoot::start(ReplicaBoot::WHEN_READY);
		$this->assertTrue(ReplicaBoot::hybrid());
		$this->flows(2);
		$this->assertFalse(ReplicaBoot::hybrid(), 'switched to mode 2: refused, so the caches');
		$this->flows(1, 63);
		$this->assertTrue(ReplicaBoot::hybrid(), 'back in mode 1');

		// MAIN's build never refuses (a node installed from MAIN's archive), so
		// there mode 2 reads MAIN's database as mode 1 does, counted.
		NodeRole::useMainBuild(true);
		$this->flows(2);
		ReplicaBoot::start(ReplicaBoot::WHEN_READY);
		$this->assertTrue(ReplicaBoot::hybrid(), 'mode 2 on MAIN\'s build: not refused');
		ReplicaBoot::start(ReplicaBoot::ALWAYS);
		$this->assertFalse(ReplicaBoot::hybrid(), 'cluster:apply: never');
	}

	public function testClusterApplyBootsFromTheReplicaInEveryMode(): void {
		$this->assertSame(ReplicaBoot::ALWAYS, ReplicaBoot::forArgv(['console.php', 'cluster:apply', '--from-disk']));
		$this->assertSame(ReplicaBoot::ALWAYS, ReplicaBoot::forArgv(['console.php', 'cluster:apply']));
		$this->assertNull(ReplicaBoot::forArgv(['console.php', 'cron:cache']));
		$this->assertNull(ReplicaBoot::forArgv(['console.php']));
		$this->assertSame(ReplicaBoot::ALWAYS, ReplicaBoot::forArgv(['console.php', 'fanout_binary', 'agent', ReplicaBoot::OPTION]), 'any command given the option');
		$this->assertNull(ReplicaBoot::forArgv(['console.php', ReplicaBoot::OPTION]), 'the option is no command');
		foreach ([null, 0, 1, 2] as $rMode) {
			$this->flows($rMode);
			$this->assertSame(ReplicaBoot::ALWAYS, BootKernel::resolve(BootContext::Cli, ['replica' => ReplicaBoot::ALWAYS])['replica']);
			$this->assertContains(ReplicaStage::class, $this->cliProfile(['replica' => ReplicaBoot::ALWAYS]));
		}
	}

	public function testUntilAnApplyBuiltTheCachesModeTwoBootsThroughTheDatabase(): void {
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, new FileCache($this->rHome . 'tmp/cache/'));
		$rLog = new ArrayObject();
		$rStage = static fn(string $rName): BootStageInterface => new class ($rName, $rLog) implements BootStageInterface {
			public function __construct(private string $rName, private ArrayObject $rLog) {
			}

			public function run(BootState $state): void {
				$this->rLog->append($this->rName);
			}
		};
		$rState = new BootState(BootContext::Cli, [], ServiceContainer::getInstance());
		(new ReplicaStage(false, ReplicaBoot::WHEN_READY, [$rStage('db'), $rStage('core')]))->run($rState);
		$this->assertSame(['db', 'core'], $rLog->getArrayCopy(), 'nothing to boot from yet: DatabaseStage and LegacyCoreStage');
		$this->assertFalse($rState->replica);
		$this->assertFalse(ReplicaBoot::active());

		// Only the settings built: the servers are still MAIN's database's.
		FileCache::setCache('replica_owned', ['settings' => 'e']);
		$this->assertFalse(ReplicaBoot::ready());
		FileCache::setCache('replica_owned', ['settings' => 'e', 'servers' => 'e/e']);
		$this->assertTrue(ReplicaBoot::ready());
	}

	// ── The boots, in a child PHP ────────────────────────────────────

	/**
	 * Run the real console.php (or $rScript) in the throwaway deploy root.
	 * MAIN's database never answers, or with $rMainDb it is that schema on the test server.
	 *
	 * @param list<string> $rArgs
	 * @return array{0: int, 1: string, 2: list<string>} exit code, stdout, the connects to MAIN's database
	 */
	private function child(array $rArgs, ?string $rScript = null, ?string $rMainDb = null): array {
		$rPrepend = $this->rHome . 'prepend.php';
		file_put_contents($rPrepend, <<<'PHP'
			<?php
			// A throwaway deploy root, and an xcvm_core whose MAIN database never
			// answers (or is XCVM_TEST_MAIN_DB, a schema on the test server).
			define('MAIN_HOME', getenv('XCVM_TEST_HOME'));
			final class XC_VM {
				public static function config_server(): array {
					return ['server_id' => 5];
				}

				public static function db_connect(bool $rMigrate = false) {
					file_put_contents(MAIN_HOME . 'connects.log', "sql\n", FILE_APPEND);
					if (!getenv('XCVM_TEST_MAIN_DB')) {
						return false;
					}
					$rDsn = preg_replace('/;?dbname=[^;]*/', '', (string) getenv('XCVM_TEST_DB_DSN')) . ';dbname=' . getenv('XCVM_TEST_MAIN_DB');
					return new PDO($rDsn, getenv('XCVM_TEST_DB_USER') ?: null, getenv('XCVM_TEST_DB_PASS') ?: null);
				}
			}

			PHP);
		@unlink($this->rHome . 'connects.log');
		$rEnv = ['XCVM_TEST_HOME' => $this->rHome, 'XCVM_TEST_SRC' => MAIN_HOME, 'PATH' => $this->rHome . 'stub:' . getenv('PATH')];
		if ($rMainDb !== null) {
			$rEnv += ['XCVM_TEST_MAIN_DB' => $rMainDb] + \TestDb::env();
		}
		$rCommand = array_merge([...xcvm_test_child_php(), '-d', 'auto_prepend_file=' . $rPrepend, $rScript ?? MAIN_HOME . 'console.php'], $rArgs);
		$rProc = proc_open($rCommand, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes, $this->rHome, $rEnv);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		fclose($rPipes[1]);
		$rCode = proc_close($rProc);
		$rConnects = is_file($this->rHome . 'connects.log') ? file($this->rHome . 'connects.log', FILE_IGNORE_NEW_LINES) : [];
		return [$rCode, $rOut, $rConnects];
	}

	/** A child script that boots as a CLI command (or `webapi`: a cached web API endpoint) and dumps what the boot left. */
	private function dumpScript(): string {
		$rScript = $this->rHome . 'dump.php';
		file_put_contents($rScript, <<<'PHP'
			<?php
			use XcVm\Core\Bootstrap\BootPipeline;
			use XcVm\Core\Bootstrap\BootState;
			use XcVm\Core\Cluster\ReplicaBoot;
			use XcVm\Core\Config\ConstantsInitializer;
			use XcVm\Core\Config\SettingsManager;
			use XcVm\Core\Container\ServiceContainer;
			use XcVm\Core\Enum\BootContext;
			use XcVm\Infrastructure\Bootstrap\WebApiBootstrap;
			use XcVm\Infrastructure\Database\DatabaseFactory;

			require getenv('XCVM_TEST_SRC') . 'bootstrap.php';
			if (($argv[1] ?? '') === 'webapi') {
				// WebApiBootstrap::init's core step, for a cached endpoint.
				ConstantsInitializer::init();
				(new BootPipeline(WebApiBootstrap::coreStages(true)))->run(new BootState(BootContext::WebApi, ['cached' => true], ServiceContainer::getInstance()));
			} else {
				XC_Bootstrap::boot(BootContext::Cli, ['replica' => ReplicaBoot::forArgv(['console.php', 'cron:cache'])]);
			}
			$rC = ServiceContainer::getInstance();
			$rSettings = SettingsManager::getAll();
			SettingsManager::get('zz_not_carried'); // a read the replica would not answer (SettingsAudit)
			echo json_encode([
				'replica' => ReplicaBoot::active(),
				'db' => get_class($GLOBALS['db']),
				'opened' => method_exists($GLOBALS['db'], 'isOpen') ? $GLOBALS['db']->isOpen() : null,
				'factory' => DatabaseFactory::get() === $GLOBALS['db'],
				'container_db' => $rC->has('db') ? $rC->get('db') === $GLOBALS['db'] : null,
				'server_id' => SERVER_ID,
				'settings_global' => $GLOBALS['rSettings'] === $rSettings,
				'core_settings' => $rC->get('core.settings') === $rSettings,
				'container_settings' => $rC->has('settings') ? $rC->get('settings') === $rSettings : null,
				'live_streaming_pass' => $rSettings['live_streaming_pass'] ?? null,
				'on_demand_wait_time' => $rSettings['on_demand_wait_time'] ?? null,
				'timezone' => date_default_timezone_get(),
				'servers' => array_keys($GLOBALS['rServers']),
				'core_servers' => array_keys($rC->get('core.servers')),
				'api_url' => $GLOBALS['rServers'][5]['api_url'] ?? null,
				'core_bouquets' => $rC->get('core.bouquets'),
				'core_categories' => $rC->get('core.categories'),
				'container_bouquets' => $rC->has('bouquets') ? $rC->get('bouquets') : null,
				'core_config' => $rC->get('core.config'),
				'ffmpeg' => is_string($GLOBALS['rFFMPEG_CPU']) && is_string($GLOBALS['rFFPROBE']),
				'request' => is_array($GLOBALS['rRequest']),
			]);
			PHP);
		return $rScript;
	}

	/** @return array<string, mixed> */
	private function dump(bool $rWebApi = false): array {
		[$rCode, $rOut, $rConnects] = $this->child($rWebApi ? ['webapi'] : [], $this->dumpScript());
		$this->assertSame(0, $rCode, $rOut);
		$rDump = json_decode($rOut, true);
		$this->assertIsArray($rDump, $rOut);
		return $rDump + ['connects' => $rConnects];
	}

	/** @return mixed a cache the child wrote */
	private function cache(string $rKey): mixed {
		$rData = (string) @file_get_contents($this->rHome . 'tmp/cache/' . $rKey);
		return function_exists('igbinary_unserialize') ? @igbinary_unserialize($rData) : @unserialize($rData);
	}

	public function testFromDiskBuildsTheCachesWhileMainsDatabaseIsUnreachable(): void {
		$this->flows(1, 63);
		$this->rFixture->node();
		// The R2 streams section too (the STREAMS flow is on): the stream
		// definitions live in tmp/ as well, lost at every reboot.
		$this->rFixture->stream(10, ReplicaFixture::streamData(10, 5), 4);
		$this->rFixture->streamsSince(4);
		[$rCode, $rOut, $rConnects] = $this->child(['cluster:apply', '--from-disk']);
		$this->assertSame(0, $rCode, $rOut);
		$this->assertSame([], $rConnects, 'no connection to MAIN\'s database, at boot or after');
		$rReport = json_decode($rOut, true);
		$this->assertSame(['verified' => ['blocklist', 'settings', 'servers', 'node', 'crontab', 'secrets', 'streams'], 'unverified' => []], $rReport['from_disk']);
		foreach (['secrets', 'settings', 'servers', 'crontab', 'streams'] as $rPart) {
			$this->assertSame('applied', $rReport[$rPart]['mode'], $rPart);
		}
		$this->assertSame('applied', $rReport['mode'], 'the blocklist');
		$this->assertSame('["http://src.example/10"]', $this->cache('replica_streams/10')['stream']['stream_source']);
		$this->assertSame([10], array_keys($this->cache('replica_streams/index')['streams']));

		$rSettings = $this->cache('settings');
		$this->assertSame(['stream-pass', 'UTC'], [$rSettings['live_streaming_pass'], $rSettings['default_timezone']]);
		$this->assertSame([1, 5], array_keys($this->cache('servers')));
		$this->assertStringContainsString('password=stream-pass', $this->cache('servers')[5]['api_url']);
		$this->assertSame(['203.0.113.1'], $this->cache('blocked_ips'));
		$this->assertSame('extra-from-main', trim((string) file_get_contents($this->rHome . 'config/openssl_extra')));
		$this->assertSame(['settings', 'servers', 'crontab', 'streams'], array_keys($this->cache('replica_owned')));
		$this->assertFileDoesNotExist($this->rHome . 'crontab.log', 'the replica did not own the crontab at boot: left as it is, and MAIN\'s table not read');
	}

	public function testAModeTwoNodesCronCacheMakesNoConnectOnceTheReplicaHoldsEverything(): void {
		$this->flows(2);
		$this->rFixture->node();
		// Every section cron:cache's caches come from: a proxy and a whitelist
		// among the servers, the catalogue, and a stream.
		$this->rFixture->whole('servers', ['servers' => [
			ReplicaFixture::server(1, 1, '192.0.2.1'), ReplicaFixture::server(5, 0, '192.0.2.5', ['whitelist_ips' => '["198.51.100.50"]']),
			ReplicaFixture::server(7, 0, '192.0.2.7', ['server_type' => 1, 'private_ip' => '10.0.0.7']),
		], 'nodes' => [['sid' => 5, 'gen' => 1, 'state' => 'active', 'ed_pub' => base64_encode(random_bytes(32))]]]);
		$this->rFixture->catalog();
		$this->rFixture->stream(10, ReplicaFixture::streamData(10, 5), 4);
		$this->rFixture->streamsSince(4);
		[$rCode, $rOut] = $this->child(['cluster:apply', '--from-disk']);
		$this->assertSame(0, $rCode, $rOut);

		// cron:cache's work as console.php runs it (as whoever runs the suite: it wants xc_vm).
		$rScript = $this->rHome . 'cron_cache.php';
		file_put_contents($rScript, <<<'PHP'
			<?php
			use XcVm\Cli\CronJobs\CacheCronJob;
			use XcVm\Core\Cluster\ReplicaBoot;
			use XcVm\Core\Enum\BootContext;

			require getenv('XCVM_TEST_SRC') . 'bootstrap.php';
			XC_Bootstrap::boot(BootContext::Cli, ['replica' => ReplicaBoot::forArgv(['console.php', 'cron:cache'])]);
			(new ReflectionMethod(CacheCronJob::class, 'loadCron'))->invoke(new CacheCronJob(), false);
			echo json_encode(['done' => true, 'replica' => ReplicaBoot::active()]);
			PHP);
		foreach ([1, 2] as $rRun) {
			[$rCode, $rOut, $rConnects] = $this->child([], $rScript);
			$this->assertSame(0, $rCode, $rOut);
			$this->assertSame(['done' => true, 'replica' => true], json_decode($rOut, true), 'run ' . $rRun . ': ' . $rOut);
			$this->assertSame([], $rConnects);
			$this->assertSame([], glob($this->rHome . 'storage/cluster/sql_audit/*.json') ?: [], 'run ' . $rRun . ': not one connect, not even a refused one');
		}
		$this->assertSame([1 => ['id' => 1, 'bouquet_name' => 'Sports', 'bouquet_order' => 1, 'streams' => [10], 'series' => [], 'channels' => [10], 'movies' => [], 'radios' => []]], $this->cache('bouquets'));
		$this->assertSame([4 => ['id' => 4, 'category_type' => 'live', 'category_name' => 'News', 'parent_id' => 0, 'cat_order' => 1, 'is_adult' => 0]], $this->cache('categories'));
		$this->assertSame(['192.0.2.7', '10.0.0.7'], array_keys($this->cache('proxy_servers')), 'the proxies, from the replica\'s servers');
		$rAllowed = $this->cache('allowed_ips');
		foreach (['127.0.0.1', '192.0.2.1', '192.0.2.5', '198.51.100.50', '10.0.0.7'] as $rIP) {
			$this->assertContains($rIP, $rAllowed, 'the allowed IPs, from the replica\'s servers');
		}
		$this->assertSame([1, 5, 7], array_keys($this->cache('servers')));
		$this->assertSame(['203.0.113.1'], $this->cache('blocked_ips'));
		$this->assertSame('["http://src.example/10"]', $this->cache('replica_streams/10')['stream']['stream_source']);
		$this->assertSame(['settings', 'servers', 'crontab', 'bouquets', 'categories', 'streams'], array_keys($this->cache('replica_owned')));
	}

	public function testTheAgentsApplyNeedsNoDatabaseOnceConfigIsOn(): void {
		$this->flows(1, 63);
		$this->rFixture->node();
		[$rCode, $rOut, $rConnects] = $this->child(['cluster:apply']);
		$this->assertSame(0, $rCode, $rOut);
		$this->assertSame([], $rConnects);
		$this->assertArrayNotHasKey('from_disk', json_decode($rOut, true), 'the .json files the agent verified');
		$this->assertSame('stream-pass', $this->cache('settings')['live_streaming_pass']);
	}

	/**
	 * The connects the child's audit counted, over its day files: [sql, sites].
	 *
	 * @return array{0: int, 1: list<string>}
	 */
	private function audited(): array {
		$rSql = 0;
		$rSites = [];
		foreach (glob($this->rHome . 'storage/cluster/sql_audit/*.json') ?: [] as $rFile) {
			$rDay = json_decode((string) file_get_contents($rFile), true);
			$rSql += (int) ($rDay['sql'] ?? 0);
			$rSites = array_merge($rSites, array_keys($rDay['sites'] ?? []));
		}
		return [$rSql, $rSites];
	}

	/** Start the child's connect audit over: its counts, its log and when it began. */
	private function clearAudit(): void {
		exec('rm -rf ' . escapeshellarg($this->rHome . 'storage/cluster/sql_audit'));
	}

	/**
	 * MAIN's database, a schema on the test server: a `crontab` table holding $rJobs, its
	 * settings row (`MAIN-DB`) and one server (7), neither what the replica has.
	 *
	 * @param array<string, string> $rJobs
	 */
	private function mainDb(array $rJobs): string {
		$this->rMainDb = new \TestDb();
		$rPdo = $this->rMainDb->pdo;
		$rPdo->exec('CREATE TABLE `crontab` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `filename` TEXT, `time` TEXT, `enabled` INTEGER)');
		foreach ($rJobs as $rName => $rTime) {
			$rPdo->prepare('INSERT INTO `crontab` (`filename`, `time`, `enabled`) VALUES (?, ?, 1)')->execute([$rName, $rTime]);
		}
		$rPdo->exec("CREATE TABLE `settings` (`server_name` TEXT, `live_streaming_pass` TEXT, `default_timezone` TEXT); INSERT INTO `settings` VALUES ('MAIN-DB', 'main-pass', 'UTC')");
		$rPdo->exec('CREATE TABLE `servers` (`id` INTEGER, `server_type` INTEGER, `is_main` INTEGER, `enabled` INTEGER, `status` INTEGER, `last_check_ago` INTEGER, `server_ip` TEXT, `private_ip` TEXT, `domain_name` TEXT, `enable_https` INTEGER, `http_broadcast_port` INTEGER, `https_broadcast_port` INTEGER, `rtmp_port` INTEGER, `geoip_countries` TEXT, `isp_names` TEXT, `parent_id` TEXT, `watchdog_data` TEXT)');
		$rPdo->exec("INSERT INTO `servers` VALUES (7, 0, 0, 1, 1, 0, '192.0.2.7', NULL, '', 0, 80, 443, 8880, '[]', '[]', NULL, NULL)");
		return $this->rMainDb->schema();
	}

	public function testAModeOneNodeBootsFromItsReplicaOnceAnApplyBuiltIt(): void {
		$this->flows(1, 63);
		$this->rFixture->node();
		// Rebooted, nothing applied yet: through MAIN's database, as before, and counted.
		[, $rOut, $rConnects] = $this->child(['--list']);
		$this->assertSame(['sql'], $rConnects);
		$this->assertStringContainsString('Cannot connect to database', $rOut, 'as MAIN\'s database being down always did');
		[$rSql, $rSites] = $this->audited();
		$this->assertSame(1, $rSql);
		$this->assertCount(1, array_filter($rSites, static fn(string $rSite): bool => str_starts_with($rSite, 'sql ') && str_contains($rSite, 'Core/Bootstrap/Stage/DatabaseStage.php:')), 'the boot\'s site');
		[, , $rConnects] = $this->child(['webapi'], $this->dumpScript());
		$this->assertSame(['sql'], $rConnects, 'the web API too');

		[$rCode, $rOut] = $this->child(['cluster:apply', '--from-disk']);
		$this->assertSame(0, $rCode, $rOut);
		$this->clearAudit();
		[$rCode, $rOut, $rConnects] = $this->child(['--list']);
		$this->assertSame(0, $rCode, $rOut);
		$this->assertSame([], $rConnects, 'the boot opens no connection');
		$this->assertStringContainsString('cluster:apply', $rOut);
		$this->assertStringContainsString('console.php cron:cleanup # XC_VM', (string) @file_get_contents($this->rHome . 'crontab.log'), 'the crontab from the replica\'s jobs');

		foreach ([false, true] as $rWebApi) {
			$rDump = $this->dump($rWebApi);
			$this->assertSame([], $rDump['connects'], $rWebApi ? 'web API' : 'CLI');
			$this->assertTrue($rDump['replica']);
			$this->assertSame(['XcVm\\Core\\Database\\LazyDatabaseHandler', false, true], [$rDump['db'], $rDump['opened'], $rDump['factory']]);
			$this->assertSame(['stream-pass', '20', 'UTC'], [$rDump['live_streaming_pass'], $rDump['on_demand_wait_time'], $rDump['timezone']]);
			$this->assertSame([[1, 5], [1, 5]], [$rDump['servers'], $rDump['core_servers']]);
		}
		$this->assertSame([0, []], $this->audited(), 'nothing counted');
		$rAudit = json_decode((string) @file_get_contents($this->rHome . 'config/cluster/audit.json'), true);
		$this->assertSame([0, 0, []], [$rAudit['sql_connects'] ?? null, $rAudit['redis_connects'] ?? null, $rAudit['sites'] ?? null], 'the report the agent sends: a zero');

		// CONFIG off: through MAIN's database again, even before an apply hands the caches back.
		$this->flows(1, 31);
		[, , $rConnects] = $this->child(['--list']);
		$this->assertSame(['sql'], $rConnects);
	}

	public function testWhatAModeOneProcessStillNeedsFromMainConnectsLazilyAndIsCounted(): void {
		$this->flows(1, 63);
		$this->rFixture->node();
		$this->child(['cluster:apply', '--from-disk']);
		$this->clearAudit();
		$rScript = $this->rHome . 'query.php';
		file_put_contents($rScript, <<<'PHP'
			<?php
			use XcVm\Core\Cluster\ReplicaBoot;
			use XcVm\Core\Enum\BootContext;

			require getenv('XCVM_TEST_SRC') . 'bootstrap.php';
			XC_Bootstrap::boot(BootContext::Cli, ['replica' => ReplicaBoot::forArgv(['console.php', 'cron:servers'])]);
			echo json_encode(['booted' => ReplicaBoot::active(), 'opened' => $GLOBALS['db']->isOpen()]), "\n";
			$rOk = $GLOBALS['db']->query('SELECT 1 AS `one`'); // line 8: MAIN's database, first used
			echo json_encode(['query' => $rOk, 'row' => $GLOBALS['db']->get_row()]), "\n";
			PHP);
		[$rCode, $rOut, $rConnects] = $this->child([], $rScript, $this->mainDb([]));
		$this->assertSame(0, $rCode, $rOut);
		$rLines = array_map(static fn(string $rLine): mixed => json_decode($rLine, true), explode("\n", trim($rOut)));
		$this->assertSame(['booted' => true, 'opened' => false], $rLines[0], 'booted from the replica, nothing opened');
		$this->assertSame(['query' => true, 'row' => ['one' => '1']], $rLines[1] ?? null, 'connected on first use, not refused');
		$this->assertSame(['sql'], $rConnects);
		$this->assertSame([1, ['sql query.php:8']], $this->audited(), 'counted, at the query\'s own site');
	}

	public function testAModeOneProcessReadsItsSettingsAndServersFromMainOnceTheReplicaHandsThemBack(): void {
		$rScript = $this->rHome . 'handback.php';
		file_put_contents($rScript, <<<'PHP'
			<?php
			use XcVm\Core\Cluster\ReplicaBoot;
			use XcVm\Core\Config\SettingsRepository;
			use XcVm\Core\Enum\BootContext;
			use XcVm\Domain\Server\ServerRepository;

			require getenv('XCVM_TEST_SRC') . 'bootstrap.php';
			XC_Bootstrap::boot(BootContext::Cli, ['replica' => ReplicaBoot::forArgv(['console.php', 'watchdog'])]);
			$rBefore = [SettingsRepository::getAll(true)['server_name'] ?? null, array_keys(ServerRepository::getAll(true)), $GLOBALS['db']->isOpen()];
			// A daemon's next pass, after an apply handed the caches back (CONFIG off, a refused section).
			unlink(CACHE_TMP_PATH . 'replica_owned');
			echo json_encode(['before' => $rBefore, 'settings' => SettingsRepository::getAll(true)['server_name'] ?? null, 'servers' => array_keys(ServerRepository::getAll(true))]);
			PHP);
		$this->rFixture->node();
		$rMainDb = $this->mainDb([]);
		foreach ([1 => [['Panel', [1, 5], false], 'MAIN-DB', [7], ['sql']], 2 => [['Panel', [1, 5], false], 'Panel', [1, 5], []]] as $rMode => [$rBefore, $rSettings, $rServers, $rConnects]) {
			$this->flows($rMode, 63);
			$this->child(['cluster:apply', '--from-disk']);
			[$rCode, $rOut, $rGot] = $this->child([], $rScript, $rMainDb);
			$this->assertSame(0, $rCode, $rOut);
			$this->assertSame(['before' => $rBefore, 'settings' => $rSettings, 'servers' => $rServers], json_decode($rOut, true), 'mode ' . $rMode . ($rMode === 1 ? ': MAIN\'s database again, as before' : ': the caches however old'));
			$this->assertSame($rConnects, $rGot, 'mode ' . $rMode);
		}
	}

	public function testAModeOneBootReadsMainsCrontabLazilyWhileTheReplicaDoesNotOwnIt(): void {
		$this->flows(1, 63);
		$this->rFixture->node();
		// The agent stored no crontab section: the replica owns the settings and servers only.
		unlink($this->rFixture->dir() . 'crontab.json');
		unlink($this->rFixture->dir() . 'crontab.rep');
		[$rCode, $rOut, $rConnects] = $this->child(['cluster:apply', '--from-disk'], null, $this->mainDb(['servers' => '* * * * *']));
		$this->assertSame(0, $rCode, $rOut);
		$this->assertSame([], $rConnects, 'cluster:apply never reads the table');
		$this->assertSame(['settings', 'servers'], array_keys($this->cache('replica_owned')));
		$this->clearAudit();

		[$rCode, $rOut, $rConnects] = $this->child(['--list'], null, $this->rMainDb->schema());
		$this->assertSame(0, $rCode, $rOut);
		$this->assertSame(['sql'], $rConnects, 'MAIN\'s crontab table, on first use');
		$this->assertStringContainsString('console.php cron:servers # XC_VM', (string) @file_get_contents($this->rHome . 'crontab.log'));
		[$rSql, $rSites] = $this->audited();
		$this->assertSame(1, $rSql);
		$this->assertCount(1, array_filter($rSites, static fn(string $rSite): bool => str_starts_with($rSite, 'sql ') && str_contains($rSite, 'Core/Cluster/ReplicaApply.php:')), 'counted at its site');

		// Mode 2 leaves the crontab as it is, and reads nothing.
		@unlink($this->rHome . 'tmp/crontab');
		@unlink($this->rHome . 'crontab.log');
		$this->flows(2);
		[$rCode, $rOut, $rConnects] = $this->child(['--list'], null, $this->rMainDb->schema());
		$this->assertSame(0, $rCode, $rOut);
		$this->assertSame([], $rConnects);
		$this->assertFileDoesNotExist($this->rHome . 'crontab.log');
	}

	public function testTheStreamingEntryPointsTakeALazyHandleOnceTheNodeBootsFromItsReplica(): void {
		if (!extension_loaded('igbinary')) {
			$this->markTestSkipped('the streaming boot reads the caches with igbinary');
		}
		$rScript = $this->rHome . 'stream.php';
		file_put_contents($rScript, <<<'PHP'
			<?php
			use XcVm\Core\Config\ConstantsInitializer;
			use XcVm\Core\Init\LegacyInitializer;

			require getenv('XCVM_TEST_SRC') . 'vendor/autoload.php';
			ConstantsInitializer::init();
			$GLOBALS['rSettings'] = null; // StreamingBootstrap's, from the settings cache
			LegacyInitializer::initStreaming();
			echo json_encode(['db' => get_class($GLOBALS['db']), 'opened' => method_exists($GLOBALS['db'], 'isOpen') ? $GLOBALS['db']->isOpen() : null, 'servers' => array_keys($GLOBALS['rServers'])]), "\n";
			$GLOBALS['db']->query('SELECT 1'); // line 10: a query the request needs
			echo "queried\n";
			PHP);
		$this->rFixture->node();
		$this->flows(1, 63);
		$this->child(['cluster:apply', '--from-disk']);

		// Mode 0, MAIN, mode 1 without CONFIG: connected at once, as before.
		// The eager connect ends the child before the script's dump line, which
		// a lazy handle would print before its query connects.
		foreach ([[0, 255], [null], [1, 31]] as $rCase) {
			$this->flows(...$rCase);
			$this->clearAudit();
			[, $rOut, $rConnects] = $this->child([], $rScript);
			$this->assertSame(['sql'], $rConnects, json_encode($rCase));
			$this->assertStringContainsString('Cannot connect to database', $rOut, json_encode($rCase));
			$this->assertStringNotContainsString('LazyDatabaseHandler', $rOut, json_encode($rCase) . ': connected at once, before the request');
			[$rSql, $rSites] = $this->audited();
			$this->assertSame($rCase === [1, 31] ? 1 : 0, $rSql, json_encode($rCase));
			if ($rCase === [1, 31]) {
				$this->assertStringContainsString('Core/Init/LegacyInitializer.php:', $rSites[0] ?? '', 'counted at the boot\'s site');
			}
		}

		// Mode 1 with CONFIG, once an apply built the caches: nothing at the
		// boot, the request's own query at its site.
		$this->flows(1, 63);
		$this->clearAudit();
		[$rCode, $rOut, $rConnects] = $this->child([], $rScript, $this->mainDb([]));
		$this->assertSame(0, $rCode, $rOut);
		$this->assertSame(['db' => 'XcVm\\Core\\Database\\LazyDatabaseHandler', 'opened' => false, 'servers' => [1, 5]], json_decode(strtok($rOut, "\n"), true));
		$this->assertStringContainsString('queried', $rOut);
		$this->assertSame(['sql'], $rConnects);
		$this->assertSame([1, ['sql stream.php:10']], $this->audited());

		// Mode 2: the boot opens nothing either, and the query is refused.
		$this->flows(2);
		$this->clearAudit();
		[, $rOut, $rConnects] = $this->child([], $rScript, $this->rMainDb->schema());
		$this->assertSame([], $rConnects);
		$this->assertStringContainsString('"opened":false', $rOut);
		$this->assertStringNotContainsString('queried', $rOut, 'refused');
		$this->assertSame([1, ['sql stream.php:10']], $this->audited());

		// Mode 1 rebooted, nothing applied yet: connected at once, as before.
		$this->flows(1, 63);
		unlink($this->rHome . 'tmp/cache/replica_owned');
		$this->clearAudit();
		[, $rOut, $rConnects] = $this->child([], $rScript);
		$this->assertSame(['sql'], $rConnects);
		$this->assertStringNotContainsString('LazyDatabaseHandler', $rOut, 'connected at once');
		[$rSql, $rSites] = $this->audited();
		$this->assertSame(1, $rSql);
		$this->assertStringContainsString('Core/Init/LegacyInitializer.php:', $rSites[0] ?? '', 'counted at the boot\'s site');
	}

	public function testStatusAsksMainsDatabaseItselfOnANodeBootedFromItsReplica(): void {
		if (!extension_loaded('igbinary')) {
			$this->markTestSkipped('status\'s streaming boot reads the caches with igbinary');
		}
		// `console.php status` runs as root, so the child runs its steps up to
		// its database check: console.php's boot, then status's own.
		$rScript = $this->rHome . 'status.php';
		file_put_contents($rScript, <<<'PHP'
			<?php
			use XcVm\Cli\Commands\StatusCommand;
			use XcVm\Core\Cluster\LbDatabaseAccessException;
			use XcVm\Core\Cluster\ReplicaBoot;
			use XcVm\Core\Enum\BootContext;
			use XcVm\Infrastructure\Bootstrap\StreamingRequestBootstrap;

			require getenv('XCVM_TEST_SRC') . 'bootstrap.php';
			XC_Bootstrap::boot(BootContext::Cli, ['replica' => ReplicaBoot::forArgv(['console.php', 'status', '1'])]);
			StreamingRequestBootstrap::init('status');
			echo json_encode(['booted' => ReplicaBoot::active(), 'connected' => $GLOBALS['db']->connected]), "\n";
			try {
				echo json_encode(['answers' => StatusCommand::mainDatabaseAnswers()]), "\n";
			} catch (LbDatabaseAccessException $e) {
				echo json_encode(['refused' => $e->rSite]), "\n";
			}
			PHP);
		$this->rFixture->node();
		$rMainDb = $this->mainDb([]);
		$rStatusSite = static fn(array $rSites): bool => count($rSites) === 1 && str_starts_with($rSites[0], 'sql ') && str_contains($rSites[0], 'Cli/Commands/StatusCommand.php:');

		// A mode 1 node booted from its replica: an unopened lazy handle, whose
		// `connected` status read as "Couldn't connect" and stopped. It asks
		// the database itself now: connected on first use, counted at its site.
		$this->flows(1, 63);
		$this->child(['cluster:apply', '--from-disk']);
		$this->clearAudit();
		[$rCode, $rOut, $rConnects] = $this->child([], $rScript, $rMainDb);
		$this->assertSame(0, $rCode, $rOut);
		$this->assertSame([['booted' => true, 'connected' => false], ['answers' => true]], array_map(static fn(string $rLine): mixed => json_decode($rLine, true), explode("\n", trim($rOut))));
		$this->assertSame(['sql'], $rConnects);
		[$rSql, $rSites] = $this->audited();
		$this->assertSame(1, $rSql);
		$this->assertTrue($rStatusSite($rSites), 'counted at status\'s site: ' . json_encode($rSites));

		// Mode 2: refused there, counted, where status used to stop uncounted.
		$this->flows(2);
		$this->clearAudit();
		[$rCode, $rOut, $rConnects] = $this->child([], $rScript, $rMainDb);
		$this->assertSame(0, $rCode, $rOut);
		$this->assertSame(['booted' => true, 'connected' => false], json_decode(strtok($rOut, "\n"), true));
		$this->assertStringContainsString('Cli/Commands/StatusCommand.php:', json_decode(explode("\n", trim($rOut))[1] ?? '', true)['refused'] ?? '', $rOut);
		$this->assertSame([], $rConnects);
		[$rSql, $rSites] = $this->audited();
		$this->assertSame(1, $rSql);
		$this->assertTrue($rStatusSite($rSites), json_encode($rSites));
	}

	public function testAModeTwoNodeBootsFromItsReplicaOnceAnApplyBuiltIt(): void {
		$this->flows(2);
		$this->rFixture->node();
		// Rebooted, nothing applied yet: nothing to boot from, and MAIN's database
		// is refused (DbConnectRefusalTest), the CLI and the web API alike.
		[, $rOut, $rConnects] = $this->child(['--list']);
		$this->assertSame([], $rConnects, 'nothing to boot from yet: the boot fails closed');
		$this->assertStringNotContainsString('cluster:apply', $rOut, 'the process ends at its boot');
		[, $rOut, $rConnects] = $this->child(['webapi'], $this->dumpScript());
		$this->assertSame([[], null], [$rConnects, json_decode($rOut, true)], 'nor for the web API');

		[$rCode, $rOut] = $this->child(['cluster:apply', '--from-disk']);
		$this->assertSame(0, $rCode, $rOut);
		[$rCode, $rOut, $rConnects] = $this->child(['--list']);
		$this->assertSame(0, $rCode, $rOut);
		$this->assertSame([], $rConnects);
		$this->assertStringContainsString('cluster:apply', $rOut);
		// That boot wrote the xc_vm crontab from the replica's jobs, without MAIN's table.
		$this->assertStringContainsString('console.php cron:cleanup # XC_VM', (string) @file_get_contents($this->rHome . 'crontab.log'));

		file_put_contents($this->rHome . 'tmp/cache/bouquets', function_exists('igbinary_serialize') ? igbinary_serialize([3 => ['id' => 3]]) : serialize([3 => ['id' => 3]]));
		foreach ([false, true] as $rWebApi) {
			$rDump = $this->dump($rWebApi);
			$this->assertSame([], $rDump['connects'], 'the boot opens no connection');
			$this->assertTrue($rDump['replica']);
			$this->assertSame(['XcVm\\Core\\Database\\LazyDatabaseHandler', false, true], [$rDump['db'], $rDump['opened'], $rDump['factory']]);
			$this->assertSame(5, $rDump['server_id']);
			$this->assertSame([true, true], [$rDump['settings_global'], $rDump['core_settings']]);
			$this->assertSame(['stream-pass', '20', 'UTC'], [$rDump['live_streaming_pass'], $rDump['on_demand_wait_time'], $rDump['timezone']]);
			$this->assertSame([[1, 5], [1, 5]], [$rDump['servers'], $rDump['core_servers']]);
			$this->assertStringContainsString('password=stream-pass', (string) $rDump['api_url']);
			$this->assertSame([[3 => ['id' => 3]], []], [$rDump['core_bouquets'], $rDump['core_categories']], 'the caches as they are');
			$this->assertSame(['server_id' => 5], $rDump['core_config']);
			$this->assertTrue($rDump['ffmpeg'] && $rDump['request']);
			if (!$rWebApi) {
				$this->assertSame([true, true, [3 => ['id' => 3]]], [$rDump['container_db'], $rDump['container_settings'], $rDump['container_bouquets']]);
			}
			// The boot itself read only allowlisted settings; the process's one miss
			// reached the day file at its exit, and the audit.json the agent sends.
			$rDays = 0;
			foreach (glob($this->rHome . 'storage/cluster/settings_misses/*.json') ?: [] as $rFile) {
				$rDays += json_decode((string) file_get_contents($rFile), true)['zz_not_carried'] ?? 0;
			}
			$this->assertSame($rWebApi ? 2 : 1, $rDays);
			$this->assertSame(['zz_not_carried'], array_keys(json_decode((string) @file_get_contents($this->rHome . 'config/cluster/audit.json'), true)['settings_misses'] ?? []));
		}
	}
}
