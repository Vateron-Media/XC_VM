<?php

namespace XcVm\Domain\Stream;

use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Core\Cluster\ReplicaStreamCache;
use XcVm\Core\Cluster\StreamRecords;
use XcVm\Core\Cluster\StreamRuntime;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * Stream Source
 *
 * Where a node reads the definition of a stream it is about to run: the
 * `streams` row (with its type and transcode profile), this node's
 * `streams_servers` row, the stream's options (user agent, proxy, cookie,
 * headers, …) and the recordings scheduled on it. The launcher, the
 * monitor, the proxy producer, live.php, the scanner and the recorder used
 * to run these queries themselves.
 *
 * The legacy backend reads MAIN's database, as they did. Once the node's
 * replica owns the streams (the STREAMS flow on, and cluster:apply built
 * the stream caches from the R2 `streams` section: ReplicaStreamCache),
 * every answer about this node comes from those caches, in the same shapes,
 * and none reads MAIN's database: a stream the node does not hold is no
 * stream. The node's runtime columns come from its own store
 * (StreamRuntime) once that is seeded, and are null until then; MAIN's
 * catalogue metadata and an argument's description stay null.
 * A start that finds no entry (streamRow) has the agent sync the section
 * first and reads again (ReplicaStreamCache::syncMissing), for a stream
 * assigned a moment before: the plan's `stream_bundle` on a miss, with the
 * delta MAIN already serves.
 *
 * The readers that take a stream's definition and its runtime state
 * together, from one joined row (the monitor, the proxy producer, the delay,
 * archive, thumbnail and created-channel workers, the loopback start, the
 * RTMP callback), read it through nodeRow() and its kin: MAIN's database's
 * row as before, or with local() the caches' definition and the store's
 * runtime state, in the same shape.
 *
 * A stream's rows and options are read without the row cleaner's HTML
 * escaping, from MAIN's database and from the replica alike
 * (StreamRecords::unescaped): get_row() would hand ffmpeg `&lt;` for a `<`
 * in a source, a header or an option. So what these readers answer is for
 * a command, never for a page. A recording's row, which starts nothing, is
 * still read through the cleaner on both.
 *
 * @package XC_VM_Domain_Stream
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class StreamSource {
	/** @var (callable(string, int, array<string, mixed>): mixed)|null */
	private static $rLoader;

	/**
	 * Do this node's readers take its streams from its replica and its own
	 * store: the replica owns the streams' definitions (ReplicaStreamCache)
	 * and the store is seeded (StreamRuntime::ready, which seeds it from a
	 * CLI process of a node in mode 1)? Otherwise they read MAIN's
	 * database, as before.
	 */
	public static function local(): bool {
		return ReplicaStreamCache::owned() && StreamRuntime::ready();
	}

	/**
	 * The stream's row joined with its type (live or not) and transcode
	 * profile. Direct-source streams are not run by a node, so they are
	 * excluded.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function streamRow(int $rStreamID, bool $rLive, ?object $rDb = null): ?array {
		if (self::$rLoader !== null) {
			return (self::$rLoader)('stream', $rStreamID, ['live' => $rLive]);
		}
		if (ReplicaStreamCache::owned()) {
			$rRow = ReplicaStreamCache::streamRow($rStreamID, $rLive);
			// A start that missed: the stream may have been assigned a moment ago.
			if ($rRow === null && ReplicaStreamCache::syncMissing($rStreamID)) {
				$rRow = ReplicaStreamCache::streamRow($rStreamID, $rLive);
			}
			return $rRow !== null && StreamRuntime::ready() ? array_merge($rRow, StreamRuntime::streamFields($rStreamID)) : $rRow;
		}
		$rDb ??= DatabaseFactory::get();
		$rDb->query('SELECT * FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type AND t2.live = ' . ($rLive ? 1 : 0) . ' LEFT JOIN `profiles` t4 ON t1.transcode_profile_id = t4.profile_id WHERE t1.direct_source = 0 AND t1.id = ?', $rStreamID);
		return $rDb->num_rows() > 0 ? self::unescaped($rDb) : null;
	}

	/**
	 * A node's `streams_servers` row for the stream (this node by default).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function serverRow(int $rStreamID, ?int $rServerID = null, ?object $rDb = null): ?array {
		$rServerID ??= intval(SERVER_ID);
		if (self::$rLoader !== null) {
			return (self::$rLoader)('server', $rStreamID, ['server_id' => $rServerID]);
		}
		if ($rServerID === intval(SERVER_ID) && ReplicaStreamCache::owned()) {
			$rEntry = ReplicaStreamCache::get($rStreamID);
			return $rEntry !== null && StreamRuntime::ready() ? self::server($rStreamID, $rEntry) : ($rEntry['server'] ?? null);
		}
		$rDb ??= DatabaseFactory::get();
		$rDb->query('SELECT * FROM `streams_servers` WHERE stream_id = ? AND `server_id` = ?', $rStreamID, $rServerID);
		return $rDb->num_rows() > 0 ? self::remembered(self::unescaped($rDb)) : null;
	}

	/**
	 * This node's row for a stream it builds from its own sources (no
	 * parent): the created channel's builder.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function builtServerRow(int $rStreamID, ?object $rDb = null): ?array {
		if (self::local()) {
			$rEntry = ReplicaStreamCache::get($rStreamID);
			$rRow = $rEntry === null ? null : self::server($rStreamID, $rEntry);
			return $rRow !== null && $rRow['parent_id'] === null ? $rRow : null;
		}
		$rDb ??= DatabaseFactory::get();
		$rDb->query('SELECT * FROM `streams_servers` WHERE stream_id  = ? AND `server_id` = ? AND `parent_id` IS NULL', $rStreamID, SERVER_ID);
		return $rDb->num_rows() > 0 ? self::remembered(self::unescaped($rDb)) : null;
	}

	/**
	 * The stream's `streams` row joined with this node's `streams_servers`
	 * row (`SELECT *`, the server row's columns last): what the monitor, the
	 * proxy producer, the delay worker and the RTMP callback run a stream
	 * with. Null when the node has no row for it.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function nodeRow(int $rStreamID, ?object $rDb = null): ?array {
		if (self::local()) {
			return self::joined($rStreamID);
		}
		$rDb ??= DatabaseFactory::get();
		$rDb->query('SELECT * FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t2.stream_id = t1.id AND t2.server_id = ? WHERE t1.id = ?', SERVER_ID, $rStreamID);
		return $rDb->num_rows() > 0 ? self::remembered(self::unescaped($rDb)) : null;
	}

	/**
	 * nodeRow() for the stream whose archive (`tv_archive`, with a duration)
	 * or thumbnails (`vframes`) this node records: null when another server
	 * records them.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function workerRow(int $rStreamID, string $rWorker, ?object $rDb = null): ?array {
		if (!in_array($rWorker, ContentSink::WORKERS, true)) {
			throw new \InvalidArgumentException('Unknown worker: ' . $rWorker);
		}
		if (self::local()) {
			$rRow = self::joined($rStreamID);
			if ($rRow === null || $rRow[$rWorker . '_server_id'] !== intval(SERVER_ID) || ($rWorker === 'tv_archive' && !(intval($rRow['tv_archive_duration']) > 0))) {
				return null;
			}
			return $rRow;
		}
		$rDb ??= DatabaseFactory::get();
		if ($rWorker === 'tv_archive') {
			$rDb->query('SELECT * FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t1.id = t2.stream_id AND t2.server_id = t1.tv_archive_server_id WHERE t1.`id` = ? AND t1.`tv_archive_server_id` = ? AND t1.`tv_archive_duration` > 0', $rStreamID, SERVER_ID);
		} else {
			$rDb->query('SELECT * FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t1.id = t2.stream_id AND t2.server_id = t1.vframes_server_id WHERE t1.`id` = ? AND t1.`vframes_server_id` = ?', $rStreamID, SERVER_ID);
		}
		return $rDb->num_rows() > 0 ? self::remembered(self::unescaped($rDb)) : null;
	}

	/**
	 * The `streams` row alone, not a direct source: the loopback start.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function plainRow(int $rStreamID, ?object $rDb = null): ?array {
		if (self::local()) {
			$rEntry = ReplicaStreamCache::get($rStreamID);
			return $rEntry === null || $rEntry['stream']['direct_source'] !== 0 ? null : self::stream($rStreamID, $rEntry);
		}
		$rDb ??= DatabaseFactory::get();
		$rDb->query('SELECT * FROM `streams` WHERE direct_source = 0 AND id = ?', $rStreamID);
		return $rDb->num_rows() > 0 ? self::unescaped($rDb) : null;
	}

	/**
	 * The `streams` row with its transcode profile (null columns without
	 * one): the created channel's builder.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function createdRow(int $rStreamID, ?object $rDb = null): ?array {
		if (self::local()) {
			$rEntry = ReplicaStreamCache::get($rStreamID);
			return $rEntry === null ? null : self::stream($rStreamID, $rEntry) + self::profile($rEntry);
		}
		$rDb ??= DatabaseFactory::get();
		$rDb->query('SELECT * FROM `streams` t1 LEFT JOIN `profiles` t3 ON t1.transcode_profile_id = t3.profile_id WHERE t1.`id` = ?', $rStreamID);
		return $rDb->num_rows() > 0 ? self::unescaped($rDb) : null;
	}

	/**
	 * A created channel's `streams` row (type 3, not a direct source) with
	 * its type and transcode profile: what each of its items is encoded with.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function channelRow(int $rStreamID, ?object $rDb = null): ?array {
		if (self::local()) {
			$rEntry = ReplicaStreamCache::get($rStreamID);
			if ($rEntry === null || $rEntry['type'] === null || $rEntry['stream']['type'] !== 3 || $rEntry['stream']['direct_source'] !== 0) {
				return null;
			}
			return self::stream($rStreamID, $rEntry) + $rEntry['type'] + self::profile($rEntry);
		}
		$rDb ??= DatabaseFactory::get();
		$rDb->query('SELECT * FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type AND t1.type = 3 LEFT JOIN `profiles` t4 ON t1.transcode_profile_id = t4.profile_id WHERE t1.direct_source = 0 AND t1.id = ?', $rStreamID);
		return $rDb->num_rows() > 0 ? self::unescaped($rDb) : null;
	}

	/**
	 * A movie or episode this node serves with a producer (a pid): its
	 * `streams` row. The VOD relay endpoint.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function movieRow(int $rStreamID, ?object $rDb = null): ?array {
		if (self::local()) {
			$rEntry = ReplicaStreamCache::get($rStreamID);
			$rServer = $rEntry === null ? null : self::server($rStreamID, $rEntry);
			if ($rServer === null || $rServer['pid'] === null || !in_array($rEntry['type']['type_key'] ?? null, ['movie', 'series'], true)) {
				return null;
			}
			return self::stream($rStreamID, $rEntry);
		}
		$rDb ??= DatabaseFactory::get();
		$rDb->query("SELECT t1.* FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t2.stream_id = t1.id AND t2.pid IS NOT NULL AND t2.server_id = ? INNER JOIN `streams_types` t3 ON t3.type_id = t1.type AND t3.type_key IN ('movie', 'series') WHERE t1.`id` = ?", SERVER_ID, $rStreamID);
		return $rDb->num_rows() > 0 ? self::unescaped($rDb) : null;
	}

	/**
	 * The stream's options joined with their argument definitions, as a list
	 * or keyed by `argument_key`.
	 *
	 * @return array<int|string, array<string, mixed>>
	 */
	public static function arguments(int $rStreamID, bool $rKeyed = false, ?object $rDb = null): array {
		if (self::$rLoader !== null) {
			return (self::$rLoader)('arguments', $rStreamID, ['keyed' => $rKeyed]);
		}
		if (ReplicaStreamCache::owned()) {
			return ReplicaStreamCache::arguments($rStreamID, $rKeyed);
		}
		$rDb ??= DatabaseFactory::get();
		$rDb->query('SELECT t1.*, t2.* FROM `streams_options` t1, `streams_arguments` t2 WHERE t1.stream_id = ? AND t1.argument_id = t2.id', $rStreamID);
		$rRows = array_map([StreamRecords::class, 'unescaped'], $rDb->get_raw_rows());
		return $rKeyed ? array_column($rRows, null, 'argument_key') : $rRows;
	}

	/**
	 * Just the source list of a stream (`stream_source`, JSON), for callers
	 * that only pull the source (the fanout proxy hand-off).
	 *
	 * @return array{stream_source?: string}
	 */
	public static function sourceRow(int $rStreamID, ?object $rDb = null): array {
		if (self::$rLoader !== null) {
			return (self::$rLoader)('source', $rStreamID, []);
		}
		if (ReplicaStreamCache::owned()) {
			return ReplicaStreamCache::sourceRow($rStreamID);
		}
		$rDb ??= DatabaseFactory::get();
		$rDb->query('SELECT `stream_source` FROM `streams` WHERE `id` = ?', $rStreamID);
		return $rDb->num_rows() > 0 ? self::unescaped($rDb) : [];
	}

	/**
	 * A recording's row (every `recordings` column), for the recorder: once
	 * the replica owns the streams, one scheduled on this node, with the
	 * `status` the node set last (StreamRuntime), else the one MAIN last
	 * heard.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function recording(int $rRecordingID, ?object $rDb = null): ?array {
		if (self::$rLoader !== null) {
			return (self::$rLoader)('recording', $rRecordingID, []);
		}
		if (ReplicaStreamCache::owned()) {
			$rRow = ReplicaStreamCache::recording($rRecordingID);
			$rStatus = $rRow === null ? null : StreamRuntime::recordingStatus($rRecordingID);
			if ($rStatus !== null) {
				$rRow['status'] = $rStatus;
			}
			return $rRow;
		}
		$rDb ??= DatabaseFactory::get();
		$rDb->query('SELECT * FROM `recordings` WHERE `id` = ?;', $rRecordingID);
		return $rDb->num_rows() > 0 ? $rDb->get_row() : null;
	}

	/** Replace the backend (tests; later the cluster API). Null restores the SQL backend. */
	public static function useLoader(?callable $rLoader): void {
		self::$rLoader = $rLoader;
	}

	/**
	 * A stream's entry as nodeRow() answers: its `streams` row and this
	 * node's `streams_servers` row, runtime state from the store. Null when
	 * the node holds no row for it.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function joined(int $rStreamID): ?array {
		$rEntry = ReplicaStreamCache::get($rStreamID);
		$rServer = $rEntry === null ? null : self::server($rStreamID, $rEntry);
		return $rServer === null ? null : array_merge(self::stream($rStreamID, $rEntry), $rServer);
	}

	/**
	 * An entry's `streams` row with the workers' pids the node kept.
	 *
	 * @param array<string, mixed> $rEntry
	 * @return array<string, mixed>
	 */
	public static function stream(int $rStreamID, array $rEntry): array {
		return array_merge($rEntry['stream'], StreamRuntime::streamFields($rStreamID));
	}

	/**
	 * An entry's `streams_servers` row with the runtime state the node kept,
	 * or null when it has none.
	 *
	 * @param array<string, mixed> $rEntry
	 * @return array<string, mixed>|null
	 */
	public static function server(int $rStreamID, array $rEntry): ?array {
		if (!is_array($rEntry['server'] ?? null)) {
			return null;
		}
		StreamRuntime::remember($rEntry['server']['server_stream_id'], $rStreamID);
		return array_merge($rEntry['server'], StreamRuntime::serverFields($rStreamID, $rEntry['stream']['type'] ?? null));
	}

	/**
	 * An entry's profile columns, null without one (a LEFT JOIN's).
	 *
	 * @param array<string, mixed> $rEntry
	 * @return array<string, mixed>
	 */
	private static function profile(array $rEntry): array {
		return $rEntry['profile'] ?? array_fill_keys(array_keys(ReplicaSections::PROFILE_FIELDS), null);
	}

	/**
	 * The row MAIN's database answered, for a command
	 * (StreamRecords::unescaped): a `<`, a `>` and an `&` as they were saved.
	 *
	 * @return array<string, mixed>
	 */
	private static function unescaped(object $rDb): array {
		return StreamRecords::unescaped($rDb->get_raw_row() ?? []);
	}

	/**
	 * A row MAIN's database answered: its server_stream_id names its stream
	 * for the node's store (an update by that id).
	 *
	 * @param array<string, mixed> $rRow
	 * @return array<string, mixed>
	 */
	private static function remembered(array $rRow): array {
		if ((int) ($rRow['server_id'] ?? 0) === intval(SERVER_ID)) {
			StreamRuntime::remember($rRow['server_stream_id'] ?? null, $rRow['stream_id'] ?? null);
		}
		return $rRow;
	}
}
