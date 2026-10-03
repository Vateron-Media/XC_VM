<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\AgentConnections;
use XcVm\Core\Cluster\StoredConnections;
use XcVm\Core\Cluster\StrictQuery;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Database\DatabaseAware;
use XcVm\Infrastructure\Redis\RedisManager;
use XcVm\Streaming\Protection\ConnectionLimiter;

/**
 * Admission when MAIN mints a stream token (plan, Phase 6, "Global
 * max_connections and kills"): the line's limit is applied before the viewer
 * reaches a node, instead of a second after it opened there.
 *
 * It applies to targets whose agent holds the viewers (the node that records
 * the connection, the originator behind a proxy, is active in mode ≥ 1 with
 * CONNECTIONS on) and to limited lines. Thumbnails and subtitles are not
 * admitted; auth.php does not call it for them.
 *
 * 1. The viewer's uuid is reserved for the identity (the line, or an HMAC
 *    identity) for the token's life plus 10 s, and the identity's other
 *    reservations still in flight are counted. Insert-then-count needs no
 *    lock: of two concurrent mints at least one sees the other.
 *    - On the cluster bus when it runs (ClusterBus): a Lua script on
 *      `RESV#<identity>` (score = expiry), in either store mode.
 *    - Without it, Redis mode: the same script on the shared Redis.
 *    - Without it, MySQL mode: `cluster_reservations` (migration 032), keyed
 *      by the uuid.
 * 2. The identity's open connections are cut, in ConnectionLimiter's order
 *    (the requesting device first, oldest first), to leave room for this
 *    viewer and the other reservations. The viewer is never evicted: it is not
 *    open yet. Evictions on CONNECTIONS nodes go out as conn.close / conn.drop
 *    commands, as every close MAIN makes does.
 * 3. The token carries the admission as its `adm` claim {exp, sid}: when the
 *    reservation expires (MAIN's unix seconds) and the node it was made for.
 *    The reservation's id is the token's own uuid. The token is sealed with
 *    MAIN's stream secret, so the node trusts the claim and admits the viewer
 *    without asking MAIN.
 * 4. When the node reports the connection (ConnectionIngest), the reservation
 *    is released. The node's conn.limit still follows, and is the re-check that
 *    settles a race between two nodes.
 *
 * A node whose viewer's token has no claim (minted before this, or while
 * admission could not apply, or expired) asks MAIN with the `conn_admit` op
 * (forNode()): the same reservation for the authenticated node, with the line
 * read on MAIN, and the cut queued for MAIN's 1 s loop, which counts the
 * reservations in flight again when it runs (cut()).
 *
 * Admission never refuses a valid viewer and never fails the request: when the
 * store or the registry cannot be read it does nothing, and conn.limit
 * enforces the limit once the viewer opens. conn_admit refuses only a line
 * auth.php would refuse (unknown, banned, disabled, expired) or an unknown or
 * disabled HMAC key.
 */
final class ConnectionAdmission {
	use DatabaseAware;

	/** Seconds a reservation outlives the token's own life (create_expiration). */
	public const PAD_SEC = 10;

	private const LUA = <<<'LUA'
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', ARGV[1])
redis.call('ZADD', KEYS[1], ARGV[2], ARGV[3])
redis.call('EXPIRE', KEYS[1], ARGV[4])
return redis.call('ZCARD', KEYS[1]) - 1
LUA;

	/** The live members of `RESV#<identity>` (score after now), less ARGV[2]'s own. */
	private const LUA_COUNT = <<<'LUA'
local n = redis.call('ZCOUNT', KEYS[1], '(' .. ARGV[1], '+inf')
local s = redis.call('ZSCORE', KEYS[1], ARGV[2])
if s and tonumber(s) > tonumber(ARGV[1]) then n = n - 1 end
return n
LUA;

	/** @var null|callable(?int, int, ?int, string, ?string, ?string, ?string): mixed */
	private static $rEnforce;

	/** @var null|callable(): int */
	private static $rClock;

