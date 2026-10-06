<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\AgentConnections;
use XcVm\Core\Cluster\CacheJobs;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Cluster\Crypto\ClusterRefusedException;
use XcVm\Core\Cluster\NodeCredentials;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Logging\FileLogger;

/**
 * Where MAIN's calls to a node go: the node's signed command channel when its
 * COMMANDS flow is on, else the legacy transport (the caller's own). Core's
 * seams (NodeRpc, SignalDispatcher, ConnectionTracker's kills) ask here first; on load balancers this
 * class is not in the build and they go legacy directly.
 *
 * Every method returns [routed, result]: routed false means "not a command
 * node, use the legacy path". Routed with a false or null result means the
 * command channel is this node's but the command was not queued: the
 * extension refused to sign it (a granting command without a licence) or
 * the database refused its row. The reason goes to the panel's error log
 * (FileLogger, type `cluster`), and the legacy path is not tried: ADR 0004
 * routes by the node's COMMANDS flow, not by an enqueue's outcome, a node in
 * mode 2 reads no legacy row, and an unsigned legacy call would skip the
 * licence gate the refusal enforces.
 */
final class ClusterRoute {
	/** @var (callable(): ClusterCrypto)|null */
	private static $rCrypto;

	/**
	 * An RPC answered by the node: `node.rpc{action}`, waiting for its ack.
	 *
	 * @param array<string, mixed> $rData NodeRpc payload (action + args)
	 * @return array{0: bool, 1: mixed} the node's raw result, or null on failure/timeout
	 */
	public static function rpc(int $rServerID, array $rData, int $rTimeout): array {
		$rTyped = self::perStream($rServerID, $rData);
		if ($rTyped !== null) {
			// The legacy answer, once the node has run (or refused) the last one.
			[$rRouted, $rCmdIDs] = $rTyped;
			if ($rCmdIDs === null || $rCmdIDs === []) {
				return [$rRouted, null];
			}
			$rOutcome = CommandBus::await((string) end($rCmdIDs), max(1, $rTimeout));
			return [true, $rOutcome !== null && $rOutcome[0] ? (string) json_encode(['result' => true]) : null];
		}
		[$rRouted, $rCmdID] = self::command($rServerID, 'node.rpc', static fn(ClusterCrypto $rCrypto): string => CommandBus::enqueue($rCrypto, $rServerID, 'node.rpc', $rData), null);
		if ($rCmdID === null) {
			return [$rRouted, null];
		}
		$rOutcome = CommandBus::await($rCmdID, max(1, $rTimeout));
		return [true, $rOutcome !== null && $rOutcome[0] ? $rOutcome[1] : null];
	}

	/**
	 * An RPC sent without waiting (NodeRpc::broadcast). A stop becomes the
	 * restrictive `stream.stop` / `vod.stop` (stops()).
	 *
	 * @param array<string, mixed> $rData
	 * @return array{0: bool, 1: bool} queued?
	 */
	public static function send(int $rServerID, array $rData): array {
		$rTyped = self::perStream($rServerID, $rData);
		if ($rTyped !== null) {
			return [$rTyped[0], $rTyped[1] !== null];
		}
		return self::enqueue($rServerID, 'node.rpc', $rData);
	}

	/** An agent whose node's PHP runs `stream.start` and `vod.start` says so at hello. */
	public const FEATURE_TYPED_STARTS = 'typed_starts';

	/**
	 * A per-stream RPC as its typed commands: a stop always (restrictive), a
	 * start where the node's PHP runs typed starts (its agent says
	 * FEATURE_TYPED_STARTS; an older one would refuse the type, so it keeps
	 * `node.rpc`). Null for anything else.
	 *
	 * @param array<string, mixed> $rData
	 * @return array{0: bool, 1: list<string>|null}|null
	 */
	private static function perStream(int $rServerID, array $rData): ?array {
		$rStop = self::stops($rData);
		if ($rStop !== null) {
			return self::stop($rServerID, $rStop[0], $rStop[1]);
		}
		$rStart = self::starts($rData);
		if ($rStart !== null && self::takesTypedStarts($rServerID)) {
			return self::start($rServerID, $rStart[0], $rStart[1], $rStart[2]);
		}
		return null;
	}

