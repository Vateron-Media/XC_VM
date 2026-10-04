<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ClusterRotateDbPasswordCommand;
use XcVm\Cli\Commands\ClusterSetDbPasswordCommand;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\DbCredentials;
use XcVm\Domain\Cluster\DbPassword;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * Phase 9's DB password rotation: MAIN rotates through xcvm_core's
 * `db_set_password`, sends the new password sealed to each node that takes
 * root commands (`node.root rotate_db`), and lists the rest, which an
 * operator updates on the node with `cluster:set-db-password`
 * (`config_set_db`). The password never rides a command in the clear. The
 * extension is a fake here; CredentialRotationTest seals and opens a real
 * command.
 */
final class DbPasswordTest extends TestCase {
	private const PASS = 'N3wPassw0rd-For.The~Panel';

	private const IID = '6c1b5bd2-2c86-4a4a-9a57-0f6e6d3b1f10';

	private TestDb $rDb;

	private array $rSettingsBefore = [];

	/** @var list<string> Passwords the fake db_set_password was given. */
	private array $rSet = [];

	private bool $rSetOk = true;

	private ?string $rLastError = null;

	/** @var list<int> Servers sent the sealed password. */
	private array $rSent = [];

	protected function setUp(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1);
		}
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `server_name` varchar(255), `server_ip` varchar(255), `server_type` int DEFAULT 0, `is_main` int DEFAULT 0)');
		$this->rDb->exec("CREATE TABLE `cluster_nodes` (`server_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `node_uuid` char(36), `state` varchar(16) NOT NULL DEFAULT 'active', `mode` int NOT NULL DEFAULT 1, `flows` int NOT NULL DEFAULT 2, `root_ready` int NOT NULL DEFAULT 1, `gen` int NOT NULL DEFAULT 1, `node_box_pub` varbinary(32) DEFAULT 'box-pub', `install_id` varchar(64) DEFAULT NULL, `db_revoked_at` int DEFAULT NULL, `updated_at` int NOT NULL DEFAULT 0)");
		$this->rDb->query("INSERT INTO `servers` (`id`, `server_name`, `server_ip`, `is_main`) VALUES (?, 'main', '10.0.0.1', 1)", (int) SERVER_ID);
		foreach ([11 => 'sealed', 12 => 'mode2', 13 => 'revoked', 14 => 'no-root', 15 => 'no-box', 16 => 'legacy'] as $rSid => $rName) {
			$this->rDb->query('INSERT INTO `servers` (`id`, `server_name`, `server_ip`) VALUES (?, ?, ?)', $rSid, $rName, '10.0.0.' . $rSid);
		}
		$this->rDb->exec("INSERT INTO `servers` (`id`, `server_name`, `server_ip`, `server_type`) VALUES (17, 'proxy', '10.0.0.17', 1)");
		$this->rDb->query("INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`, `install_id`) VALUES (11, '0f8fad5b-d9cb-469f-a165-70867728950e', ?)", self::IID);
		$this->rDb->query('INSERT INTO `cluster_nodes` (`server_id`, `mode`, `install_id`) VALUES (12, 2, ?)', self::IID);
		$this->rDb->query('INSERT INTO `cluster_nodes` (`server_id`, `mode`, `db_revoked_at`) VALUES (13, 2, 1800000000)');
		$this->rDb->query('INSERT INTO `cluster_nodes` (`server_id`, `root_ready`, `install_id`) VALUES (14, 0, ?)', self::IID);
		$this->rDb->exec("INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`, `node_box_pub`) VALUES (15, '2f8fad5b-d9cb-469f-a165-70867728950e', NULL)");
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix(1800000000000);
		$this->rSettingsBefore = SettingsManager::getAll();
		SettingsManager::set(['cluster_api_enabled' => 1]);
		DbPassword::useExtension(
			function (string $rPass): bool {
				$this->rSet[] = $rPass;
				return $this->rSetOk;
			},
			fn(): ?string => $this->rLastError,
			function (int $rSid, array $rNode, string $rPass): ?string {
				$this->rSent[] = $rSid;
				return null;
			},
		);
	}

	protected function tearDown(): void {
		DbPassword::useExtension(null);
		ClusterRotateDbPasswordCommand::useIo(null);
		ClusterSetDbPasswordCommand::useExtension(null);
		SettingsManager::set($this->rSettingsBefore);
		ClusterClock::fix(null);
		DatabaseFactory::reset();
	}

	private function events(): array {
		$this->rDb->query('SELECT `event`, `detail` FROM `cluster_audit` ORDER BY `id`');
		return array_map(static fn(array $rRow): string => $rRow['event'] . ' ' . $rRow['detail'], $this->rDb->get_rows());
	}

	private static function exec(object $rCommand, array $rArgs): array {
		ob_start();
		$rCode = $rCommand->execute($rArgs);
		return [$rCode, (string) ob_get_clean()];
	}

	public function testThePasswordRulesAreTheExtensions(): void {
		$this->assertTrue(DbPassword::valid(self::PASS));
		$this->assertTrue(DbPassword::valid(str_repeat('a', 128)));
		$this->assertFalse(DbPassword::valid(str_repeat('a', 15)), 'at least 16');
		$this->assertFalse(DbPassword::valid(str_repeat('a', 129)), 'at most 128');
		foreach (["'", '"', ' ', '\\', '#', ';', '$', '`', "\n"] as $rBad) {
			$this->assertFalse(DbPassword::valid(str_repeat('a', 20) . $rBad), json_encode($rBad));
		}
		$rOne = DbPassword::generate();
		$this->assertSame(DbPassword::GENERATED_LENGTH, strlen($rOne));
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $rOne);
		$this->assertTrue(DbPassword::valid($rOne));
		$this->assertNotSame($rOne, DbPassword::generate());
	}

	public function testThePlanSaysWhatEachLoadBalancerNeeds(): void {
		$rPlan = array_column(DbPassword::plan(), 'how', 'server_id');
		$this->assertSame([11 => 'sealed', 12 => 'mode2', 13 => 'revoked', 14 => 'manual', 15 => 'manual', 16 => 'manual'], $rPlan, 'MAIN and the proxy are not in it');
		$rWhy = array_column(DbPassword::plan(), 'why', 'server_id');
		$this->assertStringContainsString('root commands', (string) $rWhy[14]);
		$this->assertStringContainsString('box key', (string) $rWhy[15]);
		$this->assertStringContainsString('not enrolled', (string) $rWhy[16]);

		SettingsManager::set(['cluster_api_enabled' => 0]);
		$this->assertSame(['manual'], array_values(array_unique(array_column(DbPassword::plan(), 'how'))), 'no cluster API: every node by hand');
	}

	public function testARotationSendsThePasswordOnlyWhereItCan(): void {
		$this->rLastError = null;
		$rOut = DbPassword::rotate(self::PASS, 'cli');
		$this->assertTrue($rOut['ok']);
		$this->assertFalse($rOut['partial']);
		$this->assertSame([self::PASS], $this->rSet);
		$this->assertSame([11], $this->rSent);
		$this->assertCount(6, $rOut['nodes']);
		$rEvents = $this->events();
		$this->assertCount(1, $rEvents);
		$this->assertStringStartsWith('db.password_rotated {"partial":false,"sealed":1,"manual":3,"not_queued":0}', $rEvents[0]);
		$this->assertStringNotContainsString(self::PASS, implode("\n", $rEvents), 'never audited');
	}

	public function testAPartialRotationIsReported(): void {
		$this->rLastError = 'PARTIAL';
		$this->assertTrue(DbPassword::rotate(self::PASS)['partial']);
	}

	public function testARefusalChangesNothing(): void {
		$this->rSetOk = false;
		$this->rLastError = 'DB';
		$this->assertSame(['ok' => false, 'why' => 'DB', 'partial' => false, 'nodes' => []], DbPassword::rotate(self::PASS));
		$this->assertSame([], $this->rSent);
		$this->assertSame(['db.password_rotate_failed {"why":"DB"}'], $this->events());

		$this->rSet = [];
		$this->assertSame('password', DbPassword::rotate('short')['why']);
		$this->assertSame([], $this->rSet, 'an invalid password never reaches the extension');
	}

	public function testWithoutTheMethodNothingRotates(): void {
		if (class_exists('XC_VM') && method_exists('XC_VM', 'db_set_password')) {
			$this->markTestSkipped('this PHP loads an xcvm_core that rotates');
		}
		DbPassword::useExtension(null);
		$this->assertFalse(DbPassword::available());
		$this->assertSame('no_extension', DbPassword::rotate(self::PASS)['why']);
		[$rCode, $rOut] = self::exec(new ClusterRotateDbPasswordCommand(), ['--yes']);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('update xcvm_core', $rOut);
	}

	// ── A node's whole config (rollback from mode 2), not a rotation ───────

	public function testARollbackConfigIsPackedForTheNodesInstallWithCredentials(): void {
		$rPacked = [];
		$rPack = static function (string $rTarget, array $rParams) use (&$rPacked): string {
			$rPacked[] = [$rTarget, $rParams];
			return 'XCVT-blob';
		};
		// No signed channel in this suite: packed, then not queued (and never a signals row).
		$this->assertSame('cluster_config_not_queued', DbCredentials::installConfig(11, true, 'cli', $rPack));
		$this->assertSame([[self::IID, ['hostname' => '10.0.0.1', 'database' => 'xc_vm', 'server_id' => 11, 'is_lb' => 1, 'db_credentials' => true]]], $rPacked);
		$this->assertStringStartsWith('node.install_config {"credentials":true,"queued":false}', $this->events()[0]);

		$this->assertSame('cluster_config_needs_install_id', DbCredentials::installConfig(15, true, 'cli', $rPack));
		$this->assertSame('cluster_not_enrolled', DbCredentials::installConfig(16, true, 'cli', $rPack));
		$this->assertSame('cluster_strip_needs_mode2', DbCredentials::installConfig(11, false, 'cli', $rPack), 'credential-free only for mode 2');
		$this->assertSame('cluster_config_not_queued', DbCredentials::installConfig(12, false, 'cli', static fn(): bool => false), 'the extension refused to pack');
		$this->assertCount(1, $rPacked);
	}

	// ── The commands ────────────────────────────────────────────────────────

	public function testTheRotateCommandListsTheNodesAndAsksFirst(): void {
		[$rCode, $rOut] = self::exec(new ClusterRotateDbPasswordCommand(), []);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('server 11 (sealed): sent the new password sealed to its box key (node.root rotate_db)', $rOut);
		$this->assertStringContainsString('server 16 (legacy): BY HAND: not enrolled', $rOut);
		$this->assertStringContainsString("3 load balancer(s) keep the old password", $rOut);
		$this->assertStringContainsString('pass --password-stdin', $rOut);
		$this->assertStringContainsString('No terminal to confirm on: pass --yes.', $rOut);
		$this->assertSame([], $this->rSet, 'not confirmed: nothing changed');

		ClusterRotateDbPasswordCommand::useIo(static fn(string $rPrompt): string => "no\n");
		$this->assertSame(1, self::exec(new ClusterRotateDbPasswordCommand(), [])[0]);
		$this->assertSame([], $this->rSet);

		ClusterRotateDbPasswordCommand::useIo(static fn(string $rPrompt): string => "rotate\n");
		[$rCode, $rOut] = self::exec(new ClusterRotateDbPasswordCommand(), []);
		$this->assertSame(0, $rCode, $rOut);
		$this->assertStringContainsString('MAIN runs on the new password.', $rOut);
		$this->assertStringContainsString('server 11: new password sent sealed', $rOut);
		$this->assertCount(1, $this->rSet);
		$this->assertTrue(DbPassword::valid($this->rSet[0]));
		$this->assertStringNotContainsString($this->rSet[0], $rOut, 'a generated password is never shown');
	}

	public function testTheRotateCommandTakesThePasswordFromStandardInput(): void {
		ClusterRotateDbPasswordCommand::useIo(null, static fn(): string => self::PASS . "\n");
		[$rCode, $rOut] = self::exec(new ClusterRotateDbPasswordCommand(), ['--password-stdin', '--yes']);
		$this->assertSame(0, $rCode, $rOut);
		$this->assertSame([self::PASS], $this->rSet);
		$this->assertStringNotContainsString(self::PASS, $rOut);

		ClusterRotateDbPasswordCommand::useIo(null, static fn(): string => "has a space in it, sadly\n");
		[$rCode, $rOut] = self::exec(new ClusterRotateDbPasswordCommand(), ['--password-stdin', '--yes']);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('16-128 characters', $rOut);
		$this->assertCount(1, $this->rSet);
	}

	public function testTheRotateCommandReportsARefusalAndANodeNotSent(): void {
		$this->rSetOk = false;
		$this->rLastError = 'RECORD:is_lb';
		[$rCode, $rOut] = self::exec(new ClusterRotateDbPasswordCommand(), ['--yes']);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('Refused (RECORD:is_lb): This server is not MAIN.', $rOut);

		$this->rSetOk = true;
		$this->rLastError = 'PARTIAL';
		DbPassword::useExtension(static fn(string $rPass): bool => true, static fn(): string => 'PARTIAL', static fn(int $rSid, array $rNode, string $rPass): string => 'cluster_rotate_db_not_queued');
		[$rCode, $rOut] = self::exec(new ClusterRotateDbPasswordCommand(), ['--yes']);
		$this->assertSame(2, $rCode);
		$this->assertStringContainsString('(PARTIAL)', $rOut);
		$this->assertStringContainsString('server 11: password NOT sent (cluster_rotate_db_not_queued)', $rOut);
	}

	public function testTheNodeCommandSetsThePasswordFromStandardInput(): void {
		$rCalls = [];
		$rOk = true;
		$rExt = static function (string $rMethod, mixed ...$rArgs) use (&$rCalls, &$rOk): mixed {
			$rCalls[] = [$rMethod, $rArgs];
			return $rMethod === 'cluster_last_error' ? 'RECORD:db.user' : $rOk;
		};
		ClusterSetDbPasswordCommand::useExtension($rExt, static fn(): string => self::PASS . "\n");
		[$rCode, $rOut] = self::exec(new ClusterSetDbPasswordCommand(), []);
		$this->assertSame(0, $rCode, $rOut);
		$this->assertSame([['config_set_db', [self::PASS]]], $rCalls);
		$this->assertStringNotContainsString(self::PASS, $rOut);

		$rOk = false;
		[$rCode, $rOut] = self::exec(new ClusterSetDbPasswordCommand(), []);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('Refused (RECORD:db.user)', $rOut);
		$this->assertStringContainsString('MAIN sends it a whole config', $rOut);

		ClusterSetDbPasswordCommand::useExtension($rExt, static fn(): string => "\n");
		$rCalls = [];
		[$rCode, $rOut] = self::exec(new ClusterSetDbPasswordCommand(), []);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('No password on standard input', $rOut);
		$this->assertSame([], $rCalls);
	}

	#[\PHPUnit\Framework\Attributes\Group('skip-on-panel')]
	public function testOnlyTheNodeCommandShipsOnALoadBalancer(): void {
		$rRoot = dirname(__DIR__, 2);
		$rMake = (string) file_get_contents($rRoot . '/Makefile');
		$rVerify = (string) file_get_contents($rRoot . '/tools/ci/verify-lb-archive.sh');
		$this->assertStringContainsString('Cli/Commands/ClusterRotateDbPasswordCommand.php', $rMake);
		$this->assertStringContainsString('Cli/Commands/ClusterRotateDbPasswordCommand.php', $rVerify);
		$this->assertStringNotContainsString('ClusterSetDbPasswordCommand', $rMake);
		$rNode = (string) file_get_contents($rRoot . '/src/Cli/Commands/ClusterSetDbPasswordCommand.php');
		$this->assertStringNotContainsString('Domain\\', $rNode, 'the node command needs nothing the LB build strips');
	}
}
