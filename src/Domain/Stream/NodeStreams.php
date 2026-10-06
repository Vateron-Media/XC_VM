<?php

namespace XcVm\Domain\Stream;

use XcVm\Core\Cluster\AgentConnections;
use XcVm\Core\Cluster\ReplicaStreamCache;
use XcVm\Core\Cluster\ReplicaStreams;
use XcVm\Core\Cluster\StreamRuntime;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * Node Streams
 *
 * The lists of this node's streams its crons and daemons select together
 * with their runtime state (cron:streams, cron:vod, cron:cleanup, the
 * on-demand daemon). Each reads MAIN's database with the query its caller
 * ran, as before; with StreamSource::local() (the replica owns the streams'
 * definitions and the node's own store is seeded) it answers from the R2
 * stream caches (ReplicaStreamCache) and the store (StreamRuntime) in the
 * same shape, and never reads MAIN's database. The lists cron:cleanup prunes
 * files by come from the agent's whole section (ReplicaStreams): null when
 * the section is not whole, and the caller then skips that check.
 *
 * The lists whose callers delete or kill what they do not name (cron:cleanup's
 * three, cron:streams' live and on-demand streams) are null, never empty,
 * when MAIN's database did not answer the read: unknown, and the caller
 * does nothing by that list.
 *
 * The lists that walk every stream the node holds or keeps state for read
 * a stream's cache entry and its state only when the stream caches' index
 * (ReplicaStreamCache::meta, the last apply's copy of what they filter on)
 * does not already rule it out, so a node holding many movies does not read
 * each one's files for its few live or on-demand streams; a stream the index
 * lacks (its record stored since) is read.
 *
 * What the node cannot know stays as close as it can: a stream's relaying
 * children with a running feed (`attached`) are the children configured to
 * relay it from this node (its record's `children`), so an on-demand stream
 * they relay is never stopped for want of viewers; and a stream's viewers
 * come from the agent's registry with CONNECTIONS on (one open viewer is
 * enough: the callers only ask whether there is any), else from MAIN's
 * `lines_live` as before.
 *
 * @package XC_VM_Domain_Stream
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class NodeStreams {
	/** cron:vod's analysis step: rows per page. */
	public const STEP = 1000;

	/** @var list<int>|null cron:vod's run: the streams whose analysis is due, as analysis(0) found them */
	private static ?array $rDue = null;

	/**
	 * cron:streams: this node's live streams that run or should (a pid, a
	 * status, an analysis due), each with its watchdogs' pids, progress and
	 * probe results, its relaying children (`attached`), its archive and
	 * thumbnail workers, and in MySQL mode its viewers (`online_clients`,
	 * `online_clients_hls`: from the replica only for on-demand streams,
	 * the only ones cron:streams asks it for; `online_clients_hls` is null).
	 * Null when MAIN's database did not answer.
	 *
	 * @return list<array<string, mixed>>|null
	 */
	public static function liveChecks(bool $rRedis, ?object $rDb = null): ?array {
		if (!StreamSource::local()) {
			$rDb ??= DatabaseFactory::get();
			if ($rRedis) {
				$rRead = $rDb->query('SELECT t2.stream_display_name, t2.delay_minutes, t1.stream_started, t1.stream_info, t2.fps_restart, t1.stream_status, t1.progress_info, t1.stream_id, t1.monitor_pid, t1.on_demand, t1.server_stream_id, t1.pid, servers_attached.attached, t2.vframes_server_id, t2.vframes_pid, t2.tv_archive_server_id, t2.tv_archive_pid FROM `streams_servers` t1 INNER JOIN `streams` t2 ON t2.id = t1.stream_id AND t2.direct_source = 0 INNER JOIN `streams_types` t3 ON t3.type_id = t2.type LEFT JOIN (SELECT `stream_id`, COUNT(*) AS `attached` FROM `streams_servers` WHERE `parent_id` = ? AND `pid` IS NOT NULL AND `pid` > 0 AND `monitor_pid` IS NOT NULL AND `monitor_pid` > 0 GROUP BY `stream_id`) AS `servers_attached` ON `servers_attached`.`stream_id` = t1.`stream_id` WHERE (t1.pid IS NOT NULL OR t1.stream_status <> 0 OR t1.to_analyze = 1) AND t1.server_id = ? AND t3.live = 1', SERVER_ID, SERVER_ID);
			} else {
				$rRead = $rDb->query("SELECT t2.stream_display_name, t2.delay_minutes, t1.stream_started, t1.stream_info, t2.fps_restart, t1.stream_status, t1.progress_info, t1.stream_id, t1.monitor_pid, t1.on_demand, t1.server_stream_id, t1.pid, clients.online_clients, clients_hls.online_clients_hls, servers_attached.attached, t2.vframes_server_id, t2.vframes_pid, t2.tv_archive_server_id, t2.tv_archive_pid FROM `streams_servers` t1 INNER JOIN `streams` t2 ON t2.id = t1.stream_id AND t2.direct_source = 0 INNER JOIN `streams_types` t3 ON t3.type_id = t2.type LEFT JOIN (SELECT stream_id, COUNT(*) as online_clients FROM `lines_live` WHERE `server_id` = ? AND `hls_end` = 0 GROUP BY stream_id) AS clients ON clients.stream_id = t1.stream_id LEFT JOIN (SELECT `stream_id`, COUNT(*) AS `attached` FROM `streams_servers` WHERE `parent_id` = ? AND `pid` IS NOT NULL AND `pid` > 0 AND `monitor_pid` IS NOT NULL AND `monitor_pid` > 0 GROUP BY `stream_id`) AS `servers_attached` ON `servers_attached`.`stream_id` = t1.`stream_id` LEFT JOIN (SELECT stream_id, COUNT(*) as online_clients_hls FROM `lines_live` WHERE `server_id` = ? AND `container` = 'hls' AND `hls_end` = 0 GROUP BY stream_id) AS clients_hls ON clients_hls.stream_id = t1.stream_id WHERE (t1.pid IS NOT NULL OR t1.stream_status <> 0 OR t1.to_analyze = 1) AND t1.server_id = ? AND t3.live = 1", SERVER_ID, SERVER_ID, SERVER_ID, SERVER_ID);
			}
			if (!$rRead) {
				return null;
			}
			return $rDb->num_rows() > 0 ? self::remembered($rDb->get_rows()) : [];
		}
		$rOut = [];
		$rIndex = ReplicaStreamCache::index();
		foreach (StreamRuntime::ids() as $rID) {
			if (!self::candidate($rIndex, $rID, ['live' => 1, 'ds' => 0])) {
				continue;
			}
			[$rStream, $rServer, $rEntry] = self::entry($rID) ?? [null, null, null];
			if ($rServer === null || $rEntry['type'] === null || $rEntry['type']['live'] !== 1 || $rStream['direct_source'] !== 0) {
				continue;
			}
			if ($rServer['pid'] === null && ($rServer['stream_status'] === null || (int) $rServer['stream_status'] === 0) && (int) $rServer['to_analyze'] !== 1) {
				continue;
			}
			$rRow = [
				'stream_display_name' => $rStream['stream_display_name'], 'delay_minutes' => $rStream['delay_minutes'], 'stream_started' => $rServer['stream_started'],
				'stream_info' => $rServer['stream_info'], 'fps_restart' => $rStream['fps_restart'], 'stream_status' => $rServer['stream_status'],
				'progress_info' => $rServer['progress_info'], 'stream_id' => $rServer['stream_id'], 'monitor_pid' => $rServer['monitor_pid'],
				'on_demand' => $rServer['on_demand'], 'server_stream_id' => $rServer['server_stream_id'], 'pid' => $rServer['pid'],
			];
			if (!$rRedis) {
				$rRow['online_clients'] = null;
				$rRow['online_clients_hls'] = null;
			}
			$rRow += [
				'attached' => count($rEntry['children']) ?: null, 'vframes_server_id' => $rStream['vframes_server_id'], 'vframes_pid' => $rStream['vframes_pid'],
				'tv_archive_server_id' => $rStream['tv_archive_server_id'], 'tv_archive_pid' => $rStream['tv_archive_pid'],
			];
			$rOut[] = $rRow;
		}
		if (!$rRedis) {
			$rOnDemand = array_map(static fn (array $rRow): int => (int) $rRow['stream_id'], array_filter($rOut, static fn (array $rRow): bool => (int) $rRow['on_demand'] === 1));
			$rViewers = self::viewers($rOnDemand);
			foreach ($rOut as $i => $rRow) {
				$rOut[$i]['online_clients'] = ($rViewers[(int) $rRow['stream_id']] ?? 0) ?: null;
			}
		}
		return $rOut;
	}

	/**
	 * cron:streams: the direct-proxy streams this node relays, with a pid.
	 *
	 * @return list<array{id: mixed}>
	 */
	public static function proxied(?object $rDb = null): array {
		if (!StreamSource::local()) {
			$rDb ??= DatabaseFactory::get();
			$rDb->query('SELECT `streams`.`id` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `streams`.`direct_source` = 1 AND `streams`.`direct_proxy` = 1 AND `streams_servers`.`server_id` = ? AND `streams_servers`.`pid` > 0;', SERVER_ID);
			return $rDb->num_rows() > 0 ? $rDb->get_rows() : [];
		}
		$rOut = [];
		$rIndex = ReplicaStreamCache::index();
		foreach (StreamRuntime::ids() as $rID) {
			if (!self::candidate($rIndex, $rID, ['ds' => 1, 'dp' => 1])) {
				continue;
			}
			[$rStream, $rServer] = self::entry($rID) ?? [null, null];
			if ($rServer !== null && $rStream['direct_source'] === 1 && $rStream['direct_proxy'] === 1 && (int) $rServer['pid'] > 0) {
				$rOut[] = ['id' => $rStream['id']];
			}
		}
		return $rOut;
	}

	/**
	 * cron:streams: the streams this node runs on demand. Null when MAIN's
	 * database did not answer.
	 *
	 * @return list<int|string>|null
	 */
	public static function onDemandIDs(?object $rDb = null): ?array {
		if (!StreamSource::local()) {
			$rDb ??= DatabaseFactory::get();
			if (!$rDb->query('SELECT `stream_id` FROM `streams_servers` WHERE `on_demand` = 1 AND `server_id` = ?;', SERVER_ID)) {
				return null;
			}
			return array_keys($rDb->get_rows(true, 'stream_id'));
		}
		$rOut = [];
		$rIndex = ReplicaStreamCache::index();
		foreach (ReplicaStreamCache::held() as $rID) {
			if (!self::candidate($rIndex, $rID, ['od' => 1])) {
				continue;
			}
			$rServer = ReplicaStreamCache::get($rID)['server'] ?? null;
			if (is_array($rServer) && $rServer['on_demand'] === 1) {
				$rOut[] = $rID;
			}
		}
		return $rOut;
	}

	/**
	 * `scanner`: this node's on-demand streams whose source has not been
	 * checked for $rEvery seconds and which nothing is streaming (no pid, no
	 * parent), with the columns the scan needs.
	 *
	 * MAIN keeps the checks (`ondemand_check`), so from the replica the node
	 * dates its own last scan by the marker it touches after one
	 * ({@see scanned}) — it is the only one that scans its streams.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function onDemandDue(int $rEvery, ?object $rDb = null): array {
		if (!StreamSource::local()) {
			$rDb ??= DatabaseFactory::get();
			$rDb->query('SELECT `streams`.* FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `streams_servers`.`pid` IS NULL AND `streams_servers`.`on_demand` = 1 AND `streams_servers`.`parent_id` IS NULL AND `streams`.`type` = 1 AND `streams`.`direct_source` = 0 AND `streams_servers`.`server_id` = ? AND (UNIX_TIMESTAMP() - (SELECT MAX(`date`) FROM `ondemand_check` WHERE `stream_id` = `streams`.`id` AND `server_id` = `streams_servers`.`server_id`) > ? OR (SELECT MAX(`date`) FROM `ondemand_check` WHERE `stream_id` = `streams`.`id` AND `server_id` = `streams_servers`.`server_id`) IS NULL);', SERVER_ID, $rEvery);
			return $rDb->num_rows() > 0 ? $rDb->get_rows() : [];
		}

		$rOut = [];
		$rIndex = ReplicaStreamCache::index();
		foreach (ReplicaStreamCache::held() as $rID) {
			if (!self::candidate($rIndex, $rID, ['od' => 1, 'type' => 1, 'ds' => 0])) {
				continue;
			}
			[$rStream, $rServer] = self::entry($rID) ?? [null, null];
			if ($rStream === null || $rServer === null || (int) ($rStream['type'] ?? 0) !== 1
				|| (int) ($rStream['direct_source'] ?? 0) !== 0 || ($rServer['on_demand'] ?? null) !== 1
				|| $rServer['parent_id'] !== null || $rServer['pid'] !== null || !self::scanDue($rID, $rEvery)
			) {
				continue;
			}
			$rOut[] = $rStream;
		}
		return $rOut;
	}

	/** Record that this node has just scanned a stream's source. */
	public static function scanned(int $rStreamID): void {
		if (StreamSource::local()) {
			touch(self::scanMarker($rStreamID));
		}
	}

	/** Has $rEvery seconds passed since this node last scanned the stream? */
	private static function scanDue(int $rStreamID, int $rEvery): bool {
		$rMarker = self::scanMarker($rStreamID);
		return !is_file($rMarker) || time() - (int) filemtime($rMarker) > $rEvery;
	}

	/**
	 * The marker whose mtime dates the node's last scan of a stream, beside
	 * the scan's own error file. A stream that is deleted leaves its marker
	 * behind; it is a few bytes, and cron:cleanup prunes the directory.
	 */
	private static function scanMarker(int $rStreamID): string {
		return STREAMS_TMP_PATH . $rStreamID . '._scan';
	}

	/**
	 * The on-demand daemon: this node's on-demand streams with a producer
	 * (ConnectionTracker::activeOnDemandStreamIDs). $rLocal: StreamSource::local()
	 * as the caller last read it (the daemon asks every 0.8 s).
	 *
	 * @return list<int|string>
	 */
	public static function activeOnDemand(?bool $rLocal = null): array {
		if (!($rLocal ?? StreamSource::local())) {
			return ConnectionTracker::activeOnDemandStreamIDs(intval(SERVER_ID));
		}
		$rOut = [];
		$rIndex = ReplicaStreamCache::index();
		foreach (StreamRuntime::ids() as $rID) {
			if (!self::candidate($rIndex, $rID, ['od' => 1])) {
				continue;
			}
			[, $rServer] = self::entry($rID) ?? [null, null];
			if ($rServer !== null && $rServer['on_demand'] === 1 && $rServer['pid'] !== null && (int) $rServer['pid'] > 0) {
				$rOut[] = $rID;
			}
		}
		return $rOut;
	}

	/**
	 * How many servers relay each stream from this node: stream id => count,
	 * only those relayed (ConnectionTracker::attachedRestreamCounts). From
	 * the replica, the servers configured to relay it (its record's
	 * `children`): whether their feed runs is their own state.
	 *
	 * @param list<int|string> $rStreamIDs
	 * @return array<int, int>
	 */
	public static function attached(array $rStreamIDs, ?bool $rLocal = null): array {
		if (!($rLocal ?? StreamSource::local())) {
			return ConnectionTracker::attachedRestreamCounts($rStreamIDs, intval(SERVER_ID));
		}
		$rOut = [];
		foreach ($rStreamIDs as $rID) {
			$rCount = count(ReplicaStreamCache::get((int) $rID)['children'] ?? []);
			if ($rCount > 0) {
				$rOut[(int) $rID] = $rCount;
			}
		}
		return $rOut;
	}

	/**
	 * The viewers of these streams on this node: stream id => count. With
	 * CONNECTIONS on, the agent's registry, in one call (counts); an agent
	 * that does not answer it is asked per stream (1 when it holds an open
	 * viewer of the stream, and when it does not answer, so a stream is never
	 * stopped for an answer it did not get). Otherwise MAIN's `lines_live`,
	 * as ConnectionTracker::onlineClientCounts reads it.
	 *
	 * @param list<int> $rStreamIDs
	 * @return array<int, int>
	 */
	public static function viewers(array $rStreamIDs): array {
		if ($rStreamIDs === []) {
			return [];
		}
		if (!AgentConnections::enabled()) {
			return ConnectionTracker::onlineClientCounts($rStreamIDs, intval(SERVER_ID));
		}
		$rCounts = AgentConnections::counts($rStreamIDs);
		if ($rCounts !== null) {
			return $rCounts;
		}
		$rOut = [];
		foreach ($rStreamIDs as $rID) {
			$rOut[$rID] = AgentConnections::find(['stream_id' => $rID, 'hls_end' => 0]) === false ? 0 : 1;
		}
		return $rOut;
	}

	/**
	 * cron:vod: this node's created channels it builds itself (no parent),
	 * each row `streams` ⨝ `streams_servers` ⨝ its profile.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function createdChannels(?object $rDb = null): array {
		if (!StreamSource::local()) {
			$rDb ??= DatabaseFactory::get();
			$rDb->query('SELECT * FROM `streams` t1 INNER JOIN `streams_servers` t3 ON t3.stream_id = t1.id LEFT JOIN `profiles` t2 ON t2.profile_id = t1.transcode_profile_id WHERE t1.type = 3 AND t3.server_id = ? AND t3.parent_id IS NULL;', SERVER_ID);
			return $rDb->num_rows() > 0 ? self::remembered($rDb->get_rows()) : [];
		}
		$rOut = [];
		$rIndex = ReplicaStreamCache::index();
		foreach (ReplicaStreamCache::held() as $rID) {
			if (!self::candidate($rIndex, $rID, ['type' => 3])) {
				continue;
			}
			[$rStream, $rServer, $rEntry] = self::entry($rID) ?? [null, null, null];
			if ($rServer !== null && $rStream['type'] === 3 && $rServer['parent_id'] === null) {
				$rOut[] = array_merge($rStream, $rServer, $rEntry['profile'] ?? ['profile_id' => null, 'profile_name' => null, 'profile_options' => null]);
			}
		}
		return $rOut;
	}

	/**
	 * cron:vod: the recordings scheduled on this node that are due to start:
	 * neither recording nor done (the status the node set last wins over
	 * the one its record carries), airing now, or an archive's.
	 *
	 * @return list<array{id: mixed}>
	 */
	public static function recordingsDue(?object $rDb = null): array {
		if (!StreamSource::local()) {
			$rDb ??= DatabaseFactory::get();
			$rDb->query('SELECT `id` FROM `recordings` WHERE `status` NOT IN (1,2) AND `source_id` = ? AND ((`start` <= UNIX_TIMESTAMP() AND `end` > UNIX_TIMESTAMP()) OR (`archive` = 1));', SERVER_ID);
			return $rDb->num_rows() > 0 ? $rDb->get_rows() : [];
		}
		$rIndex = ReplicaStreamCache::index();
		$rNow = time();
		$rOut = [];
		foreach (ReplicaStreamCache::held() as $rID) {
			if (isset($rIndex[$rID]) && ($rIndex[$rID]['rec'] ?? []) === []) {
				continue;
			}
			foreach (ReplicaStreamCache::get($rID)['recordings'] ?? [] as $rRecording) {
				$rStatus = StreamRuntime::recordingStatus($rRecording['id']) ?? $rRecording['status'];
				$rAiring = $rRecording['start'] !== null && $rRecording['end'] !== null && $rRecording['start'] <= $rNow && $rRecording['end'] > $rNow;
				if ($rStatus !== null && !in_array((int) $rStatus, [1, 2], true) && ($rAiring || $rRecording['archive'] === 1)) {
					$rOut[] = ['id' => $rRecording['id']];
				}
			}
		}
		usort($rOut, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
		return $rOut;
	}

	/** cron:vod: how many of this node's rows have an analysis due. */
	public static function analysisCount(?object $rDb = null): int {
		if (!StreamSource::local()) {
			$rDb ??= DatabaseFactory::get();
			$rDb->query('SELECT COUNT(*) AS `count` FROM `streams_servers` WHERE `to_analyze` = 1 AND `server_id` = ?', SERVER_ID);
			return (int) $rDb->get_row()['count'];
		}
		$rCount = 0;
		foreach (StreamRuntime::ids() as $rID) {
			// The store first: the few streams with an analysis due are the only ones whose entry is read.
			if ((int) (StreamRuntime::get($rID)['to_analyze'] ?? 0) !== 1) {
				continue;
			}
			[, $rServer] = self::entry($rID) ?? [null, null];
			if ($rServer !== null && (int) $rServer['to_analyze'] === 1) {
				$rCount++;
			}
		}
		return $rCount;
	}

	/**
	 * cron:vod: a page (STEP rows from $rStep) of this node's movies and
	 * episodes with an analysis due, each row `streams_servers` ⨝ `streams`.
	 * From the replica and the store, a run's streams are found once, at its
	 * first page (step 0), and each later page takes the next of them that
	 * are still due, so a run reads each stream's state once rather than
	 * once a page.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function analysis(int $rStep, ?object $rDb = null): array {
		if (!StreamSource::local()) {
			$rDb ??= DatabaseFactory::get();
			$rDb->query('SELECT t1.*,t2.* FROM `streams_servers` t1 INNER JOIN `streams` t2 ON t2.id = t1.stream_id AND t2.direct_source = 0 INNER JOIN `streams_types` t3 ON t3.type_id = t2.type AND t3.live = 0 WHERE t1.to_analyze = 1 AND t1.server_id = ? LIMIT ' . $rStep . ', ' . self::STEP, SERVER_ID);
			return $rDb->num_rows() > 0 ? self::remembered($rDb->get_rows()) : [];
		}
		if ($rStep === 0 || self::$rDue === null) {
			$rIndex = ReplicaStreamCache::index();
			self::$rDue = [];
			foreach (StreamRuntime::ids() as $rID) {
				if ((int) (StreamRuntime::get($rID)['to_analyze'] ?? 0) === 1 && self::candidate($rIndex, $rID, ['live' => 0, 'ds' => 0])) {
					self::$rDue[] = $rID;
				}
			}
		}
		$rOut = [];
		foreach (array_slice(self::$rDue, $rStep, self::STEP) as $rID) {
			[$rStream, $rServer, $rEntry] = self::entry($rID) ?? [null, null, null];
			if ($rServer !== null && (int) $rServer['to_analyze'] === 1 && $rStream['direct_source'] === 0 && ($rEntry['type']['live'] ?? null) === 0) {
				$rOut[] = array_merge($rServer, $rStream);
			}
		}
		return $rOut;
	}

	/**
	 * cron:cleanup: the live, created and radio streams assigned to this
	 * node (their files stay). Null without the whole section, or when
	 * MAIN's database did not answer.
	 *
	 * @return list<int>|null
	 */
	public static function fileStreams(?object $rDb = null): ?array {
		if (!StreamSource::local()) {
			$rDb ??= DatabaseFactory::get();
			$rStreams = [];
			if (!$rDb->query('SELECT `id` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `streams`.`type` IN (1,3,4) AND `streams_servers`.`server_id` = ?;', SERVER_ID)) {
				return null;
			}
			foreach ($rDb->get_rows() as $rRow) {
				$rStreams[] = intval($rRow['id']);
			}
			return $rStreams;
		}
		$rAssigned = ReplicaStreams::assigned([1, 3, 4]);
		return $rAssigned === null ? null : array_keys($rAssigned);
	}

	/**
	 * cron:cleanup: the TV archives this node records: stream id => days.
	 * Null without the whole section, or when MAIN's database did not answer.
	 *
	 * @return array<int, mixed>|null
	 */
	public static function archives(?object $rDb = null): ?array {
		if (!StreamSource::local()) {
			$rDb ??= DatabaseFactory::get();
			$rArchive = [];
			if (!$rDb->query('SELECT `id`, `tv_archive_duration` FROM `streams` WHERE `type` = 1 AND `tv_archive_server_id` = ? AND `tv_archive_duration` > 0;', SERVER_ID)) {
				return null;
			}
			foreach ($rDb->get_rows() as $rRow) {
				$rArchive[intval($rRow['id'])] = $rRow['tv_archive_duration'];
			}
			return $rArchive;
		}
		return ReplicaStreams::archives(intval(SERVER_ID));
	}

	/**
	 * cron:cleanup: the created channels assigned to this node (their files
	 * stay). Null without the whole section, or when MAIN's database did not
	 * answer.
	 *
	 * @return list<int>|null
	 */
	public static function createdIDs(?object $rDb = null): ?array {
		if (!StreamSource::local()) {
			$rDb ??= DatabaseFactory::get();
			$rCreated = [];
			if (!$rDb->query('SELECT `id` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `streams`.`type` = 3 AND `streams_servers`.`server_id` = ?;', SERVER_ID)) {
				return null;
			}
			foreach ($rDb->get_rows() as $rRow) {
				$rCreated[] = intval($rRow['id']);
			}
			return $rCreated;
		}
		$rAssigned = ReplicaStreams::assigned([3]);
		return $rAssigned === null ? null : array_keys($rAssigned);
	}

	/**
	 * cron:cleanup: this node's movies and episodes with a producer, not a
	 * direct source, to check against their files. Null without the whole
	 * section.
	 *
	 * @return list<array<string, mixed>>|null
	 */
	public static function vodChecks(?object $rDb = null): ?array {
		if (!StreamSource::local()) {
			$rDb ??= DatabaseFactory::get();
			$rDb->query('SELECT `server_stream_id`, `id`, `target_container`, `movie_properties`, `stream_status` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `server_id` = ? AND `type` IN (2,5) AND `streams`.`direct_source` = 0 AND `streams_servers`.`pid` > 0;', SERVER_ID);
			return $rDb->num_rows() > 0 ? self::remembered($rDb->get_rows()) : [];
		}
		$rAssigned = ReplicaStreams::assigned([2, 5]);
		if ($rAssigned === null) {
			return null;
		}
		$rOut = [];
		foreach ($rAssigned as $rID => $rData) {
			$rRun = StreamRuntime::serverFields($rID, (int) $rData['stream']['type']);
			if ((int) ($rData['stream']['direct_source'] ?? 0) !== 0 || !((int) $rRun['pid'] > 0)) {
				continue;
			}
			StreamRuntime::remember($rData['server']['server_stream_id'] ?? null, $rID);
			$rOut[] = [
				'server_stream_id' => $rData['server']['server_stream_id'] ?? null, 'id' => $rID, 'target_container' => $rData['stream']['target_container'] ?? null,
				'movie_properties' => $rData['stream']['movie_properties'] ?? null, 'stream_status' => $rRun['stream_status'],
			];
		}
		return $rOut;
	}

	/**
	 * cron:cleanup: this node's created channels whose build holds exactly
	 * their sources (`cchannel_rsources` and `stream_source` contain each
	 * other) and has no encode running (`pids_create_channel` empty), to
	 * check against their list file. Null without the whole section.
	 *
	 * @return list<array<string, mixed>>|null
	 */
	public static function builtChannels(?object $rDb = null): ?array {
		if (!StreamSource::local()) {
			$rDb ??= DatabaseFactory::get();
			$rDb->query("SELECT `id`, `stream_display_name`, `server_stream_id` FROM `streams` t1 INNER JOIN `streams_servers` t3 ON t3.stream_id = t1.id LEFT JOIN `profiles` t2 ON t2.profile_id = t1.transcode_profile_id WHERE t1.type = 3 AND t3.server_id = ? AND JSON_CONTAINS(t3.cchannel_rsources, t1.stream_source) AND JSON_CONTAINS(t1.stream_source, t3.cchannel_rsources) AND t3.pids_create_channel = '[]';", SERVER_ID);
			return $rDb->num_rows() > 0 ? self::remembered($rDb->get_rows()) : [];
		}
		$rAssigned = ReplicaStreams::assigned([3]);
		if ($rAssigned === null) {
			return null;
		}
		$rOut = [];
		foreach ($rAssigned as $rID => $rData) {
			$rRun = StreamRuntime::serverFields($rID, 3);
			$rBuilt = json_decode((string) $rRun['cchannel_rsources'], true);
			$rSources = json_decode((string) ($rData['stream']['stream_source'] ?? ''), true);
			if (!is_array($rBuilt) || !is_array($rSources) || $rRun['pids_create_channel'] !== '[]' || !self::contains($rBuilt, $rSources) || !self::contains($rSources, $rBuilt)) {
				continue;
			}
			StreamRuntime::remember($rData['server']['server_stream_id'] ?? null, $rID);
			$rOut[] = ['id' => $rID, 'stream_display_name' => $rData['stream']['stream_display_name'] ?? null, 'server_stream_id' => $rData['server']['server_stream_id'] ?? null];
		}
		return $rOut;
	}

	/**
	 * The streams and recordings the node holds, for pruning its store: null
	 * without the whole section.
	 *
	 * @return array{0: list<int>, 1: list<int>}|null
	 */
	public static function held(): ?array {
		$rRecords = ReplicaStreams::records();
		if ($rRecords === null) {
			return null;
		}
		$rRecordings = [];
		foreach ($rRecords as $rData) {
			foreach (is_array($rData['recordings'] ?? null) ? $rData['recordings'] : [] as $rRecording) {
				if (is_int($rRecording['id'] ?? null)) {
					$rRecordings[] = $rRecording['id'];
				}
			}
		}
		return [array_keys($rRecords), $rRecordings];
	}

	/**
	 * A held stream's `streams` row and this node's row, with the node's
	 * runtime state, and its cache entry: null when the node holds no row
	 * for it.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>|null, 2: array<string, mixed>}|null
	 */
	private static function entry(int $rStreamID): ?array {
		$rEntry = ReplicaStreamCache::get($rStreamID);
		if ($rEntry === null) {
			return null;
		}
		return [StreamSource::stream($rStreamID, $rEntry), StreamSource::server($rStreamID, $rEntry), $rEntry];
	}

	/**
	 * May this stream pass a list's filter, by what the stream caches' index
	 * copied from its record at the last apply (ReplicaStreamCache::meta)? A
	 * stream the index lacks, or a key its line does not have (an index
	 * written before it), may: its entry is read.
	 *
	 * @param array<int, array<string, mixed>> $rIndex
	 * @param array<string, int> $rWhere meta key => the value it must hold
	 */
	private static function candidate(array $rIndex, int $rID, array $rWhere): bool {
		$rMeta = $rIndex[$rID] ?? null;
		if (!is_array($rMeta)) {
			return true;
		}
		foreach ($rWhere as $rKey => $rValue) {
			if (array_key_exists($rKey, $rMeta) && $rMeta[$rKey] !== $rValue) {
				return false;
			}
		}
		return true;
	}

	/**
	 * As JSON_CONTAINS(target, candidate) for two lists: every element of
	 * the candidate is in the target.
	 *
	 * @param array<mixed> $rTarget
	 * @param array<mixed> $rCandidate
	 */
	private static function contains(array $rTarget, array $rCandidate): bool {
		foreach ($rCandidate as $rValue) {
			if (!in_array($rValue, $rTarget, true)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Rows MAIN's database answered: each server_stream_id names its stream
	 * for the node's store (an update by that id).
	 *
	 * @param list<array<string, mixed>> $rRows
	 * @return list<array<string, mixed>>
	 */
	private static function remembered(array $rRows): array {
		foreach ($rRows as $rRow) {
			$rStream = $rRow['stream_id'] ?? $rRow['id'] ?? null;
			StreamRuntime::remember($rRow['server_stream_id'] ?? null, $rStream);
		}
		return $rRows;
	}
}
