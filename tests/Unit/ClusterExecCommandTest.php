<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ClusterExecCommand;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * cluster:exec runs a command only when it carries the panel's `cmd`
 * signature under the key the agent pinned, names this node and is not stale.
 */
final class ClusterExecCommandTest extends TestCase {
	private FakeClusterCrypto $rCrypto;

	private array $rState;

	protected function setUp(): void {
		$this->rCrypto = new FakeClusterCrypto();
		$this->rState = ['node_uuid' => '0f8fad5b-d9cb-469f-a165-70867728950e', 'panel_sign_pub' => base64_encode($this->rCrypto->info()['panel_sign_pub'])];
	}

	private function command(array $rOverride = [], string $rTag = 'cmd'): array {
		$rDoc = (string) json_encode($rOverride + ['v' => 1, 'type' => 'node.rpc', 'exp' => 1800000600, 'iat' => 1800000000, 'cmd_id' => str_repeat('a', 32), 'seq' => 1, 'node_uuid' => $this->rState['node_uuid'], 'gen' => 1, 'dedupe_key' => null, 'action' => 'get_pids', 'args' => new \stdClass()]);
		return ['doc' => $rDoc, 'sig' => Enc::b64url($this->rCrypto->sign($rTag, $rDoc))];
	}

	public function testAGenuineCommandVerifies(): void {
		$rCmd = ClusterExecCommand::verify($this->command(), $this->rState, 1800000000);
		$this->assertIsArray($rCmd);
		$this->assertSame(['get_pids', []], [$rCmd['action'], $rCmd['args']], 'the action is the envelope\'s');
	}

	public function testForgedOrMisaddressedCommandsAreRefused(): void {
		$rGood = $this->command();
		$this->assertSame('bad signature', ClusterExecCommand::verify(['doc' => str_replace('get_pids', 'kill_pid', $rGood['doc'])] + $rGood, $this->rState, 1800000000));
		$this->assertSame('bad signature', ClusterExecCommand::verify($this->command([], 'den'), $this->rState, 1800000000), 'another tag');
		$this->assertSame('bad signature', ClusterExecCommand::verify($rGood, ['panel_sign_pub' => base64_encode(str_repeat("\1", 32))] + $this->rState, 1800000000));
		$this->assertIsString(ClusterExecCommand::verify($this->command(['node_uuid' => '11111111-1111-4111-a111-111111111111']), $this->rState, 1800000000));
		$this->assertIsString(ClusterExecCommand::verify($rGood, $this->rState, 1800000600 + ClusterExecCommand::SKEW + 1), 'stale');
	}

	public function testConfigChangedIsLeftToTheAgentsNextReplicaPoll(): void {
		ob_start();
		$rExit = ClusterExecCommand::run(['type' => 'config.changed', 'args' => ['sections' => ['servers']]]);
		$this->assertSame(0, $rExit, 'an agent that does not run it itself still acks it');
		$this->assertSame(['deferred' => true], json_decode((string) ob_get_clean(), true));
	}

	/** `--types` says what this node's PHP runs, reading nothing: the agent says `artefact` at hello only while `artefact.fetch` is among them. */
	public function testItSaysWhichCommandTypesItRuns(): void {
		ob_start();
		$rExit = (new ClusterExecCommand())->execute(['--types']);
		$rTypes = json_decode((string) ob_get_clean(), true);
		$this->assertSame(0, $rExit);
		$this->assertSame(ClusterExecCommand::TYPES, $rTypes);
		$this->assertContains('artefact.fetch', $rTypes);
		// No binaries from MAIN's grants: every node takes them from GitHub, so the agent never says artefact_binaries.
		$this->assertNotContains('root:fanout_binary', $rTypes);
		$this->assertNotContains('root:xcvm_core', $rTypes);
		$this->assertContains('node.root', $rTypes);
		$this->assertContains('node.cache', $rTypes);
	}