	/**
	 * The start an RPC payload asks for: `{action: stream|vod, function:
	 * start, stream_ids[, force]}` → [`stream.start`|`vod.start`, ids, force].
	 * Null for anything else.
	 *
	 * @param array<string, mixed> $rData
	 * @return array{0: string, 1: list<int>, 2: bool}|null
	 */
	public static function starts(array $rData): ?array {
		$rAction = $rData['action'] ?? null;
		if (!in_array($rAction, ['stream', 'vod'], true) || ($rData['function'] ?? null) !== 'start' || !is_array($rData['stream_ids'] ?? null)) {
			return null;
		}
		$rIDs = array_values(array_unique(array_filter(array_map('intval', $rData['stream_ids']), static fn(int $rID): bool => $rID > 0)));
		return $rIDs === [] ? null : [$rAction . '.start', $rIDs, !empty($rData['force'])];
	}

	/** Does this node's PHP run typed starts (its agent says FEATURE_TYPED_STARTS)? */
	private static function takesTypedStarts(int $rServerID): bool {
		try {
			$rNode = empty(SettingsManager::get('cluster_api_enabled')) ? null : NodeRegistry::byServer($rServerID);
		} catch (\Throwable) {
			return false;
		}
		return $rNode !== null && in_array(self::FEATURE_TYPED_STARTS, explode(',', (string) ($rNode['features'] ?? '')), true);
	}

	/**
	 * Start streams (or movies) on a node: one `stream.start {stream_id}` /
	 * `vod.start {stream_id, force}` per id, the node's cluster:exec running
	 * what `node.rpc`'s start ran. Deduped per stream, so repeated clicks
	 * queue one. Granting: a MAIN without a licence starts nothing.
	 *
	 * @param list<int> $rStreamIDs
	 * @return array{0: bool, 1: list<string>|null} [routed, cmd_ids or null when not queued]
	 */
	public static function start(int $rServerID, string $rType, array $rStreamIDs, bool $rForce = false): array {
		if (!in_array($rType, ['stream.start', 'vod.start'], true)) {
			throw new \InvalidArgumentException('Not a start: ' . $rType);
		}
		return self::command($rServerID, $rType, static function (ClusterCrypto $rCrypto) use ($rServerID, $rType, $rStreamIDs, $rForce): array {
			$rOut = [];
			foreach ($rStreamIDs as $rID) {
				$rArgs = ['stream_id' => (int) $rID] + ($rType === 'vod.start' ? ['force' => $rForce] : []);
				$rOut[] = CommandBus::enqueue($rCrypto, $rServerID, $rType, $rArgs, $rType . ':' . (int) $rID);
			}
			return $rOut;
		}, null);
	}

	/**
	 * The stop an RPC payload asks for, as the restrictive command types
	 * carry it: `{action: stream|vod, function: stop, stream_ids}` →
	 * [`stream.stop`|`vod.stop`, ids]. Null for anything else (a start, a
	 * restart, any other action), which stays a granting `node.rpc`.
	 *
	 * A stop is restrictive (plan, section 7): the extension signs it even
	 * without a licence, which is when an operator most needs a stream off a
	 * node. As a `node.rpc` it was granting and refused then.
	 *
	 * @param array<string, mixed> $rData
	 * @return array{0: string, 1: list<int>}|null
	 */
	public static function stops(array $rData): ?array {
		$rAction = $rData['action'] ?? null;
		if (!in_array($rAction, ['stream', 'vod'], true) || ($rData['function'] ?? null) !== 'stop' || !is_array($rData['stream_ids'] ?? null)) {
			return null;
		}
		$rIDs = array_values(array_unique(array_filter(array_map('intval', $rData['stream_ids']), static fn(int $rID): bool => $rID > 0)));
		return $rIDs === [] ? null : [$rAction . '.stop', $rIDs];
	}

