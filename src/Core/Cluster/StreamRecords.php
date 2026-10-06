<?php

namespace XcVm\Core\Cluster;

use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * What a server's R2 `stream` records hold, read from MAIN's database (plan,
 * section 9): which streams it holds (held()) and each record's data
 * (data()), in the canonical form MAIN signs (ReplicaSections, `stream`).
 *
 * MAIN builds the records it serves from these (Domain\Cluster\StreamReplica).
 * A node whose STREAMS flow is off compares the records its agent stored
 * with what MAIN's database says through the same reads (ReplicaApply's
 * shadow report), so both sides take one definition of a record. In Core for
 * that reason: it ships to LBs, where Domain\Cluster does not. On a node it
 * runs only while it still has MAIN's database (the shadow of a mode 0 or 1
 * node).
 *
 * Every read throws when it fails: MAIN would otherwise sign a partial
 * section, and a node would report streams missing that are not.
 *
 * A record carries the stream's row, its type, its profile and its options
 * without the row cleaner's HTML escaping (unescaped()): a node starts the
 * stream with them, and get_rows() would hand ffmpeg `&lt;` for a `<` in a
 * source, a header or an option. StreamSource reads MAIN's database the
 * same way, so a start takes the same text from either. No page prints a
 * record.
 */
final class StreamRecords {
	use DatabaseAware;

	/** The highest stream id (int(11)). */
	public const MAX_ID = 2147483647;

	/**
	 * The streams a server holds: assigned to it, its TV archive or
	 * thumbnails recorded there, or a recording of it scheduled there. By
	 * id ($rIDs), or in from..to, the lowest $rLimit ids at most, ascending.
	 *
	 * @param list<int>|null $rIDs
	 * @return list<int>
	 */
	public static function held(int $rServerID, ?array $rIDs, int $rFrom = 0, int $rTo = self::MAX_ID, ?int $rLimit = null): array {
		if ($rIDs !== null) {
			$rIDs = array_values(array_unique(array_map('intval', $rIDs)));
			if ($rIDs === []) {
				return [];
			}
			$rWhere = ' IN (' . implode(',', $rIDs) . ')';
			$rArgs = [];
		} else {
			$rWhere = ' BETWEEN ? AND ?';
			$rArgs = [$rFrom, $rTo];
		}
		// Each way of holding one, cut at the limit: every id up to the
		// limit-th lowest of them all is then among what they return.
		$rLimited = static fn(string $rColumn): string => $rLimit === null ? ';' : ' ORDER BY `' . $rColumn . '` ASC LIMIT ' . max(1, $rLimit) . ';';
		$rOut = [];
		foreach ([
			['SELECT `stream_id` AS `id` FROM `streams_servers` WHERE `server_id` = ? AND `stream_id`' . $rWhere . $rLimited('stream_id'), [$rServerID]],
			['SELECT `id` FROM `streams` WHERE (`tv_archive_server_id` = ? OR `vframes_server_id` = ?) AND `id`' . $rWhere . $rLimited('id'), [$rServerID, $rServerID]],
			['SELECT `stream_id` AS `id` FROM `recordings` WHERE `source_id` = ? AND `stream_id`' . $rWhere . $rLimited('stream_id'), [$rServerID]],
		] as [$rSql, $rFirst]) {
			self::read($rSql, ...$rFirst, ...$rArgs);
			foreach (self::db()->get_rows() ?: [] as $rRow) {
				$rOut[(int) $rRow['id']] = true;
			}
		}
		$rOut = array_keys($rOut);
		sort($rOut);
		return $rLimit === null ? $rOut : array_slice($rOut, 0, max(1, $rLimit));
	}

