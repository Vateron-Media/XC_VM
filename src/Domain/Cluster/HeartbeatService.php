<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\LocalTelemetry;
use XcVm\Core\Cluster\OptionalDirSeam;
use XcVm\Core\Cluster\StrictQuery;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Util\SystemInfo;
use XcVm\Core\Util\TimeUtils;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Heartbeats. A verified heartbeat refreshes the node's last_seen_at and
 * clock offset. Its telemetry is kept in shadow for every node, and for a
 * node with the TELEMETRY flow on it is authoritative (Phase 3):
 *
 * - every 5 s it becomes the server's `watchdog_data`, `last_check_ago`,
 *   `requests_per_second` and `php_pids`, in the shape the legacy watchdog
 *   wrote (toWatchdogData(), pinned by
 *   ClusterTelemetryTest::testWatchdogDataKeepsTheLegacyShape), and,
 *   without the Redis handler, its `connections` and `users`;
 * - once a minute it becomes the node's `servers_stats` row, which
 *   `cron:servers` wrote on the LB until then.
 *
 * The LB's watchdog, the stats part of its cron:servers and network.py stand
 * down for that node (Core\Cluster\NodeFlows).
 *
 * On the cluster bus (plan, section 8): while the bus runs and a flusher has
 * finished a clean pass (MySQL took every write it had due) within
 * FLUSHER_STALE_MS, a heartbeat asks MySQL nothing. Its time, clock offset
 * and root_ready go to `cl:hb` and its telemetry to `cl:tel:<sid>`, and
 * flush() (LivenessService::tick, every second) copies them into
 * cluster_nodes at most every FLUSH_EVERY_MS per node (at once when
 * root_ready changed) and into servers / servers_stats by the rules above,
 * judged on the time MAIN heard each document. Without the bus, or when it
 * fails, or without a flusher, each heartbeat writes MySQL itself as before.
 * A flush never takes last_seen_at back: MySQL may be newer (hello, or a
 * heartbeat written directly), unless MySQL's is ahead of MAIN's clock
 * (the clock stepped back), which a heartbeat overwrites as before. What the
 * bus holds belongs to the enrolment that sent it (gen): a flush writes only
 * that enrolment's cluster_nodes row (and servers.status with it), and a
 * re-enrolment or a revocation drops it (forget()).
 */
final class HeartbeatService {
	use DatabaseAware;
	use OptionalDirSeam {
		dir as private;
	}

	/**
	 * Largest telemetry document kept per node, as MAIN encodes it: on the bus
	 * {at, auth, telemetry} with JSON_UNESCAPED_SLASHES and
	 * JSON_PRESERVE_ZERO_FRACTION (non-ASCII escaped as \uXXXX), and the
	 * shadow file {at, telemetry}. local.json alone may be 64 KiB.
	 */
	public const MAX_TELEMETRY = 131072;

	/**
	 * Largest `telemetry.local` read (encoded): the agent's cap on local.json,
	 * enforced here too, since only the node would enforce it otherwise and
	 * gpu_info and iostat_info are kept in servers_stats.
	 */
	public const MAX_LOCAL = 65536;

	/** Seconds between authoritative writes to `servers` and to `servers_stats`. */
	public const WRITE_EVERY = 5;
	public const STATS_EVERY = 60;

	/** Entries kept in watchdog_data.cpu_average_array, as the watchdog kept. */
	public const CPU_HISTORY = 30;

	/** Milliseconds between two flushes of a node's heartbeat into cluster_nodes. */
	public const FLUSH_EVERY_MS = 5000;

	/**
	 * A heartbeat stays on the bus only while a flusher has finished a clean
	 * pass (MySQL took every write it had due) within this many ms, by the
	 * bus's clock. Otherwise it writes MySQL itself, so MySQL never falls
	 * behind for want of a working flusher (the signals daemon stopped, and
	 * cron:cluster flushes only once a minute; or MySQL refusing the flush).
	 */
	public const FLUSHER_STALE_MS = 5000;

	/**
	 * Milliseconds a telemetry document stays on the bus without a newer one.
	 * Longer than the wake-ups and reservations, so under volatile-ttl those
	 * go first; the documents take at most MAX_TELEMETRY per node (usually a
	 * few KiB), never what fills the bus.
	 */
	public const TEL_TTL_MS = 600000;

	/** A node silent this long (ms) leaves the bus, once flushed. */
	public const FORGET_AFTER_MS = 600000;

	/**
	 * cluster_meta: when MAIN took a node's "updating" (status 5) from its
	 * node.state (`updating.<server id>`, by its updated_at); dropped when it
	 * takes "back" (EventIngest).
	 */
	public const UPDATING = 'updating.';

	/**
	 * Seconds a heartbeat leaves a row at 5 alone after MAIN took it
	 * (UPDATING): the agent keeps sending heartbeats until the update stops
	 * it. Past them, a node that still sends them is back by itself.
	 */
	public const UPDATING_HOLD_SEC = 60;

	/**
	 * What a heartbeat's `servers.status = 1` leaves alone: a row at 5 whose
	 * note (UPDATING) is younger than UPDATING_HOLD_SEC and not ahead of
	 * MAIN's clock. Its arguments come from held().
	 */
	private const NOT_HELD = ' AND NOT (`status` = 5 AND EXISTS (SELECT 1 FROM `cluster_meta` WHERE `name` = ? AND `updated_at` > ? AND `updated_at` <= ?))';

