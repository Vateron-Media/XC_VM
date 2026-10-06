<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\LbInstallFlow;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\EnrolmentService;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;
use XcVm\Tests\Support\InstallSchema;

/**
 * MAIN takes no enrolment in cluster mode 2 while the Redis connection
 * handler is on (EnrolmentService::begin). The SSH paths say so before the
 * node is touched: an enrolment MAIN will not take does not stop the node's
 * running agent or replace its keys (LbInstallFlow::provisionCluster), and a
 * reinstall does not replace the node's panel first (server:install).
 */
final class AuditInstallFollowEnrolRedisTest extends TestCase {
	private const SID = 9;

	private const REFUSAL = 'This node enrols in mode 2, which is not available while the Redis connection handler is on';

	private TestDb $rDb;

	private FakeClusterCrypto $rCrypto;

	/** @var list<string> what ran on the node */
	private array $rCommands = [];

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
		foreach (['029_create_cluster_nodes', '032_create_cluster_audit', '035_add_cluster_epoch_eph', '039_add_cluster_node_root_ready', '041_add_cluster_node_features', '045_add_cluster_node_audit', '052_add_cluster_node_db_revoked_at'] as $rName) {
			$this->rDb->exec(InstallSchema::migration($rName));
		}
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `status` int NOT NULL DEFAULT 0)');
		$this->rDb->exec('INSERT INTO `servers` (`id`, `status`) VALUES (9, 1)');
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix(null);
		$this->rCrypto = new FakeClusterCrypto();
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		SettingsManager::set([]);
	}

	/**
	 * Enrol the node over a session that answers as xc_agent does.
	 *
	 * @param array<string, mixed> $rSettings
	 * @return array{0: bool, 1: string} whether it was enrolled, and what was printed
	 */
	private function enrol(array $rSettings, bool $rMarkFailed = false, ?bool $rApiMode = null): array {
		SettingsManager::set($rSettings + ['cluster_api_enabled' => 1, 'lb_token_rotation_min' => 60]);
		$this->rCommands = [];
		$rRun = function ($rConn, string $rCmd): array {
			$this->rCommands[] = $rCmd;
			if (preg_match('/ keygen .* -uuid ([0-9a-f-]{36})$/', $rCmd, $rM)) {
				$rSign = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
				$rBox = sodium_crypto_scalarmult_base(random_bytes(32));
				return ['output' => json_encode([
					'node_uuid' => $rM[1], 'sign_pub' => bin2hex($rSign), 'box_pub' => bin2hex($rBox),
					'eph_pub' => bin2hex(sodium_crypto_scalarmult_base(random_bytes(32))), 'sas' => EnrolmentService::sas($rM[1], $rSign, $rBox),
				]) . "\n", 'error' => ''
				];
			}
			$rOutput = match (true) {
				str_contains($rCmd, 'console.php fanout_binary agent') => "AGENT_OK\n",
				str_contains($rCmd, ' probe ') => "OK http://10.0.0.1:25461/cluster/v1/\n",
				str_contains($rCmd, ' install ') => "OK\n",
				str_contains($rCmd, 'run.sh') && str_contains($rCmd, 'pgrep') => "STARTED\n",
				default => '',
			};
			return ['output' => $rOutput, 'error' => ''];
		};
		$rServers = [SERVER_ID => ['server_ip' => '10.0.0.1', 'http_broadcast_port' => 25461, 'enable_https' => 0], self::SID => []];
		ob_start();
		try {
			$rOk = LbInstallFlow::provisionCluster(null, $rRun, static fn(): bool => true, $rServers, self::SID, $this->rDb, $this->rCrypto, $rMarkFailed, $rApiMode);
		} finally {
			$rLog = (string) ob_get_clean();
		}
		return [$rOk, $rLog];
	}

	private function serverStatus(): int {
		$this->rDb->query('SELECT `status` FROM `servers` WHERE `id` = 9');
		return (int) $this->rDb->get_row()['status'];
	}

	public function testAModeTwoEnrolmentIsRefusedBeforeTheNodeIsTouched(): void {
		[$rOk, $rLog] = $this->enrol(['lb_new_node_mode' => 'api', 'redis_handler' => 1]);
		$this->assertFalse($rOk);
		$this->assertSame([], $this->rCommands, 'the node keeps its running agent and its keys');
		$this->assertStringContainsString(self::REFUSAL, $rLog);
		$this->assertNull(NodeRegistry::byServer(self::SID));
		$this->assertSame(1, $this->serverStatus(), 'server:enrol leaves a serving node as it was');
	}

	public function testANodeMainKeepsCredentialFreeIsRefusedTheSameWayWhateverTheSetting(): void {
		NodeRegistry::startEnrolment(self::SID, '0f8fad5b-d9cb-469f-a165-70867728950e', random_bytes(32), random_bytes(32), 2, null, ClusterAdmin::MODE2_FLOWS);
		NodeRegistry::update(self::SID, ['state' => 'active']);

		[$rOk, $rLog] = $this->enrol(['lb_new_node_mode' => 'legacy', 'redis_handler' => 1]);
		$this->assertFalse($rOk);
		$this->assertSame([], $this->rCommands, 'the node keeps its running agent and its keys');
		$this->assertStringContainsString(self::REFUSAL, $rLog);
		$rNode = (array) NodeRegistry::byServer(self::SID);
		$this->assertSame(['0f8fad5b-d9cb-469f-a165-70867728950e', 'active', 1], [$rNode['node_uuid'], $rNode['state'], (int) $rNode['gen']], 'and MAIN its row');
	}

	public function testAFreshInstallIsMarkedFailedAsByAnyOtherRefusal(): void {
		[$rOk, $rLog] = $this->enrol(['lb_new_node_mode' => 'legacy', 'redis_handler' => 1], true, true);
		$this->assertFalse($rOk);
		$this->assertSame([], $this->rCommands);
		$this->assertStringContainsString(self::REFUSAL, $rLog);
		$this->assertSame(4, $this->serverStatus());
	}

	public function testAModeOneEnrolmentDoesNotAskAboutTheHandler(): void {
		[$rOk, $rLog] = $this->enrol(['lb_new_node_mode' => 'legacy', 'redis_handler' => 1]);
		$this->assertTrue($rOk, $rLog);
		$this->assertSame(1, (int) NodeRegistry::byServer(self::SID)['mode']);

		// Nor a mode 2 one with the handler off.
		$this->rDb->exec('DELETE FROM `cluster_nodes`');
		[$rOk, $rLog] = $this->enrol(['lb_new_node_mode' => 'api', 'redis_handler' => 0]);
		$this->assertTrue($rOk, $rLog);
		$this->assertSame(2, (int) NodeRegistry::byServer(self::SID)['mode']);
	}

	/** server:install refuses before it opens the SSH session, so before the node's panel is stopped and replaced. */
	public function testAnInstallIsRefusedBeforeTheNodeIsContacted(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ServerInstallCommand.php');
		$rAt = strpos($rSource, "if (LbInstallFlow::installsInApiMode(\$rSettings, \$rServerID) && !empty(\$rSettings['redis_handler'])) {");
		$this->assertNotFalse($rAt, 'an install that ends in a mode 2 enrolment asks about the handler');
		$this->assertLessThan(strpos($rSource, 'SshSession::open('), $rAt, 'before the node is contacted');
		$this->assertMatchesRegularExpression(
			'/!empty\(\$rSettings\[\'redis_handler\'\]\)\) \{\s*\$db->query\(\'UPDATE `servers` SET `status` = 4 WHERE `id` = \?;\', \$rServerID\);\s*echo "[^"\n]*Redis connection handler[^"\n]*Exiting\\\\n";\s*return 1;/',
			$rSource,
			'the install ends as failed, saying why'
		);
	}
}
