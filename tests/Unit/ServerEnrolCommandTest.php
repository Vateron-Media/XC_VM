<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ServerEnrolCommand;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;
use XcVm\Tests\Support\FakeSshFleet;

/**
 * ServerEnrolCommand::enrol() — server:enrol's path for one node, which
 * cluster:reenrol runs node by node. The SSH layer is faked (FakeSshFleet).
 * The host key is checked before the password is sent and never trusted on
 * first use; a node that fails keeps its identity; the reason comes back to
 * the caller; the session is always closed.
 */
final class ServerEnrolCommandTest extends TestCase {
	private TestDb $rDb;

	private FakeClusterCrypto $rCrypto;

	private FakeSshFleet $rSsh;

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
		$this->rDb->exec("INSERT INTO `servers` (`id`, `ssh_hostkey_sha1`) VALUES (7, '" . sha1('host-7') . "')");
		DatabaseFactory::set($this->rDb);
		SettingsManager::set(['cluster_api_enabled' => 1, 'lb_token_rotation_min' => 60, 'lb_new_node_mode' => 'legacy']);
		ClusterClock::fix(null);
		$this->rCrypto = new FakeClusterCrypto();
		$this->rSsh = new FakeSshFleet();
		$this->rSsh->rNodes['10.0.0.7'] = ['hostkey' => sha1('host-7'), 'password' => 'pw'];
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		SettingsManager::set([]);
	}

	/** MAIN #1, the LB #7 (host key stored at its install) and the proxy #4. */
	private function servers(?string $rStored = null): array {
		return [
			SERVER_ID => ['id' => SERVER_ID, 'is_main' => 1, 'server_type' => 0, 'server_ip' => '10.0.0.1', 'http_broadcast_port' => 25461, 'enable_https' => 0],
			4 => ['id' => 4, 'is_main' => 0, 'server_type' => 1, 'server_ip' => '10.0.0.4'],
			7 => ['id' => 7, 'is_main' => 0, 'server_type' => 0, 'server_ip' => '10.0.0.7', 'ssh_hostkey_sha1' => $rStored ?? sha1('host-7')],
		];
	}

	/** @return array{0: string|null, 1: string} [enrol()'s answer, what it printed] */
	private function enrol(array $rServers, int $rServerID = 7, string $rPassword = 'pw', ?string $rExpected = null): array {
		ob_start();
		try {
			$rWhy = ServerEnrolCommand::enrol($rServers, $rServerID, 22, ['username' => 'root', 'password' => $rPassword], $rExpected, $this->rCrypto, $this->rSsh);
		} finally {
			$rOut = (string) ob_get_clean();
		}
		return [$rWhy, $rOut];
	}

	private function storedHostKey(): ?string {
		$this->rDb->query('SELECT `ssh_hostkey_sha1` FROM `servers` WHERE `id` = 7');
		return $this->rDb->get_row()['ssh_hostkey_sha1'];
	}

	public function testEnrolsTheNodeOverSsh(): void {
		[$rWhy, $rOut] = $this->enrol($this->servers());
		$this->assertNull($rWhy, $rOut);
		$this->assertSame('enrolling', NodeRegistry::byServer(7)['state']);
		$this->assertSame(['connect 10.0.0.7:22', 'login 10.0.0.7 root'], array_slice($this->rSsh->rLog, 0, 2), 'the host key is checked before the password is sent');
		$rCommands = $this->rSsh->commands('10.0.0.7');
		$this->assertStringContainsString('echo READY', $rCommands[0], 'the release is checked first');
		$this->assertStringContainsString('bin/xc_agent/run.sh', end($rCommands), 'the agent is started last');
		$this->assertSame('close 10.0.0.7', end($this->rSsh->rLog));
		$this->assertSame(1, substr_count($rOut, 'Node enrolled (uuid '), 'the flow prints once');
	}

	public function testTheHostKeyIsNeverTrustedOnFirstUse(): void {
		$rServers = $this->servers();
		$rServers[7]['ssh_hostkey_sha1'] = null;
		[$rWhy, $rOut] = $this->enrol($rServers);
		$this->assertNotNull($rWhy);
		$this->assertStringContainsString('Trust on first use is refused for enrolment. Exiting', $rOut);
		$this->assertSame([], $this->rSsh->rLog, 'nothing is contacted');

		// A key the admin read on the node is checked, then stored.
		$this->rDb->query('UPDATE `servers` SET `ssh_hostkey_sha1` = NULL WHERE `id` = 7');
		[$rWhy, $rOut] = $this->enrol($rServers, 7, 'pw', sha1('host-7'));
		$this->assertNull($rWhy, $rOut);
		$this->assertSame(sha1('host-7'), $this->storedHostKey());
	}

	public function testAChangedHostKeyRunsNothing(): void {
		$this->rSsh->rNodes['10.0.0.7']['hostkey'] = sha1('rebuilt');
		[$rWhy, $rOut] = $this->enrol($this->servers());
		$this->assertStringStartsWith('SSH host key changed since this node was installed', (string) $rWhy);
		$this->assertStringContainsString($rWhy . ". Exiting\n", $rOut);
		$this->assertSame(['connect 10.0.0.7:22', 'close 10.0.0.7'], $this->rSsh->rLog, 'no password is sent and no command runs');
		$this->assertSame(sha1('host-7'), $this->storedHostKey());

		[$rWhy] = $this->enrol($this->servers(), 7, 'pw', sha1('other'));
		$this->assertSame('SSH host key mismatch: expected ' . sha1('other') . ', got ' . sha1('rebuilt'), $rWhy);
	}

	public function testRefusalsBeforeTheFlow(): void {
		foreach ([1 => 'Server 1 is not a load balancer', 4 => 'Server 4 is not a load balancer', 99 => 'Server 99 is not a load balancer'] as $rID => $rExpected) {
			[$rWhy] = $this->enrol($this->servers(), $rID);
			$this->assertSame($rExpected, $rWhy);
		}
		$this->assertSame([], $this->rSsh->rLog);

		[$rWhy] = $this->enrol($this->servers(), 7, 'wrong');
		$this->assertSame('Failed to authenticate over SSH', $rWhy);
		$this->assertSame([], $this->rSsh->commands('10.0.0.7'));

		$this->rSsh->rNodes['10.0.0.7']['ready'] = false;
		[$rWhy] = $this->enrol($this->servers());
		$this->assertSame('The node does not run this panel release yet (no bin/xc_agent/run.sh). Update it first, then retry', $rWhy);
		$this->assertCount(1, $this->rSsh->commands('10.0.0.7'), 'only the release check ran');

		unset($this->rSsh->rNodes['10.0.0.7']);
		[$rWhy] = $this->enrol($this->servers());
		$this->assertSame('Failed to connect to server', $rWhy);
		$this->assertNull(NodeRegistry::byServer(7));
	}

	public function testTheFlowsFailureIsTheReason(): void {
		$this->rSsh->rNodes['10.0.0.7']['probe'] = false;
		[$rWhy, $rOut] = $this->enrol($this->servers());
		$this->assertSame("The node cannot reach MAIN's cluster API (http://10.0.0.1:25461/cluster/v1/): xc_agent probe: connection refused Open the cluster API port from the LB to MAIN, then reinstall", $rWhy);
		$this->assertStringContainsString("Enrolling the node in the cluster API\nThe node cannot reach MAIN's cluster API", $rOut, 'printed as it happened');
		$this->assertSame(1, substr_count($rOut, "The node cannot reach MAIN's cluster API"), 'and only once');
		$this->assertNull(NodeRegistry::byServer(7), 'no token was minted');
		$this->rDb->query('SELECT `status` FROM `servers` WHERE `id` = 7');
		$this->assertSame(1, (int) $this->rDb->get_row()['status'], 'a live node is never marked failed');

		// The API switched off: the flow leaves the node alone, and says so.
		$this->rSsh->rNodes['10.0.0.7']['probe'] = true;
		SettingsManager::set(['cluster_api_enabled' => 0]);
		[$rWhy] = $this->enrol($this->servers());
		$this->assertSame('The cluster API is disabled; the node was not enrolled and stays legacy', $rWhy);
	}

	public function testAFlowThatEnrolsNothingIsAFailure(): void {
		// No xc_agent on the node (it could not take one from its release):
		// provisionCluster returns true and leaves the node as it was. Its
		// reason is kept, whatever the clock.
		ClusterClock::fix(1_700_000_000_000);
		$this->rSsh->rNodes['10.0.0.7']['agent'] = false;
		[$rWhy, $rOut] = $this->enrol($this->servers());
		$this->assertSame('No xc_agent on the node (Failed to download xc_agent-linux-amd64); the node was not enrolled and stays legacy', $rWhy, $rOut);
		$this->assertNull(NodeRegistry::byServer(7));

		// An enrolled node keeps its identity, even one enrolled in the same second.
		$this->rSsh->rNodes['10.0.0.7']['agent'] = true;
		$this->assertNull($this->enrol($this->servers())[0]);
		$rOld = NodeRegistry::byServer(7);
		$this->rSsh->rNodes['10.0.0.7']['agent'] = false;
		[$rWhy] = $this->enrol($this->servers());
		$this->assertSame('No xc_agent on the node (Failed to download xc_agent-linux-amd64); the node was not re-enrolled and keeps its previous identity', $rWhy);
		$this->rSsh->rNodes['10.0.0.7']['agent'] = true;
		$this->assertSame($rOld['node_uuid'], NodeRegistry::byServer(7)['node_uuid']);

		// Enrolled again in the same second: a new identity is what counts.
		$this->assertNull($this->enrol($this->servers())[0]);
		$this->assertNotSame($rOld['node_uuid'], NodeRegistry::byServer(7)['node_uuid']);
	}

	public function testOneEnrolmentOfANodeAtATime(): void {
		$rLock = fopen(ServerEnrolCommand::lockPath(7), 'c');
		$this->assertTrue(flock($rLock, LOCK_EX | LOCK_NB));
		try {
			[$rWhy] = $this->enrol($this->servers());
			$this->assertSame('Another enrolment of server 7 is running', $rWhy);
			$this->assertSame([], $this->rSsh->rLog, 'nothing is contacted');
		} finally {
			flock($rLock, LOCK_UN);
			fclose($rLock);
		}
		$this->assertNull($this->enrol($this->servers())[0], 'the lock is free once the other run ends');
		$this->assertNull($this->enrol($this->servers())[0], 'and an enrolment releases it');
	}
}