	/** Milliseconds a flusher holds its lock at most. */
	private const LOCK_MS = 10000;

	/** A last_seen_at more than this many ms ahead of MAIN's clock was stored before the clock stepped back. */
	private const STEP_MS = 1000;

	/** Bus: server id => "<heard ms>:<clock offset ms>:<root_ready 0|1|->:<telemetry heard ms>:<authoritative 0|1>:<gen>". */
	public const KEY_BEATS = 'cl:hb';

	/** Bus: server id => "<heard ms>:<root_ready>:<flushed at ms>:<servers written as of ms>", what flush() last wrote. */
	public const KEY_FLUSHED = 'cl:hb_flushed';

	/** Bus: when the last clean flush pass ended (the bus's ms). */
	public const KEY_FLUSHER = 'cl:flusher';

	/** Bus: one flusher at a time. */
	public const KEY_LOCK = 'cl:flush_lock';

	/** Bus: `cl:tel:<sid>` holds {at, auth, telemetry}, the node's last telemetry document. */
	public const TEL_PREFIX = 'cl:tel:';

	/**
	 * KEYS cl:hb, cl:tel:<sid>, cl:flusher; ARGV sid, heard ms, offset, root
	 * ('-' not sent), document ('' none), auth, FLUSHER_STALE_MS, TEL_TTL_MS,
	 * gen => 1 kept on the bus, 0 no flusher has finished a clean pass lately
	 * (the caller writes MySQL). A heartbeat without root_ready or telemetry
	 * keeps the last ones of the same enrolment (gen). The first write is the
	 * one that can be refused at maxmemory, so a refused script has written
	 * nothing.
	 */
	private const RECORD_LUA = <<<'LUA'
		local t = redis.call('TIME')
		local now = tonumber(t[1]) * 1000 + math.floor(tonumber(t[2]) / 1000)
		local stamped = tonumber(redis.call('GET', KEYS[3]) or '')
		if not stamped or math.abs(now - stamped) > tonumber(ARGV[7]) then
			return 0
		end
		local root, tel, auth = ARGV[4], '0', '0'
		local cur = redis.call('HGET', KEYS[1], ARGV[1])
		if cur then
			local r, tl, a, g = string.match(cur, '^%-?%d+:%-?%d+:([01%-]):(%d+):([01]):(%d+)$')
			if g == ARGV[9] then
				if root == '-' then
					root = r
				end
				tel, auth = tl, a
			end
		end
		if ARGV[5] ~= '' then
			redis.call('SET', KEYS[2], ARGV[5], 'PX', ARGV[8])
			tel, auth = ARGV[2], ARGV[6]
		end
		redis.call('HSET', KEYS[1], ARGV[1], ARGV[2] .. ':' .. ARGV[3] .. ':' .. root .. ':' .. tel .. ':' .. auth .. ':' .. ARGV[9])
		return 1
		LUA;

	/**
	 * KEYS cl:hb, cl:hb_flushed, cl:flush_lock; ARGV token, lock ms => {1
	 * locked | 0 another flusher holds it, HGETALL cl:hb, HGETALL
	 * cl:hb_flushed}.
	 */
	private const FLUSH_BEGIN_LUA = <<<'LUA'
		local got = 0
		if redis.call('SET', KEYS[3], ARGV[1], 'NX', 'PX', ARGV[2]) then
			got = 1
		end
		return {got, redis.call('HGETALL', KEYS[1]), redis.call('HGETALL', KEYS[2])}
		LUA;

	/**
	 * KEYS cl:hb_flushed, cl:flush_lock, cl:hb, cl:flusher, then cl:tel:<sid>
	 * per node to forget; ARGV token, clean (1: stamp cl:flusher), n, n × (sid,
	 * flushed), then per node to forget (sid, its cl:hb value as read). The
	 * lock goes first and the stamp and records last: only those can be
	 * refused at maxmemory, and a bus that full takes no more heartbeats. A
	 * node is forgotten (cl:hb, its record, its document) only if no
	 * heartbeat came meanwhile; a forgotten node has no record among the n.
	 */
	private const FLUSH_END_LUA = <<<'LUA'
		if redis.call('GET', KEYS[2]) == ARGV[1] then
			redis.call('DEL', KEYS[2])
		end
		local n = tonumber(ARGV[3])
		local k = 5
		for i = 4 + 2 * n, #ARGV, 2 do
			if redis.call('HGET', KEYS[3], ARGV[i]) == ARGV[i + 1] then
				redis.call('HDEL', KEYS[3], ARGV[i])
				redis.call('HDEL', KEYS[1], ARGV[i])
				redis.call('DEL', KEYS[k])
			end
			k = k + 1
		end
		if ARGV[2] == '1' then
			local t = redis.call('TIME')
			redis.call('SET', KEYS[4], t[1] .. string.format('%03d', math.floor(tonumber(t[2]) / 1000)))
		end
		for i = 0, n - 1 do
			redis.call('HSET', KEYS[1], ARGV[4 + 2 * i], ARGV[5 + 2 * i])
		end
		return 1
		LUA;

