<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterRefusedException;
use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Core\Cluster\StreamRecords;
use XcVm\Core\Cluster\StreamRuntimeDirective;
use XcVm\Core\Cluster\StreamVersions;
use XcVm\Core\Cluster\StrictQuery;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Domain\Stream\StreamSource;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * The node replica's R2 `streams` section on MAIN (plan, section 9): one
 * `stream` record per stream a node holds (ReplicaSections), panel-signed
 * (`rep`) and sealed to the node like the whole sections, but never sent
 * whole. The `streams` op serves it two ways:
 *
 * - **Delta** (delta()): what changed past the node's cursor, by the
 *   versions StreamVersions stamps in `cluster_stream_ver`. A row whose
 *   stream the node still holds is its record at the row's version; any
 *   other is a removal. The cursor is the version of the last row served:
 *   bumps commit in the order of their versions, so the node has everything
 *   up to it. A cursor of 0 (nothing held), below the node's floor (every
 *   stream changed at once, or versions pruned) or above the head (MAIN's
 *   versions went back) is answered `full`: the node checks every stream.
 * - **Resync** (resync()): the section hashes. The node names the ETag of
 *   each stream it holds in a range of ids; MAIN compares them with every
 *   stream the node holds in that range and answers only the records that
 *   differ, and the removals. It is the safety net for what no version
 *   marks (a writer that dispatches nothing, a restore), every 5 minutes,
 *   and how a node takes the whole section at first (`full`).
 *
 * A record names the node, its generation and the stream, so it opens and
 * verifies only there; its ETag is the SHA-256 of its canonical data, as a
 * whole section's, taken with the `tickets` slot empty: a node with its
 * DATAPLANE flow on finds its relay and file tickets there (TicketService),
 * and fresh ones on the delta path each epoch, without the record's ETag or
 * version moving. It grants (a stream to run), so without a licence it is
 * not signed: it is left out and counted in `withheld`, the removals still
 * go, and a delta's cursor stops before it. Every read throws when it fails,
 * so the op answers `503 DB` instead of signing a partial section.
 */
final class StreamReplica {
	use DatabaseAware;

	/**
	 * What an agent says at hello (`features`) when it keeps the R2 streams
	 * section: only those agents are served the op.
	 */
	public const FEATURE = 'streams';

	/** Records per reply; and at most MAX_BYTES of them (base64), at least one. */
	public const MAX_RECORDS = 200;
	public const MAX_BYTES = 4194304;

	/** Version rows a delta reads per call. */
	public const MAX_ROWS = 1000;

	/** Streams a resync compares per call, and the hashes a request may carry. */
	public const MAX_EXAMINE = 1000;
	public const MAX_HASHES = 2000;

	/** The highest stream id (int(11)). */
	public const MAX_ID = StreamRecords::MAX_ID;

	/** A version row no node holds is kept this long; then it goes, the node's floor raised first. */
	public const KEEP_DAYS = 7;

	/** Rows cron:cluster looks at per run. */
	public const PRUNE_ROWS = 10000;

	/** cluster_meta: the last row the pruning looked at, "<server_id>:<stream_id>" ("0:0": the start). */
	public const META_PRUNE = 'stream_ver_prune';

	/**
	 * Is the op served to this node: its STREAMS flow is on, and its agent
	 * said FEATURE at hello (an older agent is never sent the section).
	 *
	 * @param array<string, mixed> $rNode
	 * @return string|null null when served, else what is missing ('flow' or 'feature')
	 */
	public static function refused(array $rNode): ?string {
		if (((int) ($rNode['flows'] ?? 0) & NodeRegistry::FLOW_STREAMS) === 0) {
			return 'flow';
		}
		return in_array(self::FEATURE, explode(',', (string) ($rNode['features'] ?? '')), true) ? null : 'feature';
	}

