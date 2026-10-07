<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Database\DatabaseHandler;
use XcVm\Core\Process\ProcessManager;

/**
 * The cluster bus (plan, section 2): MAIN's own Redis instance for the
 * cluster API, separate from the shared Redis the panel and legacy LBs use,
 * and reachable only through a unix socket (`bin/cluster_bus/cluster.sock`,
 * mode 0700, owned by xc_vm). It holds nothing that must survive a restart,
 * so it is not persisted, and `disable_handler` / `clear_redis` never touch
 * it.
 *
 * Today it carries wake-ups:
 *
 * - `wake:<sid>`: a command was queued for the node, so its `commands`
 *   long-poll returns at once instead of re-reading `cluster_commands` every
 *   250 ms for 20 s;
 * - `ack:<cmd_id>`: the node acked a command, so an RPC waiting for it returns
 *   at once instead of polling every 100 ms.
 *
 * A wake is a one-element list with a short TTL, taken with BLPOP: one pushed
 * just before the waiter blocks is not lost, and a stale one costs one extra
 * query. Without the bus (not started, an LB, a test) every method reports
 * that it could not help, and the callers poll as they did before.
 *
 * It also keeps the viewers' touches (`conn.touch`, P2): when each of a
 * node's viewers last asked for its playlist, `touch:<sid>:<uuid>` holding
 * `<t>:<hls_last_read>`, for nodes whose agent ends its own idle HLS viewers
 * (ConnectionIngest::touch). Without the bus, or past TOUCH_MEMORY_SHARE of
 * its memory, they go to MAIN's store.
 *
 * And the request nonces (NonceStore: `nonce:<node>`, `nonces_since`) and
 * the per-op semaphores (ClusterSemaphore: `sem:<op>`), through script().
 * Nonces and permits are sorted sets without a TTL, so the volatile-ttl
 * policy never evicts them. Nor are they refused past maxmemory (a script's
 * first write lets the rest through): their size is bounded by
 * authenticated traffic, and past maxmemory they push out the keys with a
 * TTL.
 *
 * And the heartbeats (HeartbeatService, through script()): `cl:hb` and
 * `cl:hb_flushed` (a field per node, no TTL), `cl:tel:<sid>` (the telemetry
 * document, 10 min TTL), `cl:flusher` and `cl:flush_lock`, which the
 * liveness loop flushes into MySQL.
 *
 * And what a request's authentication reads (NodeAuthCache, through
 * script()): `cl:auth:<uuid>` (the node's row) and `cl:auth:<uuid>:<epoch>`
 * (an epoch's sealed record), 30 s TTL, valid for the node's version in
 * `cl:auth_ver`, which every writer raises; `cl:auth_seq` (no TTL) turns
 * away a fill that read MySQL before a write.
 */
final class ClusterBus {
	/** The read timeout of a bus call (seconds), but for pop()'s blocking wait. */
	private const READ_TIMEOUT = 1.0;

	/** Seconds a wake waits for its reader. */
	private const WAKE_TTL = 60;

	/** Seconds before a failed connect is tried again. */
	private const RETRY_AFTER = 5;

	/** Milliseconds a viewer's last read stays on the bus without a newer touch. */
	public const TOUCH_TTL_MS = 300000;

	/**
	 * The share of the bus's maxmemory past which touches go to MAIN's store
	 * instead. The bus evicts the keys closest to expiry first
	 * (volatile-ttl): touches, which live longest, must never push out the
	 * admission reservations or the wake-ups.
	 */
	public const TOUCH_MEMORY_SHARE = 0.5;

	/**
	 * Per key: set `<t>:<value>` (ARGV[2i], ARGV[2i+1]) unless the key holds a
	 * later t, with a TTL of ARGV[1] ms.
	 */
	private const TOUCH_LUA = <<<'LUA'
		for i = 1, #KEYS do
			local t = tonumber(ARGV[2 * i])
			local cur = redis.call('GET', KEYS[i])
			local at = cur and tonumber(string.match(cur, '^(%-?%d+):'))
			if not at or at <= t then
				redis.call('SET', KEYS[i], ARGV[2 * i] .. ':' .. ARGV[2 * i + 1], 'PX', ARGV[1])
			end
		end
		return 1
		LUA;

	private static ?\Redis $rClient = null;

	private static float $rFailedAt = 0.0;

	private static ?string $rSocket = null;