	/** KEYS cl:hb, cl:hb_flushed, cl:tel:<sid>; ARGV sid. */
	private const FORGET_LUA = <<<'LUA'
		redis.call('HDEL', KEYS[1], ARGV[1])
		redis.call('HDEL', KEYS[2], ARGV[1])
		redis.call('DEL', KEYS[3])
		return 1
		LUA;

	private const HGETALL_LUA = "return redis.call('HGETALL', KEYS[1])";

	private const GET_LUA = "return redis.call('GET', KEYS[1]) or ''";

	/**
	 * The node's own clock when it built a heartbeat (unix ms): its agent
	 * stamps requests with MAIN's time, so the payload's `local_ms` says it
	 * (an agent before it: the stamp).
	 *
	 * @param array<string, mixed> $rPayload
	 */
	public static function nodeClockMs(array $rPayload, int $rNodeTsMs): int {
		return is_int($rPayload['local_ms'] ?? null) ? $rPayload['local_ms'] : $rNodeTsMs;
	}

	/**
	 * @param array<string, mixed> $rNode
	 * @param array<string, mixed> $rPayload
	 */
	public static function record(array $rNode, array $rPayload, int $rNodeTsMs): void {
		$rServerID = (int) $rNode['server_id'];
		$rNow = ClusterClock::nowMs();
		$rOffset = max(-2147483648, min(2147483647, self::nodeClockMs($rPayload, $rNodeTsMs) - $rNow));
		// Whether the node's root-owned panel-key pin is in place (root commands).
		$rRoot = array_key_exists('root_ready', $rPayload) ? (empty($rPayload['root_ready']) ? 0 : 1) : null;
		$rTelemetry = is_array($rPayload['telemetry'] ?? null) ? $rPayload['telemetry'] : null;
		$rAuth = $rTelemetry !== null && (int) $rNode['mode'] >= 1 && ((int) $rNode['flows'] & NodeRegistry::FLOW_TELEMETRY);
		if (self::onBus($rServerID, (int) $rNode['gen'], $rNow, $rOffset, $rRoot, $rTelemetry, $rAuth)) {
			return;
		}
		$rFields = ['last_seen_at' => $rNow, 'clock_offset_ms' => $rOffset];
		if ($rRoot !== null) {
			$rFields['root_ready'] = $rRoot;
		}
		NodeRegistry::update($rServerID, $rFields);
		$rDir = self::dir();
		if ($rTelemetry !== null && $rDir !== null) {
			$rJson = (string) json_encode(['at' => $rNow, 'telemetry' => $rTelemetry], JSON_UNESCAPED_SLASHES);
			if (strlen($rJson) <= self::MAX_TELEMETRY) {
				if (!is_dir($rDir)) {
					@mkdir($rDir, 0750, true);
				}
				@file_put_contents($rDir . 'tel_' . $rServerID . '.json', $rJson, LOCK_EX);
			}
		}
		if ($rAuth) {
			self::authoritativeOrAudit($rServerID, (array) $rTelemetry, intdiv($rNow, 1000));
		}
		// The node's first authenticated heartbeat is what marks the server up
		// (plan, section 6); legacy nodes keep setting it through the watchdog.
		// An update the node just reported holds for UPDATING_HOLD_SEC.
		self::db()->query('UPDATE `servers` SET `status` = 1 WHERE `id` = ? AND `status` <> 1' . self::NOT_HELD . ';', $rServerID, ...self::held($rServerID, $rNow));
	}

	/**
	 * NOT_HELD's arguments for a heartbeat MAIN heard at $rNowMs.
	 *
	 * @return array{string, int, int}
	 */
	private static function held(int $rServerID, int $rNowMs): array {
		$rNow = intdiv($rNowMs, 1000);
		return [self::UPDATING . $rServerID, $rNow - self::UPDATING_HOLD_SEC, $rNow];
	}