	/**
	 * `node.cache` runs only jobs in the form MAIN signs them
	 * (CacheJobs::job), naming at most CacheJobs::MAX targets a command
	 * (an id or a uuid counts one, a list its ids): any other is refused
	 * whole, before a job runs, and nothing is deleted.
	 */
	public function testMalformedCacheJobsAreRefusedWhole(): void {
		$rGood = ['type' => 'delete_vod', 'id' => 7];
		foreach ([
			'no jobs' => [],
			'not a list' => ['jobs' => ['a' => $rGood]],
			'empty' => ['jobs' => []],
			'a path for a uuid' => ['jobs' => [$rGood, ['type' => 'delete_con', 'uuid' => '../../config/cluster/agent.json']]],
			'an id as text' => ['jobs' => [['type' => 'delete_vod', 'id' => '7']]],
			'an id list with text' => ['jobs' => [['type' => 'delete_vods', 'id' => [7, '8;rm']]]],
			'an unknown type' => ['jobs' => [['type' => 'delete_everything', 'id' => 1]]],
			'an extra field' => ['jobs' => [$rGood + ['path' => '/etc']]],
			'too many' => ['jobs' => array_fill(0, \XcVm\Core\Cluster\CacheJobs::MAX + 1, $rGood)],
			'too many ids' => ['jobs' => [['type' => 'delete_vods', 'id' => range(1, \XcVm\Core\Cluster\CacheJobs::MAX + 1)]]],
			'too many targets' => ['jobs' => [['type' => 'delete_vods', 'id' => range(1, \XcVm\Core\Cluster\CacheJobs::MAX)], $rGood]],
		] as $rWhy => $rArgs) {
			ob_start();
			$rExit = ClusterExecCommand::run(['type' => 'node.cache', 'args' => $rArgs]);
			$this->assertSame('', ob_get_clean(), $rWhy);
			$this->assertSame(2, $rExit, $rWhy);
		}
	}

	/**
	 * `node.purge` is signed without a licence, so it runs only the jobs that
	 * remove something: a rebuild in it is refused whole, the malformed ones
	 * as for `node.cache`.
	 */
	public function testAPurgeRunsOnlyRemovals(): void {
		foreach ([
			'a rebuild' => ['jobs' => [['type' => 'update_stream', 'id' => 7]]],
			'a removal beside a rebuild' => ['jobs' => [['type' => 'delete_vod', 'id' => 7], ['type' => 'update_line', 'id' => 8]]],
			'an extra field' => ['jobs' => [['type' => 'delete_vod', 'id' => 7, 'path' => '/etc']]],
			'empty' => ['jobs' => []],
		] as $rWhy => $rArgs) {
			ob_start();
			$rExit = ClusterExecCommand::run(['type' => 'node.purge', 'args' => $rArgs]);
			$this->assertSame('', ob_get_clean(), $rWhy);
			$this->assertSame(2, $rExit, $rWhy);
		}
		ob_start();
		$rExit = ClusterExecCommand::run(['type' => 'node.purge', 'args' => ['jobs' => [['type' => 'drop_con', 'uuid' => 'abc123']]]]);
		$this->assertSame(['result' => true, 'jobs' => 1], json_decode((string) ob_get_clean(), true));
		$this->assertSame(0, $rExit);
	}

	public function testLocalRunsOnMainOnlyAndOnlyCataloguedActions(): void {
		$this->assertSame(2, ClusterExecCommand::local(['action' => 'get_pids'], false), 'a load balancer takes signed commands only');
		$this->assertSame(2, ClusterExecCommand::local(['action' => 'view_log'], true), 'not in NodeRpc::ACTIONS');
		$this->assertSame(2, ClusterExecCommand::local([], true), 'no action');
	}

	public function testUnknownTypesAndActionsDoNothing(): void {
		$this->assertSame(2, ClusterExecCommand::run(['type' => 'node.root', 'action' => 'reboot', 'args' => []]));
		$this->assertSame(2, ClusterExecCommand::run(['type' => 'node.rpc', 'action' => 'view_log', 'args' => []]), 'not in NodeRpc::ACTIONS');
		// The action is the envelope's, as the extension classes it: one among
		// the arguments (the shape before) runs nothing, alone or beside it.
		$this->assertSame(2, ClusterExecCommand::run(['type' => 'node.rpc', 'args' => ['action' => 'get_pids']]), 'no top-level action');
		$this->assertSame(2, ClusterExecCommand::run(['type' => 'node.rpc', 'action' => 'get_pids', 'args' => ['action' => 'kill_pid']]), 'a second action among the arguments');
		$this->assertSame(2, ClusterExecCommand::run(['type' => 'conn.kill_worker', 'args' => ['pid' => 0]]));
	}
}
