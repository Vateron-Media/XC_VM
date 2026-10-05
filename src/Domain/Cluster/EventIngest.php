<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Auth\BruteforceGuard;
use XcVm\Core\Cluster\AgentConnections;
use XcVm\Core\Cluster\BlocklistChanges;
use XcVm\Core\Cluster\HlsReaping;
use XcVm\Core\Cluster\LogSink;
use XcVm\Core\Cluster\NodeStateSink;
use XcVm\Core\Cluster\Redactor;
use XcVm\Core\Cluster\SignalDispatcher;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Stream\StreamsChangedEvent;
use XcVm\Domain\Stream\ContentSink;
use XcVm\Domain\Stream\RecordingFinalizer;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Domain\Stream\StreamRowMerge;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * MAIN's side of a node's `events` op (plan, sections 7 and 8, Phase 5): a
 * batch from one lane, numbered by the node from `first_useq`, applied in
 * order and at most once.
 *
 * ```text
 * p0  stream.state, stream.worker, stream.monitor,   gap-checked: first_useq must be
 *     recording.state, vod.analysis,                 useq_p0 + 1, else 409 {expected_useq}
 *     conn.upsert, conn.remove, conn.close, conn.limit,
 *     security.block_ip, node.state
 *                                                    and the node rewinds; below
 *                                                    it, only a copy of a batch
 *                                                    applied (else `backwards`)
 * p1  log.<type>, skip, node.inventory,             high-water: numbers at or below
 *     conn.divergence                                useq_p1 are skipped, gaps are fine
 * p2  conn.touch                                     no number: the latest value per
 *                                                    key by the event's time `t` wins
 * ```
 *
 * Every event is applied as the sending node: a stream's state goes to that
 * node's own `streams_servers` row and nothing else, log rows get its
 * server_id (a `log.syslog` row is root's on it). A flow that is off refuses
 * its events (dropped and counted), so nothing is written twice. A P0 or P1
 * batch and the new cursor commit together, and MAIN applies one such batch
 * per node and lane at a time, so a copy the node resent while the first was
 * being applied is recognised as a repeat. P2 keeps no cursor and takes no
 * lock: the latest value per key wins, so a repeat changes nothing.
 */
final class EventIngest {
	use DatabaseAware;

	/** Events per batch. */
	public const MAX_EVENTS = 5000;

	/** Longest a batch waits for the one before it from the same node and lane (s). */
	private const LOCK_WAIT = 10.0;

	/**
	 * How long a P0 batch's fingerprint outlives the next one (ms): a copy
	 * signed before the node moved on is refused past the request window
	 * (90 s). The newest is kept however old: the node resends its batch until
	 * it hears back, however long that takes.
	 */
	private const COPY_KEEP_MS = 180000;

	/** Lane of each event type, and the flow it needs. */
	private const TYPES = [
		'stream.state' => ['p0', NodeRegistry::FLOW_STREAMS],
		'stream.worker' => ['p0', NodeRegistry::FLOW_STREAMS],
		'stream.monitor' => ['p0', NodeRegistry::FLOW_STREAMS],
		'recording.state' => ['p0', NodeRegistry::FLOW_CONTENT],
		'conn.upsert' => ['p0', NodeRegistry::FLOW_CONNECTIONS],
		'conn.remove' => ['p0', NodeRegistry::FLOW_CONNECTIONS],
		'conn.close' => ['p0', NodeRegistry::FLOW_CONNECTIONS],
		'conn.limit' => ['p0', NodeRegistry::FLOW_CONNECTIONS],
		'vod.analysis' => ['p0', NodeRegistry::FLOW_CONTENT],
		'security.block_ip' => ['p0', NodeRegistry::FLOW_CONFIG],
		'node.state' => ['p0', NodeRegistry::FLOW_TELEMETRY],
		'node.inventory' => ['p1', NodeRegistry::FLOW_TELEMETRY],
		'conn.divergence' => ['p1', NodeRegistry::FLOW_CONNECTIONS],
		'conn.touch' => ['p2', NodeRegistry::FLOW_CONNECTIONS],
		'skip' => ['p1', NodeRegistry::FLOW_LOGS],
	];

	/** State columns that do not change what the stream cache holds. */
	private const CACHE_NEUTRAL = ['progress_info', 'delay_available_at'];

	/** @var (callable(int): mixed)|null */
	private static $rOnStreamChanged;

	private static ?string $rLockDir = null;

	/** @var list<callable(): mixed> What the batch being applied runs once it committed (afterCommit()). */
	private static array $rAfterCommit = [];

	/**
	 * What runs when an event changed a stream's routing state (tests; by
	 * default the stream's cache job is queued for MAIN, the signal the node
	 * used to write itself). Null restores the default.
	 *
	 * @param (callable(int): mixed)|null $rHook
	 */
	public static function onStreamChanged(?callable $rHook): void {
		self::$rOnStreamChanged = $rHook;
	}

	/**
	 * Tests: another directory for the lanes' lock files; null restores
	 * TMP_PATH/cluster_ingest/. Returns the directory it replaces, so a test
	 * can put it back.
	 */
	public static function useLockDir(?string $rDir): ?string {
		$rPrevious = self::$rLockDir;
		self::$rLockDir = $rDir;
		return $rPrevious;
	}

	/**
	 * `backwards` (P0): the batch starts at or below the cursor and is no
	 * copy of a batch MAIN applied. The agent numbers from MAIN's cursor and
	 * resends only the batch in flight, whole, so this is another install
	 * sending as the node (plan, section 4, "Quarantine"). Without a record
	 * of the batches applied (a fresh `cluster_ingest/`), it is a repeat, as
	 * before.
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row
	 * @param list<mixed> $rEvents
	 * @return array{ok: bool, useq: int, applied?: int, dropped?: int, expected_useq?: int, backwards?: true}
	 */
	public static function ingest(array $rNode, string $rLane, int $rFirst, array $rEvents): array {
		if ($rLane === 'p2') {
			return self::latest($rNode, $rEvents);
		}
		$rServerID = (int) $rNode['server_id'];
		$rColumn = 'useq_' . $rLane;
		$rLast = $rFirst + count($rEvents) - 1;
		$rDb = self::db();
		// One batch per node and lane at a time, and the cursor as it is now,
		// not as the request read the node's row: a batch the node sent again
		// while its first copy was still being applied (its request timed
		// out) waits here, then finds it applied.
		$rLock = self::lock($rServerID, $rLane);
		try {
			if (!$rDb->query('SELECT `' . $rColumn . '` AS `useq` FROM `cluster_nodes` WHERE `server_id` = ?;', $rServerID) || $rDb->num_rows() === 0) {
				throw new \RuntimeException('cannot read the node\'s cursor');
			}
			$rCursor = (int) $rDb->get_row()['useq'];
			$rPrint = $rLane === 'p0' && $rEvents !== [] ? $rFirst . ':' . count($rEvents) . ':' . hash('sha256', (string) json_encode($rEvents)) : null;
			$rCopies = $rPrint === null ? [] : self::copies($rLock);
			if ($rPrint !== null && $rFirst <= $rCursor && $rCopies !== [] && !isset($rCopies[$rPrint])) {
				return ['ok' => false, 'useq' => $rCursor, 'backwards' => true];
			}
			if ($rEvents === [] || $rLast <= $rCursor) {
				return ['ok' => true, 'useq' => $rCursor, 'applied' => 0, 'dropped' => 0]; // a repeat of what was applied
			}
			if ($rLane === 'p0' && $rFirst !== $rCursor + 1) {
				return ['ok' => false, 'useq' => $rCursor, 'expected_useq' => $rCursor + 1];
			}
			if ($rPrint !== null) {
				// Before the batch commits: a copy may be the next to ask.
				self::remember($rLock, $rCopies, $rPrint);
			}
			if ($rFirst <= $rCursor) {
				$rEvents = array_slice($rEvents, $rCursor - $rFirst + 1); // p1: the part already applied
			}
			// The batch and its cursor commit together or not at all: without
			// a transaction a batch that failed half way would keep its first
			// half, and the node would send all of it again.
			$rTx = method_exists($rDb, 'beginTransaction');
			self::$rAfterCommit = [];
			if ($rTx && !$rDb->beginTransaction()) {
				throw new \RuntimeException('cannot start the batch\'s transaction');
			}
			try {
				$rApplied = 0;
				$rDropped = 0;
				foreach ($rEvents as $rEvent) {
					if (self::apply($rNode, $rLane, $rEvent)) {
						$rApplied++;
					} else {
						$rDropped++;
					}
				}
				// The batch counts as applied only with its cursor. One that
				// was not written (the connection dropped mid-batch, taking
				// the transaction with it) fails the batch, and the node sends
				// it again instead of moving on past events MAIN never kept.
				// So does a commit that failed.
				if (!$rDb->query('UPDATE `cluster_nodes` SET `' . $rColumn . '` = ? WHERE `server_id` = ? AND `' . $rColumn . '` < ?;', $rLast, $rServerID, $rLast)) {
					throw new \RuntimeException('cannot advance the node\'s cursor');
				}
				if ($rTx && !$rDb->commit()) {
					throw new \RuntimeException('cannot commit the batch');
				}
			} catch (\Throwable $rE) {
				self::$rAfterCommit = []; // nothing of the batch was kept: nothing follows it
				if ($rTx) {
					try {
						$rDb->rollback();
					} catch (\Throwable) {
						// MySQL already rolled it back (a deadlock): the batch's own failure stands.
					}
				}
				throw $rE;
			}
			self::afterBatch();
			return ['ok' => true, 'useq' => $rLast, 'applied' => $rApplied, 'dropped' => $rDropped];
		} finally {
			if ($rLock !== null) {
				flock($rLock, LOCK_UN);
				fclose($rLock);
			}
		}
	}

	/**
	 * The P0 batches the lane's lock file records as applied (fingerprint =>
	 * when, ms). Empty when there is no lock or no record.
	 *
	 * @param resource|null $rLock
	 * @return array<string, int>
	 */
	private static function copies($rLock): array {
		if ($rLock === null || !rewind($rLock)) {
			return [];
		}
		$rCopies = json_decode((string) stream_get_contents($rLock), true);
		return is_array($rCopies) ? array_filter($rCopies, 'is_int') : [];
	}

	/**
	 * Record $rPrint as the newest batch, keeping the others for
	 * COPY_KEEP_MS. A write that fails leaves the lane without a record, so
	 * nothing is taken as backwards.
	 *
	 * @param resource|null $rLock
	 * @param array<string, int> $rCopies
	 */
	private static function remember($rLock, array $rCopies, string $rPrint): void {
		if ($rLock === null) {
			return;
		}
		$rNow = ClusterClock::nowMs();
		$rCopies = array_filter($rCopies, static fn(int $rAt): bool => $rAt >= $rNow - self::COPY_KEEP_MS);
		unset($rCopies[$rPrint]);
		$rCopies[$rPrint] = $rNow;
		if (!ftruncate($rLock, 0) || !rewind($rLock) || fwrite($rLock, (string) json_encode($rCopies)) === false) {
			ftruncate($rLock, 0);
		}
	}

	/**
	 * The node's lane, held while a batch is applied (`TMP_PATH/cluster_ingest/`,
	 * a file lock, so it never holds the node's database row, which every
	 * heartbeat writes). Null when the file cannot be opened: the batch then
	 * goes on unserialised, and only a copy that arrives after the first was
	 * applied is recognised.
	 *
	 * @return resource|null
	 */
	private static function lock(int $rServerID, string $rLane) {
		$rDir = self::$rLockDir ?? ((defined('TMP_PATH') ? TMP_PATH : sys_get_temp_dir() . '/') . 'cluster_ingest/');
		if (!is_dir($rDir)) {
			@mkdir($rDir, 0750, true);
		}
		$rHandle = @fopen($rDir . $rServerID . '_' . $rLane . '.lock', 'c+'); // c+: the P0 lock also holds its record (copies())
		if ($rHandle === false) {
			return null;
		}
		$rDeadline = microtime(true) + self::LOCK_WAIT;
		while (!flock($rHandle, LOCK_EX | LOCK_NB)) {
			if (microtime(true) >= $rDeadline) {
				fclose($rHandle);
				throw new \RuntimeException('the node\'s previous batch is still being applied'); // 503: the node resends later
			}
			usleep(50000);
		}
		return $rHandle;
	}

	/**
	 * One statement of a P0 or P1 batch; the batch fails when it does (503
	 * DB, and the node sends it again). Database::query() answers a failed
	 * statement with false, and a deadlock rolls back the batch's whole
	 * transaction: the statements after it would autocommit, the cursor
	 * among them, and the events applied before it would be lost for good.
	 * A statement that failed is therefore never an event refused (dropped
	 * and counted). A read the event depends on is checked too, or its
	 * failure would read as "not the node's" and drop the event.
	 */
	private static function run(string $rSql, mixed ...$rArgs): object {
		$rDb = self::db();
		if (!$rDb->query($rSql, ...$rArgs)) {
			throw new \RuntimeException('a statement of the batch failed');
		}
		return $rDb;
	}

	/**
	 * What an event writes besides its own rows: its audit line, the
	 * blocklist's change log, the stream's cache signal, a finished
	 * recording's stream versions (StreamVersions). Each swallows its
	 * own failure so the request it describes goes on, which inside the
	 * batch's transaction would hide a deadlock that rolled the whole batch
	 * back (run()). They run once the batch committed, outside it: one that
	 * fails then costs only itself, and a batch that failed leaves none of
	 * them behind (they follow when the node sends it again).
	 */
	private static function afterCommit(callable $rAction): void {
		self::$rAfterCommit[] = $rAction;
	}

	/** Run what the batch that just committed deferred (afterCommit()). */
	private static function afterBatch(): void {
		$rActions = self::$rAfterCommit;
		self::$rAfterCommit = [];
		foreach ($rActions as $rAction) {
			try {
				$rAction();
			} catch (\Throwable) {
				// The batch is committed: nothing that follows it undoes that.
			}
		}
	}

	/**
	 * The event types MAIN takes on P2, told to the agent at hello and in
	 * every heartbeat (`p2_types`): it sends them there only while MAIN lists
	 * them, and keeps the older way otherwise.
	 *
	 * @return list<string>
	 */
	public static function p2Types(): array {
		return array_keys(array_filter(self::TYPES, static fn(array $rType): bool => $rType[0] === 'p2'));
	}

	/**
	 * P2 (plan, "Ordering and backpressure"): state of which only the newest
	 * value per key counts. The lane has no cursor and is never refused for a
	 * gap. A batch is folded to the latest event per key by its time `t` (the
	 * later of two with the same time), and what holds it across batches keeps
	 * the latest too (ClusterBus::touch), so a repeated or late batch changes
	 * nothing newer. `conn.touch {uuid, hls_last_read}` is keyed by uuid.
	 *
	 * @param list<mixed> $rEvents
	 * @return array{ok: bool, useq: int, applied: int, dropped: int}
	 */
	private static function latest(array $rNode, array $rEvents): array {
		$rTouches = [];
		$rApplied = $rDropped = 0;
		foreach ($rEvents as $rEvent) {
			$rType = is_array($rEvent) && is_string($rEvent['type'] ?? null) ? $rEvent['type'] : '';
			[$rLane, $rFlow] = self::TYPES[$rType] ?? [null, 0];
			$rT = is_array($rEvent) ? ($rEvent['t'] ?? null) : null;
			$rData = is_array($rEvent) ? ($rEvent['d'] ?? null) : null;
			$rUUID = is_array($rData) ? ($rData['uuid'] ?? null) : null;
			$rRead = is_array($rData) ? ($rData['hls_last_read'] ?? null) : null;
			if ($rLane !== 'p2' || ((int) $rNode['flows'] & $rFlow) === 0 || !is_int($rT) || $rT < 0 || !is_string($rUUID) || !preg_match(AgentConnections::CONN_UUID, $rUUID) || !is_int($rRead) || $rRead < 0) {
				$rDropped++;
				continue;
			}
			if (!isset($rTouches[$rUUID]) || $rTouches[$rUUID][0] <= $rT) {
				$rTouches[$rUUID] = [$rT, $rRead];
			}
			$rApplied++;
		}
		ConnectionIngest::touch((int) $rNode['server_id'], HlsReaping::capable($rNode), $rTouches);
		return ['ok' => true, 'useq' => 0, 'applied' => $rApplied, 'dropped' => $rDropped];
	}

	/** Apply one event; false when it is refused (unknown, wrong lane, flow off, not the node's). */
	private static function apply(array $rNode, string $rLane, mixed $rEvent): bool {
		if (!is_array($rEvent) || !is_string($rEvent['type'] ?? null) || !is_array($rEvent['d'] ?? null)) {
			return false;
		}
		$rType = $rEvent['type'];
		$rData = $rEvent['d'];
		$rLog = str_starts_with($rType, 'log.') ? substr($rType, 4) : null;
		[$rWantLane, $rFlow] = $rLog !== null && isset(LogSink::TYPES[$rLog]) ? ['p1', NodeRegistry::FLOW_LOGS] : (self::TYPES[$rType] ?? [null, 0]);
		if ($rWantLane !== $rLane || ((int) $rNode['flows'] & $rFlow) === 0) {
			return false;
		}
		$rServerID = (int) $rNode['server_id'];
		if ($rLog !== null) {
			return self::logs($rServerID, $rLog, $rData);
		}
		switch ($rType) {
			case 'stream.state':
				return self::streamState($rServerID, $rData);
			case 'stream.worker':
				return self::streamWorker($rServerID, $rData);
			case 'stream.monitor':
				return self::streamMonitor($rServerID, $rData);
			case 'recording.state':
				return self::recordingState($rServerID, $rData);
			case 'vod.analysis':
				return self::vodAnalysis($rServerID, $rData);
			case 'conn.upsert':
				return is_array($rData['record'] ?? null) && ConnectionIngest::upsert($rServerID, $rData['record'], true);
			case 'conn.remove':
				return ConnectionIngest::remove($rServerID, (string) ($rData['uuid'] ?? ''), true);
			case 'conn.close':
				return ConnectionIngest::close($rServerID, (string) ($rData['uuid'] ?? ''));
			case 'conn.limit':
				return ConnectionLimits::queue($rServerID, $rData);
			case 'conn.divergence':
				return ConnectionIngest::divergence($rServerID, $rData);
			case 'security.block_ip':
				return self::blockIp($rServerID, $rData);
			case 'node.state':
				$rStatus = self::nodeStatus($rServerID, $rData);
				return self::nodeRow($rServerID, $rData, NodeStateSink::STATE, []) || $rStatus;
			case 'node.inventory':
				// time_offset as the legacy cron measured it: node clock − MAIN's.
				return self::nodeRow($rServerID, $rData, NodeStateSink::INVENTORY, ['time_offset' => (int) round(self::clockOffsetMs($rNode) / 1000)]);
		}
		// skip: the node dropped logs past its cap.
		$rCount = max(0, (int) ($rData['count'] ?? 0));
		self::afterCommit(static fn() => ClusterAudit::log('events.skip', $rServerID, ['count' => $rCount], 'node'));
		return true;
	}

	/** @param array<string, mixed> $rData {stream_id, server_id?, fields} or {ssid, fields} */
	private static function streamState(int $rServerID, array $rData): bool {
		$rFields = is_array($rData['fields'] ?? null) ? StreamRowMerge::eventFields($rData['fields']) : [];
		if ($rFields === []) {
			return false;
		}
		foreach ($rFields as $rValue) {
			if (!is_scalar($rValue) && $rValue !== null) {
				return false;
			}
		}
		if (isset($rData['ssid'])) {
			$rDb = self::run('SELECT `stream_id` FROM `streams_servers` WHERE `server_stream_id` = ? AND `server_id` = ?;', (int) $rData['ssid'], $rServerID);
			$rStreamID = $rDb->num_rows() > 0 ? (int) $rDb->get_row()['stream_id'] : 0;
			if ($rStreamID <= 0) {
				return false; // another node's row
			}
		} elseif (!isset($rData['stream_id']) || (isset($rData['server_id']) && (int) $rData['server_id'] !== $rServerID)) {
			return false; // another node's row
		} else {
			$rStreamID = (int) $rData['stream_id'];
		}
		if (!StreamRowMerge::mergeNode($rServerID, $rStreamID, $rFields, self::db())) {
			throw new \RuntimeException('a statement of the batch failed'); // its UPDATE, as run() fails it
		}
		if (array_diff(array_keys($rFields), self::CACHE_NEUTRAL) !== []) {
			self::streamChanged($rStreamID);
		}
		return true;
	}

	/**
	 * The fanout's view of a stream it supervises on the node, as the agent
	 * followed it: the row follows as PHP's reconcile would set it
	 * (StreamProcess::supervisedRowUpdate), against MAIN's own copy. A row the
	 * panel has stopped is left alone; the node's reconcile releases it.
	 *
	 * @param array<string, mixed> $rData {stream_id, state}
	 */
	private static function streamMonitor(int $rServerID, array $rData): bool {
		$rStreamID = is_int($rData['stream_id'] ?? null) ? $rData['stream_id'] : 0;
		$rState = $rData['state'] ?? null;
		if ($rStreamID <= 0 || !is_array($rState) || empty($rState['supervised'])) {
			return false;
		}
		$rDb = self::run('SELECT `stream_id`, `pid`, `monitor_pid`, `stream_status`, `current_source`, `stream_started`, `stream_info`, `audio_codec`, `video_codec`, `resolution`, `bitrate`, `compatible` FROM `streams_servers` WHERE `server_id` = ? AND `stream_id` = ?;', $rServerID, $rStreamID);
		$rRow = $rDb->num_rows() > 0 ? $rDb->get_row() : null;
		if ($rRow === null || (is_null($rRow['monitor_pid']) && is_null($rRow['pid']) && intval($rRow['stream_status']) === 0)) {
			return false;
		}
		$rSet = StreamRowMerge::eventFields(StreamProcess::supervisedRowUpdate($rRow, $rState, (bool) SettingsManager::get('player_allow_hevc'), ClusterClock::now()));
		if ($rSet === []) {
			return true;
		}
		if (!StreamRowMerge::mergeNode($rServerID, $rStreamID, $rSet, self::db())) {
			throw new \RuntimeException('a statement of the batch failed');
		}
		self::streamChanged($rStreamID);
		return true;
	}

	/**
	 * A node's flood or bruteforce guard blocked an IP (its CONFIG flow is on,
	 * so the blocklist is MAIN's): recorded in `blocked_ips` as the node used to
	 * write it, and every node picks it up with the blocklist. Only the guard's
	 * own reasons are taken, and never an address the nodes themselves never
	 * block (the cluster's servers, their whitelists, the admin allowlist), so a node cannot lock the cluster out.
	 *
	 * @param array<string, mixed> $rData {ip, reason}
	 */
	private static function blockIp(int $rServerID, array $rData): bool {
		$rIP = is_string($rData['ip'] ?? null) ? $rData['ip'] : '';
		$rReason = is_string($rData['reason'] ?? null) ? $rData['reason'] : '';
		if (filter_var($rIP, FILTER_VALIDATE_IP) === false || !preg_match(BruteforceGuard::REASON_PATTERN, $rReason)) {
			return false;
		}
		if (in_array($rIP, self::neverBlocked(), true)) {
			self::afterCommit(static fn() => ClusterAudit::log('security.block_ip_refused', $rServerID, ['ip' => $rIP], 'node'));
			return false;
		}
		if ((int) (self::run('SELECT COUNT(*) AS `n` FROM `blocked_ips` WHERE `ip` = ?;', $rIP)->get_row()['n'] ?? 0) === 0) {
			self::run('INSERT INTO `blocked_ips` (`ip`, `notes`, `date`) VALUES (?, ?, ?);', $rIP, $rReason, time());
			// Logged once the block committed: the daily full reload covers a change that could not be.
			self::afterCommit(static fn() => BlocklistChanges::set('ip', [$rIP], self::db()));
		}
		self::afterCommit(static fn() => ClusterAudit::log('security.block_ip', $rServerID, ['ip' => $rIP, 'reason' => $rReason], 'node'));
		return true;
	}

	/**
	 * A node's own `status` from its node.state (NodeStateSink::status): only
	 * updating or back (STATUSES), and only over one of them, so an install
	 * state MAIN set (3, 4) is never the node's to leave.
	 *
	 * @param array<string, mixed> $rData
	 */
	private static function nodeStatus(int $rServerID, array $rData): bool {
		$rStatus = is_array($rData['fields'] ?? null) ? ($rData['fields']['status'] ?? null) : null;
		if (!is_int($rStatus) || !in_array($rStatus, NodeStateSink::STATUSES, true)) {
			return false;
		}
		self::run('UPDATE `servers` SET `status` = ? WHERE `id` = ? AND `status` IN (' . implode(', ', NodeStateSink::STATUSES) . ');', $rStatus, $rServerID);
		return true;
	}

	/**
	 * A node's own `servers` row: only the columns its event type may set
	 * (NodeStateSink), each a scalar no longer than MAX_VALUE.
	 *
	 * @param array<string, mixed> $rData {fields}
	 * @param list<string> $rAllowed
	 * @param array<string, int> $rExtra set by MAIN alongside
	 */
	private static function nodeRow(int $rServerID, array $rData, array $rAllowed, array $rExtra): bool {
		$rFields = is_array($rData['fields'] ?? null) ? array_intersect_key($rData['fields'], array_flip($rAllowed)) : [];
		if ($rFields === []) {
			return false;
		}
		foreach ($rFields as $rValue) {
			if ((!is_scalar($rValue) && $rValue !== null) || strlen((string) $rValue) > NodeStateSink::MAX_VALUE) {
				return false;
			}
		}
		$rFields += $rExtra;
		$rSet = implode(', ', array_map(static fn(string $rColumn): string => '`' . $rColumn . '` = ?', array_keys($rFields)));
		self::run('UPDATE `servers` SET ' . $rSet . ' WHERE `id` = ?;', ...[...array_values($rFields), $rServerID]);
		return true;
	}

	/**
	 * The node's clock offset (ms) as MySQL has it. The heartbeat flush
	 * writes it without the registry, so the row the request was
	 * authenticated with may be the cluster bus's copy, up to
	 * NodeAuthCache::TTL_MS behind: the previous run's offset, or none, just
	 * after the agent restarts. That row's when MySQL cannot say.
	 *
	 * @param array<string, mixed> $rNode
	 */
	private static function clockOffsetMs(array $rNode): int {
		$rDb = self::db();
		if ($rDb->query('SELECT `clock_offset_ms` FROM `cluster_nodes` WHERE `server_id` = ?;', (int) $rNode['server_id']) && $rDb->num_rows() > 0) {
			return (int) ($rDb->get_row()['clock_offset_ms'] ?? 0);
		}
		return (int) ($rNode['clock_offset_ms'] ?? 0);
	}

	/** @return list<string> the cluster's server addresses, their whitelists and the admin allowlist */
	private static function neverBlocked(): array {
		$rIPs = ['127.0.0.1', '::1'];
		// Unread, the list would miss the servers, and one could be blocked.
		foreach (self::run('SELECT `server_ip`, `private_ip`, `whitelist_ips` FROM `servers`;')->get_rows() ?: [] as $rRow) {
			$rIPs[] = (string) $rRow['server_ip'];
			$rIPs[] = (string) $rRow['private_ip'];
			$rWhitelist = json_decode((string) $rRow['whitelist_ips'], true);
			foreach (is_array($rWhitelist) ? $rWhitelist : [] as $rIP) {
				$rIPs[] = is_string($rIP) ? $rIP : '';
			}
		}
		foreach (explode(',', (string) SettingsManager::get('allowed_ips_admin')) as $rIP) {
			$rIPs[] = trim($rIP);
		}
		return array_values(array_filter($rIPs, static fn(string $rIP): bool => $rIP !== ''));
	}

	/** @param array<string, mixed> $rData {stream_id, worker, pid} */
	private static function streamWorker(int $rServerID, array $rData): bool {
		$rWorker = $rData['worker'] ?? null;
		if (!in_array($rWorker, ContentSink::WORKERS, true) || !is_int($rData['stream_id'] ?? null) || !is_int($rData['pid'] ?? null)) {
			return false;
		}
		// Only for a stream whose worker runs on this node.
		if ((int) (self::run('SELECT COUNT(*) AS `n` FROM `streams` WHERE `id` = ? AND `' . $rWorker . '_server_id` = ?;', $rData['stream_id'], $rServerID)->get_row()['n'] ?? 0) === 0) {
			return false;
		}
		self::run('UPDATE `streams` SET `' . $rWorker . '_pid` = ? WHERE `id` = ?;', $rData['pid'], $rData['stream_id']);
		self::streamChanged($rData['stream_id']);
		return true;
	}

	/** @param array<string, mixed> $rData {id, status} */
	private static function recordingState(int $rServerID, array $rData): bool {
		$rID = is_int($rData['id'] ?? null) ? $rData['id'] : 0;
		$rStatus = $rData['status'] ?? null;
		if ($rID <= 0 || !in_array($rStatus, [1, 2, 3], true)) {
			return false;
		}
		if ($rStatus === RecordingFinalizer::DONE) {
			// A statement that failed throws, as run(). Its change is
			// dispatched once the batch committed: StreamVersions' bump in
			// the batch's transaction would hide a deadlock that rolled the
			// whole batch back (afterCommit()).
			return RecordingFinalizer::finish($rID, $rServerID, true, static function (StreamsChangedEvent $rEvent): void {
				self::afterCommit(static fn() => EventDispatcher::dispatch($rEvent));
			});
		}
		if ((int) (self::run('SELECT COUNT(*) AS `n` FROM `recordings` WHERE `id` = ? AND `source_id` = ?;', $rID, $rServerID)->get_row()['n'] ?? 0) === 0) {
			return false; // another node's recording
		}
		self::run('UPDATE `recordings` SET `status` = ? WHERE `id` = ?;', $rStatus, $rID);
		return true;
	}

	/** @param array<string, mixed> $rData {stream_id, props}: merged into MAIN's movie_properties */
	private static function vodAnalysis(int $rServerID, array $rData): bool {
		$rStreamID = is_int($rData['stream_id'] ?? null) ? $rData['stream_id'] : 0;
		$rProps = is_array($rData['props'] ?? null) ? array_intersect_key($rData['props'], array_flip(ContentSink::ANALYSIS_KEYS)) : [];
		if ($rStreamID <= 0 || $rProps === []) {
			return false;
		}
		// Only a movie this node holds.
		$rDb = self::run('SELECT `movie_properties` FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t2.`stream_id` = t1.`id` AND t2.`server_id` = ? WHERE t1.`id` = ? AND t1.`type` IN (2, 5);', $rServerID, $rStreamID);
		if ($rDb->num_rows() === 0) {
			return false;
		}
		$rCurrent = json_decode((string) $rDb->get_row()['movie_properties'], true);
		$rMerged = array_replace(is_array($rCurrent) ? $rCurrent : [], $rProps);
		self::run('UPDATE `streams` SET `movie_properties` = ? WHERE `id` = ?;', json_encode($rMerged, JSON_UNESCAPED_UNICODE), $rStreamID);
		self::streamChanged($rStreamID);
		return true;
	}

	/** The stream's cache signal, once the batch committed (afterCommit()): the cache then reads what it kept. */
	private static function streamChanged(int $rStreamID): void {
		self::afterCommit(static function () use ($rStreamID): void {
			if (self::$rOnStreamChanged !== null) {
				(self::$rOnStreamChanged)($rStreamID);
				return;
			}
			// Not StreamProcess::updateStream(): it finds MAIN in the server
			// list, which the API's bootstrap does not load, and queued the job
			// for server 0, whose signals nobody reads. MAIN's cache then kept
			// the stream's old state until the next full rebuild.
			if (SettingsManager::get('enable_cache')) {
				SignalDispatcher::cache(MainDataPlane::mainId(), ['type' => 'update_stream', 'id' => $rStreamID], true, false, self::db());
			}
		});
	}

	/** @param array<string, mixed> $rData {rows} */
	private static function logs(int $rServerID, string $rType, array $rData): bool {
		$rRows = $rData['rows'] ?? null;
		if (!is_array($rRows) || $rRows === [] || count($rRows) > LogSink::CHUNK) {
			return false;
		}
		$rColumns = array_flip(LogSink::TYPES[$rType][1]);
		$rClean = [];
		foreach ($rRows as $rRow) {
			if (!is_array($rRow)) {
				return false;
			}
			$rRow = array_filter(array_intersect_key($rRow, $rColumns), static fn($rValue) => is_scalar($rValue) || $rValue === null);
			if (isset($rColumns['server_id'])) {
				$rRow['server_id'] = $rServerID;
			}
			if ($rType === 'syslog') {
				$rRow = self::syslogRow($rRow);
				if ($rRow === null) {
					return false;
				}
			}
			$rClean[] = Redactor::redactRow($rRow);
		}
		if (!LogSink::insert($rType, $rClean, self::db())) {
			throw new \RuntimeException('a statement of the batch failed'); // the rows are checked: its INSERT failed, or activity's UPDATE `lines`
		}
		if ($rType === 'ondemand_check') {
			self::ondemandPointers($rServerID, $rClean, (int) self::db()->last_insert_id());
		}
		return true;
	}

	/**
	 * Point the node's `streams_servers` rows at the checks just inserted, as
	 * the scanner does with `last_insert_id()` when it writes them itself. A
	 * multi-row INSERT reports its first id and the rest follow consecutively.
	 *
	 * @param list<array<string, mixed>> $rRows The checks, in the order inserted.
	 */
	private static function ondemandPointers(int $rServerID, array $rRows, int $rFirstID): void {
		if ($rFirstID <= 0) {
			return;
		}
		$rLast = [];
		foreach (array_values($rRows) as $i => $rRow) {
			$rStreamID = (int) ($rRow['stream_id'] ?? 0);
			if ($rStreamID > 0) {
				$rLast[$rStreamID] = $rFirstID + $i;
			}
		}
		ksort($rLast);
		foreach ($rLast as $rStreamID => $rCheckID) {
			self::run('UPDATE `streams_servers` SET `ondemand_check` = ? WHERE `stream_id` = ? AND `server_id` = ?;', $rCheckID, $rStreamID, $rServerID);
		}
	}

	/**
	 * A `log.syslog` row as MAIN keeps it: what root did on the sending node
	 * (LogSink::syslog), so one of LogSink::SYSLOG_TYPES (never `AUTH`, whose
	 * addresses cron:root_mysql blocks), as `root` from `localhost` with no
	 * database, dated no later than MAIN's clock: the newest date is
	 * cron:root_mysql's watermark for MySQL's own log. Null: refused.
	 *
	 * @param array<string, scalar|null> $rRow
	 * @return array<string, scalar|null>|null
	 */
	private static function syslogRow(array $rRow): ?array {
		if (!in_array($rRow['type'] ?? null, LogSink::SYSLOG_TYPES, true) || !is_string($rRow['error'] ?? null)) {
			return null;
		}
		$rNow = ClusterClock::now();
		$rDate = $rRow['date'] ?? null;
		return [
			'server_id' => $rRow['server_id'], 'type' => $rRow['type'], 'error' => $rRow['error'], 'username' => 'root', 'ip' => 'localhost',
			'database' => null, 'date' => is_int($rDate) && $rDate > 0 ? min($rDate, $rNow) : $rNow,
		];
	}
}
