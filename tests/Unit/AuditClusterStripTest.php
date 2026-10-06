<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\NodeActions;
use XcVm\Core\Cluster\NodeCredentials;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\ClusterBus;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Domain\Cluster\ClusterRoute;
use XcVm\Domain\Cluster\CommandBus;
use XcVm\Domain\Cluster\DbCredentials;
use XcVm\Domain\Cluster\NodeAudit;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * "Drop DB credentials" is the one step with no way back, so the node is
 * judged as it is asked, and the command does not outlive that judgement:
 * every flow on, heard a moment ago, and its own word that it reads its
 * streams on itself (a node that says nothing has not said so). The command
 * lives minutes, not the day a root command does, and one the node was not
 * handed yet is withdrawn when the node is moved down from mode 2, where it
 * needs these credentials. One it was handed already is judged once more
 * where it runs: the node gives the credentials up only while its agent's
 * file says it is in mode 2. MAIN hands it out again only while the node's
 * row says mode 2, never after a move down, and keeps its row a day past
 * its life: the revoke waits for that ack.
 */
final class AuditClusterStripTest extends TestCase {
	private const SID = 7;

	private const NOW_MS = 1800000000000;

	private TestDb $rDb;

	private array $rSettingsBefore = [];

	private string $rFlowsFile = '';

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['029_create_cluster_nodes', '030_create_cluster_commands', '032_create_cluster_audit', '039_add_cluster_node_root_ready', '041_add_cluster_node_features', '045_add_cluster_node_audit', '052_add_cluster_node_db_revoked_at'] as $rName) {
			$this->rDb->exec(InstallSchema::migration($rName));
		}
		DatabaseFactory::set($this->rDb);
		foreach ([NodeAudit::class, NodeRegistry::class, ClusterMeta::class, CommandBus::class, DbCredentials::class] as $rClass) {
			(new \ReflectionProperty($rClass, 'db'))->setValue(null, null);
		}
		ClusterClock::fix(self::NOW_MS);
		ClusterBus::useSocket(sys_get_temp_dir() . '/no-such-bus-' . bin2hex(random_bytes(4)) . '/cluster.sock');
		$this->rSettingsBefore = SettingsManager::getAll();
		SettingsManager::set(['cluster_api_enabled' => 1]);
		ClusterRoute::useCrypto(static fn() => new FakeClusterCrypto());
		NodeRegistry::startEnrolment(self::SID, '0f8fad5b-d9cb-469f-a165-70867728950e', random_bytes(32), random_bytes(32), 2);
		// A settled mode 2 node: every flow, root's pin, heard two seconds ago, reading its streams on itself.
		NodeRegistry::update(self::SID, ['state' => 'active', 'flows' => ClusterAdmin::MODE2_FLOWS, 'root_ready' => 1, 'last_seen_at' => self::NOW_MS - 2000, 'audit' => '{"settings_misses":{},"streams_local":true}']);
		ClusterMeta::set(ClusterAdmin::MODE2_AT . self::SID, (string) (ClusterClock::now() - ClusterAdmin::CUTOVER_CLEAN_DAYS * 86400));
	}

	protected function tearDown(): void {
		DbCredentials::useRevoke(null);
		NodeCredentials::useExtension(null);
		NodeFlows::usePath(null);
		NodeRole::useMainBuild(null);
		if ($this->rFlowsFile !== '') {
			@unlink($this->rFlowsFile);
		}
		ClusterRoute::useCrypto(null);
		ClusterBus::useSocket(null);
		SettingsManager::set($this->rSettingsBefore);
		ClusterClock::fix(null);
		DatabaseFactory::reset();
	}

	/** @return list<array<string, mixed>> the node's strip commands, oldest first */
	private function strips(): array {
		$this->rDb->query('SELECT `state`, `exp`, `created_at` FROM `cluster_commands` WHERE `server_id` = ? AND `action` = ? ORDER BY `seq`', self::SID, NodeCredentials::STRIP);
		return $this->rDb->get_rows();
	}

	/** @return list<string> the root actions a poll from seq 0 hands the node, oldest first */
	private function handedOut(): array {
		return array_map(static fn(array $rCmd): string => json_decode($rCmd['doc'], true)['action'], CommandBus::pending(self::SID, 0));
	}

	private function act(string $rAction): string {
		$rServers = [1 => ['is_main' => 1, 'server_type' => 0], self::SID => ['is_main' => 0, 'server_type' => 0, 'server_name' => 'lb-7']];
		$rMode = (string) NodeRegistry::byServer(self::SID)['mode'];
		return ClusterAdmin::act(new FakeClusterCrypto(), ['cluster_action' => $rAction, 'server_id' => self::SID, 'mode' => $rMode], $rServers, 1, [], 3)['message'];
	}

	public function testASettledNodeIsAskedAndTheCommandLivesMinutes(): void {
		$this->assertNull(DbCredentials::strip(self::SID));
		$rStrips = $this->strips();
		$this->assertCount(1, $rStrips);
		$this->assertSame('queued', $rStrips[0]['state']);
		$this->assertSame(DbCredentials::STRIP_TTL, (int) $rStrips[0]['exp'] - (int) $rStrips[0]['created_at']);
		$this->assertLessThanOrEqual(900, DbCredentials::STRIP_TTL, 'minutes: the node was judged as it was asked');

		// Any other root action keeps its day.
		$this->assertTrue(NodeActions::reloadNginx(self::SID));
		$this->rDb->query("SELECT `exp` - `created_at` AS `life` FROM `cluster_commands` WHERE `action` = 'reload_nginx'");
		$this->assertSame(CommandBus::TTL['node.root'], (int) $this->rDb->get_row()['life']);
	}

	public function testANodeNotHeardAMomentAgoIsNotAsked(): void {
		NodeRegistry::update(self::SID, ['last_seen_at' => self::NOW_MS - 11000]);
		$this->assertSame('cluster_strip_not_heard', DbCredentials::strip(self::SID), 'its last report is as old as its last heartbeat');
		NodeRegistry::update(self::SID, ['last_seen_at' => null]);
		$this->assertSame('cluster_strip_not_heard', DbCredentials::strip(self::SID), 'never heard');
		$this->assertSame([], $this->strips());
		$this->rDb->query("SELECT COUNT(*) AS `n` FROM `cluster_audit` WHERE `event` = 'node.strip_credentials'");
		$this->assertSame(0, (int) $this->rDb->get_row()['n'], 'nothing was asked of the node');
	}

	public function testANodeThatDoesNotSayItReadsItsStreamsItselfIsNotAsked(): void {
		// A node with STREAMS off says nothing of its streams.
		NodeRegistry::update(self::SID, ['audit' => '{"settings_misses":{}}']);
		$this->assertSame('cluster_strip_not_local', DbCredentials::strip(self::SID), 'no word is not a yes');
		NodeRegistry::update(self::SID, ['audit' => null]);
		$this->assertSame('cluster_strip_not_local', DbCredentials::strip(self::SID), 'no report at all');
		NodeRegistry::update(self::SID, ['audit' => '{"settings_misses":{},"streams_local":false}']);
		$this->assertSame('cluster_strip_not_local', DbCredentials::strip(self::SID));
		$this->assertSame([], $this->strips());
	}

	public function testANodeShortOfAFlowIsNotAsked(): void {
		NodeRegistry::update(self::SID, ['flows' => ClusterAdmin::MODE2_FLOWS & ~NodeRegistry::FLOW_TELEMETRY]);
		$this->assertSame('cluster_mode_needs_flows', DbCredentials::strip(self::SID));
		$this->assertSame([], $this->strips());
	}

	public function testMovingTheNodeDownWithdrawsADropItWasNotHandedYet(): void {
		$this->assertNull(DbCredentials::strip(self::SID));
		$this->assertTrue(NodeActions::reloadNginx(self::SID));
		$this->assertSame('cluster_mode_done', $this->act('mode_down'));
		$this->assertSame(['reload_nginx'], $this->handedOut(), 'in mode 1 the node needs these credentials');
		// Its life ended with the move: back in mode 2 it does not go out either.
		NodeRegistry::update(self::SID, ['mode' => 2]);
		$this->assertSame(['reload_nginx'], $this->handedOut());
		CommandBus::prune();
		$this->assertSame([], $this->strips(), 'never handed out: gone at the next prune');
		$this->rDb->query("SELECT COUNT(*) AS `n` FROM `cluster_commands` WHERE `action` = 'reload_nginx'");
		$this->assertSame(1, (int) $this->rDb->get_row()['n'], 'its other commands stay');
	}

	public function testADropTheNodeWasHandedStaysForItsAck(): void {
		$this->assertNull(DbCredentials::strip(self::SID));
		$this->assertCount(1, CommandBus::pending(self::SID, 0));
		$this->assertSame('cluster_mode_done', $this->act('mode_down'));
		$this->assertSame(['delivered'], array_column($this->strips(), 'state'), 'the node may have run it: its ack still says what its config holds');
	}

	public function testADropTheNodeWasHandedGoesOutNoMoreBelowModeTwo(): void {
		$this->assertNull(DbCredentials::strip(self::SID));
		$this->assertTrue(NodeActions::reloadNginx(self::SID));
		CommandBus::enqueue(new FakeClusterCrypto(), self::SID, 'resync', []);
		$rActions = static fn(array $rCommands): array => array_map(static fn(array $rCmd): string => json_decode($rCmd['doc'], true)['action'] ?? json_decode($rCmd['doc'], true)['type'], $rCommands);
		$this->assertSame([NodeCredentials::STRIP, 'reload_nginx', 'resync'], $rActions(CommandBus::pending(self::SID, 0)), 'handed out; say the reply was lost, so the agent asks from 0 again');
		$this->assertSame('cluster_mode_done', $this->act('mode_down'));
		$this->assertSame(['reload_nginx', 'resync'], $rActions(CommandBus::pending(self::SID, 0)), 'in mode 1 the node needs these credentials; its other commands still go out');
		$this->assertSame(['delivered'], array_column($this->strips(), 'state'), 'the row stays: the node may have run it before the move');
	}

	public function testADropIsHandedOutOnlyWhileTheNodesRowSaysModeTwo(): void {
		$this->assertNull(DbCredentials::strip(self::SID));
		$this->assertTrue(NodeActions::reloadNginx(self::SID));
		// The row a move down leaves when its withdrawal ran before the strip was stored.
		NodeRegistry::update(self::SID, ['mode' => 1]);
		$this->assertSame(['reload_nginx'], $this->handedOut(), 'held back; its other commands go out');
		$this->assertSame(['queued'], array_column($this->strips(), 'state'), 'and not marked as handed out');
		NodeRegistry::update(self::SID, ['mode' => 2]);
		$this->assertSame([NodeCredentials::STRIP, 'reload_nginx'], $this->handedOut(), 'in mode 2 it goes out as before');
		$this->assertSame(['delivered'], array_column($this->strips(), 'state'));
		// Handed out, and the row leaves mode 2 with nothing ending the strip.
		NodeRegistry::update(self::SID, ['mode' => 1]);
		$this->assertSame(['reload_nginx'], $this->handedOut(), 'not a second time');
	}

	public function testADropTheNodeWasHandedKeepsItsRowForALateAck(): void {
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `server_ip` varchar(255))');
		$this->rDb->exec("INSERT INTO `servers` (`id`, `server_ip`) VALUES (" . self::SID . ", '10.0.0.7')");
		$rRevoked = [];
		DbCredentials::useRevoke(static function (string $rHost) use (&$rRevoked): bool {
			$rRevoked[] = $rHost;
			return true;
		});
		$this->assertNull(DbCredentials::strip(self::SID));
		$this->assertTrue(NodeActions::reloadNginx(self::SID));
		$rCmd = json_decode(CommandBus::pending(self::SID, 0)[0]['doc'], true);
		// Two with the same life: another command the node was handed, and a strip it was not.
		$this->rDb->query("UPDATE `cluster_commands` SET `exp` = ? WHERE `action` = 'reload_nginx'", $rCmd['exp']);
		$this->assertNull(DbCredentials::strip(self::SID));
		$this->assertSame(['delivered', 'queued'], array_column($this->strips(), 'state'));

		// Root on the node runs a command up to 300 s past its exp (RootPin::verify): the ack comes after it.
		ClusterClock::fix(($rCmd['exp'] + 1) * 1000);
		CommandBus::prune();
		$this->assertSame(['delivered'], array_column($this->strips(), 'state'), 'handed out: the row waits for its ack; never handed out: gone at exp');
		$this->rDb->query("SELECT COUNT(*) AS `n` FROM `cluster_commands` WHERE `action` = 'reload_nginx'");
		$this->assertSame(0, (int) $this->rDb->get_row()['n'], 'any other command goes at its exp, handed out or not');
		$this->assertSame([], CommandBus::pending(self::SID, 0), 'past exp it goes out no more');

		$rResult = '{"config":{"server_id":7,"is_lb":1,"db_credentials":false,"redis_auth":false,"changed":true}}';
		$this->assertTrue(CommandBus::ack(self::SID, $rCmd['cmd_id'], true, $rResult, $rFirst));
		$this->assertTrue($rFirst);
		$this->assertTrue(DbCredentials::acked(self::SID, $rCmd['cmd_id'], true, $rResult));
		$this->assertSame(['10.0.0.7'], $rRevoked);
		$this->assertNotNull(DbCredentials::revokedAt(self::SID));
	}

	public function testADropNeverAckedGoesADayPastItsExp(): void {
		$this->assertNull(DbCredentials::strip(self::SID));
		$rCmd = json_decode(CommandBus::pending(self::SID, 0)[0]['doc'], true);
		ClusterClock::fix(($rCmd['exp'] + 86399) * 1000);
		CommandBus::prune();
		$this->assertCount(1, $this->strips());
		ClusterClock::fix(($rCmd['exp'] + 86400) * 1000);
		CommandBus::prune();
		$this->assertSame([], $this->strips());
	}

	public function testADropWithdrawnByAMoveDownDoesNotComeBackWithModeTwo(): void {
		$this->assertNull(DbCredentials::strip(self::SID));
		$rCmd = json_decode(CommandBus::pending(self::SID, 0)[0]['doc'], true);
		$this->assertSame('cluster_mode_done', $this->act('mode_down'));
		// Back in mode 2 within the command's ten minutes: the days there count from now.
		NodeRegistry::update(self::SID, ['mode' => 2]);
		$this->assertSame([], CommandBus::pending(self::SID, 0), 'the move down withdrew it for good');
		ClusterClock::fix(self::NOW_MS + 61000);
		CommandBus::prune();
		$this->assertSame(['delivered'], array_column($this->strips(), 'state'), 'its row still waits for an ack');
		$this->assertTrue(CommandBus::ack(self::SID, $rCmd['cmd_id'], true, '{"config":{"db_credentials":false}}'));
	}

	public function testADropBeingHandedOutAsTheNodeIsMovedDownKeepsItsRowForItsAck(): void {
		$this->assertNull(DbCredentials::strip(self::SID));
		$rLog = new QueryLogDb($this->rDb);
		$rMoved = null;
		$rLog->rBefore = function (string $rQuery) use (&$rMoved, $rLog): void {
			// The hand-out has read the strip in mode 2 and not marked it yet: the operator moves the node down.
			if ($rMoved === null && str_starts_with($rQuery, "UPDATE `cluster_commands` SET `state` = 'delivered'")) {
				DatabaseFactory::set($this->rDb);
				$rMoved = $this->act('mode_down');
				DatabaseFactory::set($rLog);
			}
		};
		DatabaseFactory::set($rLog);
		$rOut = CommandBus::pending(self::SID, 0);
		DatabaseFactory::set($this->rDb);
		$this->assertSame('cluster_mode_done', $rMoved);
		$rCmd = json_decode($rOut[0]['doc'], true);
		$this->assertSame(NodeCredentials::STRIP, $rCmd['action'], 'the node holds it');
		$this->assertSame(['delivered'], array_column($this->strips(), 'state'), 'so its row waits for the ack, as that of any strip it was handed');
		NodeRegistry::update(self::SID, ['mode' => 2]);
		$this->assertSame([], CommandBus::pending(self::SID, 0), 'and it goes out no more');
		CommandBus::prune();
		$rResult = '{"config":{"server_id":7,"is_lb":1,"db_credentials":false,"redis_auth":false,"changed":true}}';
		$this->assertTrue(CommandBus::ack(self::SID, $rCmd['cmd_id'], true, $rResult, $rFirst), 'the ack says what its config holds');
		$this->assertTrue($rFirst);
	}

	/** The agent's flows.json on the node: the mode MAIN's last reply gave it. */
	private function nodeMode(int $rMode): void {
		$this->rFlowsFile = sys_get_temp_dir() . '/xcvm-audit-strip-flows-' . getmypid() . '.json';
		file_put_contents($this->rFlowsFile, json_encode(['mode' => $rMode, 'flows' => ClusterAdmin::MODE2_FLOWS, 'state' => 'active']));
		NodeFlows::usePath($this->rFlowsFile);
	}

	public function testTheNodeGivesThemUpOnlyWhileItIsInModeTwo(): void {
		$rCalls = [];
		NodeCredentials::useExtension(static function (string $rMethod, mixed ...$rArgs) use (&$rCalls): array {
			$rCalls[] = $rMethod;
			return ['server_id' => self::SID, 'is_lb' => 1, 'db_credentials' => false, 'redis_auth' => false, 'changed' => true];
		});
		NodeRole::useMainBuild(false);

		// Root runs the command after MAIN moved the node down: its agent's file says mode 1 by then.
		$this->nodeMode(1);
		try {
			NodeCredentials::run(['action' => NodeCredentials::STRIP]);
			$this->fail('in mode 1 the node needs these credentials');
		} catch (\RuntimeException $rE) {
			$this->assertStringContainsString(NodeCredentials::STRIP . ': refused', $rE->getMessage(), 'the ack carries why, and a failed command revokes nothing');
		}
		$this->assertSame([], $rCalls, 'its config was not touched');

		// A config MAIN packed is how credentials come back: installed in any mode.
		NodeCredentials::run(['action' => NodeCredentials::INSTALL, 'blob' => base64_encode('XCVT')]);
		$this->assertSame(['install_config'], $rCalls);

		$this->nodeMode(2);
		$rConfig = NodeCredentials::outcome(NodeCredentials::run(['action' => NodeCredentials::STRIP]));
		$this->assertSame(['install_config', 'strip_db_credentials'], $rCalls);
		$this->assertFalse($rConfig['db_credentials']);
	}

	public function testTheCommandLineSaysWhyInWords(): void {
		foreach (['cluster_mode_needs_flows', 'cluster_strip_not_heard', 'cluster_strip_not_local'] as $rKey) {
			$this->assertArrayHasKey($rKey, \XcVm\Cli\Commands\ClusterStripCredentialsCommand::MESSAGES);
		}
	}
}
