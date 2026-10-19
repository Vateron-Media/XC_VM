<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Cache\FileCache;

/**
 * The node's stream caches built from its R2 `streams` section (plan,
 * section 9, "Storage and boot"): one entry per stream the node holds, in the
 * shapes the node's readers took from MAIN's database, so they keep their
 * contract (StreamSource, and through it the stream starts, the monitor,
 * the proxy producer, live.php's fanout hand-off, the scanner, and the
 * recordings):
 *
 * ```text
 * <cache dir>/replica_streams/<id>   one entry per stream:
 *   stream      the `streams` row: the record's STREAM_FIELDS, the local
 *               columns (STREAM_LOCAL) null
 *   type        its `streams_types` row, or null
 *   profile     its transcoding profile, or null
 *   server      the node's `streams_servers` row: STREAM_SERVER_FIELDS,
 *               the runtime columns (STREAM_SERVER_LOCAL) null; or null
 *   arguments   `SELECT t1.*, t2.*` of streams_options ⨝ streams_arguments:
 *               the argument's id as `id` (it wins the join), and
 *               `argument_description`, which no record carries, null
 *   recordings  its `recordings` rows scheduled on the node (every column)
 *   children    the servers that relay it from the node
 *   etag, ver   the record's
 * <cache dir>/replica_streams/index   {streams: {id: meta()}, unreadable: [ids whose record did not read]}
 * ```
 *
 * The directory is 0700: an entry holds the stream's sources, which may
 * carry an upstream's credentials (the agent keeps its files 0600 for the
 * same reason).
 *
 * The cache directory is the other caches' (tmp/cache/, a tmpfs), so the
 * entries go with `replica_owned` at a reboot and an apply builds them again:
 * `cluster:apply --from-disk` in `service` on a node whose CONFIG flow is on;
 * otherwise `startup`'s `cron:cache` (the minute's apply), once the node
 * booted through MAIN's database as it does for every other cache then.
 * ReplicaApply::streams() writes them; the readers here take them only while
 * the replica owns the section (owned()): the STREAMS flow on, the section
 * stored by the agent, and an apply that built them since. Then no reader
 * reads MAIN's database for a stream's definition: an entry missing is built
 * from the agent's file (`replica/streams/<id>.json`, a record it stored
 * since that apply), and a stream without one is a stream the node does not
 * hold. A stream whose record did not read at the last apply (from disk: did
 * not verify) is not built from its file: it keeps the entry it had, or has
 * none, until the next apply reads its record. That apply, without
 * `--from-disk` (the agent's, or cron:cache's minute), trusts the agent's
 * `.json` as it does for every section.
 *
 * What no record carries stays null in an entry: the node's own runtime
 * state (pids, status, the current source, probe results, the created
 * channel's build state), which it keeps in its own store (StreamRuntime)
 * and StreamSource lays over the entry once that is seeded, and MAIN's
 * catalogue metadata.
 */
final class ReplicaStreamCache {
	/** The entries' directory, under the other caches'. */
	public const DIR = 'replica_streams/';

	/** The index's name in that directory (a stream's entry is its id). */
	public const INDEX = 'index';

	/** @var array<string, FileCache> */
	private static array $rStores = [];

	/** The entries' store, in the default cache directory. */
	public static function store(): FileCache {
		$rPath = FileCache::defaultPath() . self::DIR;
		if (!isset(self::$rStores[$rPath])) {
			self::$rStores[$rPath] = new FileCache($rPath);
			if (is_dir($rPath) && (fileperms($rPath) & 0777) !== 0700) {
				@chmod($rPath, 0700);
			}
		}
		return self::$rStores[$rPath];
	}

	/**
	 * Does the replica own the streams' definitions? The record first (no
	 * flows.json read on MAIN or a legacy node, which have none), then the
	 * STREAMS flow and the agent's section (ReplicaApply::owns).
	 */
	public static function owned(): bool {
		return ReplicaApply::built(ReplicaSections::STREAMS) && ReplicaApply::owns(ReplicaSections::STREAMS);
	}