	/**
	 * A resync request as the node sends it: `{from, to, hashes: {"<id>": etag}}`,
	 * stream ids within from..to, at most MAX_HASHES; `hashes` null or absent
	 * names none (an agent's empty map). Null when malformed.
	 *
	 * @return array{from: int, to: int, hashes: array<int, string>}|null
	 */
	public static function resyncRequest(mixed $rAsk): ?array {
		if (!is_array($rAsk) || !is_int($rAsk['from'] ?? null) || !is_int($rAsk['to'] ?? null)) {
			return null;
		}
		[$rFrom, $rTo, $rHashes] = [$rAsk['from'], $rAsk['to'], $rAsk['hashes'] ?? []];
		if (!is_array($rHashes) || $rFrom < 0 || $rTo < $rFrom || $rTo > self::MAX_ID || count($rHashes) > self::MAX_HASHES) {
			return null;
		}
		$rOut = [];
		foreach ($rHashes as $rID => $rEtag) {
			if (!is_int($rID) || $rID < 1 || $rID < $rFrom || $rID > $rTo || !is_string($rEtag) || !preg_match('/^[0-9a-f]{64}\z/', $rEtag)) {
				return null;
			}
			$rOut[$rID] = $rEtag;
		}
		return ['from' => $rFrom, 'to' => $rTo, 'hashes' => $rOut];
	}

	/**
	 * What changed past the node's cursor.
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row
	 * @param array{epoch: int, from: int}|null $rTickets the node's ticket refresh (TicketService::request), if it asks
	 * @return array{ver: int, head: int, more: bool, full?: bool, streams: list<array{id: int, ver: int, etag: string, sealed: string}>, removed: list<int>, withheld?: int, tickets?: array{epoch: int, streams: object, next: ?int, withheld?: bool}}
	 */
	public static function delta(ClusterCrypto $rCrypto, array $rNode, int $rSince, ?array $rTickets = null): array {
		$rServerID = (int) $rNode['server_id'];
		$rHead = StreamVersions::head(self::db());
		if ($rSince <= 0 || $rSince < StreamVersions::floor($rServerID, self::db()) || $rSince > $rHead) {
			return ['ver' => $rSince, 'head' => $rHead, 'more' => false, 'full' => true, 'streams' => [], 'removed' => []];
		}
		// The ticket refresh rides the delta (TicketService::refresh); it
		// moves no version and no ETag.
		$rRefresh = $rTickets === null ? null : TicketService::refresh($rCrypto, $rNode, $rTickets, ClusterClock::now());
		self::read('SELECT `stream_id`, `ver` FROM `cluster_stream_ver` WHERE `server_id` = ? AND `ver` > ? ORDER BY `ver` ASC LIMIT ' . self::MAX_ROWS . ';', $rServerID, $rSince);
		$rRows = array_map(static fn(array $rRow): array => [(int) $rRow['stream_id'], (int) $rRow['ver']], self::db()->get_rows() ?: []);
		$rData = self::data($rServerID, self::held($rServerID, array_column($rRows, 0)));
		$rPage = self::pageServeOnly($rCrypto, $rNode, $rRows, $rData, null);
		$rDone = $rPage['done'];
		$rOut = [
			'ver' => $rDone > 0 ? $rRows[$rDone - 1][1] : $rSince, 'head' => $rHead,
			'more' => $rDone < count($rRows) ? !$rPage['withheld'] : count($rRows) >= self::MAX_ROWS,
			'streams' => $rPage['streams'], 'removed' => $rPage['removed'],
		];
		return $rOut + ($rPage['withheld'] > 0 ? ['withheld' => $rPage['withheld']] : []) + ($rRefresh !== null ? ['tickets' => $rRefresh] : []);
	}

