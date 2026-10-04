<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeLease;
use XcVm\Core\Config\SettingsManager;

/**
 * The verdict a node reads from its own lease (plan, section 9): the agent
 * writes what MAIN signed and where MAIN's clock stands, and this decides
 * whether a new viewer may start and whether the ones running may carry on.
 *
 * Every case here is also a case for failing open: the switch off, no file, a
 * file the agent stopped refreshing, no lease, no anchor, a legacy node. A
 * fleet must not go off the air because an agent died or a file went stale —
 * the gate on a revoked licence is MAIN refusing to sign a lease at all.
 */
final class NodeLeaseTest extends TestCase {
	/**
	 * The agent's file as its writer produces it (XC_VM_Fanout,
	 * `internal/clusteragent/lease.go`), byte for byte: the agent's
	 * TestTheLeaseStateFileIsTheFixtureThePanelReads writes it from fixed inputs
	 * and compares it with its own copy (`testdata/cluster_lease_state.json`),
	 * and records this digest too — so whichever side changes the format first
	 * fails until the file is copied over and both digests are updated.
	 */
	private const AGENT_FIXTURE = 'cluster_lease_state.json';

	private const AGENT_FIXTURE_SHA256 = '09389ff2b7f238bf3375b8201d24a2be500ddf7b596068d0b3d4cb20974d72cc';

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-lease-' . bin2hex(random_bytes(4));
		mkdir($this->rDir);
		file_put_contents($this->rDir . '/flows.json', json_encode(['mode' => 1, 'flows' => NodeFlows::CONFIG, 'state' => 'active']));
		NodeFlows::usePath($this->rDir . '/flows.json');
		SettingsManager::set(['lb_lease_fence' => 1, 'lb_fence_drain_min' => 10]);
		// The agent's file alone: the compiled verdict is LeaseVerdictCacheTest's.
		NodeLease::useExtension(false);
	}

	protected function tearDown(): void {
		NodeFlows::usePath(null);
		NodeLease::usePath(null);
		NodeLease::useExtension(null);
		SettingsManager::set([]);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * Write the agent's file. $rExpOffset is where the lease's exp sits relative
	 * to MAIN's clock as the agent last vouched for it: negative is past.
	 */
	private function state(int $rExpOffset, int $rAgeMs = 0, ?int $rAnchorMs = null, ?int $rExp = null): void {
		$rNowMs = (int) round(microtime(true) * 1000);
		$rAnchor = $rAnchorMs ?? $rNowMs;
		file_put_contents($this->rDir . '/lease_state.json', (string) json_encode([
			'exp' => $rExp ?? (intdiv($rAnchor, 1000) + $rExpOffset),
			'iat' => intdiv($rAnchor, 1000) - 3600,
			'gen' => 4,
			'server_id' => 7,
			'anchor_ms' => $rAnchor,
			'wrote_at_ms' => $rNowMs - $rAgeMs,
		]));
		NodeLease::usePath($this->rDir . '/lease_state.json');
	}

	/**
	 * The fixture, rewritten as the agent would have written it $rAgeMs ago by
	 * this machine's clock: `wrote_at_ms` is the only field on local time, and
	 * everything else — the lease, and MAIN's clock as the agent vouched for
	 * it — stays as the Go writer put it.
	 *
	 * @return array<string, int>
	 */
	private function agentFile(int $rAgeMs): array {
		$rDoc = json_decode((string) file_get_contents(dirname(__DIR__) . '/Support/' . self::AGENT_FIXTURE), true);
		$rDoc['wrote_at_ms'] = (int) round(microtime(true) * 1000) - $rAgeMs;
		file_put_contents($this->rDir . '/lease_state.json', (string) json_encode($rDoc));
		NodeLease::usePath($this->rDir . '/lease_state.json');
		return $rDoc;
	}

	public function testTheAgentsFileIsWhatThisReads(): void {
		$rPath = dirname(__DIR__) . '/Support/' . self::AGENT_FIXTURE;
		$rBytes = (string) file_get_contents($rPath);
		$this->assertSame(self::AGENT_FIXTURE_SHA256, hash('sha256', $rBytes), 'copy it from the agent\'s testdata/ and update the digest in both tests');

		// The fields this reads, all integers: exp and iat in seconds on MAIN's
		// clock, the anchor (MAIN's clock) and the write (this machine's) in ms.
		$rDoc = json_decode($rBytes, true);
		$this->assertSame(['exp', 'iat', 'gen', 'server_id', 'anchor_ms', 'wrote_at_ms'], array_keys($rDoc));
		$this->assertContainsOnly('int', $rDoc);
		$this->assertSame($rDoc['exp'] * 1000 - 30000, $rDoc['anchor_ms'], 'the fixture\'s anchor sits 30 s short of its exp');

		// As written, long ago: an agent that has stopped rewriting it.
		NodeLease::usePath($rPath);
		$this->assertSame('the agent has stopped refreshing it', NodeLease::verdict()['why']);

		// Just written: MAIN's clock 30 s short of the exp.
		$this->agentFile(0);
		$rVerdict = NodeLease::verdict();
		$this->assertSame(NodeLease::SERVING, $rVerdict['state']);
		$this->assertSame('', $rVerdict['why'], 'the lease decided it');
		$this->assertSame([$rDoc['exp'], 4, $rDoc['exp'] - 30, $rDoc['exp'] + 600], [$rVerdict['exp'], $rVerdict['gen'], $rVerdict['anchor'], $rVerdict['drain_until']]);

		// Not rewritten for 45 s (still fresher than STALE_SEC): MAIN's clock is
		// carried forward by the time since, which takes it past the exp.
		$this->agentFile(45000);
		$this->assertSame(NodeLease::DRAINING, NodeLease::state());
		SettingsManager::set(['lb_lease_fence' => 1, 'lb_fence_drain_min' => 0]);
		$this->agentFile(45000);
		$this->assertSame(NodeLease::FENCED, NodeLease::state());

		// Written in this machine's future (its clock moved back an hour since):
		// nothing is added, and the anchor stands where the agent put it.
		$this->agentFile(-3600 * 1000);
		$rVerdict = NodeLease::verdict();
		$this->assertSame([NodeLease::SERVING, $rDoc['exp'] - 30], [$rVerdict['state'], $rVerdict['anchor']]);
	}

	public function testALiveLeaseServes(): void {
		$this->state(600);
		$rVerdict = NodeLease::verdict();
		$this->assertSame(NodeLease::SERVING, $rVerdict['state']);
		$this->assertSame(4, $rVerdict['gen']);
		$this->assertSame($rVerdict['exp'] + 600, $rVerdict['drain_until'], 'the drain follows the exp');
		$this->assertFalse(NodeLease::refusesNewSessions());
	}

	public function testPastTheExpTheDrainRefusesNewViewersOnly(): void {
		$this->state(-5);
		$this->assertSame(NodeLease::DRAINING, NodeLease::state());
		$this->assertTrue(NodeLease::refusesNewSessions(), 'no new viewer starts');
		$this->assertFalse(NodeLease::refusesEverything(), 'the ones running drain');
	}

	public function testPastTheDrainNothingIsServed(): void {
		$this->state(-601);
		$this->assertSame(NodeLease::FENCED, NodeLease::state());
		$this->assertTrue(NodeLease::refusesEverything());
	}

	public function testWithoutADrainTheExpIsTheEnd(): void {
		SettingsManager::set(['lb_lease_fence' => 1, 'lb_fence_drain_min' => 0]);
		$this->state(-1);
		$this->assertSame(NodeLease::FENCED, NodeLease::state());
	}

	public function testTheSwitchIsOffUntilAnOperatorTurnsItOn(): void {
		SettingsManager::set(['lb_fence_drain_min' => 10]);
		$this->state(-86400);
		$this->assertSame(NodeLease::SERVING, NodeLease::state(), 'a lease expired a day ago fences nothing while the switch is off');
		$this->assertSame('the switch is off', NodeLease::verdict()['why']);
	}

	public function testAnAgentThatStoppedRefreshingItServes(): void {
		// The agent rewrites the file every heartbeat; older than STALE_SEC means
		// it is not running, and a node with no agent has no statement of MAIN's.
		$this->state(-86400, (NodeLease::STALE_SEC + 1) * 1000);
		$this->assertSame(NodeLease::SERVING, NodeLease::state());
		$this->assertSame('the agent has stopped refreshing it', NodeLease::verdict()['why']);
	}

	public function testNoFileNoLeaseAndNoAnchorAllServe(): void {
		NodeLease::usePath($this->rDir . '/absent.json');
		$this->assertSame('the agent has written no lease state', NodeLease::verdict()['why']);

		$this->state(0, 0, null, 0);
		$this->assertSame('MAIN has sent this node no lease', NodeLease::verdict()['why']);

		$this->state(0, 0, 0, time() + 600);
		$this->assertSame('MAIN has never been heard on this node', NodeLease::verdict()['why']);
		$this->assertSame(NodeLease::SERVING, NodeLease::state());
	}

	public function testALegacyNodeIsNeverFenced(): void {
		file_put_contents($this->rDir . '/flows.json', json_encode(['mode' => 0, 'flows' => 0, 'state' => '']));
		NodeFlows::usePath($this->rDir . '/flows.json');
		$this->state(-86400);
		$this->assertSame('not a cluster node', NodeLease::verdict()['why']);
	}

	public function testTheThreeEndpointsAskAndTheSwitchShipsOff(): void {
		$rSrc = static fn(string $rPath): string => (string) file_get_contents(MAIN_HOME . $rPath);

		// A new viewer is refused where the node already refuses one whose cache is
		// not built: at the top of the stream auth entry, before any of its work.
		$rAuth = $rSrc('Public/stream/auth.php');
		$this->assertMatchesRegularExpression(
			'/generateError\(\'CACHE_INCOMPLETE\'\);\s*\}(.*\n)*?if \(NodeLease::refusesNewSessions\(\$rSettings\)\) \{\s*\n\s*generateError\(\'STREAM_OFFLINE\'\);/',
			$rAuth,
			'the refusal sits at the top of auth.php'
		);
		$this->assertStringContainsString('use XcVm\Core\Cluster\NodeLease;', $rAuth);

		// The requests that keep a session running stop only past the drain, and
		// they pass their own settings: these two read the cache file, not SettingsManager.
		foreach (['Public/stream/segment.php', 'Public/stream/key.php'] as $rFile) {
			$this->assertStringContainsString('NodeLease::refusesEverything($rSettings)', $rSrc($rFile), $rFile);
			$this->assertStringContainsString('use XcVm\Core\Cluster\NodeLease;', $rSrc($rFile), $rFile);
		}

		// The switch: off by default, in the bounds MAIN clamps, on the admin form,
		// named in en.ini, and in the allowlist so it reaches a node's replica.
		$this->assertSame([0, 0, 1], \XcVm\Core\Cluster\ClusterSettings::INTS['lb_lease_fence']);
		$this->assertStringContainsString("`lb_lease_fence` tinyint(1) DEFAULT '0'", $rSrc('migrations/database/up/051_add_lease_fence_switch.sql'));
		$this->assertStringContainsString("['lb_lease_fence', 'switch'", $rSrc('Public/Views/admin/settings.php'));
		$this->assertStringContainsString('lb_lease_fence = "', $rSrc('Core/Localization/lang/en.ini'));
		$rAllowed = require MAIN_HOME . 'Core/Cluster/lb_settings_keys.php';
		foreach (['lb_lease_fence', 'lb_fence_drain_min'] as $rKey) {
			$this->assertContains($rKey, $rAllowed['keys'], $rKey . ' must reach a node');
		}
	}

	public function testAClockMovedBackDoesNotShortenTheWindow(): void {
		// The file says it was written in this machine's future: the elapsed time
		// since clamps to nothing, so the anchor stands where the agent put it.
		$rNowMs = (int) round(microtime(true) * 1000);
		file_put_contents($this->rDir . '/lease_state.json', (string) json_encode([
			'exp' => intdiv($rNowMs, 1000) + 5, 'iat' => intdiv($rNowMs, 1000) - 3600, 'gen' => 4, 'server_id' => 7,
			'anchor_ms' => $rNowMs, 'wrote_at_ms' => $rNowMs + 30 * 1000,
		]));
		NodeLease::usePath($this->rDir . '/lease_state.json');
		$this->assertSame(NodeLease::SERVING, NodeLease::state());
	}
}