	/**
	 * A record's data as the node's readers take it (above), or null when it
	 * is not the record of this stream for this node: its `stream` is another
	 * stream, its `server` row or a recording names another server, or a part
	 * is not what MAIN sends. A key a later MAIN adds is left out.
	 *
	 * @param array<mixed> $rData the record's data, as MAIN signed it
	 * @return array<string, mixed>|null
	 */
	public static function entry(int $rID, array $rData, int $rServerID, string $rEtag, int $rVer): ?array {
		$rStream = $rData['stream'] ?? null;
		$rType = $rData['type'] ?? null;
		$rProfile = $rData['profile'] ?? null;
		$rServer = $rData['server'] ?? null;
		if (!is_array($rStream) || ($rStream['id'] ?? null) !== $rID || $rServerID <= 0) {
			return null;
		}
		if (($rType !== null && (!is_array($rType) || !is_int($rType['type_id'] ?? null))) || ($rProfile !== null && (!is_array($rProfile) || !is_int($rProfile['profile_id'] ?? null)))) {
			return null;
		}
		if ($rServer !== null && (!is_array($rServer) || ($rServer['server_id'] ?? null) !== $rServerID || ($rServer['stream_id'] ?? null) !== $rID)) {
			return null;
		}
		if (!ReplicaRecords::listOf($rData['options'] ?? [], 'is_array') || !ReplicaRecords::listOf($rData['children'] ?? [], 'is_int') || !ReplicaRecords::listOf($rData['recordings'] ?? [], 'is_array')) {
			return null;
		}
		$rArguments = [];
		foreach ($rData['options'] ?? [] as $rOption) {
			if (!is_int($rOption['argument_id'] ?? null)) {
				return null;
			}
			$rRow = ReplicaSections::typed($rOption, ReplicaSections::OPTION_FIELDS + ReplicaSections::ARGUMENT_FIELDS);
			// As `SELECT t1.*, t2.*` returns it: the option's columns, then the
			// argument's, whose `id` (the option's argument_id) wins the join.
			$rArguments[] = [
				'id' => $rRow['argument_id'], 'stream_id' => $rID, 'argument_id' => $rRow['argument_id'], 'value' => $rRow['value'],
				'argument_cat' => $rRow['argument_cat'], 'argument_name' => $rRow['argument_name'], 'argument_description' => null,
				'argument_wprotocol' => $rRow['argument_wprotocol'], 'argument_key' => $rRow['argument_key'], 'argument_cmd' => $rRow['argument_cmd'],
				'argument_type' => $rRow['argument_type'], 'argument_default_value' => $rRow['argument_default_value'],
			];
		}
		$rRecordings = [];
		foreach ($rData['recordings'] ?? [] as $rRecording) {
			if (!is_int($rRecording['id'] ?? null) || ($rRecording['stream_id'] ?? null) !== $rID || ($rRecording['source_id'] ?? null) !== $rServerID) {
				return null;
			}
			$rRecordings[] = ReplicaSections::typed($rRecording, ReplicaSections::RECORDING_FIELDS);
		}
		return [
			'etag' => $rEtag, 'ver' => $rVer,
			'resume' => ($rData['resume'] ?? false) === true,
			'stream' => ReplicaSections::typed($rStream, ReplicaSections::STREAM_FIELDS) + array_fill_keys(ReplicaSections::STREAM_LOCAL, null),
			'type' => $rType === null ? null : ReplicaSections::typed($rType, ReplicaSections::STREAM_TYPE_FIELDS),
			'profile' => $rProfile === null ? null : ReplicaSections::typed($rProfile, ReplicaSections::PROFILE_FIELDS),
			'server' => $rServer === null ? null : ReplicaSections::typed($rServer, ReplicaSections::STREAM_SERVER_FIELDS) + array_fill_keys(ReplicaSections::STREAM_SERVER_LOCAL, null),
			'arguments' => $rArguments,
			'recordings' => $rRecordings,
			'children' => array_values($rData['children'] ?? []),
		];
	}