	/**
	 * The section hashes: the records of the streams the node holds in
	 * from..to whose ETag differs from the one it names, and the removals of
	 * those it names that it does not hold. `next` is where to go on from
	 * when the reply could not cover the range, else null. The node's cursor
	 * is left as it was.
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row
	 * @param array<int, string> $rHashes stream id => the ETag the node holds
	 * @return array{ver: int, head: int, next: ?int, streams: list<array{id: int, ver: int, etag: string, sealed: string}>, removed: list<int>, withheld?: int}
	 */
	public static function resync(ClusterCrypto $rCrypto, array $rNode, int $rSince, int $rFrom, int $rTo, array $rHashes): array {
		$rServerID = (int) $rNode['server_id'];
		$rHead = StreamVersions::head(self::db());
		$rHeld = self::held($rServerID, null, $rFrom, $rTo, self::MAX_EXAMINE + 1);
		$rNext = null;
		if (count($rHeld) > self::MAX_EXAMINE) {
			$rHeld = array_slice($rHeld, 0, self::MAX_EXAMINE);
			$rNext = (int) end($rHeld) + 1;
		}
		$rBound = $rNext === null ? $rTo : $rNext - 1;
		$rIDs = array_merge($rHeld, array_filter(array_keys($rHashes), static fn(int $rID): bool => $rID <= $rBound));
		$rIDs = array_values(array_unique($rIDs));
		sort($rIDs);
		$rVers = self::versions($rServerID, $rHeld);
		$rItems = array_map(static fn(int $rID): array => [$rID, $rVers[$rID] ?? 0], $rIDs);
		$rPage = self::pageServeOnly($rCrypto, $rNode, $rItems, self::data($rServerID, $rHeld), $rHashes);
		if ($rPage['done'] < count($rItems)) {
			$rNext = $rItems[$rPage['done']][0];
		}
		$rOut = ['ver' => $rSince, 'head' => $rHead, 'next' => $rNext, 'streams' => $rPage['streams'], 'removed' => $rPage['removed']];
		return $rOut + ($rPage['withheld'] > 0 ? ['withheld' => $rPage['withheld']] : []);
	}

	/**
	 * page(), gated for a serve-only target: before signing the records, it
	 * installs the batched snapshot loader (runtimeLoader) and the directive
	 * composer (runtimeUpgrade) so page() upgrades each survivor's directive to
	 * a portable transcode Spec, always restoring the previous StreamSource
	 * backend. A non-serve-only target pages exactly as before (no loader, no
	 * composer) — the inert guarantee. The serve_only column is read once here.
	 *
	 * @param list<array{0: int, 1: int}> $rItems
	 * @param array<int, array<string, mixed>> $rData
	 * @param array<int, string>|null $rHave
	 * @return array{streams: list<array{id: int, ver: int, etag: string, sealed: string}>, removed: list<int>, done: int, withheld: int}
	 */
	private static function pageServeOnly(ClusterCrypto $rCrypto, array $rNode, array $rItems, array $rData, ?array $rHave): array {
		$rServerID = (int) $rNode['server_id'];
		if (!self::serveOnly($rServerID)) {
			return self::page($rCrypto, $rNode, $rItems, $rData, $rHave);
		}
		// features [] — MAIN does not store a node's live remux capability, so
		// the Spec defaults to ffmpeg (never wrong). Wiring real features is a follow-up.
		StreamSource::useLoader(self::runtimeLoader($rServerID, array_keys($rData)));
		try {
			return self::page($rCrypto, $rNode, $rItems, $rData, $rHave, self::runtimeUpgrade($rServerID, []));
		} finally {
			StreamSource::useLoader(null);
		}
	}

	/** Is this server a serve-only LB (`serve_only` on its `servers` row): its Go agent runs the streams MAIN directs (runtimeUpgrade). */
	private static function serveOnly(int $rServerID): bool {
		self::read('SELECT `serve_only` FROM `servers` WHERE `id` = ?;', $rServerID);
		return (bool) (int) (self::db()->get_row()['serve_only'] ?? 0);
	}

