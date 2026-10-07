<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\NodeCredentials;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterBus;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Domain\Cluster\ClusterRoute;
use XcVm\Domain\Cluster\CommandBus;
use XcVm\Domain\Cluster\DbCredentials;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;
use XcVm\Tests\Support\InstallSchema;

/**
 * A credential strip root ran after MAIN moved its node below mode 2 (ADR
 * 0004, the credential strip's outcome): its ack revokes nothing and sends
 * the credentials back, as a signed node.root install_config whose blob MAIN
 * packed with them for the node's install_id.
 */
final class CredentialsRestoreTest extends TestCase {
	private const SID = 7;
	private const UUID = '7a1c0d2e-3b4f-4a5b-8c6d-7e8f9a0b1c2d';
	private const INSTALL_ID = 'install-0123456789ab';

	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		preg_match_all('/CREATE TABLE IF NOT EXISTS `(cluster_\w+)`/', (string) file_get_contents(MAIN_HOME . 'bin/install/database.sql'), $rTables);
		foreach ($rTables[1] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY, `server_ip` varchar(255))');
		$this->rDb->query("INSERT INTO `servers` (`id`, `server_ip`) VALUES (?, '10.0.0.1'), (?, '10.0.0.7')", (int) SERVER_ID, self::SID);
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix(1800000000000);
		ClusterBus::useSocket(sys_get_temp_dir() . '/no-such-bus-' . bin2hex(random_bytes(4)) . '/cluster.sock');
		SettingsManager::set(['cluster_api_enabled' => 1]);
		$rCrypto = new FakeClusterCrypto();
		ClusterRoute::useCrypto(fn() => $rCrypto);
		ClusterMeta::set('panel_sign_pub', base64_encode($rCrypto->info()['panel_sign_pub']));
		NodeRegistry::startEnrolment(self::SID, self::UUID, random_bytes(32), random_bytes(32), 1);
		NodeRegistry::update(self::SID, ['state' => 'active', 'mode' => 2, 'flows' => 255, 'root_ready' => 1, 'install_id' => self::INSTALL_ID]);
	}

	protected function tearDown(): void {
		DbCredentials::usePack(null);
		ClusterRoute::useCrypto(null);
		ClusterBus::useSocket(null);
		ClusterClock::fix(null);
		SettingsManager::set([]);
		DatabaseFactory::reset();
	}

	public function testAStripRootRanAfterAModeDownGetsItsCredentialsBack(): void {
		$this->assertSame([true, true], ClusterRoute::root(self::SID, ['action' => NodeCredentials::STRIP]));
		$rCmdID = (string) json_decode(CommandBus::pending(self::SID, 0)[0]['doc'], true)['cmd_id'];
		// MAIN moves the node down while the strip is in root's hands; root runs it all the same.
		NodeRegistry::update(self::SID, ['mode' => 1]);
		$rPacked = [];
		DbCredentials::usePack(static function (string $rID, array $rParams) use (&$rPacked): string {
			$rPacked[] = [$rID, $rParams];
			return 'a config with the credentials';
		});

		$this->assertFalse(DbCredentials::acked(self::SID, $rCmdID, true, json_encode(['config' => ['db_credentials' => false]])), 'nothing revoked');
		$this->rDb->query('SELECT `db_revoked_at` FROM `cluster_nodes` WHERE `server_id` = ?', self::SID);
		$this->assertNull($this->rDb->get_row()['db_revoked_at']);

		$this->assertSame([[self::INSTALL_ID, ['hostname' => '10.0.0.1', 'database' => 'xc_vm', 'server_id' => self::SID, 'is_lb' => 1, 'db_credentials' => true]]], $rPacked, 'packed with the credentials, for this node');
		$rInstall = array_values(array_filter(CommandBus::pending(self::SID, 0), static fn(array $rRow): bool => (json_decode($rRow['doc'], true)['action'] ?? null) === NodeCredentials::INSTALL));
		$this->assertCount(1, $rInstall, 'sent back as a signed root command');
		$this->assertSame(base64_encode('a config with the credentials'), json_decode($rInstall[0]['doc'], true)['args']['blob']);

		$this->rDb->query("SELECT `detail` FROM `cluster_audit` WHERE `event` = 'node.credentials_restored'");
		$this->assertSame(['cmd_id' => $rCmdID, 'queued' => true], json_decode((string) $this->rDb->get_row()['detail'], true));
	}
}