	/** The bus socket, or null where there is none (an LB build). */
	public static function socket(): ?string {
		if (self::$rSocket !== null) {
			return self::$rSocket;
		}
		return defined('MAIN_HOME') ? MAIN_HOME . 'bin/cluster_bus/cluster.sock' : null;
	}

	/** Tests: another socket (null: the default), and forget the connection. */
	public static function useSocket(?string $rSocket): void {
		self::$rSocket = $rSocket;
		self::$rClient = null;
		self::$rFailedAt = 0.0;
	}

	/** A connected client, or null when the bus is not reachable. */
	public static function client(): ?\Redis {
		if (self::$rClient instanceof \Redis) {
			return self::$rClient;
		}
		$rSocket = self::socket();
		if ($rSocket === null || !class_exists(\Redis::class) || !file_exists($rSocket) || microtime(true) - self::$rFailedAt < self::RETRY_AFTER) {
			return null;
		}
		try {
			$rRedis = new \Redis();
			if (!$rRedis->connect($rSocket, 0, 0.2, null, 0, self::READ_TIMEOUT)) {
				throw new \RuntimeException('connect');
			}
			self::$rClient = $rRedis;
			return $rRedis;
		} catch (\Throwable) {
			self::$rFailedAt = microtime(true);
			return null;
		}
	}

	/** A command was queued for this node: wake its long-poll. */
	public static function wakeNode(int $rServerID): bool {
		return self::push('wake:' . $rServerID);
	}

	/**
	 * Wait until the node is woken, up to $rSeconds. True when woken, false on
	 * timeout, null when the bus is not there (the caller polls instead).
	 */
	public static function waitNode(int $rServerID, float $rSeconds): ?bool {
		return self::pop('wake:' . $rServerID, $rSeconds);
	}

	/**
	 * waitNode(), holding no MySQL connection while blocked: with the bus
	 * there, $rDb is closed first (a DatabaseHandler reconnects on its next
	 * query), so a node's long-poll does not keep a connection for 20 s.
	 * Without the bus nothing is closed: the caller polls every 250 ms and
	 * would reconnect each time.
	 */
	public static function waitNodeReleasing(int $rServerID, float $rSeconds, ?object $rDb): ?bool {
		if (!self::client() instanceof \Redis) {
			return null;
		}
		if ($rDb instanceof DatabaseHandler) {
			$rDb->close_mysql();
		}
		return self::waitNode($rServerID, $rSeconds);
	}

	/** A command was acked: wake whoever awaits its outcome. */
	public static function wakeAck(string $rCmdID): bool {
		return self::push('ack:' . $rCmdID);
	}

	/** Wait for a command's ack, as waitNode(). */
	public static function waitAck(string $rCmdID, float $rSeconds): ?bool {
		return self::pop('ack:' . $rCmdID, $rSeconds);
	}

	/**
	 * Keep a node's touches: when each viewer last asked for its playlist. A
	 * value is replaced only by one whose event time t is not earlier, so a
	 * late or repeated batch never takes a viewer back, and each expires
	 * TOUCH_TTL_MS after its last write. The key names the sending node, so a
	 * node writes only its own viewers' keys; a reader takes the connection's
	 * server_id from MAIN's store.
	 *
	 * @param array<string, array{0: int, 1: int}> $rTouches uuid => [t (ms), hls_last_read]
	 * @return bool False without the bus, or once it holds TOUCH_MEMORY_SHARE
	 *              of its maxmemory: the caller writes MAIN's store.
	 */
	public static function touch(int $rServerID, array $rTouches): bool {
		$rRedis = self::client();
		if ($rRedis === null) {
			return false;
		}
		try {
			$rMemory = $rRedis->info('memory');
			$rMax = is_array($rMemory) ? (int) ($rMemory['maxmemory'] ?? 0) : 0;
			if (is_array($rMemory) && $rMax > 0 && (int) ($rMemory['used_memory'] ?? 0) >= $rMax * self::TOUCH_MEMORY_SHARE) {
				return false;
			}
			foreach (array_chunk($rTouches, 1000, true) as $rChunk) {
				$rKeys = $rArgs = [];
				foreach ($rChunk as $rUUID => [$rT, $rRead]) {
					$rKeys[] = 'touch:' . $rServerID . ':' . $rUUID;
					array_push($rArgs, (string) $rT, (string) $rRead);
				}
				if ($rRedis->eval(self::TOUCH_LUA, [...$rKeys, (string) self::TOUCH_TTL_MS, ...$rArgs], count($rKeys)) === false) {
					throw new \RuntimeException('touch');
				}
			}
			return true;
		} catch (\Throwable) {
			self::drop();
			return false;
		}
	}