	/**
	 * A built entry's line in the index: its record's `etag` and `ver`, its
	 * recordings (`rec`), its `server_stream_id` on the node (`ssid`), and
	 * what the lists walking the node's streams filter on (NodeStreams), so
	 * they read an entry only when it may pass: its `on_demand` on the node
	 * (`od`, null without a row there), its `streams.type` (`type`), its
	 * type's `live` flag (`live`, null without a type), its `direct_source`
	 * (`ds`) and `direct_proxy` (`dp`). As the entries, the last apply's.
	 *
	 * @param array<string, mixed> $rEntry
	 * @return array{etag: string, ver: int, rec: list<int>, ssid: int|null, od: int|null, type: int|null, live: int|null, ds: int|null, dp: int|null, resume: bool}
	 */
	public static function meta(array $rEntry): array {
		return [
			'etag' => $rEntry['etag'], 'ver' => $rEntry['ver'], 'rec' => array_column($rEntry['recordings'], 'id'),
			'ssid' => $rEntry['server']['server_stream_id'] ?? null, 'od' => $rEntry['server']['on_demand'] ?? null,
			'type' => $rEntry['stream']['type'] ?? null, 'live' => $rEntry['type']['live'] ?? null,
			'ds' => $rEntry['stream']['direct_source'] ?? null, 'dp' => $rEntry['stream']['direct_proxy'] ?? null,
			'resume' => ($rEntry['resume'] ?? false) === true,
		];
	}

	/**
	 * The entry of a stream the node holds: the cache, or when it is gone the
	 * agent's file (written to the cache again); null when the node does not
	 * hold it or its record does not read. Never MAIN's database.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get(int $rID): ?array {
		$rEntry = self::store()->get((string) $rID);
		if (is_array($rEntry)) {
			return $rEntry;
		}
		// Its record did not read (from disk: did not verify) at the last apply.
		if (in_array($rID, self::unreadable(), true)) {
			return null;
		}
		$rDoc = ReplicaRecords::storedStream(ReplicaApply::dir(), $rID);
		if ($rDoc === null) {
			return null;
		}
		$rEntry = self::entry($rID, $rDoc['data'], defined('SERVER_ID') ? (int) SERVER_ID : 0, $rDoc['etag'], $rDoc['ver']);
		if ($rEntry !== null) {
			self::store()->set((string) $rID, $rEntry);
		}
		return $rEntry;
	}

	/** An agent that says this in flows.json syncs the streams section on request (syncMissing). */
	public const FEATURE_SYNC = 'streams_sync';

	/** How long a start waits on its agent's sync. */
	public const SYNC_TIMEOUT = 10.0;

	/** A stream asked again after this long may be synced again (a later assignment). */
	public const SYNC_AGAIN_SEC = 60;

	/** @var array<int, int> stream id => when this process last synced for it */
	private static array $rSynced = [];

	/**
	 * A start found no entry for $rID: MAIN may have assigned the stream a
	 * moment ago, its record not yet synced (the start's command raced the
	 * agent's sync). Ask the agent for a streams sync now (`POST
	 * /v1/streams_sync`, when flows.json says it can) and say whether it
	 * synced, so the caller reads again. Once per stream a minute in a
	 * process, never for a stream the node has an entry for, or one whose
	 * record did not read at the last apply. The plan's `stream_bundle` op on
	 * a start miss, done with the delta MAIN already serves.
	 */
	public static function syncMissing(int $rID): bool {
		if (($rAt = self::$rSynced[$rID] ?? null) !== null && time() - $rAt < self::SYNC_AGAIN_SEC) {
			return false;
		}
		if (!NodeFlows::agentHas(self::FEATURE_SYNC) || self::get($rID) !== null || in_array($rID, self::unreadable(), true)) {
			return false;
		}
		self::$rSynced[$rID] = time();
		$rOut = AgentClient::request('POST', '/v1/streams_sync', null, self::SYNC_TIMEOUT);
		return $rOut !== null && $rOut[0] === 200;
	}

	/**
	 * StreamSource::streamRow's answer: the `streams` row with its type (live
	 * or not) and profile, or null (not held, another kind, a direct source,
	 * no type), as the SQL join answers.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function streamRow(int $rID, bool $rLive): ?array {
		$rEntry = self::get($rID);
		if ($rEntry === null || $rEntry['type'] === null || $rEntry['type']['live'] !== ($rLive ? 1 : 0) || $rEntry['stream']['direct_source'] !== 0) {
			return null;
		}
		return $rEntry['stream'] + $rEntry['type'] + ($rEntry['profile'] ?? array_fill_keys(array_keys(ReplicaSections::PROFILE_FIELDS), null));
	}

	/**
	 * StreamSource::serverRow's answer for this node.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function serverRow(int $rID): ?array {
		return self::get($rID)['server'] ?? null;
	}

	/**
	 * StreamSource::arguments' answer: a list, or keyed by `argument_key`.
	 *
	 * @return array<int|string, array<string, mixed>>
	 */
	public static function arguments(int $rID, bool $rKeyed): array {
		$rRows = self::get($rID)['arguments'] ?? [];
		if (!$rKeyed) {
			return $rRows;
		}
		$rOut = [];
		foreach ($rRows as $rRow) {
			$rOut[(string) $rRow['argument_key']] = $rRow;
		}
		return $rOut;
	}