	/**
	 * Stop streams (or VOD) on a node: one `stream.stop {stream_id}` /
	 * `vod.stop {stream_id}` per id, the node's cluster:exec running
	 * StreamProcess's stop for it. Deduped per stream, so repeated clicks
	 * queue one. Restrictive, so it also reaches a quarantined node.
	 *
	 * @param list<int> $rStreamIDs
	 * @return array{0: bool, 1: list<string>|null} [routed, cmd_ids or null when not queued]
	 */
	public static function stop(int $rServerID, string $rType, array $rStreamIDs): array {
		if (!in_array($rType, ['stream.stop', 'vod.stop'], true)) {
			throw new \InvalidArgumentException('Not a stop: ' . $rType);
		}
		return self::command($rServerID, $rType, static function (ClusterCrypto $rCrypto) use ($rServerID, $rType, $rStreamIDs): array {
			$rOut = [];
			foreach ($rStreamIDs as $rID) {
				$rOut[] = CommandBus::enqueue($rCrypto, $rServerID, $rType, ['stream_id' => (int) $rID], $rType . ':' . (int) $rID);
			}
			return $rOut;
		}, null, false, null, true);
	}

	/**
	 * Fence a node (`node.fence {reason, drain_min}`, restrictive): its agent
	 * stops new viewers at once and, after `drain_min` minutes, drops the ones
	 * still watching (ADR 0004, Phase 9). An operator's fence stays until
	 * unfence(); one MAIN queues for a lapsed licence (licenceFence()) ends
	 * when the node's session is accepted again.
	 *
	 * @return array{0: bool, 1: bool}
	 */
	public static function fence(int $rServerID, string $rReason, int $rDrainMin): array {
		$rArgs = ['reason' => substr($rReason, 0, 64), 'drain_min' => max(0, min(60, $rDrainMin))];
		return self::enqueue($rServerID, 'node.fence', $rArgs, self::FENCE_KEY, true);
	}

	/**
	 * Lift a fence (`node.unfence`, granting: a panel without a licence cannot
	 * put a fenced fleet back on the air). Shares the fence's dedupe key, so a
	 * fence the node has not taken yet is superseded rather than run.
	 *
	 * @return array{0: bool, 1: bool}
	 */
	public static function unfence(int $rServerID): array {
		return self::enqueue($rServerID, 'node.unfence', [], self::FENCE_KEY);
	}

	/** The dedupe key a fence and an unfence share. */
	public const FENCE_KEY = 'node.fence';

	/** The reason a fence MAIN queues for a lapsed licence carries (licenceFence()). */
	public const LICENCE_FENCE = 'licence';

	/**
	 * The hard revocation mode's fence (plan, section 9, "FENCED"): a node
	 * whose session MAIN refuses for want of a licence gets a restrictive
	 * `node.fence {reason: licence}`, which rides the panel-signed
	 * LICENCE_INVALID with its other kills (ClusterApi::killsFor()). Queued
	 * once while one is waiting; the node's agent lifts it on its own when
	 * MAIN accepts its session again. Never throws: the denial goes out
	 * whatever becomes of this.
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row
	 */
	public static function licenceFence(ClusterCrypto $rCrypto, array $rNode, int $rDrainMin): bool {
		try {
			$rServerID = (int) $rNode['server_id'];
			if (!CommandBus::accepts($rNode) || CommandBus::waiting($rServerID, self::FENCE_KEY)) {
				return false;
			}
			CommandBus::enqueue($rCrypto, $rServerID, 'node.fence', ['reason' => self::LICENCE_FENCE, 'drain_min' => max(0, min(60, $rDrainMin))], self::FENCE_KEY);
			return true;
		} catch (\Throwable $rE) {
			self::unsent((int) ($rNode['server_id'] ?? 0), 'node.fence', $rE);
			return false;
		}
	}