	/**
	 * The records of a page, in the order given: a stream the node holds is
	 * sent (unless it names its ETag in $rHave), one it no longer holds is a
	 * removal (in a resync, only one it names). `done` counts the items
	 * handled; the page stops at the first record past the reply's size, and
	 * a delta's at the first one that cannot be signed without a licence.
	 *
	 * @param list<array{0: int, 1: int}> $rItems [stream id, version]
	 * @param array<int, array<string, mixed>> $rData the held streams' data
	 * @param array<int, string>|null $rHave the node's ETags (a resync), null for a delta
	 * @return array{streams: list<array{id: int, ver: int, etag: string, sealed: string}>, removed: list<int>, done: int, withheld: int}
	 */
	private static function page(ClusterCrypto $rCrypto, array $rNode, array $rItems, array $rData, ?array $rHave, ?callable $rUpgrade = null): array {
		$rStreams = [];
		$rRemoved = [];
		$rBytes = 0;
		$rWithheld = 0;
		$rDone = 0;
		foreach ($rItems as [$rID, $rVer]) {
			if (!isset($rData[$rID])) {
				if ($rHave === null || isset($rHave[$rID])) {
					$rRemoved[] = $rID;
				}
				$rDone++;
				continue;
			}
			if ($rUpgrade !== null) {
				// A serve-only target: compose the portable Spec for this survivor
				// and upgrade its directive, before the ETag is taken over it.
				$rData[$rID]['runtime'] = $rUpgrade($rID, $rData[$rID]['runtime']);
			}
			$rEtag = ReplicaBuilder::etag($rData[$rID]);
			if ($rHave !== null && ($rHave[$rID] ?? '') === $rEtag) {
				$rDone++;
				continue;
			}
			if (count($rStreams) >= self::MAX_RECORDS) {
				break;
			}
			try {
				// The tickets of the moment go in the record, after its ETag was
				// taken without them (TicketService): a refresh moves no ETag.
				$rRecord = $rData[$rID];
				$rRecord['tickets'] = TicketService::forRecord($rCrypto, $rNode, $rRecord, ClusterClock::now());
				$rDoc = [
					'v' => 1, 'section' => ReplicaSections::STREAM, 'node' => (string) $rNode['node_uuid'], 'gen' => (int) $rNode['gen'],
					'stream_id' => $rID, 'ver' => $rVer, 'etag' => $rEtag, 'iat' => ClusterClock::now(), 'data' => $rRecord,
				];
				$rSealed = base64_encode(ReplicaBuilder::record($rCrypto, $rNode, 'rep', ReplicaBuilder::json($rDoc)));
			} catch (ClusterRefusedException $rE) {
				if ($rE->reason() !== 'LICENCE') {
					throw $rE;
				}
				// A record grants: left out, and a delta's cursor stops before it.
				$rWithheld++;
				if ($rHave === null) {
					break;
				}
				$rDone++;
				continue;
			}
			if ($rStreams !== [] && $rBytes + strlen($rSealed) > self::MAX_BYTES) {
				break;
			}
			$rBytes += strlen($rSealed);
			$rStreams[] = ['id' => $rID, 'ver' => $rVer, 'etag' => $rEtag, 'sealed' => $rSealed];
			$rDone++;
		}
		return ['streams' => $rStreams, 'removed' => $rRemoved, 'done' => $rDone, 'withheld' => $rWithheld];
	}

	/**
	 * The streams a server holds (StreamRecords::held, shared with the node's
	 * shadow report).
	 *
	 * @param list<int>|null $rIDs
	 * @return list<int>
	 */
	public static function held(int $rServerID, ?array $rIDs, int $rFrom = 0, int $rTo = self::MAX_ID, ?int $rLimit = null): array {
		return StreamRecords::held($rServerID, $rIDs, $rFrom, $rTo, $rLimit);
	}

	/**
	 * The records' data of these held streams (StreamRecords::data, shared
	 * with the node's shadow report), in canonical form.
	 *
	 * @param list<int> $rIDs
	 * @return array<int, array<string, mixed>> stream id => data
	 */
	public static function data(int $rServerID, array $rIDs): array {
		return StreamRecords::data($rServerID, $rIDs);
	}

