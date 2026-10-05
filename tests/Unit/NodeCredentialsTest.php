<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\RootSignalsCronJob;
use XcVm\Core\Cluster\NodeActions;
use XcVm\Core\Cluster\NodeCredentials;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\DbCredentials;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * Phase 9, plan section 10 step 3: a node gives up MAIN's credentials
 * (`node.root strip_db_credentials` / `install_config`, run by root through
 * xcvm_core), then MAIN revokes its grant and records `db_revoked_at`.
 * The extension is absent in this suite: the node refuses cleanly, and the
 * success paths run against a fake.
 */
final class NodeCredentialsTest extends TestCase {
	private TestDb $rDb;

	private int $rNow = 1800000000;

	/** @var list<string> Hosts the fake db_revoke was asked for. */
	private array $rRevoked = [];

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		$this->rDb->exec("CREATE TABLE `cluster_nodes` (`server_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `state` varchar(16) NOT NULL DEFAULT 'active', `mode` int NOT NULL DEFAULT 2, `db_revoked_at` int DEFAULT NULL, `updated_at` int NOT NULL DEFAULT 0)");
		$this->rDb->exec('CREATE TABLE `cluster_commands` (`server_id` int, `cmd_id` char(32), `type` varchar(32), `payload` text)');
		// When the page moved a node to mode 2 (DbCredentials::strip counts its days from it); none here.
		$this->rDb->exec('CREATE TABLE `cluster_meta` (`name` varchar(64) PRIMARY KEY, `value` text, `updated_at` int)');
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `server_ip` varchar(255))');
		$this->rDb->exec("INSERT INTO `servers` (`id`, `server_ip`) VALUES (7, '10.0.0.7')");
		$this->rDb->exec('INSERT INTO `cluster_nodes` (`server_id`) VALUES (7)');
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix($this->rNow * 1000);
		DbCredentials::useRevoke(function (string $rHost): bool {
			$this->rRevoked[] = $rHost;
			return true;
		});
	}

	protected function tearDown(): void {
		NodeCredentials::useExtension(null);
		DbCredentials::useRevoke(null);
		ClusterClock::fix(null);
		DatabaseFactory::reset();
	}

	private function command(string $rCmdID, string $rAction, string $rType = 'node.root'): void {
		$this->rDb->query('INSERT INTO `cluster_commands` (`server_id`, `cmd_id`, `type`, `payload`) VALUES (?, ?, ?, ?)', 7, $rCmdID, $rType, json_encode(['type' => $rType, 'action' => $rAction, 'args' => []]));
	}

	private function revokedAt(): ?int {
		$this->rDb->query('SELECT `db_revoked_at` FROM `cluster_nodes` WHERE `server_id` = 7');
		$rAt = $this->rDb->get_row()['db_revoked_at'];
		return $rAt === null ? null : (int) $rAt;
	}

	private function fakeExtension(array|false $rAnswer, string $rError = 'RECORD:is_lb'): void {
		NodeCredentials::useExtension(static fn(string $rMethod, mixed ...$rArgs): mixed => $rMethod === 'cluster_last_error' ? $rError : $rAnswer);
	}

	// ── The node ────────────────────────────────────────────────────────────

	public function testBothActionsAreCatalogedAndClusterOnly(): void {
		foreach ([NodeCredentials::STRIP, NodeCredentials::INSTALL] as $rAction) {
			$this->assertContains($rAction, NodeActions::ROOT_ACTIONS);
			$this->assertContains($rAction, NodeActions::CLUSTER_ONLY, 'never a signals row: only a signed node.root');
		}
	}

	public function testAnOldExtensionRefusesCleanly(): void {
		if (class_exists('XC_VM') && method_exists('XC_VM', 'strip_db_credentials')) {
			$this->markTestSkipped('this PHP loads an xcvm_core that has the method');
		}
		try {
			NodeCredentials::run(['action' => NodeCredentials::STRIP]);
			$this->fail('ran without the method');
		} catch (\RuntimeException $rE) {
			$this->assertStringContainsString('has no strip_db_credentials()', $rE->getMessage());
		}
	}

	public function testStripReportsTheConfigAfterwards(): void {
		$this->fakeExtension(['server_id' => 7, 'is_lb' => 1, 'db_credentials' => false, 'redis_auth' => false, 'changed' => true]);
		$rLine = NodeCredentials::run(['action' => NodeCredentials::STRIP]);
		$this->assertSame(['server_id' => 7, 'is_lb' => 1, 'db_credentials' => false, 'redis_auth' => false, 'changed' => true], NodeCredentials::outcome("Stripped.\n" . $rLine));
	}

	public function testARefusalCarriesTheExtensionsReason(): void {
		$this->fakeExtension(false, 'RECORD:server_id');
		$this->expectExceptionMessage('install_config: refused by xcvm_core: RECORD:server_id');
		NodeCredentials::run(['action' => NodeCredentials::INSTALL, 'blob' => base64_encode('XCVT...')]);
	}

	public function testInstallNeedsABlob(): void {
		$this->fakeExtension(['server_id' => 7]);
		foreach ([[], ['blob' => ''], ['blob' => '!!not base64!!'], ['blob' => 7]] as $rArgs) {
			try {
				NodeCredentials::run(['action' => NodeCredentials::INSTALL] + $rArgs);
				$this->fail('ran without a blob');
			} catch (\RuntimeException $rE) {
				$this->assertStringContainsString('no config blob', $rE->getMessage());
			}
		}
	}

	public function testTheInstallBlobReachesTheExtensionAsBytes(): void {
		$rSeen = null;
		NodeCredentials::useExtension(static function (string $rMethod, mixed ...$rArgs) use (&$rSeen): array {
			$rSeen = [$rMethod, $rArgs];
			return ['server_id' => 7, 'is_lb' => 1, 'db_credentials' => false, 'redis_auth' => false, 'changed' => true];
		});
		NodeCredentials::run(['action' => NodeCredentials::INSTALL, 'blob' => base64_encode("XCVT\x01\x00binary")]);
		$this->assertSame(['install_config', ["XCVT\x01\x00binary"]], $rSeen);
	}

	public function testRootSignalsRunsThem(): void {
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/RootSignalsCronJob.php');
		$this->assertStringContainsString("case '" . NodeCredentials::STRIP . "':", $rSrc);
		$this->assertStringContainsString("case '" . NodeCredentials::INSTALL . "':", $rSrc);
		$this->assertStringContainsString('NodeCredentials::run($rData)', $rSrc);
		$this->assertTrue(method_exists(RootSignalsCronJob::class, 'executeAction'));
	}

	public function testAnUnknownResultIsNoOutcome(): void {
		foreach (['', '{"queued":true}', "Stripped.\nnot json", '{"config":"x"}'] as $rResult) {
			$this->assertNull(NodeCredentials::outcome($rResult), $rResult);
		}
	}

	// ── MAIN ────────────────────────────────────────────────────────────────

	public function testAStripThatLeftNoCredentialsRevokesTheGrant(): void {
		$this->command(str_repeat('a', 32), NodeCredentials::STRIP);
		$rResult = "Removed.\n" . json_encode(['config' => ['server_id' => 7, 'db_credentials' => false]]);
		$this->assertTrue(DbCredentials::acked(7, str_repeat('a', 32), true, $rResult));
		$this->assertSame(['10.0.0.7'], $this->rRevoked);
		$this->assertSame($this->rNow, $this->revokedAt());
		$this->rDb->query("SELECT `event` FROM `cluster_audit` WHERE `server_id` = 7");
		$this->assertSame('node.db_revoked', $this->rDb->get_row()['event']);
	}

	public function testNothingIsRevokedOnDoubt(): void {
		$rClean = json_encode(['config' => ['db_credentials' => false]]);
		$this->command(str_repeat('b', 32), NodeCredentials::STRIP);
		$this->command(str_repeat('c', 32), NodeCredentials::INSTALL);
		$this->command(str_repeat('d', 32), 'reboot');
		$this->command(str_repeat('e', 32), NodeCredentials::STRIP, 'node.rpc');
		$this->assertFalse(DbCredentials::acked(7, str_repeat('b', 32), false, $rClean), 'the command failed');
		$this->assertFalse(DbCredentials::acked(7, str_repeat('b', 32), true, '{"queued":true}'), 'root had not finished');
		$this->assertFalse(DbCredentials::acked(7, str_repeat('c', 32), true, json_encode(['config' => ['db_credentials' => true]])), 'a rollback config');
		$this->assertFalse(DbCredentials::acked(7, str_repeat('d', 32), true, $rClean), 'another action');
		$this->assertFalse(DbCredentials::acked(7, str_repeat('e', 32), true, $rClean), 'not a root command');
		$this->assertFalse(DbCredentials::acked(7, str_repeat('f', 32), true, $rClean), 'no such command');
		$this->assertSame([], $this->rRevoked);
		$this->assertNull($this->revokedAt());
	}

	public function testACredentialFreeInstallRevokesToo(): void {
		$this->command(str_repeat('c', 32), NodeCredentials::INSTALL);
		$this->assertTrue(DbCredentials::acked(7, str_repeat('c', 32), true, json_encode(['config' => ['db_credentials' => false]])));
		$this->assertSame(['10.0.0.7'], $this->rRevoked);
	}

	public function testARefusedRevokeIsAuditedAndNotRecorded(): void {
		DbCredentials::useRevoke(static fn(string $rHost): bool => false);
		$this->assertFalse(DbCredentials::revoke(7));
		$this->assertNull($this->revokedAt());
		$this->rDb->query("SELECT `event` FROM `cluster_audit` WHERE `server_id` = 7");
		$this->assertSame('node.db_revoke_failed', $this->rDb->get_row()['event']);
	}

	public function testOnlyAnActiveNodeInMode2IsAskedToStrip(): void {
		$this->rDb->exec('UPDATE `cluster_nodes` SET `mode` = 1 WHERE `server_id` = 7');
		$this->assertSame('cluster_strip_needs_mode2', DbCredentials::strip(7));
		$this->rDb->exec("UPDATE `cluster_nodes` SET `mode` = 2, `state` = 'quarantined' WHERE `server_id` = 7");
		$this->assertSame('cluster_strip_not_active', DbCredentials::strip(7));
		$this->assertSame('cluster_not_enrolled', DbCredentials::strip(99));
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `cluster_audit`');
		$this->assertSame(0, (int) $this->rDb->get_row()['n'], 'nothing sent, nothing audited');
		// Mode 2 and active, but no signed channel in this suite: nothing falls
		// back to a signals row, and the attempt is audited.
		$this->rDb->exec("UPDATE `cluster_nodes` SET `state` = 'active' WHERE `server_id` = 7");
		$this->assertSame('cluster_strip_not_queued', DbCredentials::strip(7, 'admin:3'));
		$this->rDb->query('SELECT `event`, `actor`, `detail` FROM `cluster_audit` WHERE `server_id` = 7');
		$this->assertSame(['node.strip_credentials', 'admin:3', '{"queued":false}'], array_values($this->rDb->get_row()));
	}

	public function testRevokedAt(): void {
		$this->assertNull(DbCredentials::revokedAt(7));
		DbCredentials::revoke(7);
		$this->assertSame($this->rNow, DbCredentials::revokedAt(7));
		$this->assertNull(DbCredentials::revokedAt(99));
	}

	public function testTheColumnShipsInTheSchema(): void {
		$rSrc = static fn(string $rPath): string => (string) file_get_contents(MAIN_HOME . $rPath);
		$this->assertStringContainsString('`db_revoked_at` int(11) DEFAULT NULL', $rSrc('migrations/database/up/052_add_cluster_node_db_revoked_at.sql'));
		$this->assertStringContainsString('DROP COLUMN IF EXISTS `db_revoked_at`', $rSrc('migrations/database/down/052_add_cluster_node_db_revoked_at.sql'));
		$this->assertStringContainsString('`db_revoked_at` int(11) DEFAULT NULL,', $rSrc('bin/install/database.sql'));
	}
}
