<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ClusterExecCommand;
use XcVm\Core\Cluster\Crypto\ClusterRefusedException;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Logging\FileLogger;
use XcVm\Domain\Cluster\ClusterBus;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterRoute;
use XcVm\Domain\Cluster\CommandBus;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * The extension owns the command registry: `cluster_sign` classes a `cmd`
 * record by its type (and `node.root`'s action), and refuses a type, an
 * envelope key or a restrictive command's argument it does not know. Its
 * table, as xcvm_core generates it, is tests/Support/cluster_commands.json
 * (byte-identical in the extension and the agent; ClusterVectorsTest pins
 * its digest). Every command MAIN sends, built exactly as CommandBus and
 * ClusterRoute build it, must satisfy it: before, `conn.close`, `node.cache`
 * and `artefact.fetch` were types the extension did not know, `node.rpc` and
 * `node.root` carried their action among the arguments, and
 * `conn.kill_worker` carried `rtmp`; the real extension refused every one
 * while this test double signed anything.
 */
final class CommandBusRegistryTest extends TestCase {
	private const SID = 17;

	private TestDb $rDb;

	private FakeClusterCrypto $rCrypto;

	private string $rLog;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['029_create_cluster_nodes', '030_create_cluster_commands'] as $rName) {
			$rSql = (string) file_get_contents(MAIN_HOME . 'migrations/database/up/' . $rName . '.sql');
			$this->rDb->exec((string) $rSql);
		}
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `root_ready` tinyint(1) NOT NULL DEFAULT 0');
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `features` varchar(255) DEFAULT NULL');
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix(1800000000000);
		ClusterBus::useSocket(sys_get_temp_dir() . '/no-such-bus-' . bin2hex(random_bytes(4)) . '/cluster.sock');
		SettingsManager::set(['cluster_api_enabled' => 1]);
		$this->rCrypto = new FakeClusterCrypto();
		ClusterRoute::useCrypto(fn() => $this->rCrypto);
		NodeRegistry::startEnrolment(self::SID, '0f8fad5b-d9cb-469f-a165-70867728950e', random_bytes(32), random_bytes(32), 2);
		NodeRegistry::update(self::SID, ['state' => 'active', 'mode' => 2, 'flows' => NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_CONNECTIONS, 'root_ready' => 1]);
		$this->rLog = (string) tempnam(sys_get_temp_dir(), 'xcvm-log');
		FileLogger::setLogFile($this->rLog);
	}

	protected function tearDown(): void {
		FileLogger::setLogFile(null);
		@unlink($this->rLog);
		ClusterRoute::useCrypto(null);
		ClusterBus::useSocket(null);
		ClusterClock::fix(null);
		SettingsManager::set([]);
		DatabaseFactory::reset();
	}

	/** @return array<string, mixed> */
	private static function registry(): array {
		return FakeClusterCrypto::commandRegistry();
	}

	/** @return list<string> the types the extension classes R */
	private static function restrictiveTypes(): array {
		return array_keys(array_filter(self::registry()['types'], static fn(array $rEntry): bool => $rEntry['class'] === 'R'));
	}

	/** @return list<array{doc: string, class: string}> every queued command, oldest first */
	private function queued(): array {
		$this->rDb->query('SELECT `payload`, `class`, `action` FROM `cluster_commands` WHERE `server_id` = ? ORDER BY `seq`', self::SID);
		return array_map(static fn(array $rRow): array => ['doc' => (string) $rRow['payload'], 'class' => (string) $rRow['class'], 'action' => $rRow['action']], $this->rDb->get_rows());
	}

	/**
	 * Why the extension would refuse this document, read off the registry
	 * alone (not FakeClusterCrypto's code): null when it would sign it.
	 */
	private static function refusal(string $rPayload): ?string {
		$rReg = self::registry();
		$rDoc = json_decode($rPayload);
		if (!$rDoc instanceof \stdClass) {
			return 'json_object';
		}
		$rEntry = $rReg['types'][$rDoc->type ?? ''] ?? null;
		if (!is_array($rEntry)) {
			return 'type';
		}
		$rKeys = array_merge($rReg['envelope'], empty($rEntry['action']) ? [] : [$rReg['action_key']]);
		if (array_diff(array_keys(get_object_vars($rDoc)), $rKeys) !== []) {
			return 'key';
		}
		if (!empty($rEntry['action']) && !is_string($rDoc->action ?? null)) {
			return 'action';
		}
		if (isset($rDoc->args) && !$rDoc->args instanceof \stdClass) {
			return 'args';
		}
		$rAllowed = empty($rEntry['action']) ? ($rEntry['args'] ?? null) : ($rEntry['restrictive_actions'][$rDoc->action] ?? null);
		if (is_array($rAllowed) && isset($rDoc->args) && array_diff(array_keys(get_object_vars($rDoc->args)), $rAllowed) !== []) {
			return 'args';
		}
		return null;
	}

	public function testTheFixtureIsTheExtensionsCommandTable(): void {
		$rReg = self::registry();
		$this->assertSame(1, $rReg['version']);
		$this->assertSame('cmd', $rReg['tag']);
		$this->assertSame(['v', 'type', 'exp', 'iat', 'cmd_id', 'seq', 'node_uuid', 'gen', 'dedupe_key', 'args'], $rReg['envelope']);
		$this->assertSame(['node.rpc', 'node.root'], array_keys(array_filter($rReg['types'], static fn(array $rEntry): bool => !empty($rEntry['action']))));
	}

	public function testMainsListsAgreeWithTheExtension(): void {
		$rTypes = array_keys(self::registry()['types']);
		$this->assertSame([], array_diff(CommandBus::TYPES, $rTypes), 'every type MAIN sends is one the extension signs');
		$this->assertSame([], array_diff(ClusterExecCommand::TYPES, $rTypes), 'every type cluster:exec runs is one the extension signs');
		$this->assertEqualsCanonicalizing(self::restrictiveTypes(), CommandBus::RESTRICTIVE);
		$this->assertEqualsCanonicalizing(self::restrictiveTypes(), FakeClusterCrypto::RESTRICTIVE_COMMANDS);
		$this->assertEqualsCanonicalizing(array_keys(array_filter(self::registry()['types'], static fn(array $rEntry): bool => !empty($rEntry['action']))), CommandBus::ACTION_TYPES);
	}

	/**
	 * Each type CommandBus::TYPES names, sent the way MAIN sends it
	 * (ClusterRoute for the seams, CommandBus::enqueue for ReplicaBuilder's
	 * `config.changed` and ArtefactGrants' `artefact.fetch`), is a record the
	 * extension signs, with the action where it reads it and the class it
	 * gives it in the `class` column.
	 */
	public function testEveryCommandMainSendsIsOneTheExtensionSigns(): void {
		$this->assertSame([true, true], ClusterRoute::send(self::SID, ['action' => 'free_temp']));
		$this->assertSame([true, true], ClusterRoute::root(self::SID, ['action' => 'reboot']));
		// A removal as node.purge, a rebuild as node.cache.
		$this->assertSame([true, true], ClusterRoute::cache(self::SID, [['type' => 'delete_vod', 'id' => 7]]));
		$this->assertSame([true, true], ClusterRoute::cache(self::SID, [['type' => 'update_stream', 'id' => 7]]));
		$this->assertSame([true, true], ClusterRoute::kill(self::SID, 4242, true));
		$this->assertSame([true, true], ClusterRoute::kill(self::SID, 4243, false));
		$this->assertSame([true, true], ClusterRoute::drop(self::SID, 'viewer1'));
		$this->assertSame([true, true], ClusterRoute::closeConnection(self::SID, 'viewer2', false));
		$this->assertSame([true, true], ClusterRoute::closeConnection(self::SID, 'viewer3', true));
		$this->assertSame([true, true], ClusterRoute::rotateNow(self::SID));
		$this->assertSame([true, true], ClusterRoute::send(self::SID, ['action' => 'stream', 'function' => 'stop', 'stream_ids' => [5]]));
		$this->assertSame([true, true], ClusterRoute::send(self::SID, ['action' => 'vod', 'function' => 'stop', 'stream_ids' => [6]]));
		// Typed starts, to a node whose PHP runs them (its agent says typed_starts).
		$this->rDb->query('UPDATE `cluster_nodes` SET `features` = ? WHERE `server_id` = ?', ClusterRoute::FEATURE_TYPED_STARTS, self::SID);
		$this->assertSame([true, true], ClusterRoute::send(self::SID, ['action' => 'stream', 'function' => 'start', 'stream_ids' => [5]]));
		$this->assertSame([true, true], ClusterRoute::send(self::SID, ['action' => 'vod', 'function' => 'start', 'stream_ids' => [6], 'force' => true]));
		$this->rDb->query('UPDATE `cluster_nodes` SET `features` = NULL WHERE `server_id` = ?', self::SID);
		$this->assertSame([true, true], ClusterRoute::fence(self::SID, 'admin', 10));
		$this->assertSame([true, true], ClusterRoute::unfence(self::SID));
		// The unfence superseded that fence (one dedupe key): the lease-fence
		// path's shape, sent as its own row.
		CommandBus::enqueue($this->rCrypto, self::SID, 'node.fence', ['reason' => ClusterRoute::LICENCE_FENCE, 'drain_min' => 10]);
		$this->assertSame([true, true], ClusterRoute::resync(self::SID));
		CommandBus::enqueue($this->rCrypto, self::SID, 'policy.update', [], 'policy.update');
		$this->assertSame([true, true], ClusterRoute::quarantine(self::SID, 'admin'));
		CommandBus::enqueue($this->rCrypto, self::SID, 'config.changed', ['sections' => ['servers']], 'config.changed');
		CommandBus::enqueue($this->rCrypto, self::SID, 'artefact.fetch', ['artefact' => ['id' => 'offair/banned', 'name' => 'banned.ts', 'size' => 3, 'sha256' => str_repeat('0', 64), 'mtime' => 1799990000, 'ctime' => 1799990000]]);
		// StreamAssign's and StreamPush's.
		CommandBus::enqueue($this->rCrypto, self::SID, 'stream.assign', ['stream_ids' => [5], 'set' => ['to_analyze' => 1], 'fill' => ['pid' => 1]]);
		CommandBus::enqueue($this->rCrypto, self::SID, 'queue.poke', [], 'queue.poke');

		$rSeen = [];
		foreach ($this->queued() as $rRow) {
			$rDoc = json_decode($rRow['doc'], true);
			$rType = $rDoc['type'];
			$rSeen[] = $rType;
			$this->assertNull(self::refusal($rRow['doc']), $rType . ': ' . $rRow['doc']);
			$this->assertSame(FakeClusterCrypto::commandClass($rRow['doc']), $rRow['class'], $rType);
			$this->assertSame(self::registry()['types'][$rType]['class'], $rRow['class'], $rType);
			if (in_array($rType, CommandBus::ACTION_TYPES, true)) {
				$this->assertIsString($rDoc['action'], $rType);
				$this->assertSame($rDoc['action'], $rRow['action'], 'the column keeps the envelope\'s action');
				$this->assertArrayNotHasKey('action', $rDoc['args'], $rType . ': the action is not an argument');
			} else {
				$this->assertArrayNotHasKey('action', $rDoc, $rType);
			}
		}
		$this->assertEqualsCanonicalizing(CommandBus::TYPES, array_values(array_unique($rSeen)), 'every type MAIN sends was sent');
	}

	public function testAnActionTypeNeedsItsAction(): void {
		$this->expectException(\InvalidArgumentException::class);
		CommandBus::enqueue($this->rCrypto, self::SID, 'node.rpc', ['function' => 'x']);
	}

	/**
	 * The test double refuses what the extension refuses, the same way
	 * (sign::classify's cases), so a command MAIN builds wrong fails here
	 * and not first against a real xcvm_core.
	 */
	public function testTheTestDoubleRefusesWhatTheExtensionRefuses(): void {
		$rCmd = static fn(string $rType, array $rExtra = []): string => (string) json_encode(['type' => $rType, 'exp' => 1800000060] + $rExtra);
		foreach ([
			'conn.close' => [$rCmd('conn.close', ['args' => ['uuid' => 'v1', 'remove' => false]]), 'R'],
			'conn.kill_worker with rtmp' => [$rCmd('conn.kill_worker', ['args' => ['pid' => 1, 'rtmp' => true]]), 'R'],
			'node.cache' => [$rCmd('node.cache', ['args' => ['jobs' => []]]), 'G'],
			'artefact.fetch' => [$rCmd('artefact.fetch', ['args' => ['artefact' => []]]), 'G'],
			'node.rpc' => [$rCmd('node.rpc', ['action' => 'get_pids', 'args' => new \stdClass()]), 'G'],
			'node.root fence' => [$rCmd('node.root', ['action' => 'fence', 'args' => ['reason' => 'x']]), 'R'],
			'node.root reboot' => [$rCmd('node.root', ['action' => 'reboot']), 'G'],
		] as $rName => [$rPayload, $rClass]) {
			$this->assertSame($rClass, $this->rCrypto->recordClass('cmd', $rPayload), $rName);
		}
		foreach ([
			'an unknown type' => [$rCmd('rm -rf'), 'RECORD:type'],
			'the action among the arguments' => [$rCmd('node.rpc', ['args' => ['action' => 'get_pids']]), 'RECORD:action'],
			'no action' => [$rCmd('node.root'), 'RECORD:action'],
			'an argument a restrictive type does not take' => [$rCmd('conn.close', ['args' => ['uuid' => 'v1', 'reopen' => true]]), 'RECORD:args'],
			'a fence that also reboots' => [$rCmd('node.root', ['action' => 'fence', 'args' => ['then' => 'reboot']]), 'RECORD:args'],
			'an action on another type' => [$rCmd('conn.drop', ['action' => 'x']), 'RECORD:key'],
			'an unknown envelope key' => [$rCmd('conn.drop', ['inline' => 1]), 'RECORD:key'],
			'args as a list' => [$rCmd('conn.drop', ['args' => [1]]), 'RECORD:args'],
			'no exp' => [(string) json_encode(['type' => 'conn.drop']), 'RECORD:exp'],
		] as $rName => [$rPayload, $rReason]) {
			try {
				$this->rCrypto->sign('cmd', $rPayload);
				$this->fail($rName . ': signed');
			} catch (ClusterRefusedException $rE) {
				$this->assertSame($rReason, $rE->reason(), $rName);
			}
		}
	}

	/**
	 * Without a licence the extension signs only restrictive commands. A
	 * refused one is not queued, is not sent the legacy way either (ADR 0004
	 * routes by the COMMANDS flow; an unsigned legacy call would skip the
	 * licence gate), and says why in the panel's error log.
	 */
	public function testAnUnlicensedMainSendsKillsAndLogsWhatItCannotSign(): void {
		$this->rCrypto->rLicensed = false;
		$this->assertSame([true, true], ClusterRoute::kill(self::SID, 4242, true));
		$this->assertSame([true, true], ClusterRoute::closeConnection(self::SID, 'viewer2', true));
		$this->assertSame([true, false], ClusterRoute::send(self::SID, ['action' => 'free_temp']));
		$this->assertSame([true, false], ClusterRoute::root(self::SID, ['action' => 'reboot']));
		// A removal goes as node.purge, which signs without a licence; a rebuild does not.
		$this->assertSame([true, true], ClusterRoute::cache(self::SID, [['type' => 'delete_vod', 'id' => 7]]));
		$this->assertSame([true, false], ClusterRoute::cache(self::SID, [['type' => 'update_stream', 'id' => 7]]));
		$this->assertSame(['conn.kill_worker', 'conn.close', 'node.purge'], array_map(static fn(array $rRow): string => json_decode($rRow['doc'], true)['type'], $this->queued()));
		$rLog = array_map(static fn(string $rLine): array => (array) json_decode((string) base64_decode($rLine), true), file($this->rLog, FILE_IGNORE_NEW_LINES) ?: []);
		$this->assertSame(
			['Command node.rpc for server 17 not queued (refused: LICENCE)', 'Command node.root for server 17 not queued (refused: LICENCE)', 'Command node.cache for server 17 not queued (refused: LICENCE)'],
			array_column($rLog, 'message')
		);
		$this->assertSame(['cluster', 'cluster', 'cluster'], array_column($rLog, 'type'));
	}
}