	/** Seconds a viewer MAIN closed keeps a node's late upsert of it out (tomb()). */
	public const TOMB_TTL = 3600;

	/**
	 * MAIN closed one of a node's viewers (a kick, a limit, its sweep): an
	 * upsert of that same connection, its `date_start`, already on its way
	 * from the node is not to open it again (ConnectionIngest). Each
	 * connection closed under the uuid is kept (`tombs:<sid>:<uuid>`, a set,
	 * for TOMB_TTL after the last close): a player that came back under the
	 * same uuid and was closed again may still have the first one's upsert on
	 * its way. False without the bus: the node's digest then corrects MAIN's
	 * store, as before.
	 */
	public static function tomb(int $rServerID, string $rUUID, int $rDateStart): bool {
		$rRedis = self::client();
		if ($rRedis === null) {
			return false;
		}
		$rKey = 'tombs:' . $rServerID . ':' . $rUUID;
		try {
			$rOut = $rRedis->multi()->sAdd($rKey, (string) $rDateStart)->expire($rKey, self::TOMB_TTL)->exec();
		} catch (\Throwable) {
			self::drop();
			return false;
		}
		return is_array($rOut) && ($rOut[1] ?? false) === true;
	}

	/** Did MAIN close this connection of a node's (its uuid and `date_start`) in the last TOMB_TTL? False without the bus. */
	public static function tombed(int $rServerID, string $rUUID, int $rDateStart): bool {
		$rRedis = self::client();
		if ($rRedis === null) {
			return false;
		}
		try {
			return (bool) $rRedis->sIsMember('tombs:' . $rServerID . ':' . $rUUID, (string) $rDateStart);
		} catch (\Throwable) {
			self::drop();
			return false;
		}
	}

	/**
	 * The last reads a node's touches left on the bus: uuid => hls_last_read,
	 * for the viewers that have one. Null without the bus.
	 *
	 * @param list<string> $rUUIDs
	 * @return array<string, int>|null
	 */
	public static function lastReads(int $rServerID, array $rUUIDs): ?array {
		$rRedis = self::client();
		if ($rRedis === null) {
			return null;
		}
		if ($rUUIDs === []) {
			return [];
		}
		try {
			$rValues = $rRedis->mGet(array_map(static fn(string $rUUID): string => 'touch:' . $rServerID . ':' . $rUUID, $rUUIDs));
		} catch (\Throwable) {
			self::drop();
			return null;
		}
		$rOut = [];
		foreach (is_array($rValues) ? array_values($rValues) : [] as $i => $rValue) {
			if (is_string($rValue) && preg_match('/^-?\d+:(-?\d+)$/', $rValue, $rM)) {
				$rOut[$rUUIDs[$i]] = (int) $rM[1];
			}
		}
		return $rOut;
	}

	/**
	 * Run a Lua script on the bus (NonceStore, ClusterSemaphore): its reply,
	 * or null without the bus or when the call failed (a lost connection, an
	 * error reply such as OOM), so the caller does what it does without the
	 * bus. A script must never reply nil, which reads as a failure.
	 *
	 * @param list<string> $rKeys
	 * @param list<int|string> $rArgs
	 */
	public static function script(string $rLua, array $rKeys, array $rArgs): mixed {
		$rRedis = self::client();
		if ($rRedis === null) {
			return null;
		}
		try {
			$rOut = $rRedis->eval($rLua, [...$rKeys, ...array_map('strval', $rArgs)], count($rKeys));
		} catch (\Throwable) {
			self::drop();
			return null;
		}
		if ($rOut === false) {
			$rRedis->clearLastError();
			return null;
		}
		return $rOut;
	}

