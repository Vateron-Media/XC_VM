<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\LbInstallFlow;
use XcVm\Core\Backup\BackupService;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\CredentialFreeConfig;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\DbCredentials;
use XcVm\Domain\Cluster\EnrolmentService;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * `lb_new_node_mode = api` (plan, section 12; ADR 0004, Phase 9): a new load
 * balancer installed in mode 2 from its first boot, with no DB grant and a
 * config.enc that carries none of MAIN's credentials. The extension packs it
 * (`config_pack(…, ['db_credentials' => false])`, found by `install_config`);
 * an older one would pack MAIN's credentials, so API mode is refused there.
 * `api_mode_allowed` is the Redis connection handler off: a node in mode 2 may
 * not open MAIN's Redis, and with the handler every viewer there is turned away.
 * A node MAIN already keeps credential-free (mode 2, or a revoked grant) is
 * reinstalled the same way, whatever the setting, and never granted again.
 */
final class ApiModeInstallTest extends TestCase {
	private const SID = 9;

	private TestDb $rDb;

	/** @var list<string> */
	private array $rRan = [];

	/** @var array<string, string> */
	private array $rSent = [];

	/** What the node prints for `XC_VM::install_id()`. */
	private string $rIidOutput = "0f8fad5b-install-id-0001\n";

