<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\LbInstallFlow;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\EnrolmentService;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;
use XcVm\Tests\Support\InstallSchema;

/**
 * The SSH paths enrol a node in cluster mode 2 with the Redis connection
 * handler on, as with it off (LbInstallFlow::provisionCluster): a node in mode
 * 2 never opens MAIN's Redis (ConnectionTracker::openStore).
 */
final class AuditInstallFollowEnrolRedisTest extends TestCase {
	private const SID = 9;

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

	public function testAnEnrolmentDoesNotAskAboutTheHandler(): void {
		[$rOk, $rLog] = $this->enrol(['lb_new_node_mode' => 'api', 'redis_handler' => 1]);
		$this->assertTrue($rOk, $rLog);
		$this->assertSame(2, (int) NodeRegistry::byServer(self::SID)['mode']);

		$this->rDb->exec('DELETE FROM `cluster_nodes`');
		[$rOk, $rLog] = $this->enrol(['lb_new_node_mode' => 'legacy', 'redis_handler' => 1]);
		$this->assertTrue($rOk, $rLog);
		$this->assertSame(1, (int) NodeRegistry::byServer(self::SID)['mode']);
	}
}