	/**
	 * Every node that takes commands gets the licence fence when MAIN runs in
	 * the hard revocation mode and the extension no longer holds a licence
	 * binding (ClusterCronJob, every minute): its session is refused then, so
	 * the fence rides the refusal (licenceFence()). Queued here rather than
	 * where the refusal is written, which is before anything is authenticated
	 * and must change no state. Answers how many were queued.
	 *
	 * @param array<string, mixed> $rSettings
	 */
	public static function licenceFences(ClusterCrypto $rCrypto, array $rSettings): int {
		if (ClusterSettings::enum('lb_revocation_mode', $rSettings['lb_revocation_mode'] ?? null) !== 'hard' || !empty($rCrypto->info()['licensed'])) {
			return 0;
		}
		$rQueued = 0;
		foreach (NodeRegistry::enrolled() as $rNode) {
			$rQueued += self::licenceFence($rCrypto, $rNode, ClusterSettings::int('lb_fence_drain_min', $rSettings['lb_fence_drain_min'] ?? null)) ? 1 : 0;
		}
		return $rQueued;
	}

	/**
	 * Quarantine a node on the operator's word: `node.quarantine {reason}`
	 * (restrictive) is queued while the node still takes commands, then its
	 * state becomes `quarantined`, which hands it the restrictive commands
	 * only (ClusterApi::commands()) and stops the replica and every granting
	 * command until trust().
	 *
	 * @return array{0: bool, 1: bool}
	 */
	public static function quarantine(int $rServerID, string $rReason): array {
		$rReason = substr($rReason, 0, 64);
		$rOut = self::enqueue($rServerID, 'node.quarantine', ['reason' => $rReason], 'node.quarantine', true);
		if ($rOut[0]) {
			NodeRegistry::update($rServerID, ['state' => 'quarantined', 'quarantine_reason' => $rReason]);
		}
		return $rOut;
	}

	/**
	 * *Trust again* (plan, section 4, "Quarantine"): back to `active`, and a
	 * forced token rotation, so whatever held the quarantined token loses it.
	 */
	public static function trust(int $rServerID): bool {
		$rNode = NodeRegistry::byServer($rServerID);
		if ($rNode === null || $rNode['state'] !== 'quarantined') {
			return false;
		}
		NodeRegistry::update($rServerID, ['state' => 'active', 'quarantine_reason' => null]);
		self::rotateNow($rServerID);
		return true;
	}

	/**
	 * `resync {sections}` (restrictive): the node's agent fetches its replica
	 * again from scratch (`config`, `streams`) and sends its whole connection
	 * set (`connections`), for a node an operator suspects has drifted.
	 *
	 * @param list<string> $rSections
	 * @return array{0: bool, 1: bool}
	 */
	public static function resync(int $rServerID, array $rSections = self::RESYNC_SECTIONS): array {
		$rSections = array_values(array_intersect(self::RESYNC_SECTIONS, $rSections));
		return self::enqueue($rServerID, 'resync', ['sections' => $rSections], 'resync');
	}

	/** What a resync may name. */
	public const RESYNC_SECTIONS = ['config', 'streams', 'connections'];

	/**
	 * Kill a viewer's worker on its node (SignalDispatcher::kill): restrictive,
	 * so it is signed even without a licence.
	 *
	 * @return array{0: bool, 1: bool}
	 */
	public static function kill(int $rServerID, int $rPID, bool $rRTMP): array {
		return self::enqueue($rServerID, 'conn.kill_worker', ['pid' => $rPID, 'rtmp' => $rRTMP]);
	}

	/**
	 * Rotate a node's token now (`token.rotate_now`): the operator's answer to a
	 * token they no longer trust, without waiting for its refresh window or
	 * stopping the node. The command is the agent's own — the token lives there,
	 * not in the node's PHP — and it is restrictive, so the extension signs it
	 * even while MAIN's licence is refused.
	 *
	 * @return array{0: bool, 1: bool} [routed, queued]
	 */
	public static function rotateNow(int $rServerID): array {
		return self::enqueue($rServerID, 'token.rotate_now', [], 'token.rotate_now');
	}

	/**
	 * Drop a viewer the node's fanout serves (a daemon viewer has no worker
	 * pid): `conn.drop {uuid}`, run by the node's agent against its fanout.
	 * Restrictive, like kill.
	 *
	 * @return array{0: bool, 1: bool}
	 */
	public static function drop(int $rServerID, string $rUUID): array {
		if (!preg_match(AgentConnections::CONN_UUID, $rUUID)) {
			return [false, false];
		}
		return self::enqueue($rServerID, 'conn.drop', ['uuid' => $rUUID], 'drop:' . $rUUID);
	}