	/**
	 * Admit the viewer a token is being minted for. Returns whether admission
	 * applied (the viewer was reserved and the limit applied).
	 *
	 * @param array<string, mixed> $rSettings
	 * @param array<string, mixed> $rTokenData the token auth.php is about to mint
	 */
	public static function admit(array $rSettings, array $rTokenData, ?string $rIP, ?string $rUserAgent): bool {
		return self::claim($rSettings, $rTokenData, $rIP, $rUserAgent) !== null;
	}

	/**
	 * admit(), and the token data to mint: with its `adm` claim when admission
	 * applied (auth.php's viewer mint sites).
	 *
	 * @param array<string, mixed> $rSettings
	 * @param array<string, mixed> $rTokenData
	 * @return array<string, mixed>
	 */
	public static function admitToken(array $rSettings, array $rTokenData, ?string $rIP, ?string $rUserAgent): array {
		$rClaim = self::claim($rSettings, $rTokenData, $rIP, $rUserAgent);
		if ($rClaim !== null) {
			$rTokenData['adm'] = $rClaim;
		}
		return $rTokenData;
	}

	/**
	 * Admit the viewer a token is being minted for; its `adm` claim when
	 * admission applied, else null.
	 *
	 * @param array<string, mixed> $rSettings
	 * @param array<string, mixed> $rTokenData
	 * @return array{exp: int, sid: int}|null
	 */
	public static function claim(array $rSettings, array $rTokenData, ?string $rIP, ?string $rUserAgent): ?array {
		try {
			$rUser = is_array($rTokenData['user_info'] ?? null) ? $rTokenData['user_info'] : [];
			$rMax = (int) ($rUser['max_connections'] ?? 0);
			$rUUID = (string) ($rTokenData['uuid'] ?? '');
			if (empty($rSettings['cluster_api_enabled']) || !preg_match(AgentConnections::CONN_UUID, $rUUID)) {
				return null;
			}
			// An unlimited line reserves nothing, but its player's ended row on
			// another server makes way all the same (makeWay): a live HLS mint only.
			$rHls = ($rTokenData['extension'] ?? '') === 'm3u8' && isset($rTokenData['stream_id']);
			if ($rMax <= 0 && !$rHls) {
				return null;
			}
			$rNode = self::nodeOf($rTokenData);
			if (!self::takesConnections($rNode)) {
				return null;
			}
			$rHMAC = (int) ($rTokenData['hmac_id'] ?? 0);
			$rIdentifier = (string) ($rTokenData['identifier'] ?? '');
			$rLineID = (int) ($rUser['id'] ?? 0);
			if ($rHMAC === 0 && $rLineID === 0) {
				return null;
			}
			$rIdentity = StoredConnections::identity(['user_id' => $rLineID, 'hmac_id' => $rHMAC, 'hmac_identifier' => $rIdentifier]);
			$rTtl = self::ttl($rSettings);
			$rStreamID = (int) ($rTokenData['stream_id'] ?? $rTokenData['stream'] ?? 0);
			if ($rMax <= 0) {
				self::makeWay($rSettings, $rNode, $rHMAC !== 0 ? $rHMAC : null, $rIdentifier, $rLineID, $rStreamID, (string) $rIP, (string) $rUserAgent);
				return null;
			}
			$rOthers = self::reserve(!empty($rSettings['redis_handler']), $rIdentity, $rUUID, $rTtl, $rNode, $rStreamID);
			if ($rOthers === null) {
				return null;
			}
			// Room left for open connections: the limit, less this viewer and
			// the others still on their way to a node.
			$rRoom = max(0, $rMax - $rOthers - 1);
			self::enforce($rHMAC !== 0 ? null : $rLineID, (int) ($rUser['pair_id'] ?? 0), $rRoom, $rHMAC !== 0 ? $rHMAC : null, $rIdentifier, $rIP, $rUserAgent, $rUUID);
			if ($rHls) {
				self::makeWay($rSettings, $rNode, $rHMAC !== 0 ? $rHMAC : null, $rIdentifier, $rLineID, $rStreamID, (string) $rIP, (string) $rUserAgent);
			}
			return ['exp' => self::now() + $rTtl, 'sid' => $rNode];
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * The player a live HLS mint sends to $rNode may have a session on another
	 * server that has ended and still has its record in MAIN's store: its own
	 * end, or the cut just above. The node's record carries the same uuid
	 * (ConnectionTracker::hlsConnectionKey names the player, not the server)
	 * and MAIN's ingest refuses a uuid another server holds, so the viewer
	 * would go uncounted until that record is swept. MAIN closes it here, at
	 * its own mint, as its sweep would (ConnectionIngest::retireEnded). A node
	 * that still reaches the database does this itself in the table store
	 * (ConnectionTracker::createLive); one in mode 2 cannot, and in Redis no
	 * node does.
	 *
	 * An open record never makes way: it may be a second device with the same
	 * address and player on the same line. Never fails the mint.
	 *
	 * @param array<string, mixed> $rSettings
	 */
	private static function makeWay(array $rSettings, int $rNode, ?int $rHMAC, string $rIdentifier, int $rLineID, int $rStreamID, string $rIP, string $rUserAgent): void {
		if ($rStreamID <= 0) {
			return;
		}
		try {
			$rKey = ConnectionTracker::hlsConnectionKey($rHMAC, $rIdentifier, $rLineID, $rStreamID, $rIP, $rUserAgent);
			$rNow = self::now();
			if (!empty($rSettings['redis_handler'])) {
				$rRedis = RedisManager::instance();
				if ($rRedis instanceof \Redis) {
					ConnectionIngest::retireEndedRecord($rRedis, $rKey, $rNode, $rNow);
				}
				return;
			}
			$rDb = self::db();
			if (!$rDb->query('SELECT * FROM `lines_live` WHERE `uuid` = ? AND `hls_end` = 1 AND `container` = ? AND `server_id` <> ?;', $rKey, 'hls', $rNode)) {
				return;
			}
			foreach ($rDb->get_rows() as $rRow) {
				$rLast = (int) ($rRow['hls_last_read'] ?? 0);
				ConnectionIngest::retireEnded($rRow, $rLast > 0 && $rLast < $rNow ? $rLast : $rNow);
			}
		} catch (\Throwable) {
			// The record stays for the sweep, as before.
		}
	}

	/**
	 * `conn_admit`: admission for a viewer the node is about to record whose
	 * token carries no `adm` claim (or an expired one). The node is the
	 * authenticated one, and MAIN never takes a limit from it: the line is read
	 * here. The answer:
	 *
	 * - `{admit: true, exp}`: reserved for the node until exp (MAIN's unix
	 *   seconds), as at mint. The cut that leaves room for the viewer is queued
	 *   for MAIN's 1 s loop (ConnectionLimits), which has the legacy globals
	 *   ConnectionLimiter needs and this endpoint does not.
	 * - `{admit: false, exp: 0, reason}`: a line auth.php would refuse
	 *   (UNKNOWN_LINE, EXPIRED, BANNED, DISABLED, in auth.php's order), or an
	 *   HMAC key that is unknown or disabled (UNKNOWN_HMAC).
	 *
	 * A limited line is reserved; an unlimited one needs nothing. An HMAC
	 * identity is reserved but not cut: its limit is signed into the client's
	 * request and stored nowhere, so the node's conn.limit, which carries it,
	 * enforces it. A repeated uuid refreshes its own reservation, and the cut
	 * never evicts the viewer asking.
	 *
	 * The other identity's id may be absent, null or 0 (a struct without
	 * omitempty); an identifier is cut to 255 bytes, as conn.limit's queue
	 * cuts it.
	 *
	 * @param array<string, mixed> $rSettings
	 * @param array<string, mixed> $rRequest {uuid, line_id | hmac_id + identifier, stream_id, ip, ua}
	 * @return array{admit: bool, exp: int, reason?: string}|null null for a malformed request
	 */
	public static function forNode(array $rSettings, int $rServerID, array $rRequest): ?array {
		$rUUID = $rRequest['uuid'] ?? null;
		$rLineID = ($rRequest['line_id'] ?? null) === 0 ? null : ($rRequest['line_id'] ?? null);
		$rHMAC = ($rRequest['hmac_id'] ?? null) === 0 ? null : ($rRequest['hmac_id'] ?? null);
		$rIdentifier = $rRequest['identifier'] ?? null;
		$rStreamID = $rRequest['stream_id'] ?? 0;
		$rIP = $rRequest['ip'] ?? '';
		$rUserAgent = $rRequest['ua'] ?? '';
		$rIsLine = is_int($rLineID) && $rLineID > 0 && $rHMAC === null;
		$rIsHMAC = is_int($rHMAC) && $rHMAC > 0 && is_string($rIdentifier) && $rLineID === null;
		$rValid = is_string($rUUID) && preg_match(AgentConnections::CONN_UUID, $rUUID) && is_int($rStreamID) && $rStreamID >= 0 && is_string($rIP) && is_string($rUserAgent);
		if (!$rValid || $rIsLine === $rIsHMAC) {
			return null;
		}
		$rIdentifier = substr((string) $rIdentifier, 0, 255);
		$rIP = substr($rIP, 0, 64);
		$rUserAgent = substr($rUserAgent, 0, 512);
		$rIdentity = StoredConnections::identity(['user_id' => $rLineID, 'hmac_id' => $rHMAC, 'hmac_identifier' => $rIdentifier]);
		$rDb = self::db();
		if ($rIsLine) {
			StrictQuery::orThrow($rDb, 'db', 'SELECT `max_connections`, `enabled`, `admin_enabled`, `exp_date` FROM `lines` WHERE `id` = ?;', $rLineID);
			$rLine = $rDb->num_rows() === 1 ? $rDb->get_row() : null;
			$rReason = match (true) {
				$rLine === null => 'UNKNOWN_LINE',
				$rLine['exp_date'] !== null && (int) $rLine['exp_date'] <= self::now() => 'EXPIRED',
				(int) $rLine['admin_enabled'] === 0 => 'BANNED',
				(int) $rLine['enabled'] === 0 => 'DISABLED',
				default => null,
			};
			if ($rReason !== null) {
				return ['admit' => false, 'exp' => 0, 'reason' => $rReason];
			}
			$rMax = (int) $rLine['max_connections'];
		} else {
			StrictQuery::orThrow($rDb, 'db', 'SELECT `id` FROM `hmac_keys` WHERE `id` = ? AND `enabled` = 1;', $rHMAC);
			if ($rDb->num_rows() !== 1) {
				return ['admit' => false, 'exp' => 0, 'reason' => 'UNKNOWN_HMAC'];
			}
			$rMax = null;
		}
		$rTtl = self::ttl($rSettings);
		if ($rMax === null || $rMax > 0) {
			try {
				$rReserved = self::reserve(!empty($rSettings['redis_handler']), $rIdentity, $rUUID, $rTtl, $rServerID, $rStreamID) !== null;
			} catch (\Throwable) {
				$rReserved = false; // a store that cannot be reached refuses no one: conn.limit follows the open
			}
			// The cut counts the reservations in flight when it runs (cut()).
			if ($rReserved && $rIsLine) {
				ConnectionLimits::queueAdmission($rServerID, $rUUID, (int) $rLineID, $rIP, $rUserAgent);
			}
		}
		return ['admit' => true, 'exp' => self::now() + $rTtl];
	}

	/**
	 * A conn_admit's cut, run by MAIN's loop (ConnectionLimits::drain): the
	 * line's open connections, and its pair's, down to what leaves room for
	 * the viewer and the line's other reservations still in flight. Both the
	 * limit and the reservations are read now, not at admission: a
	 * reservation counted then may have opened since (ingest released it),
	 * and would count twice, open and in flight. A viewer that opened
	 * meanwhile is already counted among the open ones, so it takes no room
	 * of its own; it is never cut. A store that cannot be read cuts nothing,
	 * as at mint: conn.limit follows the open.
	 */
	public static function cut(bool $rRedisMode, int $rLineID, bool $rOpen, string $rIP, string $rUserAgent, string $rUUID): bool {
		$rDb = self::db();
		$rDb->query('SELECT `max_connections`, `pair_id` FROM `lines` WHERE `id` = ?;', $rLineID);
		if ($rDb->num_rows() !== 1) {
			return false;
		}
		$rLine = $rDb->get_row();
		$rMax = (int) $rLine['max_connections'];
		if ($rMax <= 0) {
			return true;
		}
		try {
			$rOthers = self::inFlight($rRedisMode, (string) $rLineID, $rUUID);
		} catch (\Throwable) {
			$rOthers = null;
		}
		if ($rOthers === null) {
			return false;
		}
		$rRoom = max(0, $rMax - $rOthers - ($rOpen ? 0 : 1));
		self::enforce($rLineID, (int) ($rLine['pair_id'] ?? 0), $rRoom, null, '', $rIP, $rUserAgent, $rUUID);
		return true;
	}

	/** The node that records the viewer: the originator behind a proxy, else the redirect target. */
	public static function nodeOf(array $rTokenData): int {
		$rTarget = is_array($rTokenData['channel_info'] ?? null) ? $rTokenData['channel_info'] : $rTokenData;
		return (int) (($rTarget['originator_id'] ?? null) ?: ($rTarget['redirect_id'] ?? 0));
	}

	/** Does this node's agent hold its viewers (active, mode ≥ 1, CONNECTIONS on)? */
	public static function takesConnections(int $rServerID): bool {
		if ($rServerID <= 0) {
			return false;
		}
		$rNode = NodeRegistry::byServer($rServerID);
		return $rNode !== null && $rNode['state'] === 'active' && (int) $rNode['mode'] >= 1
			&& ((int) $rNode['flows'] & NodeRegistry::FLOW_CONNECTIONS) !== 0;
	}

	/**
	 * Reserve the uuid for the identity and count the identity's other live
	 * reservations; null when the store cannot be reached.
	 */
	public static function reserve(bool $rRedisMode, string $rIdentity, string $rUUID, int $rTtl, int $rServerID = 0, int $rStreamID = 0): ?int {
		$rNow = self::now();
		return self::counted($rRedisMode, self::LUA, ['RESV#' . $rIdentity, $rNow, $rNow + $rTtl, $rUUID, $rTtl], $rIdentity, $rUUID, $rNow, static function () use ($rIdentity, $rUUID, $rNow, $rTtl, $rServerID, $rStreamID): bool {
			if (strlen($rUUID) > 32) {
				return false; // the table's id is char(32), the size auth.php mints
			}
			$db = self::db();
			$db->query('DELETE FROM `cluster_reservations` WHERE `exp` < ?;', $rNow);
			$db->query('REPLACE INTO `cluster_reservations` (`id`, `identity`, `server_id`, `stream_id`, `created_at`, `exp`) VALUES (?, ?, ?, ?, ?, ?);', $rUUID, $rIdentity, $rServerID, $rStreamID ?: null, $rNow, $rNow + $rTtl);
			return true;
		});
	}

	/**
	 * The identity's live reservations other than $rExceptUUID's, counted now,
	 * in the store reserve() writes to; null when it cannot be read.
	 */
	public static function inFlight(bool $rRedisMode, string $rIdentity, string $rExceptUUID): ?int {
		$rNow = self::now();
		return self::counted($rRedisMode, self::LUA_COUNT, ['RESV#' . $rIdentity, $rNow, $rExceptUUID], $rIdentity, $rExceptUUID, $rNow);
	}

	/**
	 * reserve()'s and inFlight()'s store walk: $rLua (keys: `RESV#<identity>`,
	 * then $rArgs) on the cluster bus when MAIN runs it, falling through to
	 * the store when it fails; else on the shared Redis in Redis mode; else
	 * `cluster_reservations`, where $rWrite first writes the reservation (or
	 * answers false, for null) and the identity's live reservations other
	 * than $rUUID's are counted. Null when the store cannot be read.
	 *
	 * @param list<int|string> $rArgs KEYS[1], then ARGV
	 * @param (\Closure(): bool)|null $rWrite
	 */
	private static function counted(bool $rRedisMode, string $rLua, array $rArgs, string $rIdentity, string $rUUID, int $rNow, ?\Closure $rWrite = null): ?int {
		// The cluster bus when MAIN runs it (plan: reservations live there),
		// whatever the store mode; else the shared Redis or the table.
		$rBus = ClusterBus::client();
		if ($rBus instanceof \Redis) {
			try {
				$rCount = $rBus->eval($rLua, $rArgs, 1);
				if (is_int($rCount)) {
					return max(0, $rCount);
				}
			} catch (\Throwable) {
				// Fall through to the store.
			}
		}
		if ($rRedisMode) {
			$rRedis = RedisManager::instance();
			if (!$rRedis instanceof \Redis) {
				return null;
			}
			$rCount = $rRedis->eval($rLua, $rArgs, 1);
			return is_int($rCount) ? max(0, $rCount) : null;
		}
		if ($rWrite !== null && !$rWrite()) {
			return null;
		}
		$db = self::db();
		if (!$db->query('SELECT COUNT(*) AS `n` FROM `cluster_reservations` WHERE `identity` = ? AND `id` <> ? AND `exp` >= ?;', $rIdentity, $rUUID, $rNow)) {
			return null;
		}
		return (int) ($db->get_row()['n'] ?? 0);
	}

	/** The node reported the connection: it is open now, no longer reserved. */
	public static function release(bool $rRedisMode, string $rIdentity, string $rUUID): void {
		try {
			ClusterBus::client()?->zRem('RESV#' . $rIdentity, $rUUID);
			if ($rRedisMode) {
				$rRedis = RedisManager::instance();
				if ($rRedis instanceof \Redis) {
					$rRedis->zRem('RESV#' . $rIdentity, $rUUID);
				}
				return;
			}
			self::db()->query('DELETE FROM `cluster_reservations` WHERE `id` = ?;', $rUUID);
		} catch (\Throwable) {
			// It expires on its own.
		}
	}

	/**
	 * Tests: the enforcer (ConnectionLimiter::closeConnections's arguments)
	 * and the clock.
	 */
	public static function useEnforcer(?callable $rEnforce, ?callable $rClock = null): void {
		self::$rEnforce = $rEnforce;
		self::$rClock = $rClock;
	}

	/** A reservation's life: the token's (create_expiration, 5 s by default) plus PAD_SEC. */
	private static function ttl(array $rSettings): int {
		return max(1, (int) ($rSettings['create_expiration'] ?? 0) ?: 5) + self::PAD_SEC;
	}

	/**
	 * Cut an identity's open connections to $rRoom: a line's pair first, then
	 * the line; or an HMAC identity. The viewer $rUUID is never cut.
	 */
	private static function enforce(?int $rLineID, int $rPairID, int $rRoom, ?int $rHMAC, string $rIdentifier, ?string $rIP, ?string $rUserAgent, string $rUUID): void {
		if ($rHMAC !== null) {
			self::limit(null, $rRoom, $rHMAC, $rIdentifier, $rIP, $rUserAgent, $rUUID);
			return;
		}
		if ($rPairID !== 0) {
			self::limit($rPairID, $rRoom, null, '', $rIP, $rUserAgent, $rUUID);
		}
		self::limit($rLineID, $rRoom, null, '', $rIP, $rUserAgent, $rUUID);
	}

	/**
	 * ConnectionLimiter::closeConnections (or the tests' enforcer), with the
	 * viewer's IP as REMOTE_ADDR, which the limiter reads to prefer the
	 * requesting device: in MAIN's loop there is none.
	 */
	private static function limit(?int $rLineID, int $rRoom, ?int $rHMAC, string $rIdentifier, ?string $rIP, ?string $rUserAgent, string $rUUID): void {
		if (self::$rEnforce !== null) {
			(self::$rEnforce)($rLineID, $rRoom, $rHMAC, $rIdentifier, $rIP, $rUserAgent, $rUUID);
			return;
		}
		$rWas = $_SERVER['REMOTE_ADDR'] ?? null;
		if ($rIP !== null && $rIP !== '') {
			$_SERVER['REMOTE_ADDR'] = $rIP;
		}
		try {
			ConnectionLimiter::closeConnections($rLineID, $rRoom, $rHMAC, $rIdentifier, $rIP, $rUserAgent, $rUUID);
		} finally {
			if ($rWas === null) {
				unset($_SERVER['REMOTE_ADDR']);
			} else {
				$_SERVER['REMOTE_ADDR'] = $rWas;
			}
		}
	}

	private static function now(): int {
		return self::$rClock !== null ? (int) (self::$rClock)() : ClusterClock::now();
	}
}