	/**
	 * The records' data of these held streams, those whose `streams` row
	 * exists (ReplicaSections, `stream` records), in canonical form.
	 *
	 * @param list<int> $rIDs
	 * @return array<int, array<string, mixed>> stream id => data
	 */
	public static function data(int $rServerID, array $rIDs): array {
		if ($rIDs === []) {
			return [];
		}
		$rIn = implode(',', array_map('intval', $rIDs));
		self::read('SELECT `' . implode('`, `', array_keys(ReplicaSections::STREAM_FIELDS)) . '` FROM `streams` WHERE `id` IN (' . $rIn . ') ORDER BY `id`;');
		$rStreams = self::rows();
		if ($rStreams === []) {
			return [];
		}
		self::read('SELECT * FROM `streams_types`;');
		$rTypes = [];
		foreach (self::rows() as $rRow) {
			$rTypes[(int) $rRow['type_id']] = ReplicaSections::typed($rRow, ReplicaSections::STREAM_TYPE_FIELDS);
		}
		$rProfileIDs = array_values(array_unique(array_filter(array_map(static fn(array $rRow): int => (int) $rRow['transcode_profile_id'], $rStreams))));
		$rProfiles = [];
		if ($rProfileIDs !== []) {
			self::read('SELECT * FROM `profiles` WHERE `profile_id` IN (' . implode(',', $rProfileIDs) . ');');
			foreach (self::rows() as $rRow) {
				$rProfiles[(int) $rRow['profile_id']] = ReplicaSections::typed($rRow, ReplicaSections::PROFILE_FIELDS);
			}
		}
		$rOptionColumns = array_merge(array_map(static fn(string $rColumn): string => 't1.`' . $rColumn . '`', array_keys(ReplicaSections::OPTION_FIELDS)), array_map(static fn(string $rColumn): string => 't2.`' . $rColumn . '`', array_keys(ReplicaSections::ARGUMENT_FIELDS)));
		self::read('SELECT t1.`stream_id`, ' . implode(', ', $rOptionColumns) . ' FROM `streams_options` t1 INNER JOIN `streams_arguments` t2 ON t2.`id` = t1.`argument_id` WHERE t1.`stream_id` IN (' . $rIn . ') ORDER BY t1.`stream_id`, t1.`argument_id`, t1.`id`;');
		$rOptions = [];
		foreach (self::rows() as $rRow) {
			$rOptions[(int) $rRow['stream_id']][] = ReplicaSections::typed($rRow, ReplicaSections::OPTION_FIELDS + ReplicaSections::ARGUMENT_FIELDS);
		}
		self::read('SELECT `' . implode('`, `', array_keys(ReplicaSections::STREAM_SERVER_FIELDS)) . '` FROM `streams_servers` WHERE `server_id` = ? AND `stream_id` IN (' . $rIn . ') ORDER BY `server_stream_id`;', $rServerID);
		$rServers = [];
		foreach (self::db()->get_rows() ?: [] as $rRow) {
			$rServers[(int) $rRow['stream_id']] ??= ReplicaSections::typed($rRow, ReplicaSections::STREAM_SERVER_FIELDS);
		}
		self::read('SELECT `stream_id`, `server_id` FROM `streams_servers` WHERE `parent_id` = ? AND `stream_id` IN (' . $rIn . ') ORDER BY `stream_id`, `server_id`;', $rServerID);
		$rChildren = [];
		foreach (self::db()->get_rows() ?: [] as $rRow) {
			$rChildren[(int) $rRow['stream_id']][] = (int) $rRow['server_id'];
		}
		self::read('SELECT `' . implode('`, `', array_keys(ReplicaSections::RECORDING_FIELDS)) . '` FROM `recordings` WHERE `source_id` = ? AND `stream_id` IN (' . $rIn . ') ORDER BY `id`;', $rServerID);
		$rRecordings = [];
		foreach (self::db()->get_rows() ?: [] as $rRow) {
			$rRecordings[(int) $rRow['stream_id']][] = ReplicaSections::typed($rRow, ReplicaSections::RECORDING_FIELDS);
		}
		$rOut = [];
		foreach ($rStreams as $rRow) {
			$rID = (int) $rRow['id'];
			$rStream = ReplicaSections::typed($rRow, ReplicaSections::STREAM_FIELDS);
			$rOut[$rID] = ReplicaSections::canonical([
				'stream' => $rStream,
				'type' => $rTypes[(int) $rStream['type']] ?? null,
				'profile' => $rProfiles[(int) $rStream['transcode_profile_id']] ?? null,
				'options' => $rOptions[$rID] ?? [],
				'server' => $rServers[$rID] ?? null,
				'children' => array_values(array_unique($rChildren[$rID] ?? [])),
				'recordings' => $rRecordings[$rID] ?? [],
				// Phase 8: the relay and file tickets a node pulls this stream with,
				// which MAIN fills after taking the ETag with them null (TicketService).
				'tickets' => null,
			]);
		}
		return $rOut;
	}

	/**
	 * A row read with get_raw_rows(), for a command: what the row cleaner of
	 * get_rows() hands (line ends as LF, the blanks around a value trimmed)
	 * but for its HTML escaping, so a `<`, a `>` and an `&` are the ones that
	 * were saved. A number stays the driver's, where the cleaner hands a
	 * non-zero one as text. Never echo such a row into a page.
	 *
	 * @param array<string, mixed> $rRow
	 * @return array<string, mixed>
	 */
	public static function unescaped(array $rRow): array {
		return array_map(static fn(mixed $rValue): mixed => is_string($rValue) ? trim(str_replace(["\r\n", "\n\r", "\r"], "\n", $rValue)) : $rValue, $rRow);
	}

	/**
	 * The rows of the last read, for a command (unescaped()).
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function rows(): array {
		return array_map([self::class, 'unescaped'], self::db()->get_raw_rows());
	}

	/** Run one of the section's reads: a failed one throws, never an empty result. */
	private static function read(string $rQuery, mixed ...$rArgs): void {
		StrictQuery::run(self::db(), 'streams', $rQuery, ...$rArgs);
	}
}