	/**
	 * A close MAIN made to a connection another node's agent holds (its
	 * CONNECTIONS flow is on): `conn.close {uuid, remove}`, so the node's
	 * registry follows and a kicked HLS viewer is not resumed there.
	 *
	 * @return array{0: bool, 1: bool}
	 */
	public static function closeConnection(int $rServerID, string $rUUID, bool $rRemove): array {
		if (!preg_match(AgentConnections::CONN_UUID, $rUUID)) {
			return [false, false];
		}
		try {
			$rNode = NodeRegistry::byServer($rServerID);
		} catch (\Throwable) {
			return [false, false];
		}
		if ($rNode === null || ((int) $rNode['flows'] & NodeRegistry::FLOW_CONNECTIONS) === 0) {
			return [false, false];
		}
		return self::enqueue($rServerID, 'conn.close', ['uuid' => $rUUID, 'remove' => $rRemove], 'close:' . $rUUID);
	}

	/**
	 * Cache jobs for a node in mode 2 (SignalDispatcher::cache and
	 * cacheBatch): its signals daemon reads no `signals` row (its connects
	 * to MAIN are refused), so they go as signed `node.cache {jobs}`
	 * commands, in order, none naming more than CacheJobs::MAX targets (a
	 * list of ids split across them: CacheJobs::commands), which the node's
	 * cluster:exec runs as the daemon ran the rows. A job not in the form
	 * the node runs (CacheJobs::job) is left out.
	 * Granting to the extension, which classes by type: without a licence
	 * nothing is sent. MAIN's own jobs, nodes in mode 0 or 1 and nodes that
	 * take no command keep the legacy row.
	 *
	 * @param list<array<string, mixed>> $rJobs payloads as the signals rows carried them
	 * @return array{0: bool, 1: bool}
	 */
	public static function cache(int $rServerID, array $rJobs): array {
		if (defined('SERVER_ID') && $rServerID === (int) SERVER_ID) {
			return [false, false];
		}
		try {
			$rNode = empty(SettingsManager::get('cluster_api_enabled')) ? null : NodeRegistry::byServer($rServerID);
		} catch (\Throwable) {
			return [false, false];
		}
		if ($rNode === null || (int) $rNode['mode'] !== 2) {
			return [false, false];
		}
		return self::command($rServerID, 'node.cache', static function (ClusterCrypto $rCrypto) use ($rServerID, $rJobs): bool {
			// The removals first, as a restrictive node.purge that signs without a
			// licence; an extension from before it refuses the type, and they go
			// as node.cache like the rest.
			[$rPurges, $rRest] = CacheJobs::split($rJobs);
			$rSent = false;
			foreach (CacheJobs::commands($rPurges) as $rCommand) {
				try {
					CommandBus::enqueue($rCrypto, $rServerID, 'node.purge', ['jobs' => $rCommand]);
				} catch (ClusterRefusedException $rE) {
					if ($rE->reason() !== 'RECORD:type') {
						throw $rE;
					}
					CommandBus::enqueue($rCrypto, $rServerID, 'node.cache', ['jobs' => $rCommand]);
				}
				$rSent = true;
			}
			foreach (CacheJobs::commands($rRest) as $rCommand) {
				CommandBus::enqueue($rCrypto, $rServerID, 'node.cache', ['jobs' => $rCommand]);
				$rSent = true;
			}
			return $rSent;
		}, false, false, $rNode);
	}

	/**
	 * A root action (NodeActions): a signed `node.root` command, run on the
	 * node by cluster:root after it checks the signature against its
	 * root-owned pin of the panel key. Only for nodes that report that pin.
	 * An action that needs a file of MAIN's carries its artefact grant
	 * (ArtefactGrants::forRoot).
	 *
	 * @param array<string, mixed> $rPayload {action, …} as the signals row carried it
	 * @return array{0: bool, 1: bool}
	 */
	public static function root(int $rServerID, array $rPayload): array {
		return self::command($rServerID, 'node.root', static function (ClusterCrypto $rCrypto) use ($rServerID, $rPayload): bool {
			// A strip does not wait a day for its node: it was judged as it was queued (DbCredentials::strip).
			$rTtl = ($rPayload['action'] ?? null) === NodeCredentials::STRIP ? DbCredentials::STRIP_TTL : null;
			CommandBus::enqueue($rCrypto, $rServerID, 'node.root', ArtefactGrants::forRoot($rServerID, $rPayload), null, $rTtl);
			return true;
		}, false, true);
	}