	/**
	 * A StreamSource loader (StreamSource::useLoader) backed by one batched read
	 * each of the held streams' full rows, this server's `streams_servers` rows,
	 * and their arguments — so a serve-only target's portable Spec composes
	 * (StreamProcess::buildSupervisorSpec) with no per-stream query. The reads
	 * mirror StreamSource's own SQL so the Spec matches an on-node read; the
	 * stream join is live-only (streamRow(live: true)), so a non-live stream is
	 * absent and the loader answers null for it. Only 'stream' / 'server' /
	 * 'arguments' are answered from the snapshot (portable buildSupervisorSpec
	 * reads no other kind); 'source' is an empty row, anything else null.
	 *
	 * @param list<int> $rIDs the held streams of the page
	 * @return callable(string, int, array<string, mixed>): mixed
	 */
	public static function runtimeLoader(int $rServerID, array $rIDs): callable {
		$rStreams = [];
		$rServers = [];
		$rArgs = [];
		if ($rIDs !== []) {
			$rIn = implode(',', array_map('intval', $rIDs));
			self::read('SELECT t1.*, t2.*, t4.* FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.`type_id` = t1.`type` AND t2.`live` = 1 LEFT JOIN `profiles` t4 ON t1.`transcode_profile_id` = t4.`profile_id` WHERE t1.`direct_source` = 0 AND t1.`id` IN (' . $rIn . ');');
			foreach (self::db()->get_raw_rows() as $rRow) {
				$rStreams[(int) $rRow['id']] = StreamRecords::unescaped($rRow);
			}
			self::read('SELECT * FROM `streams_servers` WHERE `server_id` = ? AND `stream_id` IN (' . $rIn . ');', $rServerID);
			foreach (self::db()->get_raw_rows() as $rRow) {
				$rServers[(int) $rRow['stream_id']] = StreamRecords::unescaped($rRow);
			}
			self::read('SELECT t1.*, t2.* FROM `streams_options` t1, `streams_arguments` t2 WHERE t1.`argument_id` = t2.`id` AND t1.`stream_id` IN (' . $rIn . ');');
			foreach (self::db()->get_raw_rows() as $rRow) {
				$rArgs[(int) $rRow['stream_id']][] = StreamRecords::unescaped($rRow);
			}
		}
		return static function (string $rWhat, int $rID, array $rOpts) use ($rStreams, $rServers, $rArgs): mixed {
			return match ($rWhat) {
				'stream' => $rStreams[$rID] ?? null,
				'server' => $rServers[$rID] ?? null,
				'arguments' => ($rOpts['keyed'] ?? false) ? array_column($rArgs[$rID] ?? [], null, 'argument_key') : ($rArgs[$rID] ?? []),
				'source' => [],
				default => null,
			};
		};
	}

	/**
	 * The composer a serve-only target's page runs per surviving record: it
	 * builds the portable Spec (StreamProcess::buildSupervisorSpec, portable
	 * mode) and upgrades the record's already-classified directive
	 * (StreamRuntimeDirective::upgrade) — "other" with a non-null Spec becomes
	 * "transcode"; a proxy or a non-supervisable stream (null Spec) is left
	 * unchanged. It reads the stream through whatever StreamSource loader is
	 * installed (the snapshot from runtimeLoader in the signing path; a fake in
	 * tests), so it runs no query of its own.
	 *
	 * @param list<string> $rFeatures the target node's live features (default []): no remux
	 * @return callable(int, array): array
	 */
	public static function runtimeUpgrade(int $rServerID, array $rFeatures): callable {
		return static function (int $rID, array $rDirective) use ($rServerID, $rFeatures): array {
			// Only an "other" directive can become a transcode; a proxy (served by
			// the puller) or an already-decided directive is left as is, and its
			// Spec is never composed — so buildSupervisorSpec runs only where its
			// result can be used.
			if (($rDirective['kind'] ?? '') !== 'other') {
				return $rDirective;
			}
			return StreamRuntimeDirective::upgrade(
				$rDirective,
				StreamProcess::buildSupervisorSpec($rID, ['features' => $rFeatures, 'server_id' => $rServerID])
			);
		};
	}

