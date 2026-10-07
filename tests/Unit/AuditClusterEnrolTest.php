<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\ClusterBus;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\DbCredentials;
use XcVm\Domain\Cluster\EnrolCodeService;
use XcVm\Domain\Cluster\EnrolmentService;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;
use XcVm\Tests\Support\InstallSchema;

/**
 * A node MAIN keeps free of its credentials (cluster mode 2, or a revoked
 * grant) stays so when it is enrolled again by code, as it does over SSH
 * (LbInstallFlow::installsInApiMode): it comes back in mode 2 with the flows
 * mode 2 needs and MAIN's record of the revoke, never in mode 1 with no
 * database to read. And no enrolment puts a node in mode 2 while the Redis
 * connection handler is on, whichever path asks: the page's mode button
 * already refuses that move (ClusterAdmin::act).
 */
final class AuditClusterEnrolTest extends TestCase {
	private const SID = 5;

	private TestDb $rDb;

	private FakeClusterCrypto $rCrypto;

	private array $rSettings = ['cluster_api_enabled' => 1, 'lb_token_rotation_min' => 60, 'lb_revocation_mode' => 'graceful', 'lb_new_node_mode' => 'legacy'];

	private array $rMain = ['id' => 1, 'server_ip' => '10.0.0.1', 'http_broadcast_port' => 25461];

	private array $rSettingsBefore = [];

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach ([
			'029_create_cluster_nodes', '031_create_cluster_enrolment', '032_create_cluster_audit', '035_add_cluster_epoch_eph', '036_add_cluster_enrol_request_eph',
			'039_add_cluster_node_root_ready', '041_add_cluster_node_features', '045_add_cluster_node_audit', '052_add_cluster_node_db_revoked_at',
		] as $rName) {
			$this->rDb->exec(InstallSchema::migration($rName));
		}
		DatabaseFactory::set($this->rDb);
		foreach ([NodeRegistry::class, DbCredentials::class, EnrolCodeService::class, ClusterAdmin::class] as $rClass) {
			(new \ReflectionProperty($rClass, 'db'))->setValue(null, null);
		}
		$this->rSettingsBefore = SettingsManager::getAll();
		SettingsManager::set($this->rSettings);
		$this->rCrypto = new FakeClusterCrypto();
		ClusterClock::fix(1800000000000);
		ClusterBus::useSocket(sys_get_temp_dir() . '/no-such-bus-' . bin2hex(random_bytes(4)) . '/cluster.sock');
	}

	protected function tearDown(): void {
		ClusterBus::useSocket(null);
		ClusterClock::fix(null);
		SettingsManager::set($this->rSettingsBefore);
		DatabaseFactory::reset();
	}

	/** The node as MAIN holds it before it asks again: enrolled in $rMode, its grant revoked at $rRevokedAt (null: never). */
	private function enrolled(int $rMode, ?int $rRevokedAt, string $rState = 'active'): void {
		NodeRegistry::startEnrolment(self::SID, '0f8fad5b-d9cb-469f-a165-70867728950e', random_bytes(32), random_bytes(32), $rMode, null, $rMode === 2 ? ClusterAdmin::MODE2_FLOWS : 0);
		NodeRegistry::update(self::SID, ['state' => $rState, 'db_revoked_at' => $rRevokedAt]);
	}

	/** A request by code waiting for the admin, with new keys; returns its SAS. */
	private function pending(): string {
		$rUuid = '1f8fad5b-d9cb-469f-a165-70867728950e';
		$rSign = random_bytes(32);
		$rBox = random_bytes(32);
		$this->rDb->query(
			'INSERT INTO `cluster_enrol_requests` (`server_id`, `code_id`, `node_uuid`, `node_sign_pub`, `node_box_pub`, `agent_eph_pub`, `state`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
			self::SID,
			1,
			$rUuid,
			$rSign,
			$rBox,
			random_bytes(32),
			'pending_approval',
			ClusterClock::now()
		);
		return EnrolmentService::sas($rUuid, $rSign, $rBox);
	}

	/** @return array<string, mixed> */
	private function node(): array {
		return (array) NodeRegistry::byServer(self::SID);
	}

	public function testACredentialFreeNodeEnrolledByCodeStaysInModeTwo(): void {
		$this->enrolled(2, 1790000000);
		$this->assertSame('approved', EnrolCodeService::approve($this->rCrypto, self::SID, $this->pending(), $this->rSettings, $this->rMain, 3));
		$rNode = $this->node();
		$this->assertSame('1f8fad5b-d9cb-469f-a165-70867728950e', $rNode['node_uuid']);
		$this->assertSame(2, (int) $rNode['mode'], 'in mode 1 it would have no database');
		$this->assertSame(ClusterAdmin::MODE2_FLOWS, (int) $rNode['flows']);
		$this->assertSame(1790000000, DbCredentials::revokedAt(self::SID), 'MAIN still knows it revoked the grant');
		$this->assertTrue(DbCredentials::credentialFree(self::SID));
	}

	public function testANodeWhoseGrantWasRevokedDoesTooWhateverItsMode(): void {
		$this->enrolled(1, 1790000000);
		$this->assertSame('approved', EnrolCodeService::approve($this->rCrypto, self::SID, $this->pending(), $this->rSettings, $this->rMain, 3));
		$this->assertSame(2, (int) $this->node()['mode']);
		$this->assertSame(1790000000, DbCredentials::revokedAt(self::SID));
	}

	public function testAnyOtherNodeEnrolsByCodeAsTheSettingSays(): void {
		$this->assertSame('approved', EnrolCodeService::approve($this->rCrypto, self::SID, $this->pending(), $this->rSettings, $this->rMain, 3), 'a new node');
		$this->assertSame([1, 0], [(int) $this->node()['mode'], (int) $this->node()['flows']]);

		$this->rDb->exec('DELETE FROM `cluster_enrol_requests`');
		$this->assertSame('approved', EnrolCodeService::approve($this->rCrypto, self::SID, $this->pending(), $this->rSettings, $this->rMain, 3), 'a node in mode 1 that holds its grant');
		$this->assertSame([1, 0], [(int) $this->node()['mode'], (int) $this->node()['flows']]);
	}

	public function testAnEnrolmentEntersModeTwoWithTheRedisHandlerOn(): void {
		// A node in mode 2 never opens MAIN's Redis (ConnectionTracker::openStore):
		// its viewers are its agent's, whatever store MAIN keeps.
		$this->enrolled(2, 1790000000, 'revoked');
		$this->assertSame('approved', EnrolCodeService::approve($this->rCrypto, self::SID, $this->pending(), $this->rSettings + ['redis_handler' => 1], $this->rMain, 3));
		$this->assertSame(2, (int) $this->node()['mode']);
	}
}