	public static function setUpBeforeClass(): void {
		foreach (['SERVER_ID' => 1, 'TMP_PATH' => sys_get_temp_dir() . '/xcvm-test-tmp/', 'CONFIG_PATH' => sys_get_temp_dir() . '/xcvm-test-config/'] as $rName => $rValue) {
			if (!defined($rName)) {
				define($rName, $rValue);
			}
		}
		@mkdir(TMP_PATH, 0700, true);
	}

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$rSql = (string) file_get_contents(MAIN_HOME . 'migrations/database/up/029_create_cluster_nodes.sql');
		foreach (array_filter(array_map('trim', explode(';', (string) $rSql))) as $rStatement) {
			$this->rDb->exec($rStatement);
		}
		$this->rDb->exec('ALTER TABLE `cluster_node_epochs` ADD COLUMN `agent_eph_pub` binary(32) DEFAULT NULL');
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `status` int NOT NULL DEFAULT 0)');
		$this->rDb->exec('INSERT INTO `servers` (`id`, `status`) VALUES (9, 0)');
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix(null);
		SettingsManager::set(['cluster_api_enabled' => 1, 'lb_new_node_mode' => 'api']);
	}

	protected function tearDown(): void {
		CredentialFreeConfig::useSeams(null);
		DatabaseFactory::reset();
		SettingsManager::set([]);
	}

	private function install(bool $rApi): bool {
		$rRun = function ($rConn, string $rCmd): array {
			$this->rRan[] = $rCmd;
			return ['output' => str_contains($rCmd, 'install_id') ? $this->rIidOutput : '', 'error' => ''];
		};
		$rSend = function ($rConn, string $rLocal, string $rRemote): bool {
			$this->rSent[$rRemote] = (string) file_get_contents($rLocal);
			return true;
		};
		ob_start();
		try {
			return LbInstallFlow::provisionConfig(null, $rRun, $rSend, [SERVER_ID => ['server_ip' => '10.0.0.1'], self::SID => []], self::SID, $this->rDb, $rApi);
		} finally {
			ob_end_clean();
		}
	}

	private function serverStatus(): int {
		$this->rDb->query('SELECT `status` FROM `servers` WHERE `id` = ?', self::SID);
		return (int) $this->rDb->get_row()['status'];
	}

	public function testTheSwitchNeedsTheRedisHandlerOffAndTheExtension(): void {
		$rMain = ['http_broadcast_port' => 80, 'https_broadcast_port' => 443];
		$rOn = ['cluster_api_enabled' => 1];
		$rAllowed = ['extension_ok' => true, 'api_mode_allowed' => true, 'credential_free_config' => true];
		$this->assertSame([[], [['lb_new_node_mode', 'cluster_error_api_mode']]], ClusterSettings::normalize(['lb_new_node_mode' => 'api'], $rMain, $rOn, ['credential_free_config' => true]), 'api_mode_allowed false: the Redis handler is on');
		$this->assertSame([[], [['lb_new_node_mode', 'cluster_error_api_mode_extension']]], ClusterSettings::normalize(['lb_new_node_mode' => 'api'], $rMain, $rOn, ['api_mode_allowed' => true, 'credential_free_config' => false]));
		$this->assertSame([['lb_new_node_mode' => 'api'], []], ClusterSettings::normalize(['lb_new_node_mode' => 'api'], $rMain, $rOn, $rAllowed));

		// Mode 2 is the cluster API's: with it off a new node installs legacy, so api is refused, as the save leaves the API.
		$this->assertSame([[], [['lb_new_node_mode', 'cluster_error_api_mode_api_off']]], ClusterSettings::normalize(['lb_new_node_mode' => 'api'], $rMain, ['cluster_api_enabled' => 0], $rAllowed));
		$this->assertSame([['cluster_api_enabled' => 1, 'lb_new_node_mode' => 'api'], []], ClusterSettings::normalize(['cluster_api_enabled' => 1, 'lb_new_node_mode' => 'api'], $rMain, [], $rAllowed), 'switched on in the same save');
		$this->assertSame([['cluster_api_enabled' => 0], [['lb_new_node_mode', 'cluster_error_api_mode_api_off']]], ClusterSettings::normalize(['cluster_api_enabled' => 0, 'lb_new_node_mode' => 'api'], $rMain, $rOn, $rAllowed), 'switched off in the same save');
		$this->assertFalse(ClusterSettings::newNodesInApiMode(['cluster_api_enabled' => 0, 'lb_new_node_mode' => 'api']), 'what the install reads');

		$rService = (string) file_get_contents(MAIN_HOME . 'Domain/Server/SettingsService.php');
		$this->assertStringContainsString('\'api_mode_allowed\' => empty($rCurrent[\'redis_handler\']),', $rService, 'the handler off allows it');
		$this->assertStringContainsString("'credential_free_config' => CredentialFreeConfig::supported(),", $rService);
		$this->assertStringContainsString('cluster_error_api_mode_extension = ', (string) file_get_contents(MAIN_HOME . 'Core/Localization/lang/en.ini'));
	}

	public function testApiModeIsOnlyWithTheClusterApi(): void {
		$this->assertTrue(ClusterSettings::newNodesInApiMode(['cluster_api_enabled' => 1, 'lb_new_node_mode' => 'api']));
		$this->assertFalse(ClusterSettings::newNodesInApiMode(['cluster_api_enabled' => 0, 'lb_new_node_mode' => 'api']));
		$this->assertFalse(ClusterSettings::newNodesInApiMode(['cluster_api_enabled' => 1, 'lb_new_node_mode' => 'legacy']));
		$this->assertFalse(ClusterSettings::newNodesInApiMode(['cluster_api_enabled' => 1]));
	}

	/** An older extension would pack MAIN's credentials: the install stops before anything is written. */
	public function testAnOldExtensionRefusesApiMode(): void {
		CredentialFreeConfig::useSeams(static fn(): bool => false, static function (): string {
			throw new \LogicException('packed without the feature');
		});
		$this->assertFalse($this->install(true));
		$this->assertSame(4, $this->serverStatus());
		$this->assertSame([], $this->rRan, 'nothing ran on the node');
		$this->assertSame([], $this->rSent);
		$this->assertFalse(CredentialFreeConfig::pack('0f8fad5b-install-id-0001', ['hostname' => 'x']));
	}

	public function testApiModeShipsACredentialFreeConfig(): void {
		$rPacked = [];
		CredentialFreeConfig::useSeams(static fn(): bool => true, static function (string $rIid, array $rParams) use (&$rPacked): string {
			$rPacked[] = [$rIid, $rParams];
			return 'XCVT-credential-free';
		});
		$this->assertTrue($this->install(true));
		$this->assertSame([['0f8fad5b-install-id-0001', ['db_credentials' => false, 'hostname' => '10.0.0.1', 'database' => 'xc_vm', 'server_id' => self::SID, 'is_lb' => 1]]], $rPacked);
		$this->assertSame('XCVT-credential-free', $this->rSent[CONFIG_PATH . 'config.enc']);
		$this->assertSame(0, $this->serverStatus());
	}

	/** A fresh node's PHP may print warnings before the id: only the id is packed for. */
	public function testTheInstallIdIsTheLastLineOfTheNodesOutput(): void {
		$rPacked = [];
		CredentialFreeConfig::useSeams(static fn(): bool => true, static function (string $rIid) use (&$rPacked): string {
			$rPacked[] = $rIid;
			return 'XCVT-credential-free';
		});
		$this->rIidOutput = "Warning: XC_VM: ionCube Loader not detected.\n0f8fad5b-install-id-0001\n";
		$this->assertTrue($this->install(true));
		$this->assertSame(['0f8fad5b-install-id-0001'], $rPacked);

		$this->rIidOutput = "Warning: no id here\n";
		$this->assertFalse($this->install(true));
		$this->assertSame(4, $this->serverStatus());
	}

	/** Born in mode 2 with the flows mode 2 needs, as a promoted node must have. */
	public function testAnApiModeNodeIsEnrolledInModeTwoWithItsFlows(): void {
		$rCrypto = new FakeClusterCrypto();
		EnrolmentService::begin($rCrypto, self::SID, '0f8fad5b-d9cb-469f-a165-70867728950e', random_bytes(32), random_bytes(32), random_bytes(32), ['lb_new_node_mode' => 'api', 'lb_token_rotation_min' => 60]);
		$rNode = NodeRegistry::byServer(self::SID);
		$this->assertSame([2, ClusterAdmin::MODE2_FLOWS], [(int) $rNode['mode'], (int) $rNode['flows']]);

		EnrolmentService::begin($rCrypto, self::SID, '1f8fad5b-d9cb-469f-a165-70867728950e', random_bytes(32), random_bytes(32), random_bytes(32), ['lb_new_node_mode' => 'legacy', 'lb_token_rotation_min' => 60]);
		$rNode = NodeRegistry::byServer(self::SID);
		$this->assertSame([1, 0], [(int) $rNode['mode'], (int) $rNode['flows']], 'legacy stays mode 1, flows off');
	}

	/** No DB grant for an API-mode load balancer, at creation or at the end of its install; and no silent legacy fallback. */
	public function testAnApiModeNodeGetsNoGrantAndNoLegacyFallback(): void {
		$rService = (string) file_get_contents(MAIN_HOME . 'Domain/Server/ServerService.php');
		$this->assertMatchesRegularExpression('/if \(\$rArray\[\'server_type\'\] == 0 && !ClusterSettings::newNodesInApiMode\(SettingsManager::getAll\(\)\)\) \{\s*BackupService::grantPrivileges/', $rService);
		$rInstall = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ServerInstallCommand.php');
		$this->assertStringContainsString('$rApiMode = $rType == 2 && LbInstallFlow::installsInApiMode(SettingsManager::getAll(), $rServerID);', $rInstall);
		$this->assertStringContainsString('$this->finalizeHostAfterRuntime($rConn, $rRunSSH, $rHost, !$rApiMode);', $rInstall);
		$this->assertStringContainsString('provisionConfig($rConn, $rRunSSH, $rSendFileSSH, $rServers, $rServerID, $db, $rApiMode)', $rInstall);
		$this->assertStringContainsString('provisionCluster($rConn, $rRunSSH, $rSendFileSSH, $rServers, $rServerID, $db, null, true, $rApiMode)', $rInstall);
		$this->assertMatchesRegularExpression('/if \(\$rGrant\) \{\s*BackupService::grantPrivileges\(\$rHost\);/', $rInstall);

		// Without the cluster API's crypto an API-mode install fails rather than "stays legacy".
		ob_start();
		$rOk = LbInstallFlow::provisionCluster(null, static fn(): array => ['output' => '', 'error' => ''], static fn(): bool => true, [], self::SID, $this->rDb, null);
		ob_end_clean();
		$this->assertFalse($rOk);
		$this->assertSame(4, $this->serverStatus());
	}

	// ── A node MAIN keeps credential-free ──────────────────────────────────

	private function node(int $rMode, ?int $rRevokedAt): void {
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `db_revoked_at` int DEFAULT NULL');
		NodeRegistry::startEnrolment(self::SID, '0f8fad5b-d9cb-469f-a165-70867728950e', random_bytes(32), random_bytes(32), $rMode);
		if ($rRevokedAt !== null) {
			$this->rDb->query('UPDATE `cluster_nodes` SET `db_revoked_at` = ? WHERE `server_id` = ?', $rRevokedAt, self::SID);
		}
	}

	/** Reinstalling a node in mode 2, or one whose grant MAIN revoked, keeps it credential-free whatever lb_new_node_mode says. */
	public function testACredentialFreeNodeReinstallsInApiMode(): void {
		$rLegacy = ['cluster_api_enabled' => 1, 'lb_new_node_mode' => 'legacy'];
		$this->assertFalse(LbInstallFlow::installsInApiMode($rLegacy, self::SID), 'not enrolled: a new node follows the setting');
		$this->assertTrue(LbInstallFlow::installsInApiMode(['cluster_api_enabled' => 1, 'lb_new_node_mode' => 'api'], self::SID));

		$this->node(1, null);
		$this->assertFalse(DbCredentials::credentialFree(self::SID));
		$this->assertFalse(LbInstallFlow::installsInApiMode($rLegacy, self::SID), 'mode 1 holds its grant');

		$this->rDb->query('UPDATE `cluster_nodes` SET `mode` = 2 WHERE `server_id` = ?', self::SID);
		$this->assertTrue(DbCredentials::credentialFree(self::SID));
		$this->assertTrue(LbInstallFlow::installsInApiMode($rLegacy, self::SID));
		$this->assertFalse(LbInstallFlow::installsInApiMode(['cluster_api_enabled' => 0, 'lb_new_node_mode' => 'legacy'], self::SID), 'no cluster API: nothing to enrol into');

		$this->rDb->query('UPDATE `cluster_nodes` SET `mode` = 1, `db_revoked_at` = 1800000000 WHERE `server_id` = ?', self::SID);
		$this->assertTrue(LbInstallFlow::installsInApiMode($rLegacy, self::SID), 'a revoked grant is not handed back');
	}

	/** The re-enrolment replaces the node's row; in mode 2 the revoke survives it. */
	public function testAReEnrolmentInModeTwoKeepsTheRevoke(): void {
		$this->node(2, 1800000000);
		$rCrypto = new FakeClusterCrypto();
		EnrolmentService::begin($rCrypto, self::SID, '1f8fad5b-d9cb-469f-a165-70867728950e', random_bytes(32), random_bytes(32), random_bytes(32), ['lb_new_node_mode' => 'api', 'lb_token_rotation_min' => 60]);
		$this->assertSame(1800000000, DbCredentials::revokedAt(self::SID));
		$this->assertSame(2, (int) NodeRegistry::byServer(self::SID)['gen']);

		EnrolmentService::begin($rCrypto, self::SID, '2f8fad5b-d9cb-469f-a165-70867728950e', random_bytes(32), random_bytes(32), random_bytes(32), ['lb_new_node_mode' => 'legacy', 'lb_token_rotation_min' => 60]);
		$this->assertNull(DbCredentials::revokedAt(self::SID), 'a deliberate mode-1 enrolment starts clean');
	}

	/** Every grant path goes through grantPrivileges(), which grants nothing to such a node's host. */
	public function testNoGrantReachesACredentialFreeNodesHost(): void {
		$this->rDb->exec('ALTER TABLE `servers` ADD COLUMN `server_ip` varchar(64)');
		$this->rDb->exec('ALTER TABLE `servers` ADD COLUMN `server_type` int NOT NULL DEFAULT 0');
		$this->rDb->exec("UPDATE `servers` SET `server_ip` = '203.0.113.9' WHERE `id` = 9");
		$this->node(2, null);
		$this->assertTrue(DbCredentials::credentialFreeHost('203.0.113.9'));
		$this->assertFalse(DbCredentials::credentialFreeHost('203.0.113.10'), 'another host');
		$this->assertFalse(BackupService::grantPrivileges('203.0.113.9'), 'refused before the extension is asked');

		$this->rDb->exec('UPDATE `servers` SET `server_type` = 1 WHERE `id` = 9');
		$this->assertFalse(DbCredentials::credentialFreeHost('203.0.113.9'), 'only load balancers are cluster nodes');

		$rTools = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ToolsCommand.php');
		$this->assertStringContainsString('if (!BackupService::grantPrivileges($rServer[\'server_ip\'])) {', $rTools);
	}
}