	/**
	 * The versions of these streams for the server (0 for one never stamped).
	 *
	 * @param list<int> $rIDs
	 * @return array<int, int>
	 */
	private static function versions(int $rServerID, array $rIDs): array {
		if ($rIDs === []) {
			return [];
		}
		self::read('SELECT `stream_id`, `ver` FROM `cluster_stream_ver` WHERE `server_id` = ? AND `stream_id` IN (' . implode(',', array_map('intval', $rIDs)) . ');', $rServerID);
		$rOut = [];
		foreach (self::db()->get_rows() ?: [] as $rRow) {
			$rOut[(int) $rRow['stream_id']] = (int) $rRow['ver'];
		}
		return $rOut;
	}

	/**
	 * Drop the version rows of streams their server no longer holds, once
	 * they are KEEP_DAYS old (cron:cluster). A run looks at PRUNE_ROWS rows
	 * in key order, from where the last one stopped (META_PRUNE), and starts
	 * over once it reached the end: the rows of held streams, which stay,
	 * never keep it from the others. Each server's floor is raised to the
	 * newest version it loses first, so a node whose cursor is below it (it
	 * may not have seen that removal) checks every stream again.
	 *
	 * @return int the rows dropped
	 */
	public static function prune(?int $rNow = null): int {
		$rBefore = ($rNow ?? time()) - self::KEEP_DAYS * 86400;
		self::read('SELECT `value` FROM `cluster_meta` WHERE `name` = ?;', self::META_PRUNE);
		[$rFromServer, $rFromStream] = array_map('intval', explode(':', (string) (self::db()->get_row()['value'] ?? '')) + [0, 0]);
		self::read('SELECT `server_id`, `stream_id`, `ver`, `updated_at` FROM `cluster_stream_ver` WHERE `server_id` > ? OR (`server_id` = ? AND `stream_id` > ?) ORDER BY `server_id` ASC, `stream_id` ASC LIMIT ' . self::PRUNE_ROWS . ';', $rFromServer, $rFromServer, $rFromStream);
		$rRows = self::db()->get_rows() ?: [];
		$rByServer = [];
		foreach ($rRows as $rRow) {
			if ((int) $rRow['updated_at'] < $rBefore) {
				$rByServer[(int) $rRow['server_id']][(int) $rRow['stream_id']] = (int) $rRow['ver'];
			}
		}
		$rDropped = 0;
		foreach ($rByServer as $rServerID => $rVers) {
			$rHeld = [];
			foreach (array_chunk(array_keys($rVers), self::MAX_EXAMINE) as $rChunk) {
				$rHeld = array_merge($rHeld, self::held($rServerID, $rChunk));
			}
			$rGone = array_diff_key($rVers, array_flip($rHeld));
			if ($rGone === []) {
				continue;
			}
			StreamVersions::raiseFloor(max($rGone), $rServerID, self::db());
			foreach (array_chunk(array_keys($rGone), self::MAX_EXAMINE) as $rChunk) {
				// Only rows not stamped again since they were read.
				self::read('DELETE FROM `cluster_stream_ver` WHERE `server_id` = ? AND `updated_at` < ? AND `stream_id` IN (' . implode(',', $rChunk) . ');', $rServerID, $rBefore);
				$rDropped += (int) self::db()->num_rows();
			}
		}
		// The next run goes on past the last row looked at, or from the start
		// once this one reached the end.
		$rLast = count($rRows) < self::PRUNE_ROWS ? null : end($rRows);
		self::read('DELETE FROM `cluster_meta` WHERE `name` = ?;', self::META_PRUNE);
		self::read('INSERT INTO `cluster_meta` (`name`, `value`, `updated_at`) VALUES (?, ?, ?);', self::META_PRUNE, is_array($rLast) ? (int) $rLast['server_id'] . ':' . (int) $rLast['stream_id'] : '0:0', time());
		return $rDropped;
	}

	/** Run one of the section's statements: a failed one throws, never an empty result. */
	private static function read(string $rQuery, mixed ...$rArgs): void {
		StrictQuery::run(self::db(), 'streams', $rQuery, ...$rArgs);
	}
}