	/**
	 * Keep a heartbeat on the bus: false when it must go to MySQL (no bus, no
	 * flusher running, the script failed, or a document too big for it).
	 *
	 * @param array<mixed>|null $rTelemetry
	 */
	private static function onBus(int $rServerID, int $rGen, int $rNow, int $rOffset, ?int $rRoot, ?array $rTelemetry, bool $rAuth): bool {
		if (ClusterBus::client() === null) {
			return false;
		}
		$rDoc = '';
		if ($rTelemetry !== null) {
			// Exact on the way back: 7.0 stays a float (the flush decodes it).
			$rDoc = json_encode(['at' => $rNow, 'auth' => $rAuth ? 1 : 0, 'telemetry' => $rTelemetry], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
			if ($rDoc === false || strlen($rDoc) > self::MAX_TELEMETRY) {
				return false;
			}
		}
		return ClusterBus::script(self::RECORD_LUA, [self::KEY_BEATS, self::TEL_PREFIX . $rServerID, self::KEY_FLUSHER], [
			$rServerID, $rNow, $rOffset, $rRoot === null ? '-' : $rRoot, $rDoc, $rAuth ? 1 : 0, self::FLUSHER_STALE_MS, self::TEL_TTL_MS, $rGen,
		]) === 1;
	}

	/**
	 * Drop what the bus holds of a node (`cl:hb`, `cl:hb_flushed`,
	 * `cl:tel:<sid>`): it belongs to the enrolment that sent it, which a
	 * re-enrolment or a revocation ends (NodeRegistry). A heartbeat of that
	 * enrolment still in flight may land after it; the flush's gen guard
	 * keeps it out of the new row.
	 */
	public static function forget(int $rServerID): void {
		ClusterBus::script(self::FORGET_LUA, [self::KEY_BEATS, self::KEY_FLUSHED, self::TEL_PREFIX . $rServerID], [$rServerID]);
	}

	/**
	 * One flusher pass (LivenessService::tick, every second): the heartbeats
	 * the bus holds go to MySQL, cluster_nodes at most every FLUSH_EVERY_MS per
	 * node (at once for a new node or a root_ready MySQL does not have yet,
	 * with servers.status 1), and each authoritative telemetry document by
	 * the direct path's own WRITE_EVERY / STATS_EVERY rules, on the time MAIN
	 * heard it. What MySQL refuses is tried again at the next pass; only a
	 * clean pass (MySQL took it all) lets heartbeats keep to the bus
	 * (FLUSHER_STALE_MS). One flusher writes at a time; the others only read.
	 * A node silent for FORGET_AFTER_MS leaves the bus with its record, once
	 * flushed.
	 *
	 * @return array<int, int> server id => when MAIN last heard it (ms), as the
	 *                         bus has it; [] without the bus.
	 */
	public static function flush(): array {
		$rToken = bin2hex(random_bytes(8));
		$rOut = ClusterBus::script(self::FLUSH_BEGIN_LUA, [self::KEY_BEATS, self::KEY_FLUSHED, self::KEY_LOCK], [$rToken, self::LOCK_MS]);
		if (!is_array($rOut) || count($rOut) !== 3) {
			return [];
		}
		$rBeats = self::beats($rOut[1]);
		$rHeard = array_map(static fn(array $rBeat): int => $rBeat['heard'], $rBeats);
		if ((int) $rOut[0] !== 1) {
			return $rHeard;
		}
		$rFlushed = self::flushedRecords($rOut[2]);
		$rNow = ClusterClock::nowMs();
		$rRecords = $rForget = [];
		$rClean = true;
		try {
			foreach ($rBeats as $rID => $rBeat) {
				$rPrev = $rFlushed[$rID] ?? null;
				$rRec = $rPrev ?? ['heard' => 0, 'root' => '-', 'at' => 0, 'written' => 0];
				// What MySQL refuses is not recorded, and goes again next pass.
				try {
					$rDue = $rPrev === null || $rNow - $rRec['at'] >= self::FLUSH_EVERY_MS || $rRec['at'] > $rNow || $rBeat['root'] !== $rRec['root'];
					if ($rBeat['heard'] !== $rRec['heard'] && $rDue) {
						$rRec = ['heard' => $rBeat['heard'], 'root' => self::flushNode($rID, $rBeat, $rNow, $rRec['root']), 'at' => $rNow] + $rRec;
					}
				} catch (\Throwable) {
					$rClean = false;
				}
				try {
					if ($rBeat['auth'] && $rBeat['tel'] > $rRec['written'] && intdiv($rBeat['tel'], 1000) - intdiv($rRec['written'], 1000) >= self::WRITE_EVERY) {
						$rRec['written'] = self::flushTelemetry($rID, $rBeat['tel']) ?? throw new \RuntimeException('flush');
					}
				} catch (\Throwable) {
					$rClean = false;
				}
				if ($rNow - $rBeat['heard'] > self::FORGET_AFTER_MS && $rRec['heard'] === $rBeat['heard']) {
					// Not recorded: FLUSH_END drops the record. A node it keeps (a
					// heartbeat came meanwhile) has an older one, so that heartbeat
					// is due at once.
					$rForget[$rID] = $rBeat['raw'];
				} elseif ($rRec !== $rPrev) {
					$rRecords[$rID] = $rRec;
				}
			}
		} finally {
			$rKeys = [self::KEY_FLUSHED, self::KEY_LOCK, self::KEY_BEATS, self::KEY_FLUSHER];
			$rArgs = [$rToken, $rClean ? 1 : 0, count($rRecords)];
			foreach ($rRecords as $rID => $rRec) {
				array_push($rArgs, $rID, $rRec['heard'] . ':' . $rRec['root'] . ':' . $rRec['at'] . ':' . $rRec['written']);
			}
			foreach ($rForget as $rID => $rRaw) {
				$rKeys[] = self::TEL_PREFIX . $rID;
				array_push($rArgs, $rID, $rRaw);
			}
			ClusterBus::script(self::FLUSH_END_LUA, $rKeys, $rArgs);
		}
		return $rHeard;
	}

	/**
	 * When MAIN last heard each node, as the bus has it (ms): [] without the
	 * bus. MySQL's last_seen_at may be older by up to a flush.
	 *
	 * @return array<int, int>
	 */
	public static function lastSeen(): array {
		return array_map(static fn(array $rBeat): int => $rBeat['heard'], self::beats(ClusterBus::script(self::HGETALL_LUA, [self::KEY_BEATS], [])));
	}

	/** The later of MySQL's last_seen_at and the bus's (lastSeen(), flush()); null when neither has one. */
	public static function freshest(mixed $rStored, ?int $rOnBus): ?int {
		$rStored = $rStored === null ? null : (int) $rStored;
		return $rOnBus === null ? $rStored : max($rStored ?? $rOnBus, $rOnBus);
	}

	/**
	 * The node's last telemetry document, {at: ms MAIN heard it, telemetry}:
	 * the bus's `cl:tel:<sid>` or the shadow file `tel_<sid>.json`, whichever
	 * is newer. Null when neither holds one.
	 *
	 * @return array{at: int, telemetry: array<mixed>}|null
	 */
	public static function telemetry(int $rServerID): ?array {
		$rBus = self::document(ClusterBus::script(self::GET_LUA, [self::TEL_PREFIX . $rServerID], []));
		$rDir = self::dir();
		$rFile = $rDir === null ? null : self::document(@file_get_contents($rDir . 'tel_' . $rServerID . '.json'));
		$rDoc = $rBus !== null && ($rFile === null || $rBus['at'] > $rFile['at']) ? $rBus : $rFile;
		return $rDoc === null ? null : ['at' => $rDoc['at'], 'telemetry' => $rDoc['telemetry']];
	}

	/**
	 * A telemetry document as stored, {at, auth, telemetry}; null when it is
	 * not one.
	 *
	 * @return array{at: int, auth: bool, telemetry: array<mixed>}|null
	 */
	private static function document(mixed $rJson): ?array {
		$rDoc = is_string($rJson) && $rJson !== '' ? json_decode($rJson, true) : null;
		if (!is_array($rDoc) || !is_int($rDoc['at'] ?? null) || !is_array($rDoc['telemetry'] ?? null)) {
			return null;
		}
		return ['at' => $rDoc['at'], 'auth' => !empty($rDoc['auth']), 'telemetry' => $rDoc['telemetry']];
	}

	/**
	 * `cl:hb` as HGETALL returned it.
	 *
	 * @return array<int, array{heard: int, offset: int, root: string, tel: int, auth: bool, gen: int, raw: string}>
	 */
	private static function beats(mixed $rFlat): array {
		$rOut = [];
		foreach (self::pairs($rFlat) as $rID => $rValue) {
			if (preg_match('/^(-?\d+):(-?\d+):([01-]):(\d+):([01]):(\d+)$/', $rValue, $rM)) {
				$rOut[$rID] = ['heard' => (int) $rM[1], 'offset' => (int) $rM[2], 'root' => $rM[3], 'tel' => (int) $rM[4], 'auth' => $rM[5] === '1', 'gen' => (int) $rM[6], 'raw' => $rValue];
			}
		}
		return $rOut;
	}

	/**
	 * `cl:hb_flushed` as HGETALL returned it.
	 *
	 * @return array<int, array{heard: int, root: string, at: int, written: int}>
	 */
	private static function flushedRecords(mixed $rFlat): array {
		$rOut = [];
		foreach (self::pairs($rFlat) as $rID => $rValue) {
			if (preg_match('/^(-?\d+):([01-]):(-?\d+):(-?\d+)$/', $rValue, $rM)) {
				$rOut[$rID] = ['heard' => (int) $rM[1], 'root' => $rM[2], 'at' => (int) $rM[3], 'written' => (int) $rM[4]];
			}
		}
		return $rOut;
	}

	/**
	 * A flat HGETALL reply keyed by server id.
	 *
	 * @return array<int, string>
	 */
	private static function pairs(mixed $rFlat): array {
		$rFlat = is_array($rFlat) ? array_values($rFlat) : [];
		$rOut = [];
		for ($i = 0; $i + 1 < count($rFlat); $i += 2) {
			if (is_numeric($rFlat[$i]) && is_string($rFlat[$i + 1])) {
				$rOut[(int) $rFlat[$i]] = $rFlat[$i + 1];
			}
		}
		return $rOut;
	}

	/**
	 * A node's last heartbeat into cluster_nodes, for the enrolment that sent
	 * it (gen) and unless MySQL already has a later one (not one ahead of
	 * MAIN's clock), and the server marked up, as each heartbeat did (but a
	 * reported update for its hold, NOT_HELD).
	 *
	 * @param array{heard: int, offset: int, root: string, tel: int, auth: bool, gen: int, raw: string} $rBeat
	 * @param string $rRecorded The root_ready recorded at the last flush.
	 * @return string The root_ready to record: when it changed, what MySQL
	 *                now holds, read back ('-' without the row), since a
	 *                newer last_seen_at (hello) keeps the UPDATE from
	 *                matching, and the next heartbeat must then still count
	 *                as a change.
	 */
	private static function flushNode(int $rServerID, array $rBeat, int $rNow, string $rRecorded): string {
		$rSql = 'UPDATE `cluster_nodes` SET `last_seen_at` = ?, `clock_offset_ms` = ?';
		$rArgs = [$rBeat['heard'], $rBeat['offset']];
		if ($rBeat['root'] !== '-') {
			$rSql .= ', `root_ready` = ?';
			$rArgs[] = (int) $rBeat['root'];
		}
		array_push($rArgs, intdiv($rBeat['heard'], 1000), $rServerID, $rBeat['gen'], $rBeat['heard'], $rNow + self::STEP_MS);
		$rDb = self::db();
		StrictQuery::orThrow($rDb, 'flush', $rSql . ', `updated_at` = ? WHERE `server_id` = ? AND `gen` = ? AND (`last_seen_at` IS NULL OR `last_seen_at` < ? OR `last_seen_at` > ?);', ...$rArgs);
		StrictQuery::orThrow($rDb, 'flush', 'UPDATE `servers` SET `status` = 1 WHERE `id` = ? AND `status` <> 1 AND EXISTS (SELECT 1 FROM `cluster_nodes` WHERE `server_id` = ? AND `gen` = ?)' . self::NOT_HELD . ';', $rServerID, $rServerID, $rBeat['gen'], ...self::held($rServerID, $rNow));
		if ($rBeat['root'] === '-' || $rBeat['root'] === $rRecorded) {
			return $rBeat['root'];
		}
		StrictQuery::orThrow($rDb, 'flush', 'SELECT `root_ready` FROM `cluster_nodes` WHERE `server_id` = ? AND `gen` = ?;', $rServerID, $rBeat['gen']);
		return $rDb->num_rows() > 0 ? (string) (int) $rDb->get_row()['root_ready'] : '-';
	}

	/**
	 * The node's telemetry document on the bus into servers (and
	 * servers_stats), when it is authoritative: the servers row's
	 * last_check_ago afterwards, in ms, or null when MySQL refused (the next
	 * pass tries again).
	 *
	 * @param int $rTel When MAIN heard the document cl:hb names (ms): done
	 *                  with, when it is gone (expired, evicted).
	 */
	private static function flushTelemetry(int $rServerID, int $rTel): ?int {
		$rDoc = self::document(ClusterBus::script(self::GET_LUA, [self::TEL_PREFIX . $rServerID], []));
		if ($rDoc === null || !$rDoc['auth']) {
			return $rTel;
		}
		$rLast = self::authoritativeOrAudit($rServerID, $rDoc['telemetry'], intdiv($rDoc['at'], 1000));
		return $rLast === null ? null : $rLast * 1000;
	}

	/**
	 * authoritative(), whose failure never costs the node its heartbeat.
	 *
	 * @param array<mixed> $rTel
	 * @return int|null As authoritative(); the heard time when it failed.
	 */
	private static function authoritativeOrAudit(int $rServerID, array $rTel, int $rNow): ?int {
		try {
			return self::authoritative($rServerID, $rTel, $rNow);
		} catch (\Throwable $rE) {
			ClusterAudit::log('telemetry.error', $rServerID, substr($rE->getMessage(), 0, 200));
			return $rNow;
		}
	}

	/**
	 * The legacy `SystemInfo::getStats()` document, plus the watchdog's
	 * `cpu_average_array` and `fanout`, from an agent's telemetry.
	 *
	 * @param array<string, mixed> $rTel The agent's sample (clusteragent.Sampler).
	 * @param array<string, mixed> $rPrev The server's current watchdog_data.
	 * @param string|null $rInterface servers.network_interface; null or 'auto' for all.
	 * @return array<string, mixed>
	 */
	public static function toWatchdogData(array $rTel, array $rPrev, ?string $rInterface = null): array {
		$rCores = max(0, (int) ($rTel['cpu_cores'] ?? 0));
		$rLoad = is_array($rTel['load'] ?? null) ? (float) ($rTel['load'][0] ?? 0) : 0.0;
		$rCpu = isset($rTel['cpu']) ? (float) $rTel['cpu'] : (float) ($rPrev['cpu'] ?? 0);
		$rTotal = max(0, (int) ($rTel['mem_total_kb'] ?? 0));
		$rUsed = max(0, $rTotal - (int) ($rTel['mem_avail_kb'] ?? 0));
		$rOut = [
			'cpu' => min(100, round($rCpu, 2)),
			'cpu_cores' => $rCores,
			'cpu_avg' => min(100, round(($rLoad * 100) / ($rCores ?: 1), 2)),
			'cpu_name' => (string) ($rTel['cpu_name'] ?? ''),
			'total_mem' => $rTotal,
			'total_mem_free' => $rTotal - $rUsed,
			'total_mem_used' => $rUsed,
			'total_mem_used_percent' => min(100, SystemInfo::memUsedPercent($rUsed, $rTotal)),
			'total_disk_space' => (float) ($rTel['disk_total'] ?? 0),
			'free_disk_space' => (float) ($rTel['disk_free'] ?? 0),
			'kernel' => (string) ($rTel['kernel'] ?? ''),
			'uptime' => isset($rTel['uptime_s']) ? TimeUtils::secondsToTime((int) $rTel['uptime_s']) : '',
			'total_running_streams' => (int) ($rTel['stream_producers'] ?? 0),
			'bytes_sent' => 0,
			'bytes_sent_total' => 0,
			'bytes_received' => 0,
			'bytes_received_total' => 0,
			'network_speed' => 0,
			'interfaces' => array_values(array_filter((array) ($rTel['interfaces'] ?? []), 'is_string')),
			'network_info' => [],
		];
		$rOnly = (in_array($rInterface, [null, '', 'auto'], true)) ? null : $rInterface;
		$rNet = is_array($rTel['net'] ?? null) ? $rTel['net'] : [];
		ksort($rNet);
		foreach ($rNet as $rName => $rRate) {
			$rName = (string) $rName;
			if (!is_array($rRate) || $rName === 'lo' || ($rOnly !== null && $rName !== $rOnly) || ($rOnly === null && str_starts_with($rName, 'bond'))) {
				continue;
			}
			$rOut['network_info'][$rName] = [
				'in_bytes' => (int) ($rRate['in_bytes'] ?? 0), 'in_packets' => (int) ($rRate['in_packets'] ?? 0), 'in_errors' => (int) ($rRate['in_errors'] ?? 0),
				'out_bytes' => (int) ($rRate['out_bytes'] ?? 0), 'out_packets' => (int) ($rRate['out_packets'] ?? 0), 'out_errors' => (int) ($rRate['out_errors'] ?? 0),
			];
			$rOut['bytes_sent'] += (int) ($rRate['out_bytes'] ?? 0);
			$rOut['bytes_received'] += (int) ($rRate['in_bytes'] ?? 0);
			$rOut['bytes_sent_total'] += (int) ($rRate['tx_total'] ?? 0);
			$rOut['bytes_received_total'] += (int) ($rRate['rx_total'] ?? 0);
			if ($rOut['network_speed'] === 0 && (int) ($rRate['speed'] ?? 0) > 0) {
				$rOut['network_speed'] = (int) $rRate['speed'];
			}
		}
		// The node's watchdog probes these (local.json); an absent key is an absent tool.
		$rLocal = self::local($rTel);
		foreach (LocalTelemetry::DEVICES as $rKey) {
			$rOut[$rKey] = self::deviceSection($rKey, $rLocal[$rKey] ?? null);
		}
		$rOut['cpu_load_average'] = $rLoad;
		$rHistory = is_array($rPrev['cpu_average_array'] ?? null) ? array_values($rPrev['cpu_average_array']) : [];
		$rHistory[] = $rOut['cpu'];
		$rOut['cpu_average_array'] = array_slice($rHistory, -self::CPU_HISTORY);
		$rOut['fanout'] = is_array($rLocal['fanout'] ?? null) ? $rLocal['fanout'] : ($rPrev['fanout'] ?? null);
		return $rOut;
	}

	/**
	 * The telemetry's `local` (the node's local.json), or [] when there is none
	 * or it is over MAX_LOCAL: absent, as the agent would have left it out.
	 *
	 * @param array<string, mixed> $rTel
	 * @return array<mixed>
	 */
	private static function local(array $rTel): array {
		$rLocal = $rTel['local'] ?? null;
		if (!is_array($rLocal) || strlen((string) json_encode($rLocal, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) > self::MAX_LOCAL) {
			return [];
		}
		return $rLocal;
	}

	/**
	 * One device section of the node's local.json, in SystemInfo's shape as
	 * far as the panel reads it: [] for a non-array, capture devices as a
	 * list (names, and objects for video), GPUs as objects, and iostat's CPU
	 * figures, which the dashboard rounds, as numbers.
	 *
	 * @return array<mixed>
	 */
	private static function deviceSection(string $rKey, mixed $rValue): array {
		if (!is_array($rValue)) {
			return [];
		}
		switch ($rKey) {
			case 'audio_devices':
				return array_values(array_filter($rValue, 'is_string'));
			case 'video_devices':
				return array_values(array_filter($rValue, 'is_array'));
			case 'gpu_info':
				if (array_key_exists('gpus', $rValue)) {
					$rValue['gpus'] = is_array($rValue['gpus']) ? array_values(array_filter($rValue['gpus'], 'is_array')) : [];
				}
				return $rValue;
			default:
				if (array_key_exists('avg-cpu', $rValue)) {
					$rValue['avg-cpu'] = is_array($rValue['avg-cpu']) ? array_filter($rValue['avg-cpu'], 'is_numeric') : [];
				}
				return $rValue;
		}
	}

	/**
	 * Write a TELEMETRY node's figures where the legacy watchdog and
	 * cron:servers wrote them, at most every WRITE_EVERY / STATS_EVERY seconds.
	 *
	 * @param array<string, mixed> $rTel
	 * @param int $rNow When MAIN heard it (s).
	 * @return int|null The row's last_check_ago afterwards ($rNow once written;
	 *                  $rNow too without a row), null when the write failed.
	 */
	private static function authoritative(int $rServerID, array $rTel, int $rNow): ?int {
		if (self::db()->query('SELECT `watchdog_data`, `last_check_ago`, `network_interface` FROM `servers` WHERE `id` = ?;', $rServerID) === false) {
			return null;
		}
		$rRow = self::db()->num_rows() > 0 ? self::db()->get_row() : null;
		if ($rRow === null) {
			return $rNow;
		}
		if ($rNow - (int) $rRow['last_check_ago'] < self::WRITE_EVERY) {
			return (int) $rRow['last_check_ago'];
		}
		$rPrev = json_decode((string) ($rRow['watchdog_data'] ?? ''), true);
		$rStats = self::toWatchdogData($rTel, is_array($rPrev) ? $rPrev : [], $rRow['network_interface'] ?? null);
		$rRps = (int) (self::local($rTel)['requests_per_second'] ?? 0);
		$rPIDs = array_key_exists('php_pids', $rTel) && is_array($rTel['php_pids']) ? array_values(array_map('intval', $rTel['php_pids'])) : null;
		$rCounts = self::counts($rServerID);
		$rSql = 'UPDATE `servers` SET `watchdog_data` = ?, `last_check_ago` = ?, `requests_per_second` = ?, `php_pids` = ?';
		$rArgs = [json_encode($rStats, JSON_PARTIAL_OUTPUT_ON_ERROR), $rNow, $rRps, json_encode($rPIDs)];
		if ($rCounts !== null) {
			$rSql .= ', `connections` = ?, `users` = ?';
			$rArgs[] = $rCounts['connections'];
			$rArgs[] = $rCounts['users'];
		}
		if (self::db()->query($rSql . ' WHERE `id` = ?;', ...[...$rArgs, $rServerID]) === false) {
			return null;
		}

		$rDir = self::dir();
		$rMarker = $rDir === null ? null : $rDir . 'stats_' . $rServerID;
		if ($rMarker !== null && is_file($rMarker) && $rNow - (int) @file_get_contents($rMarker) < self::STATS_EVERY) {
			return $rNow;
		}
		self::statsRow($rServerID, $rStats, $rCounts, $rNow);
		if ($rMarker !== null) {
			if (!is_dir(dirname($rMarker))) {
				@mkdir(dirname($rMarker), 0750, true);
			}
			@file_put_contents($rMarker, (string) $rNow, LOCK_EX);
		}
		return $rNow;
	}

	/** Where the shadow copies and stats markers go (useDir(): tests' own); null (not kept) without TMP_PATH. */
	private static function defaultDir(): ?string {
		return defined('TMP_PATH') ? TMP_PATH . 'cluster/' : null;
	}

	/**
	 * The node's connection and user counts as its watchdog counted them
	 * without the Redis handler; null with it (MAIN's watchdog counts those).
	 *
	 * @return array{connections: int, users: int}|null
	 */
	private static function counts(int $rServerID): ?array {
		if (SettingsManager::get('redis_handler')) {
			return null;
		}
		self::db()->query('SELECT COUNT(*) AS `count` FROM `lines_live` WHERE `hls_end` = 0 AND `server_id` = ?;', $rServerID);
		$rConnections = (int) (self::db()->get_row()['count'] ?? 0);
		self::db()->query('SELECT `activity_id` FROM `lines_live` WHERE `hls_end` = 0 AND `server_id` = ? GROUP BY `user_id`;', $rServerID);
		return ['connections' => $rConnections, 'users' => (int) self::db()->num_rows()];
	}

	/**
	 * The minute's `servers_stats` row, as the LB's cron:servers wrote it.
	 *
	 * @param array<string, mixed> $rStats toWatchdogData()
	 * @param array{connections: int, users: int}|null $rCounts
	 * @param int $rNow When MAIN heard it (s): the row's time.
	 */
	private static function statsRow(int $rServerID, array $rStats, ?array $rCounts, int $rNow): void {
		if ($rCounts === null) {
			self::db()->query('SELECT `connections`, `users` FROM `servers` WHERE `id` = ?;', $rServerID);
			$rRow = self::db()->get_row() ?: [];
			$rCounts = ['connections' => (int) ($rRow['connections'] ?? 0), 'users' => (int) ($rRow['users'] ?? 0)];
		}
		self::db()->query('SELECT COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `server_id` = ? AND `pid` > 0 AND `type` = 1;', $rServerID);
		$rStreams = (int) (self::db()->get_row()['count'] ?? 0);
		$rHistory = (array) $rStats['cpu_average_array'];
		$rCpu = count($rHistory) > 0 ? round(array_sum($rHistory) / count($rHistory), 2) : $rStats['cpu'];
		self::db()->query(
			'INSERT INTO `servers_stats`(`server_id`, `connections`, `total_users`, `users`, `streams`, `cpu`, `cpu_cores`, `cpu_avg`, `total_mem`, `total_mem_free`, `total_mem_used`, `total_mem_used_percent`, `total_disk_space`, `uptime`, `total_running_streams`, `bytes_sent`, `bytes_received`, `bytes_sent_total`, `bytes_received_total`, `cpu_load_average`, `gpu_info`, `iostat_info`, `time`) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);',
			$rServerID,
			$rCounts['connections'],
			(int) (SettingsManager::get('total_users') ?? 0),
			$rCounts['users'],
			$rStreams,
			$rCpu,
			$rStats['cpu_cores'],
			$rStats['cpu_avg'],
			$rStats['total_mem'],
			$rStats['total_mem_free'],
			$rStats['total_mem_used'],
			$rStats['total_mem_used_percent'],
			$rStats['total_disk_space'],
			$rStats['uptime'],
			$rStats['total_running_streams'],
			$rStats['bytes_sent'],
			$rStats['bytes_received'],
			$rStats['bytes_sent_total'],
			$rStats['bytes_received_total'],
			$rStats['cpu_load_average'],
			json_encode($rStats['gpu_info'], JSON_UNESCAPED_UNICODE),
			json_encode($rStats['iostat_info'], JSON_UNESCAPED_UNICODE),
			$rNow
		);
	}
}
