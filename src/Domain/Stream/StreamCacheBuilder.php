<?php

namespace XcVm\Domain\Stream;

use XcVm\Core\Cache\FileCache;

/**
 * Stream Cache Builder
 *
 * Builds the per-stream cache entry (`STREAMS_TMP_PATH/stream_<id>`) that the
 * streaming endpoints read instead of the database: the stream's delivery
 * fields, its bouquets and its per-server runtime rows.
 *
 * cron:cache_engine builds it on every node, from MAIN's database, and still
 * does. In API mode (Phase 5) a node gets the same entry from MAIN's R2
 * stream delta and `stream_bundle` instead, so the shape lives here, in one
 * place, and entry() is pure.
 *
 * @package XC_VM_Domain_Stream
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class StreamCacheBuilder {
	/** `streams` (t1) ⨝ `streams_types` (t2) columns a cache entry carries in `info`. */
	public const STREAM_COLUMNS = 't1.id,t1.epg_id,t1.added,t1.allow_record,t1.year,t1.channel_id,t1.movie_properties,t1.stream_source,t1.tv_archive_server_id,t1.vframes_server_id,t1.tv_archive_duration,t1.stream_icon,t1.custom_sid,t1.category_id,t1.stream_display_name,t1.series_no,t1.direct_source,t1.direct_proxy,t2.type_output,t1.target_container,t2.live,t1.rtmp_output,t1.order,t2.type_key,t1.tmdb_id,t1.adaptive_link';

	/** `streams_servers` columns a cache entry carries per server. */
	public const SERVER_COLUMNS = ['stream_id', 'server_id', 'pid', 'to_analyze', 'stream_status', 'monitor_pid', 'on_demand', 'delay_available_at', 'bitrate', 'parent_id', 'stream_info', 'video_codec', 'audio_codec', 'resolution', 'compatible'];

	/**
	 * Stream rows, either a page (offset/limit, in id order so consecutive
	 * pages neither skip nor repeat a stream) or the given ids.
	 *
	 * Null when the query fails: the caller must not take a failed read for
	 * "these streams no longer exist" and drop their cache entries.
	 *
	 * @param list<int>|null $rIDs
	 * @return list<array<string, mixed>>|null
	 */
	public static function streamRows(object $rDb, ?array $rIDs, ?int $rOffset = null, ?int $rLimit = null): ?array {
		$rSql = 'SELECT ' . self::STREAM_COLUMNS . ' FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type';
		if ($rIDs !== null) {
			if ($rIDs === []) {
				return [];
			}
			$rSql .= ' WHERE `t1`.`id` IN (' . implode(',', array_map('intval', $rIDs)) . ');';
		} else {
			$rSql .= ' ORDER BY `t1`.`id` ASC LIMIT ' . intval($rOffset) . ', ' . intval($rLimit) . ';';
		}
		if (!$rDb->query($rSql) || !$rDb->result) {
			return null;
		}
		$rRows = $rDb->result->rowCount() > 0 ? $rDb->result->fetchAll(\PDO::FETCH_ASSOC) : [];
		$rDb->result = null;
		return $rRows;
	}

	/**
	 * Per-server rows of the given streams: stream_id => server_id => row.
	 *
	 * @param list<int> $rStreamIDs
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	public static function serverMap(object $rDb, array $rStreamIDs): array {
		$rMap = [];
		if ($rStreamIDs === []) {
			return $rMap;
		}
		if ($rDb->query('SELECT `' . implode('`, `', self::SERVER_COLUMNS) . '` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ')')) {
			if ($rDb->result && $rDb->result->rowCount() > 0) {
				foreach ($rDb->result->fetchAll(\PDO::FETCH_ASSOC) as $rRow) {
					$rMap[intval($rRow['stream_id'])][intval($rRow['server_id'])] = $rRow;
				}
			}
			$rDb->result = null;
		}
		return $rMap;
	}

	/**
	 * The cache entry for one stream. A stream's source URLs stay out of the
	 * cache unless it is a direct source (the viewer is sent to the source).
	 *
	 * @param array<string, mixed> $rStreamRow A streamRows() row.
	 * @param list<int> $rBouquets Bouquet ids holding the stream.
	 * @param array<int, array<string, mixed>> $rServers server_id => serverMap() row.
	 * @return array{info: array<string, mixed>, bouquets: list<int>, servers: array<int, array<string, mixed>>}
	 */
	public static function entry(array $rStreamRow, array $rBouquets, array $rServers): array {
		if (!$rStreamRow['direct_source']) {
			unset($rStreamRow['stream_source']);
		}
		return ['info' => $rStreamRow, 'bouquets' => $rBouquets, 'servers' => $rServers];
	}

	public static function path(int $rStreamID): string {
		return STREAMS_TMP_PATH . 'stream_' . $rStreamID;
	}

	/**
	 * Write a stream's entry atomically: stream auth reads these files on
	 * every request and must never see a half-written one.
	 *
	 * @param array<string, mixed> $rEntry
	 */
	public static function write(int $rStreamID, array $rEntry): bool {
		return FileCache::writeAtomic(self::path($rStreamID), igbinary_serialize($rEntry));
	}

	/** Drop the entry of a stream that no longer exists. */
	public static function remove(int $rStreamID): void {
		@unlink(self::path($rStreamID));
	}
}