	/**
	 * StreamSource::sourceRow's answer.
	 *
	 * @return array{stream_source?: string|null}
	 */
	public static function sourceRow(int $rID): array {
		$rEntry = self::get($rID);
		return $rEntry === null ? [] : ['stream_source' => $rEntry['stream']['stream_source']];
	}

	/**
	 * A recording scheduled on this node (every `recordings` column), or null.
	 * Its `status` is MAIN's, as last heard. The index names the recordings of
	 * the entries the last apply built; on a miss, the streams whose record
	 * the agent stored since (not in the index) are looked through too, their
	 * entries built from its files as get() builds them.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function recording(int $rRecordingID): ?array {
		$rIndex = self::index();
		$rIDs = [];
		foreach ($rIndex as $rID => $rMeta) {
			if (in_array($rRecordingID, $rMeta['rec'] ?? [], true)) {
				$rIDs[] = (int) $rID;
			}
		}
		$rFound = self::recordingOf($rIDs, $rRecordingID);
		if ($rFound !== null) {
			return $rFound;
		}
		$rHeld = ReplicaStreams::ids();
		return is_array($rHeld) ? self::recordingOf(array_diff($rHeld, array_keys($rIndex)), $rRecordingID) : null;
	}

	/**
	 * That recording among these streams' entries, or null.
	 *
	 * @param array<int> $rIDs
	 * @return array<string, mixed>|null
	 */
	private static function recordingOf(array $rIDs, int $rRecordingID): ?array {
		foreach ($rIDs as $rID) {
			foreach (self::get((int) $rID)['recordings'] ?? [] as $rRow) {
				if ($rRow['id'] === $rRecordingID) {
					return $rRow;
				}
			}
		}
		return null;
	}

	/**
	 * The streams the node holds: those the last apply built, and those the
	 * agent stored since (their files), ascending.
	 *
	 * @return list<int>
	 */
	public static function held(): array {
		$rIDs = array_keys(self::index());
		$rStored = ReplicaStreams::ids();
		if (is_array($rStored)) {
			$rIDs = array_merge($rIDs, $rStored);
		}
		$rIDs = array_values(array_unique(array_map('intval', $rIDs)));
		sort($rIDs);
		return $rIDs;
	}

	/**
	 * What the last apply built: stream id => its meta().
	 *
	 * @return array<int, array{etag: string, ver: int, rec: list<int>, ssid?: int|null, od?: int|null, type?: int|null, live?: int|null, ds?: int|null, dp?: int|null}>
	 */
	public static function index(): array {
		$rIndex = self::store()->get(self::INDEX);
		return is_array($rIndex) && is_array($rIndex['streams'] ?? null) ? $rIndex['streams'] : [];
	}

	/**
	 * The streams whose record did not read at the last apply: never built
	 * from their file until an apply reads it.
	 *
	 * @return list<int>
	 */
	public static function unreadable(): array {
		$rIndex = self::store()->get(self::INDEX);
		return is_array($rIndex) && is_array($rIndex['unreadable'] ?? null) ? $rIndex['unreadable'] : [];
	}

	/**
	 * @param array<int, array{etag: string, ver: int, rec: list<int>, ssid?: int|null, od?: int|null, type?: int|null, live?: int|null, ds?: int|null, dp?: int|null}> $rStreams
	 * @param list<int> $rUnreadable
	 */
	public static function writeIndex(array $rStreams, array $rUnreadable = []): bool {
		ksort($rStreams);
		return self::store()->set(self::INDEX, ['streams' => $rStreams, 'unreadable' => array_values($rUnreadable)]);
	}

	/**
	 * The streams with an entry in the cache, by the files' names.
	 *
	 * @return list<int>
	 */
	public static function cached(): array {
		return FileIds::of(glob(self::store()->getBasePath() . '*') ?: [], '', false);
	}
}
