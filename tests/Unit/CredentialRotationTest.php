<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ClusterRotateCredentialsCommand;
use XcVm\Core\Cluster\NodeActions;
use XcVm\Core\Cluster\RootCredentials;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterBus;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Domain\Cluster\ClusterRoute;
use XcVm\Domain\Cluster\CommandBus;
use XcVm\Domain\Cluster\CredentialRotation;
use XcVm\Domain\Cluster\DbPassword;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * Redis and DB password rotation for the nodes that still use them (plan,
 * section 10, step 3): MAIN rotates, and pushes the new password sealed to
 * each node's box key in a `node.root` command, or, for Redis only, to a
 * legacy node without root commands as a `signals` row it answers from
 * MAIN's settings. The DB password is `cluster:rotate-db-password`'s
 * (DbPassword); here its sealed command goes through the real bus.
 */
final class CredentialRotationTest extends TestCase {
	private const ROOT_NODE = 21;

	private const LEGACY_NODE = 22;

	private const API_NODE = 23;

	private TestDb $rDb;

	private FakeClusterCrypto $rCrypto;

	private string $rDir;

	/** @var list<list<string>> */
	private array $rRedis = [];

	/** @var list<array{0: string, 1: int, 2: string}> */
	private array $rMainConfig = [];

	private string $rBoxSk;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['029_create_cluster_nodes', '030_create_cluster_commands'] as $rName) {
			$rSql = (string) file_get_contents(MAIN_HOME . 'migrations/database/up/' . $rName . '.sql');
			foreach (array_filter(array_map('trim', explode(';', (string) $rSql))) as $rStatement) {
				$this->rDb->exec($rStatement);
			}
		}
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `root_ready` tinyint(1) NOT NULL DEFAULT 0');
		$this->rDb->exec("CREATE TABLE `settings` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `redis_password` varchar(512))");
		$this->rDb->exec("INSERT INTO `settings` VALUES (1, 'old-redis-password')");
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `is_main` tinyint, `server_type` tinyint, `server_ip` varchar(64), `private_ip` varchar(64))');
		$this->rDb->exec("INSERT INTO `servers` VALUES (1, 1, 0, '203.0.113.1', '10.0.0.1'), (21, 0, 0, '203.0.113.21', NULL), (22, 0, 0, '203.0.113.22', NULL), (23, 0, 0, '203.0.113.23', NULL), (30, 0, 1, '203.0.113.30', NULL)");
		$this->rDb->exec('ALTER TABLE `servers` ADD COLUMN `server_name` varchar(255)');
		$this->rDb->exec('CREATE TABLE `signals` (`signal_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `server_id` int, `time` int, `custom_data` text, `cache` tinyint DEFAULT 0)');
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix(1800000000000);
		ClusterBus::useSocket(sys_get_temp_dir() . '/no-such-bus-' . bin2hex(random_bytes(4)) . '/cluster.sock');
		SettingsManager::set(['cluster_api_enabled' => 1]);
		$this->rCrypto = new FakeClusterCrypto();
		ClusterRoute::useCrypto(fn() => $this->rCrypto);

		$this->rBoxSk = random_bytes(32);
		NodeRegistry::startEnrolment(self::ROOT_NODE, '0f8fad5b-d9cb-469f-a165-70867728950e', random_bytes(32), sodium_crypto_scalarmult_base($this->rBoxSk), 1);
		NodeRegistry::update(self::ROOT_NODE, ['state' => 'active', 'mode' => 1, 'flows' => NodeRegistry::FLOW_COMMANDS, 'root_ready' => 1]);
		NodeRegistry::startEnrolment(self::API_NODE, '1f8fad5b-d9cb-469f-a165-70867728950e', random_bytes(32), random_bytes(32), 2);
		NodeRegistry::update(self::API_NODE, ['state' => 'active', 'mode' => 2, 'flows' => NodeRegistry::FLOW_COMMANDS, 'root_ready' => 1]);

		$this->rDir = sys_get_temp_dir() . '/xcvm-cred-' . bin2hex(random_bytes(4));
		mkdir($this->rDir);
		file_put_contents($this->rDir . '/redis.conf', "bind *\nport 6379\nrequirepass old-redis-password\n");
	}

	protected function tearDown(): void {
		ClusterRoute::useCrypto(null);
		ClusterBus::useSocket(null);
		ClusterClock::fix(null);
		RootCredentials::useSeams(null);
		DbPassword::useExtension(null);
		SettingsManager::set([]);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function rotation(bool $rRedisOk = true, bool $rConfigOk = true): CredentialRotation {
		return new CredentialRotation(
			function (array $rArgs) use ($rRedisOk): mixed {
				$this->rRedis[] = $rArgs;
				return $rRedisOk ? 'OK' : false;
			},
			function (string $rHost, int $rPort, string $rAuth) use ($rConfigOk): bool {
				$this->rMainConfig[] = [$rHost, $rPort, $rAuth];
				return $rConfigOk;
			},
			$this->rDir . '/redis.conf'
		);
	}

	/** @return array<string, mixed> */
	private function rootCommand(int $rServerID): array {
		$rRows = CommandBus::pending($rServerID, 0);
		$this->assertCount(1, $rRows);
		return json_decode($rRows[0]['doc'], true);
	}

	public function testTheTargetsAreTheLoadBalancersBelowModeTwo(): void {
		$this->assertSame([self::ROOT_NODE, self::LEGACY_NODE], array_keys(CredentialRotation::targets()), 'MAIN, a proxy and a mode-2 node use neither');
	}

	/**
	 * Both passwords open Redis while the nodes move; MAIN switches at once; a
	 * root node gets the new one sealed to its box key, a legacy node a signals
	 * row with no secret at all.
	 */
	public function testARedisRotationAddsThenPushes(): void {
		$rJob = $this->rotation()->redis('new-redis-password');
		$this->assertSame(['ACL', 'SETUSER', 'default', 'on', '>new-redis-password'], $this->rRedis[0]);
		$this->assertSame([['127.0.0.1', 6379, 'new-redis-password']], $this->rMainConfig);
		$this->rDb->query('SELECT `redis_password` FROM `settings`');
		$this->assertSame('new-redis-password', $this->rDb->get_row()['redis_password']);
		$this->assertStringContainsString("\nrequirepass new-redis-password\n", (string) file_get_contents($this->rDir . '/redis.conf'));
		$this->assertSame([self::ROOT_NODE => ['via' => 'command'], self::LEGACY_NODE => ['via' => 'signal']], $rJob['nodes']);

		$rCmd = $this->rootCommand(self::ROOT_NODE);
		$this->assertSame(['node.root', 'rotate_redis', '10.0.0.1', 6379], [$rCmd['type'], $rCmd['action'], $rCmd['args']['host'], $rCmd['args']['port']]);
		$this->assertStringNotContainsString('new-redis-password', json_encode($rCmd), 'the command row holds ciphertext only');

		// The node's root side opens it with the key its agent holds.
		file_put_contents($this->rDir . '/agent.json', json_encode(['node_uuid' => '0f8fad5b-d9cb-469f-a165-70867728950e', 'node_box_sk' => base64_encode($this->rBoxSk)]));
		$rSet = [];
		RootCredentials::useSeams(static function (string $rHost, int $rPort, string $rAuth) use (&$rSet): bool {
			$rSet[] = [$rHost, $rPort, $rAuth];
			return true;
		}, null, $this->rDir . '/agent.json', $this->rDir . '/');
		$this->assertStringContainsString('Redis credentials updated', RootCredentials::rotateRedis($rCmd['args'], null));
		$this->assertSame([['10.0.0.1', 6379, 'new-redis-password']], $rSet);

		// The legacy node's row carries no secret; its root cron reads MAIN's settings.
		$this->rDb->query('SELECT `custom_data` FROM `signals` WHERE `server_id` = ?', self::LEGACY_NODE);
		$rRow = json_decode((string) $this->rDb->get_row()['custom_data'], true);
		$this->assertSame(['action' => 'rotate_redis', 'host' => '10.0.0.1', 'port' => 6379], $rRow);
		$rSet = [];
		RootCredentials::rotateRedis($rRow, $this->rDb);
		$this->assertSame([['10.0.0.1', 6379, 'new-redis-password']], $rSet);
	}

	public function testASealForAnotherNodeDoesNotOpen(): void {
		$this->rotation()->redis('new-redis-password');
		$rCmd = $this->rootCommand(self::ROOT_NODE);
		file_put_contents($this->rDir . '/agent.json', json_encode(['node_uuid' => '0f8fad5b-d9cb-469f-a165-70867728950e', 'node_box_sk' => base64_encode(random_bytes(32))]));
		RootCredentials::useSeams(static fn(): bool => true, null, $this->rDir . '/agent.json', $this->rDir . '/');
		$this->expectExceptionMessage('does not open');
		RootCredentials::rotateRedis($rCmd['args'], null);
	}

	/** MAIN's config.enc must move first: without it the new password is withdrawn and nothing else changes. */
	public function testAFailedMainConfigWithdrawsTheNewPassword(): void {
		try {
			$this->rotation(true, false)->redis('new-redis-password');
			$this->fail('rotated without MAIN\'s config.enc');
		} catch (\RuntimeException) {
		}
		$this->assertSame(['ACL', 'SETUSER', 'default', '<new-redis-password'], $this->rRedis[1]);
		$this->rDb->query('SELECT `redis_password` FROM `settings`');
		$this->assertSame('old-redis-password', $this->rDb->get_row()['redis_password']);
		$this->assertNull(ClusterMeta::get(CredentialRotation::META_REDIS));
		$this->assertSame([], CommandBus::pending(self::ROOT_NODE, 0));
	}

	/** The old password goes only once every command node acked (or --force); never kept, dropped by hash. */
	public function testFinishWaitsForTheAcks(): void {
		$rRotation = $this->rotation();
		$rRotation->redis('new-redis-password');
		$this->assertSame([self::ROOT_NODE, self::LEGACY_NODE], $rRotation->finishRedis(false), 'nothing acked; the legacy node reports nothing');
		$rCmd = $this->rootCommand(self::ROOT_NODE);
		CommandBus::ack(self::ROOT_NODE, $rCmd['cmd_id'], true, 'Redis credentials updated');
		$this->assertSame('acked', CredentialRotation::redisStatus()['nodes'][self::ROOT_NODE]['state']);
		$this->assertSame([self::LEGACY_NODE], $rRotation->finishRedis(false));
		$this->assertSame([], $rRotation->finishRedis(true));
		$this->assertSame(['ACL', 'SETUSER', 'default', '!' . hash('sha256', 'old-redis-password')], end($this->rRedis));
		$this->assertNull(ClusterMeta::get(CredentialRotation::META_REDIS));
		$this->assertStringNotContainsString('old-redis-password', (string) json_encode($this->rDb->query('SELECT * FROM `cluster_meta`') ? $this->rDb->get_rows() : []));
	}

	public function testASecondRotationWaitsForTheFirstToFinish(): void {
		$this->rotation()->redis('new-redis-password');
		$this->expectExceptionMessage('finish it first');
		$this->rotation()->redis('newer-redis-password');
	}

	/**
	 * `cluster:rotate-db-password` seals the new DB password to a root node's
	 * box key; the command row holds ciphertext only, the node opens it and
	 * hands it to config_set_db. A legacy node gets nothing (set by hand), and
	 * `cluster:rotate-credentials db` points at the one command.
	 */
	public function testTheDbPasswordTravelsSealed(): void {
		DbPassword::useExtension(static fn(string $rPass): bool => true, static fn(): ?string => null);
		$rOut = DbPassword::rotate('N3wPassw0rd-For.The~Panel');
		$this->assertTrue($rOut['ok']);
		$rHow = array_column($rOut['nodes'], 'how', 'server_id');
		$this->assertSame([self::ROOT_NODE => 'sealed', self::LEGACY_NODE => 'manual', self::API_NODE => 'mode2'], $rHow);
		$this->assertSame([self::ROOT_NODE => null, self::LEGACY_NODE => null, self::API_NODE => null], array_column($rOut['nodes'], 'result', 'server_id'));

		$rCmd = $this->rootCommand(self::ROOT_NODE);
		$this->assertSame(['node.root', 'rotate_db'], [$rCmd['type'], $rCmd['action']]);
		$this->assertStringNotContainsString('N3wPassw0rd-For.The~Panel', json_encode($rCmd), 'the command row holds ciphertext only');
		$this->assertSame([], CommandBus::pending(self::API_NODE, 0));
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `signals`');
		$this->assertSame(0, (int) $this->rDb->get_row()['n'], 'a DB password never goes out as a signals row');

		file_put_contents($this->rDir . '/agent.json', json_encode(['node_uuid' => '0f8fad5b-d9cb-469f-a165-70867728950e', 'node_box_sk' => base64_encode($this->rBoxSk)]));
		$rSet = [];
		RootCredentials::useSeams(null, static function (string $rPass) use (&$rSet): bool {
			$rSet[] = $rPass;
			return true;
		}, $this->rDir . '/agent.json', $this->rDir . '/');
		$this->assertSame('Database credentials updated', RootCredentials::rotateDb($rCmd['args']));
		$this->assertSame(['N3wPassw0rd-For.The~Panel'], $rSet);

		ob_start();
		$rCode = (new ClusterRotateCredentialsCommand($this->rotation()))->execute(['db']);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('cluster:rotate-db-password', (string) ob_get_clean());
	}

	public function testTheRootActionsAreCatalogued(): void {
		$this->assertContains('rotate_redis', NodeActions::ROOT_ACTIONS);
		$this->assertContains('rotate_db', NodeActions::ROOT_ACTIONS);
		$this->assertContains('rotate_db', NodeActions::CLUSTER_ONLY, 'a password only travels sealed');
		$this->assertNotContains('rotate_redis', NodeActions::CLUSTER_ONLY, 'a legacy node answers it from MAIN\'s settings');
		$rCron = (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/RootSignalsCronJob.php');
		$this->assertStringContainsString("case 'rotate_redis':", $rCron);
		$this->assertStringContainsString("case 'rotate_db':", $rCron);
		$this->expectExceptionMessage('only a signed root command');
		RootCredentials::rotateDb(['action' => 'rotate_db']);
	}
}
