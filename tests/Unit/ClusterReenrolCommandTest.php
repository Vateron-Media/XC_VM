<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ClusterReenrolCommand;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Domain\Cluster\EnrolmentService;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;
use XcVm\Tests\Support\FakeSshFleet;

/**
 * cluster:reenrol — re-enrols the fleet over SSH, node by node, through
 * server:enrol's path (ServerEnrolCommand::enrol → LbInstallFlow::provisionCluster).
 * The SSH layer is faked: each fake node answers the way xc_agent does, and
 * presents its own host key. What is checked: every selected node gets a new
 * identity and generation; a node that fails is reported and the run goes on;
 * the host key is never trusted on first use; a licence refusal stops the run;
 * a node revoked while the run goes on stays revoked; a dry run contacts no
 * node; the credential file is taken from bin/install/ only, owner-only, and a
 * real run deletes it even when it refuses to run.
 */
final class ClusterReenrolCommandTest extends TestCase {
	private TestDb $rDb;

	private FakeClusterCrypto $rCrypto;

	private FakeSshFleet $rSsh;

	private string $rDir;

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
		foreach (['029_create_cluster_nodes', '032_create_cluster_audit'] as $rName) {
			$rSql = (string) file_get_contents(MAIN_HOME . 'migrations/database/up/' . $rName . '.sql');
			$this->rDb->exec($rSql);
		}
		$this->rDb->exec('ALTER TABLE `cluster_node_epochs` ADD COLUMN `agent_eph_pub` binary(32) DEFAULT NULL');
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `status` int NOT NULL DEFAULT 1, `ssh_hostkey_sha1` char(40) DEFAULT NULL)');
		DatabaseFactory::set($this->rDb);
		SettingsManager::set(['cluster_api_enabled' => 1, 'lb_token_rotation_min' => 60, 'lb_new_node_mode' => 'legacy']);
		ClusterClock::fix(null);
		$this->rCrypto = new FakeClusterCrypto();
		$this->rSsh = new FakeSshFleet();
		$this->rDir = sys_get_temp_dir() . '/xcvm_reenrol_' . uniqid('', true) . '/';
		mkdir($this->rDir, 0750, true);
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		SettingsManager::set([]);
		foreach (glob($this->rDir . '*') ?: [] as $rFile) {
			@unlink($rFile);
		}
		@rmdir($this->rDir);
	}

	/** MAIN #1 and the load balancers #7, #8, #9, #10 and #12, each with the host key stored at its install. */
	private function servers(): array {
		$rServers = [SERVER_ID => ['id' => SERVER_ID, 'is_main' => 1, 'server_type' => 0, 'server_name' => 'Main', 'server_ip' => '10.0.0.1', 'http_broadcast_port' => 25461, 'enable_https' => 0]];
		foreach ([7 => 'lb-a', 8 => 'lb-b', 9 => 'lb-c', 10 => 'lb-d', 12 => 'lb-e'] as $rID => $rName) {
			$rServers[$rID] = ['id' => $rID, 'is_main' => 0, 'server_type' => 0, 'server_name' => $rName, 'server_ip' => '10.0.0.' . $rID, 'ssh_hostkey_sha1' => sha1('host-' . $rID)];
			$this->rDb->query('INSERT INTO `servers` (`id`, `ssh_hostkey_sha1`) VALUES (?, ?)', $rID, sha1('host-' . $rID));
			$this->rSsh->rNodes['10.0.0.' . $rID] = ['hostkey' => sha1('host-' . $rID), 'password' => 'pw'];
		}
		return $rServers;
	}

	/** A node enrolled before the run, in $rState. */
	private function enrolled(int $rServerID, string $rState = 'active'): array {
		$rUuid = sprintf('%08x-0000-4000-8000-%012x', $rServerID, $rServerID);
		NodeRegistry::startEnrolment($rServerID, $rUuid, str_repeat("\x01", 32), str_repeat("\x02", 32), 1, $this->rCrypto);
		NodeRegistry::update($rServerID, ['state' => $rState]);
		return (array) NodeRegistry::byServer($rServerID);
	}

	/** @return array{0: int, 1: string} [exit code, output] */
	private function reenrol(array $rServers, ?array $rIDs, ?array $rCreds, bool $rDryRun = false, array $rStates = ['enrolling', 'active'], bool $rPending = false): array {
		ob_start();
		try {
			$rCode = ClusterReenrolCommand::run($rServers, $rIDs, $rStates, $rCreds, $rDryRun, $this->rCrypto, $this->rSsh, $rPending);
		} finally {
			$rOut = (string) ob_get_clean();
		}
		return [$rCode, $rOut];
	}

	/** The command from its arguments on, as execute() runs it. @return array{0: int, 1: string} [exit code, output] */
	private function main(array $rServers, array $rArgs): array {
		ob_start();
		try {
			$rCode = ClusterReenrolCommand::main($rArgs, $this->rCrypto, $rServers, $this->rSsh, $this->rDir);
		} finally {
			$rOut = (string) ob_get_clean();
		}
		return [$rCode, $rOut];
	}

	/** A credential file in the test's bin/install/. */
	private function credFile(int $rMode = 0600): string {
		$rPath = $this->rDir . 'fleet.cred';
		file_put_contents($rPath, json_encode(['u' => 'root', 'p' => 'pw']));
		chmod($rPath, $rMode);
		return $rPath;
	}

	private static function creds(array $rNodes = []): array {
		return ['default' => ['username' => 'root', 'password' => 'pw'], 'nodes' => $rNodes];
	}

	private function audit(string $rEvent): array {
		$this->rDb->query('SELECT `detail` FROM `cluster_audit` WHERE `event` = ? ORDER BY `id`', $rEvent);
		return array_map(static fn($rRow) => json_decode((string) $rRow['detail'], true), $this->rDb->get_rows());
	}

	public function testReenrolsEveryActiveNodeAndReportsEach(): void {
		$rServers = $this->servers();
		$rBefore = [7 => $this->enrolled(7), 8 => $this->enrolled(8), 12 => $this->enrolled(12, 'enrolling')];
		[$rCode, $rOut] = $this->reenrol($rServers, null, self::creds());
		$this->assertSame(0, $rCode, $rOut);
		foreach ($rBefore as $rID => $rOld) {
			$rNew = NodeRegistry::byServer($rID);
			$this->assertNotSame($rOld['node_uuid'], $rNew['node_uuid'], "#{$rID}: a new identity");
			$this->assertSame((int) $rOld['gen'] + 1, (int) $rNew['gen'], "#{$rID}: the old generation's tokens stop working");
			$this->assertSame('enrolling', $rNew['state']);
			$this->assertStringContainsString("#{$rID} {$rServers[$rID]['server_name']}: re-enrolled (node {$rNew['node_uuid']}, gen 2)", $rOut);
			$this->assertContains('connect 10.0.0.' . $rID . ':22', $this->rSsh->rLog);
			$this->assertContains('login 10.0.0.' . $rID . ' root', $this->rSsh->rLog);
		}
		$this->assertStringContainsString('3 re-enrolled, 0 failed, 0 not attempted, 0 skipped', $rOut);
		$this->assertSame([['ok' => [7, 8, 12], 'failed' => []]], $this->audit('cluster.reenrol'));
		$this->assertSame(3, substr_count(implode("\n", $this->rSsh->rLog), 'close '), 'each session is closed');
	}

	/** --pending leaves out the active nodes enrolled since the root changed; without a record of the change nothing runs. */
	public function testPendingLeavesOutTheNodesOnTheCurrentRoot(): void {
		$rServers = $this->servers();
		ClusterClock::fix(1000000);
		$this->enrolled(7);
		ClusterMeta::set(ClusterMeta::ROOT_AT, '2000');
		ClusterClock::fix(3000000);
		$this->enrolled(8);
		$this->enrolled(9, 'enrolling');
		ClusterClock::fix(null);

		[$rCode, $rOut] = $this->reenrol($rServers, null, self::creds(), false, ['enrolling', 'active'], true);
		$this->assertSame(0, $rCode, $rOut);
		$this->assertStringContainsString('#7 lb-a: re-enrolled', $rOut, 'enrolled before the root changed');
		$this->assertStringContainsString('#8 lb-b: skipped: enrolled since the root changed on 1970-01-01 00:33 UTC (name it to re-enrol it)', $rOut);
		$this->assertStringContainsString('#9 lb-c: re-enrolled', $rOut, 'an enrolment never completed');
		$this->assertNotContains('connect 10.0.0.8:22', $this->rSsh->rLog);

		$this->rDb->query("DELETE FROM `cluster_meta` WHERE `name` = 'root_at'");
		$this->rSsh->rLog = [];
		[$rCode, $rOut] = $this->reenrol($rServers, null, self::creds(), false, ['enrolling', 'active'], true);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('no record of when its root last changed', $rOut);
		$this->assertSame([], $this->rSsh->rLog, 'no node contacted');
	}

	public function testAFailingNodeIsReportedAndTheRunGoesOn(): void {
		$rServers = $this->servers();
		$rOld = [7 => $this->enrolled(7), 8 => $this->enrolled(8), 9 => $this->enrolled(9), 10 => $this->enrolled(10)];
		$this->rSsh->rNodes['10.0.0.7']['hostkey'] = sha1('rebuilt');      // host key changed
		$this->rSsh->rNodes['10.0.0.9']['ready'] = false;                  // old release
		$this->rSsh->rNodes['10.0.0.10']['probe'] = false;                 // cannot reach MAIN
		[$rCode, $rOut] = $this->reenrol($rServers, null, self::creds());
		$this->assertSame(1, $rCode, $rOut);

		$this->assertStringContainsString('#7 lb-a: failed: SSH host key changed since this node was installed', $rOut);
		$this->assertSame([], $this->rSsh->commands('10.0.0.7'), 'a node whose host key does not match gets no command, and keeps its agent');
		$this->assertSame($rOld[7]['node_uuid'], NodeRegistry::byServer(7)['node_uuid']);
		$this->assertSame(sha1('host-7'), $this->storedHostKey(7), 'the stored key is kept');

		$this->assertStringContainsString('#9 lb-c: failed: The node does not run this panel release yet', $rOut);
		$this->assertSame($rOld[9]['node_uuid'], NodeRegistry::byServer(9)['node_uuid']);

		$this->assertMatchesRegularExpression("/#10 lb-d: failed: The node cannot reach MAIN's cluster API \\(http:\\/\\/10\\.0\\.0\\.1:25461\\/cluster\\/v1\\/\\): xc_agent probe: connection refused/", $rOut);
		$this->assertSame($rOld[10]['node_uuid'], NodeRegistry::byServer(10)['node_uuid'], 'no token was minted');

		$this->assertNotSame($rOld[8]['node_uuid'], NodeRegistry::byServer(8)['node_uuid'], 'the run went on past the failures');
		$this->assertStringContainsString('#8 lb-b: re-enrolled', $rOut);
		$this->assertStringContainsString('1 re-enrolled, 3 failed, 0 not attempted, 0 skipped', $rOut);
		$this->assertSame([['ok' => [8], 'failed' => [7, 9, 10]]], $this->audit('cluster.reenrol'));
	}

	public function testAnErrorFailsOnlyItsNode(): void {
		$rServers = $this->servers();
		$this->enrolled(7);
		$this->enrolled(8);
		$this->rSsh->rHook = static function (string $rHost, string $rCommand): void {
			if ($rHost === '10.0.0.7' && str_contains($rCommand, ' keygen ')) {
				throw new \RuntimeException('channel died');
			}
		};
		[$rCode, $rOut] = $this->reenrol($rServers, null, self::creds());
		$this->assertSame(1, $rCode, $rOut);
		$this->assertStringContainsString('#7 lb-a: failed: error: channel died', $rOut);
		$this->assertStringContainsString('#8 lb-b: re-enrolled', $rOut, 'the run went on');
		$this->assertContains('close 10.0.0.7', $this->rSsh->rLog, 'the session was closed');
		$this->assertSame([['ok' => [8], 'failed' => [7]]], $this->audit('cluster.reenrol'));

		$this->rSsh->rHook = null;
		[$rCode, $rOut] = $this->reenrol($rServers, [7], self::creds(), false, []);
		$this->assertSame(0, $rCode, 'the node is free for the next run: ' . $rOut);
	}

	public function testAFlowThatEnrolsNothingIsAFailure(): void {
		$rServers = $this->servers();
		$rOld = $this->enrolled(7);
		// No xc_agent on the node (none from its release): provisionCluster returns true without enrolling it.
		$this->rSsh->rNodes['10.0.0.7']['agent'] = false;
		[$rCode, $rOut] = $this->reenrol($rServers, [7], self::creds(), false, []);
		$this->assertSame(1, $rCode, $rOut);
		$this->assertStringContainsString('#7 lb-a: failed: No xc_agent on the node (Failed to download xc_agent-linux-amd64); the node was not re-enrolled and keeps its previous identity', $rOut);
		$this->assertSame($rOld['node_uuid'], NodeRegistry::byServer(7)['node_uuid']);
		$this->assertSame([['ok' => [], 'failed' => [7]]], $this->audit('cluster.reenrol'));
	}

	public function testANodeRevokedDuringTheRunStaysRevoked(): void {
		$rServers = $this->servers();
		$this->enrolled(7);
		$rOld = [8 => $this->enrolled(8), 9 => $this->enrolled(9)];
		$this->enrolled(10);
		$this->enrolled(12);
		// While #7 is re-enrolled, an admin revokes #8, the API quarantines
		// #9, and #10 is removed from the cluster.
		$this->rSsh->rHook = function (string $rHost, string $rCommand): void {
			if ($rHost === '10.0.0.7' && str_contains($rCommand, ' install ')) {
				NodeRegistry::revoke(8, $this->rCrypto);
				NodeRegistry::update(9, ['state' => 'quarantined']);
				$this->rDb->query('DELETE FROM `cluster_nodes` WHERE `server_id` = 10');
			}
		};
		[$rCode, $rOut] = $this->reenrol($rServers, null, self::creds());
		$this->assertSame(0, $rCode, $rOut);
		$this->assertStringContainsString('#7 lb-a: re-enrolled', $rOut);
		$this->assertStringContainsString('#8 lb-b: skipped: revoked since the run started (name it to re-enrol it)', $rOut);
		$this->assertStringContainsString('#9 lb-c: skipped: quarantined since the run started', $rOut);
		$this->assertStringContainsString('#10 lb-d: skipped: no longer enrolled', $rOut);
		$this->assertStringContainsString('#12 lb-e: re-enrolled', $rOut);
		$this->assertSame('revoked', NodeRegistry::byServer(8)['state'], 'the revocation stands');
		$this->assertSame($rOld[8]['node_uuid'], NodeRegistry::byServer(8)['node_uuid']);
		$this->assertSame($rOld[9]['node_uuid'], NodeRegistry::byServer(9)['node_uuid']);
		$this->assertNull(NodeRegistry::byServer(10));
		foreach (['8', '9', '10'] as $rID) {
			$this->assertNotContains('connect 10.0.0.' . $rID . ':22', $this->rSsh->rLog);
		}
		$this->assertSame([['ok' => [7, 12], 'failed' => []]], $this->audit('cluster.reenrol'));

		// A node named by id is taken whatever its state, but one that is no
		// longer enrolled is not.
		$this->rSsh->rHook = function (string $rHost, string $rCommand): void {
			if ($rHost === '10.0.0.7' && str_contains($rCommand, ' install ')) {
				$this->rDb->query('DELETE FROM `cluster_nodes` WHERE `server_id` = 12');
			}
		};
		[$rCode, $rOut] = $this->reenrol($rServers, [7, 8, 12], self::creds(), false, []);
		$this->assertSame(1, $rCode, $rOut);
		$this->assertStringContainsString('#8 lb-b: re-enrolled', $rOut);
		$this->assertStringContainsString('#12 lb-e: not attempted: no longer enrolled', $rOut);
	}

	public function testEachNodesPortAndPasswordAreUsed(): void {
		$rServers = $this->servers();
		foreach ([7, 8, 9] as $rID) {
			$this->enrolled($rID);
		}
		$this->rSsh->rNodes['10.0.0.8']['password'] = 'other';
		$rCreds = self::creds([7 => ['port' => 2222], 8 => ['password' => 'other', 'port' => 2022]]);
		$rCreds['default']['port'] = 2020;
		[$rCode, $rOut] = $this->reenrol($rServers, null, $rCreds);
		$this->assertSame(0, $rCode, $rOut);
		$this->assertContains('connect 10.0.0.7:2222', $this->rSsh->rLog, "the node's port");
		$this->assertContains('connect 10.0.0.8:2022', $this->rSsh->rLog);
		$this->assertContains('connect 10.0.0.9:2020', $this->rSsh->rLog, "the file's default port");
		$this->assertStringContainsString('3 re-enrolled, 0 failed', $rOut, "#8 logged in with its own password");
	}

	public function testTheHostKeyIsNeverTrustedOnFirstUse(): void {
		$rServers = $this->servers();
		$this->enrolled(7);
		$this->enrolled(8);
		// Neither node has a stored key; only #8 gets one in the credential file.
		foreach ([7, 8] as $rID) {
			$rServers[$rID]['ssh_hostkey_sha1'] = null;
			$this->rDb->query('UPDATE `servers` SET `ssh_hostkey_sha1` = NULL WHERE `id` = ?', $rID);
		}
		$rKeygen = '256 SHA1:' . rtrim(base64_encode(sha1('host-8', true)), '=') . ' root@lb-b (ED25519)';
		$rCreds = ClusterReenrolCommand::parseCredentials((string) json_encode(['u' => 'root', 'p' => 'pw', 'nodes' => ['8' => ['hostkey' => $rKeygen]]]));
		$this->assertIsArray($rCreds);
		[$rCode, $rOut] = $this->reenrol($rServers, null, $rCreds);
		$this->assertSame(1, $rCode, $rOut);
		$this->assertStringContainsString('#7 lb-a: not attempted: no SSH host key to check', $rOut);
		$this->assertNotContains('connect 10.0.0.7:22', $this->rSsh->rLog, 'no connection at all to a node whose key is unknown');
		$this->assertStringContainsString('#8 lb-b: re-enrolled', $rOut);
		$this->assertSame(sha1('host-8'), $this->storedHostKey(8), 'the checked key is stored');

		// The credential file's key wins over the stored one, as --expect-hostkey does.
		$rCreds = self::creds([12 => ['hostkey' => sha1('other')]]);
		$this->enrolled(12);
		[$rCode, $rOut] = $this->reenrol($rServers, [12], $rCreds);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('#12 lb-e: failed: SSH host key mismatch: expected ' . sha1('other') . ', got ' . sha1('host-12'), $rOut);
	}

	public function testStateSelectionAndNamedNodes(): void {
		$rServers = $this->servers();
		$this->enrolled(7);
		$rRevoked = $this->enrolled(8, 'revoked');
		$rQuarantined = $this->enrolled(9, 'quarantined');
		$this->enrolled(13); // its server was deleted
		[$rCode, $rOut] = $this->reenrol($rServers, null, self::creds());
		$this->assertSame(0, $rCode, 'skipped nodes are not failures');
		$this->assertStringContainsString('#8 lb-b: skipped: revoked', $rOut);
		$this->assertStringContainsString('#9 lb-c: skipped: quarantined', $rOut);
		$this->assertStringContainsString('#13: skipped: no load balancer with this id', $rOut);
		$this->assertSame($rRevoked['node_uuid'], NodeRegistry::byServer(8)['node_uuid'], 'a revoked node stays revoked');
		$this->assertSame('revoked', NodeRegistry::byServer(8)['state']);
		$this->assertSame($rQuarantined['node_uuid'], NodeRegistry::byServer(9)['node_uuid']);
		$this->assertStringContainsString('1 re-enrolled, 0 failed, 0 not attempted, 3 skipped', $rOut);

		[$rCode, $rOut] = $this->reenrol($rServers, null, self::creds(), false, ['quarantined']);
		$this->assertSame(0, $rCode, $rOut);
		$this->assertNotSame($rQuarantined['node_uuid'], NodeRegistry::byServer(9)['node_uuid'], '--state=quarantined takes it');
		$this->assertStringContainsString('#7 lb-a: skipped: enrolling', $rOut, '#7 was re-enrolled above and is enrolling now');

		// A node named by id is taken whatever its state; a server that is not enrolled is not.
		// MAIN is SERVER_ID's row, whichever id an earlier test gave the constant.
		[$rCode, $rOut] = $this->reenrol($rServers, [99, 10, SERVER_ID, 8], self::creds());
		$this->assertSame(1, $rCode);
		$this->assertNotSame($rRevoked['node_uuid'], NodeRegistry::byServer(8)['node_uuid']);
		$this->assertSame('enrolling', NodeRegistry::byServer(8)['state']);
		$this->assertStringContainsString('#10 lb-d: not attempted: not enrolled (server:enrol enrols it)', $rOut);
		$this->assertStringContainsString('#' . SERVER_ID . ' Main: not attempted: not a load balancer', $rOut);
		$this->assertStringContainsString('#99: not attempted: not a load balancer', $rOut);
		$this->assertNotContains('connect 10.0.0.10:22', $this->rSsh->rLog);
		$rOrder = [SERVER_ID => '#' . SERVER_ID . ' Main: ', 8 => '#8 lb-b: ', 10 => '#10 lb-d: ', 99 => '#99: '];
		ksort($rOrder);
		$this->assertMatchesRegularExpression('/^' . implode('.*\n', array_map(fn (string $rLine): string => preg_quote($rLine, '/'), $rOrder)) . '/m', $rOut, 'reported in server id order');
	}

	public function testDryRunContactsNoNode(): void {
		$rServers = $this->servers();
		$rOld = [7 => $this->enrolled(7), 8 => $this->enrolled(8), 9 => $this->enrolled(9)];
		$rServers[9]['ssh_hostkey_sha1'] = null;
		$rCreds = self::creds([8 => ['username' => 'admin', 'port' => 2222, 'hostkey' => sha1('host-8')]]);
		$rCreds['default']['port'] = 2022;
		[$rCode, $rOut] = $this->reenrol($rServers, null, $rCreds, true);
		$this->assertSame(1, $rCode, 'a node that could not be attempted fails the dry run too');
		$this->assertSame([], $this->rSsh->rLog, 'no node is contacted');
		foreach ($rOld as $rID => $rNode) {
			$this->assertSame($rNode['node_uuid'], NodeRegistry::byServer($rID)['node_uuid']);
		}
		$this->assertStringContainsString('#7 lb-a: would re-enrol as root@10.0.0.7:2022, host key ' . sha1('host-7') . ' (stored at its install)', $rOut, "the file's default port");
		$this->assertStringContainsString('#8 lb-b: would re-enrol as admin@10.0.0.8:2222, host key ' . sha1('host-8') . ' (credential file)', $rOut);
		$this->assertStringContainsString('#9 lb-c: not attempted: no SSH host key to check', $rOut);
		$this->assertStringContainsString('2 would be re-enrolled, 1 not attempted, 0 skipped', $rOut);
		$this->assertSame([], $this->audit('cluster.reenrol'));

		// The file's top-level port is every other node's default; 22 without one.
		[, $rOut] = $this->reenrol($rServers, [9], self::creds([9 => ['hostkey' => sha1('host-9')]]), true);
		$this->assertStringContainsString('root@10.0.0.9:22,', $rOut);
		[, $rOut] = $this->reenrol($rServers, [9], ['default' => ['username' => 'root', 'password' => 'pw', 'port' => 2022], 'nodes' => [9 => ['hostkey' => sha1('host-9')]]], true);
		$this->assertStringContainsString('root@10.0.0.9:2022,', $rOut);

		// Without a credential file a dry run still shows the host keys.
		[$rCode, $rOut] = $this->reenrol($rServers, [7, 8], null, true);
		$this->assertSame(0, $rCode, $rOut);
		$this->assertStringContainsString('#8 lb-b: would re-enrol (no credential file given)', $rOut);
	}

	public function testNodesWithoutCredentialsAreNotAttempted(): void {
		$rServers = $this->servers();
		$this->enrolled(7);
		$this->enrolled(8);
		$rCreds = ['default' => [], 'nodes' => [8 => ['username' => 'root', 'password' => 'pw']]];
		[$rCode, $rOut] = $this->reenrol($rServers, null, $rCreds);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('#7 lb-a: not attempted: no SSH credentials for it in the credential file', $rOut);
		$this->assertStringContainsString('#8 lb-b: re-enrolled', $rOut);
		$this->assertNotContains('connect 10.0.0.7:22', $this->rSsh->rLog);

		// A password without a user is no credential either.
		[$rCode, $rOut] = $this->reenrol($rServers, [7], ['default' => ['password' => 'pw'], 'nodes' => []], false, []);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('#7 lb-a: not attempted: no SSH credentials for it in the credential file', $rOut);
	}

	public function testALicenceRefusalStopsTheRun(): void {
		$rServers = $this->servers();
		$rOld = [7 => $this->enrolled(7), 8 => $this->enrolled(8)];

		// Unlicensed from the start: nothing is touched.
		$this->rCrypto->rLicensed = false;
		[$rCode, $rOut] = $this->reenrol($rServers, null, self::creds());
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('CLUSTER_LICENCE_REQUIRED', $rOut);
		$this->assertSame([], $this->rSsh->rLog);

		// Refused on the first node: the others are left alone, since each
		// would have its agent stopped only to be refused the same way.
		$this->rCrypto->rLicensed = true;
		$this->rCrypto->rRefuseIssue = 'LICENCE';
		[$rCode, $rOut] = $this->reenrol($rServers, null, self::creds());
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('#7 lb-a: failed: CLUSTER_LICENCE_REQUIRED', $rOut);
		$this->assertStringContainsString('#8 lb-b: not attempted: the run stopped at the licence refusal', $rOut);
		$this->assertNotContains('connect 10.0.0.8:22', $this->rSsh->rLog);
		$this->assertSame($rOld[8]['node_uuid'], NodeRegistry::byServer(8)['node_uuid']);
		$this->assertSame([['ok' => [], 'failed' => [7]]], $this->audit('cluster.reenrol'));
	}

	public function testNothingToReenrol(): void {
		[$rCode, $rOut] = $this->reenrol($this->servers(), null, self::creds());
		$this->assertSame(0, $rCode);
		$this->assertStringContainsString('No enrolled node to re-enrol', $rOut);
	}

	public function testCredentialFileRules(): void {
		$rPath = $this->rDir . 'fleet.cred';
		$rJson = (string) json_encode(['u' => 'root', 'p' => 'pw', 'port' => 22, 'nodes' => ['7' => ['p' => 'other', 'port' => 2222, 'hostkey' => implode(':', str_split(strtoupper(sha1('host-7')), 2))], '8' => ['u' => 'admin']]]);
		$rWrite = function (string $rContent, int $rMode = 0600) use ($rPath): void {
			file_put_contents($rPath, $rContent);
			chmod($rPath, $rMode);
		};

		$rWrite($rJson);
		$rExpected = [
			'default' => ['username' => 'root', 'password' => 'pw', 'port' => 22],
			'nodes' => [7 => ['password' => 'other', 'port' => 2222, 'hostkey' => sha1('host-7')], 8 => ['username' => 'admin']],
		];
		$this->assertSame($rExpected, ClusterReenrolCommand::readCredentials($rPath, true, $this->rDir));
		$this->assertFileExists($rPath, 'a dry run keeps it for the real run');
		$this->assertSame($rExpected, ClusterReenrolCommand::readCredentials($rPath, false, $this->rDir));
		$this->assertFileDoesNotExist($rPath, 'deleted once read');

		$rWrite($rJson, 0640);
		$this->assertSame('its group or others can open it (chmod 600 it)', ClusterReenrolCommand::readCredentials($rPath, true, $this->rDir), 'readable by others: refused');
		$this->assertFileExists($rPath, 'a dry run keeps it');
		$this->assertStringContainsString('so it was deleted', (string) ClusterReenrolCommand::readCredentials($rPath, false, $this->rDir));
		$this->assertFileDoesNotExist($rPath, 'a real run deletes it: its secrets are out already');

		mkdir($this->rDir . 'sub', 0700);
		file_put_contents($this->rDir . 'sub/fleet.cred', $rJson);
		chmod($this->rDir . 'sub/fleet.cred', 0600);
		$this->assertIsString(ClusterReenrolCommand::readCredentials($this->rDir . 'sub/fleet.cred', false, $this->rDir), 'below bin/install: refused');
		$this->assertFileExists($this->rDir . 'sub/fleet.cred', 'and not deleted');
		unlink($this->rDir . 'sub/fleet.cred');
		rmdir($this->rDir . 'sub');

		$rOutside = sys_get_temp_dir() . '/xcvm_' . uniqid('', true) . '.cred';
		file_put_contents($rOutside, $rJson);
		chmod($rOutside, 0600);
		$this->assertIsString(ClusterReenrolCommand::readCredentials($rOutside, false, $this->rDir), 'outside bin/install: refused');
		$this->assertFileExists($rOutside, 'and not deleted either');
		@unlink($rOutside);
		file_put_contents($this->rDir . 'fleet.json', $rJson);
		chmod($this->rDir . 'fleet.json', 0600);
		$this->assertIsString(ClusterReenrolCommand::readCredentials($this->rDir . 'fleet.json', false, $this->rDir), 'not a .cred');

		// A single server:install credential ({"u", "p"}) is every node's default.
		$this->assertSame(['default' => ['username' => 'root', 'password' => 'pw'], 'nodes' => []], ClusterReenrolCommand::parseCredentials('{"u":"root","p":"pw"}'));
		foreach ([
			'not json',
			'[]',
			'{"u":"root","pasword":"pw"}',
			'{"u":"root","p":"pw","port":0}',
			'{"u":"root","p":"pw","port":"22"}',
			'{"u":"root","p":"pw","hostkey":"' . sha1('x') . '"}',
			'{"u":"root","p":"pw","nodes":{"7":{"hostkey":"SHA256:abc"}}}',
			'{"u":"root","p":"pw","nodes":{"lb-a":{"p":"x"}}}',
			'{"u":"root","p":"pw","nodes":{"7":{"p":1}}}',
			'{"u":"root","p":"pw","nodes":[{"p":"x"}]}',
		] as $rBad) {
			$this->assertIsString(ClusterReenrolCommand::parseCredentials($rBad), $rBad);
		}
	}

	public function testArguments(): void {
		$this->assertSame(['ids' => null, 'states' => ['enrolling', 'active'], 'cred-file' => '/x/f.cred', 'dry-run' => false, 'pending' => false], ClusterReenrolCommand::parseArgs(['--all', '--cred-file=/x/f.cred']));
		$this->assertSame(['ids' => null, 'states' => ['quarantined', 'active'], 'cred-file' => null, 'dry-run' => true, 'pending' => false], ClusterReenrolCommand::parseArgs(['--all', '--state=quarantined,active', '--dry-run']));
		$this->assertSame(['ids' => [7, 9], 'states' => [], 'cred-file' => '/x/f.cred', 'dry-run' => false, 'pending' => false], ClusterReenrolCommand::parseArgs(['7', '9', '7', '--cred-file=/x/f.cred']));
		$this->assertSame(['ids' => null, 'states' => ['enrolling', 'active'], 'cred-file' => '/x/f.cred', 'dry-run' => false, 'pending' => true], ClusterReenrolCommand::parseArgs(['--all', '--pending', '--cred-file=/x/f.cred']));
		foreach ([
			[],
			['--cred-file=/x/f.cred'],
			['--all', '7', '--cred-file=/x/f.cred'],
			['--all'],
			['--all', '--state=bogus', '--dry-run'],
			['7', '--state=active', '--cred-file=/x/f.cred'],
			['seven', '--cred-file=/x/f.cred'],
			['0', '--cred-file=/x/f.cred'],
			['--all', '--force', '--cred-file=/x/f.cred'],
			['7', '--pending', '--cred-file=/x/f.cred'],
			['--all', '--cred-file=/x/f.cred', '--expect-hostkey=' . sha1('x')],
		] as $rArgs) {
			$this->assertIsString(ClusterReenrolCommand::parseArgs($rArgs), implode(' ', $rArgs));
		}
	}

	public function testTheCommandFromItsArguments(): void {
		$rServers = $this->servers();
		$rOld = [7 => $this->enrolled(7), 8 => $this->enrolled(8)];

		// A dry run keeps the file and contacts no node.
		$rPath = $this->credFile();
		[$rCode, $rOut] = $this->main($rServers, ['--all', '--dry-run', '--cred-file=' . $rPath]);
		$this->assertSame(0, $rCode, $rOut);
		$this->assertStringContainsString('2 would be re-enrolled', $rOut);
		$this->assertSame([], $this->rSsh->rLog);
		$this->assertFileExists($rPath);
		foreach ($rOld as $rID => $rNode) {
			$this->assertSame($rNode['node_uuid'], NodeRegistry::byServer($rID)['node_uuid']);
		}

		// The same run for real deletes it and re-enrols both nodes.
		[$rCode, $rOut] = $this->main($rServers, ['--all', '--cred-file=' . $rPath]);
		$this->assertSame(0, $rCode, $rOut);
		$this->assertFileDoesNotExist($rPath);
		$this->assertStringContainsString('2 re-enrolled, 0 failed', $rOut);
		foreach ($rOld as $rID => $rNode) {
			$this->assertNotSame($rNode['node_uuid'], NodeRegistry::byServer($rID)['node_uuid']);
		}
	}

	public function testARefusedRunStillDeletesTheCredentialFile(): void {
		$rServers = $this->servers();
		$rOld = $this->enrolled(7);

		SettingsManager::set(['cluster_api_enabled' => 0]);
		$rPath = $this->credFile();
		[$rCode, $rOut] = $this->main($rServers, ['--all', '--dry-run', '--cred-file=' . $rPath]);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('The cluster API is disabled', $rOut);
		$this->assertFileExists($rPath, 'a dry run keeps it');
		[$rCode, $rOut] = $this->main($rServers, ['--all', '--cred-file=' . $rPath]);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('The cluster API is disabled', $rOut);
		$this->assertFileDoesNotExist($rPath, 'a real run deletes it whatever it refuses');
		SettingsManager::set(['cluster_api_enabled' => 1, 'lb_token_rotation_min' => 60, 'lb_new_node_mode' => 'legacy']);

		$rPath = $this->credFile();
		[$rCode, $rOut] = $this->main($rServers, ['--all', '--cred-file=' . $rPath, '--expect-hostkey=' . sha1('x')]);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString("Unknown option '--expect-hostkey'", $rOut);
		$this->assertFileDoesNotExist($rPath);

		$rPath = $this->credFile(0644);
		[$rCode, $rOut] = $this->main($rServers, ['--all', '--cred-file=' . $rPath]);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('Credential file refused: its group or others could open it, so it was deleted', $rOut);
		$this->assertFileDoesNotExist($rPath);

		// A file anywhere else is refused and never deleted.
		$rOutside = sys_get_temp_dir() . '/xcvm_' . uniqid('', true) . '.cred';
		file_put_contents($rOutside, '{"u":"root","p":"pw"}');
		chmod($rOutside, 0600);
		[$rCode, $rOut] = $this->main($rServers, ['--all', '--cred-file=' . $rOutside]);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('Credential file refused: it must be a .cred file in', $rOut);
		$this->assertFileExists($rOutside);
		@unlink($rOutside);

		$this->assertSame([], $this->rSsh->rLog, 'no node was contacted');
		$this->assertSame($rOld['node_uuid'], NodeRegistry::byServer(7)['node_uuid']);
	}

	public function testOneRunAtATime(): void {
		$rServers = $this->servers();
		$this->enrolled(7);
		$rLock = fopen(ClusterReenrolCommand::lockPath(), 'c');
		$this->assertTrue(flock($rLock, LOCK_EX | LOCK_NB));
		try {
			[$rCode, $rOut] = $this->main($rServers, ['--all', '--dry-run']);
			$this->assertSame(1, $rCode);
			$this->assertStringContainsString('Another cluster:reenrol is running', $rOut);
		} finally {
			flock($rLock, LOCK_UN);
			fclose($rLock);
		}
		[$rCode, $rOut] = $this->main($rServers, ['--all', '--dry-run']);
		$this->assertSame(0, $rCode, $rOut);
	}

	private function storedHostKey(int $rServerID): ?string {
		$this->rDb->query('SELECT `ssh_hostkey_sha1` FROM `servers` WHERE `id` = ?', $rServerID);
		return $this->rDb->get_row()['ssh_hostkey_sha1'];
	}
}