	/**
	 * One command for a node that takes them (target()), its type's TTL and
	 * the given dedupe key: [routed, queued].
	 *
	 * @param array<string, mixed> $rArgs
	 * @return array{0: bool, 1: bool}
	 */
	private static function enqueue(int $rServerID, string $rType, array $rArgs, ?string $rDedupeKey = null, bool $rQuarantined = false): array {
		return self::command($rServerID, $rType, static function (ClusterCrypto $rCrypto) use ($rServerID, $rType, $rArgs, $rDedupeKey): bool {
			CommandBus::enqueue($rCrypto, $rServerID, $rType, $rArgs, $rDedupeKey);
			return true;
		}, false, false, null, $rQuarantined);
	}

	/**
	 * Every route's shape: [false, $rFailed] when the node takes no command
	 * (target(), with $rRoot and $rNode as it takes them), else [true, what
	 * $rSend queued with the extension], or [true, $rFailed] when it threw,
	 * the reason logged (unsent(), under $rType). The legacy path is never
	 * tried once routed.
	 *
	 * $rQuarantined also routes to a quarantined node, for the restrictive
	 * commands it is still handed (ClusterApi::commands()).
	 *
	 * @param \Closure(ClusterCrypto): mixed $rSend
	 * @param array<string, mixed>|null $rNode
	 * @return array{0: bool, 1: mixed}
	 */
	private static function command(int $rServerID, string $rType, \Closure $rSend, mixed $rFailed = false, bool $rRoot = false, ?array $rNode = null, bool $rQuarantined = false): array {
		$rCrypto = self::target($rServerID, $rRoot, $rNode, $rQuarantined);
		if (!$rCrypto instanceof ClusterCrypto) {
			return [false, $rFailed];
		}
		try {
			return [true, $rSend($rCrypto)];
		} catch (\Throwable $rE) {
			self::unsent($rServerID, $rType, $rE);
			return [true, $rFailed];
		}
	}

	/**
	 * A command the node's channel did not take, and why (the extension's
	 * refusal code, or the database's), in the panel's error log.
	 */
	private static function unsent(int $rServerID, string $rType, \Throwable $rE): void {
		$rWhy = $rE instanceof ClusterRefusedException ? 'refused: ' . $rE->reason() : $rE->getMessage();
		$rPrev = $rE->getPrevious();
		FileLogger::log('cluster', 'Command ' . $rType . ' for server ' . $rServerID . ' not queued (' . $rWhy . ')', $rPrev !== null ? $rPrev->getMessage() : '');
	}

	/** Tests: supply the extension handle. Null restores the factory. */
	public static function useCrypto(?callable $rFactory): void {
		self::$rCrypto = $rFactory;
	}

	/**
	 * The extension, when this node takes commands; null for the legacy path.
	 *
	 * @param array<string, mixed>|null $rNode the node's row, when the caller read it with cluster_api_enabled on
	 */
	private static function target(int $rServerID, bool $rRoot = false, ?array $rNode = null, bool $rQuarantined = false): ?ClusterCrypto {
		try {
			$rNode ??= empty(SettingsManager::get('cluster_api_enabled')) ? null : NodeRegistry::byServer($rServerID);
			if ($rQuarantined && $rNode !== null && $rNode['state'] === 'quarantined') {
				$rNode['state'] = 'active';
			}
			if (!($rRoot ? CommandBus::acceptsRoot($rNode) : CommandBus::accepts($rNode))) {
				return null;
			}
			return self::$rCrypto !== null ? (self::$rCrypto)() : ClusterCryptoFactory::create();
		} catch (\Throwable) {
			return null; // no cluster tables, no extension: the legacy path
		}
	}
}