	/**
	 * Record the current second in a mark, a file beside the bus socket
	 * (NonceStore, NodeAuthCache), at most one write a second; false when it
	 * cannot be written, true where there is no bus. A mark never moves back
	 * (a worker that read the clock a second earlier writes late), unless it
	 * is more than a second ahead: the clock stepped back. One that root
	 * creates goes to the owner of the bus's directory, so that the workers
	 * can move it on.
	 */
	public static function mark(string $rName, int $rNowMs): bool {
		$rPath = self::markPath($rName);
		if ($rPath === null) {
			return true;
		}
		$rSec = intdiv($rNowMs, 1000);
		$rAt = self::markedAt($rName);
		if ($rAt !== null && $rAt >= $rSec && $rAt <= $rSec + 1) {
			return true;
		}
		if (!@touch($rPath, $rSec)) {
			return false;
		}
		if ($rAt === null && function_exists('posix_geteuid') && posix_geteuid() === 0) {
			$rOwner = @fileowner(dirname($rPath));
			if ($rOwner !== false && $rOwner !== 0) {
				@chown($rPath, $rOwner);
			}
		}
		return true;
	}

	/** The second a mark holds, or null without one. */
	public static function markedAt(string $rName): ?int {
		$rPath = self::markPath($rName);
		if ($rPath === null) {
			return null;
		}
		clearstatcache(true, $rPath);
		$rAt = @filemtime($rPath);
		return $rAt === false ? null : $rAt;
	}

	private static function markPath(string $rName): ?string {
		$rSocket = self::socket();
		return $rSocket === null ? null : dirname($rSocket) . '/' . $rName;
	}

	/** Is the bus's redis-server running here? */
	public static function running(): bool {
		return ProcessManager::isAnyProcessRunning(['redis-server unixsocket:', 'bin/cluster_bus/cluster.conf']);
	}

	/**
	 * Start the bus when MAIN ships it and it is not running (boot, and the
	 * servers cron as a watchdog). As root, it runs as xc_vm.
	 */
	public static function ensureRunning(): bool {
		if (!defined('MAIN_HOME') || !file_exists(MAIN_HOME . 'bin/cluster_bus/cluster.conf') || !is_executable(MAIN_HOME . 'bin/redis/redis-server')) {
			return false;
		}
		if (self::running()) {
			return true;
		}
		// The server daemonizes (cluster.conf), so this returns at once.
		$rArgv = [MAIN_HOME . 'bin/redis/redis-server', MAIN_HOME . 'bin/cluster_bus/cluster.conf'];
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			$rArgv = array_merge(['sudo', '-u', 'xc_vm'], $rArgv);
		}
		$rNull = ['file', '/dev/null', 'w'];
		$rProc = proc_open($rArgv, [0 => ['file', '/dev/null', 'r'], 1 => $rNull, 2 => $rNull], $rPipes);
		return is_resource($rProc) && proc_close($rProc) === 0;
	}

	private static function push(string $rKey): bool {
		$rRedis = self::client();
		if (!$rRedis instanceof \Redis) {
			return false;
		}
		try {
			$rRedis->multi(\Redis::PIPELINE)->lPush($rKey, '1')->lTrim($rKey, 0, 0)->expire($rKey, self::WAKE_TTL)->exec();
			return true;
		} catch (\Throwable) {
			self::drop();
			return false;
		}
	}

	private static function pop(string $rKey, float $rSeconds): ?bool {
		$rRedis = self::client();
		if (!$rRedis instanceof \Redis) {
			return null;
		}
		$rSeconds = max(0.01, $rSeconds);
		// phpredis takes a fractional block only from 6.0 (the panel ships 6.3);
		// 5.x raises a fatal on one — "Timeout value must be a LONG" — which no
		// catch here can stop, so a distro extension would kill the worker instead
		// of falling back to polling. Whole seconds there, never 0: blPop reads a
		// zero as "block until something arrives".
		$rBlock = version_compare((string) phpversion('redis'), '6.0', '<') ? max(1, (int) ceil($rSeconds)) : $rSeconds;
		try {
			// The read must outlast the block.
			$rRedis->setOption(\Redis::OPT_READ_TIMEOUT, (string) ($rBlock + 1.0));
			$rOut = $rRedis->blPop([$rKey], $rBlock);
			return is_array($rOut) && $rOut !== [];
		} catch (\Throwable) {
			self::drop();
			return null;
		} finally {
			// Back to the connection's own, or every later call on it waits as
			// long as this block did before it gives up on a bus that hangs.
			if (self::$rClient === $rRedis) {
				try {
					$rRedis->setOption(\Redis::OPT_READ_TIMEOUT, (string) self::READ_TIMEOUT);
				} catch (\Throwable) {
					self::drop();
				}
			}
		}
	}

	private static function drop(): void {
		self::$rClient = null;
		self::$rFailedAt = microtime(true);
	}
}
