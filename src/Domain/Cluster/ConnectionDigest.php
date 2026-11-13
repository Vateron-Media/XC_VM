<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\StoredConnections;

/**
 * A node's open connections in one short value (cluster plan, Phase 6,
 * "Connection digest"): the agent sends its registry's in every heartbeat, and
 * MAIN compares it with its own store for that node. A drift that outlives
 * the events in flight (two checks in a row) makes MAIN ask the node for a
 * `conn_snapshot` (ConnectionSnapshot).
 *
 * ```text
 * {count, users, xor64}
 * count   open connections (hls_end not set)
 * users   distinct owners: "u:<user_id>", or "h:<hmac_id>:<hmac_identifier>"
 * xor64   XOR of the first 8 bytes of SHA-256(uuid "\n" owner), 16 hex digits
 * ```
 *
 * The Go agent computes the same value (clusteragent.Digest); both sides pin
 * one vector in their tests.
 */
final class ConnectionDigest {
	/** Least time between two checks of one node (ms). */
	public const EVERY_MS = 4000;

	/** Checks in a row that must disagree before MAIN asks for a snapshot. */
	public const MISSES = 2;

	/** Least time between two snapshot requests to one node (ms). */
	public const COOLDOWN_MS = 30000;

	/** A quiet outbox can still have events being applied on MAIN. */
	public const SETTLE_MS = 12000;

	/** Bound retries when a full snapshot leaves the same divergence. */
	public const MAX_RETRY_MS = 300000;

	/** What of() reads of a record: the uuid, its owner and whether it ended. */
	private const KEYS = ['uuid', 'user_id', 'hmac_id', 'hmac_identifier', 'hls_end'];

	private static ?string $rDir = null;

	private static ?int $rEvery = null;

	/** Tests: another state directory and check interval; null restores the defaults. */
	public static function useState(?string $rDir, ?int $rEveryMs = null): void {
		self::$rDir = $rDir;
		self::$rEvery = $rEveryMs;
	}

	/**
	 * @param iterable<array<string, mixed>> $rRecords
	 * @return array{count: int, users: int, xor64: string}
	 */
	public static function of(iterable $rRecords): array {
		$rCount = 0;
		$rUsers = [];
		$rXor = str_repeat("\0", 8);
		foreach ($rRecords as $rRecord) {
			if (!is_array($rRecord) || !empty($rRecord['hls_end']) || !isset($rRecord['uuid'])) {
				continue;
			}
			$rOwner = self::owner($rRecord);
			$rCount++;
			$rUsers[$rOwner] = true;
			$rXor ^= substr(hash('sha256', $rRecord['uuid'] . "\n" . $rOwner, true), 0, 8);
		}
		return ['count' => $rCount, 'users' => count($rUsers), 'xor64' => bin2hex($rXor)];
	}

	/** @param array<string, mixed> $rRecord */
	public static function owner(array $rRecord): string {
		if (!empty($rRecord['user_id'])) {
			return 'u:' . intval($rRecord['user_id']);
		}
		return 'h:' . intval($rRecord['hmac_id'] ?? 0) . ':' . strval($rRecord['hmac_identifier'] ?? '');
	}

	/**
	 * The node's open connections in MAIN's store, by uuid, with what the
	 * digest reads of them (KEYS).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function stored(int $rServerID): array {
		$rOut = [];
		foreach (StoredConnections::ofServer($rServerID, true, self::KEYS) as $rRecord) {
			$rOut[(string) $rRecord['uuid']] = $rRecord;
		}
		return $rOut;
	}

	/**
	 * A heartbeat's digest against MAIN's store: whether MAIN wants the node's
	 * snapshot now. Checked at most every EVERY_MS per node; a disagreement
	 * must hold for MISSES checks in a row (the events in flight catch up in
	 * well under that), and a node is asked at most once per COOLDOWN_MS.
	 */
	public static function check(int $rServerID, mixed $rDigest, ?array $rP0 = null): bool {
		if (!is_array($rDigest) || !is_int($rDigest['count'] ?? null) || !is_int($rDigest['users'] ?? null) || !is_string($rDigest['xor64'] ?? null) || !preg_match('/^[a-f0-9]{16}$/iD', $rDigest['xor64'])) {
			return false;
		}
		$rNow = ClusterClock::nowMs();
		$rFile = self::dir() . $rServerID . '.json';
		$rState = (self::state($rServerID) ?? []) + ['at' => 0, 'miss' => 0, 'asked' => 0];
		if ($rNow - (int) $rState['at'] < (self::$rEvery ?? self::EVERY_MS)) {
			return false;
		}
		$rOurs = self::of(self::stored($rServerID));
		$rSame = $rOurs['count'] === $rDigest['count'] && $rOurs['users'] === $rDigest['users'] && hash_equals($rOurs['xor64'], strtolower($rDigest['xor64']));
		$rState['at'] = $rNow;
		$rState['reported'] = array_intersect_key($rDigest, array_flip(['count', 'users', 'xor64']));
		$rState['stored'] = $rOurs;
		// A registry change is already in P0 but not yet in MAIN's store.
		// Counting that as drift makes busy nodes request full snapshots
		// continuously, competing with the events that would settle it.
		$rPending = is_int($rP0['files'] ?? null) && $rP0['files'] > 0;
		$rState['pending_p0'] = $rPending;
		$rGap = self::gap($rOurs, $rDigest);
		if (($rState['drift_key'] ?? null) !== $rGap) {
			$rState['miss'] = 0;
			$rState['drift_since'] = $rNow;
		}
		$rState['drift_key'] = $rGap;
		$rState['drift_since'] = $rSame ? 0 : ((int) ($rState['drift_since'] ?? 0) ?: $rNow);
		$rSettling = $rPending && $rNow - $rState['drift_since'] < self::COOLDOWN_MS;
		$rState['miss'] = $rSame || $rSettling ? 0 : (int) $rState['miss'] + 1;
		$rFeedbackFile = self::dir() . $rServerID . '.snapshot.json';
		$rFeedback = json_decode((string) @file_get_contents($rFeedbackFile), true);
		if ($rSame) {
			@unlink($rFeedbackFile);
		}
		$rRetry = !$rSame && ($rFeedback['gap'] ?? null) === $rGap ? max(self::COOLDOWN_MS, min(self::MAX_RETRY_MS, (int) ($rFeedback['retry_ms'] ?? 0))) : self::COOLDOWN_MS;
		$rState['retry_ms'] = $rRetry;
		$rLastAttempt = max((int) $rState['asked'], !$rSame && ($rFeedback['gap'] ?? null) === $rGap ? (int) ($rFeedback['at'] ?? 0) : 0);
		$rWant = $rState['miss'] >= self::MISSES && $rNow - $rState['drift_since'] >= self::SETTLE_MS && $rNow - $rLastAttempt >= $rRetry;
		if ($rWant) {
			$rState['miss'] = 0;
			$rState['asked'] = $rNow;
		}
		if (!is_dir(self::dir())) {
			@mkdir(self::dir(), 0750, true);
		}
		@file_put_contents($rFile, json_encode($rState), LOCK_EX);
		return $rWant;
	}

	/**
	 * A successful full snapshot can intentionally leave closed lifecycles out
	 * of MAIN's store. Repeating that same snapshot cannot repair them. Keep
	 * its feedback apart from heartbeat state, so concurrent writes do not
	 * overwrite a newer heartbeat. A changed gap uses the normal retry period.
	 *
	 * @param list<mixed> $rRecords
	 */
	public static function snapshotApplied(int $rServerID, array $rRecords): void {
		try {
			$rOurs = self::of(self::stored($rServerID));
		} catch (\Throwable) {
			return; // Retry feedback must not fail a completed snapshot.
		}
		$rReported = self::of(self::scalarRecords($rRecords));
		$rFile = self::dir() . $rServerID . '.snapshot.json';
		if ($rOurs === $rReported) {
			@unlink($rFile);
			return;
		}
		$rGap = self::gap($rOurs, $rReported);
		$rPrevious = json_decode((string) @file_get_contents($rFile), true);
		$rRetry = ($rPrevious['gap'] ?? null) === $rGap ? (int) ($rPrevious['retry_ms'] ?? self::COOLDOWN_MS) : self::COOLDOWN_MS;
		if (!is_dir(self::dir())) {
			@mkdir(self::dir(), 0750, true);
		}
		@file_put_contents($rFile, json_encode(['at' => ClusterClock::nowMs(), 'gap' => $rGap, 'retry_ms' => min(self::MAX_RETRY_MS, max(self::COOLDOWN_MS, $rRetry) * 2)]), LOCK_EX);
	}

	/**
	 * Normalize snapshot fields as ingest does without duplicating a full
	 * 50,000-record snapshot in memory for optional retry feedback.
	 *
	 * @param list<mixed> $rRecords
	 * @return \Generator<int, array<string, mixed>>
	 */
	private static function scalarRecords(array $rRecords): \Generator {
		foreach ($rRecords as $rRecord) {
			if (is_array($rRecord)) {
				yield array_filter($rRecord, static fn($rValue): bool => is_scalar($rValue) || $rValue === null);
			}
		}
	}

	/**
	 * @param array{count: int, users: int, xor64: string} $rOurs
	 * @param array{count: int, users: int, xor64: string} $rReported
	 */
	private static function gap(array $rOurs, array $rReported): string {
		return ($rReported['count'] - $rOurs['count']) . ':' . ($rReported['users'] - $rOurs['users']) . ':' . bin2hex((hex2bin($rOurs['xor64']) ?: '') ^ (hex2bin($rReported['xor64']) ?: ''));
	}

	/**
	 * The node's last check, as check() keeps it: `at` (when), `miss` (checks
	 * in a row that disagreed), `asked` (the last snapshot request), in ms on
	 * MAIN's clock. Null before its first check.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function state(int $rServerID): ?array {
		$rState = json_decode((string) @file_get_contents(self::dir() . $rServerID . '.json'), true);
		return is_array($rState) ? $rState : null;
	}

	private static function dir(): string {
		return self::$rDir ?? ((defined('TMP_PATH') ? TMP_PATH : sys_get_temp_dir() . '/') . 'cluster_digest/');
	}
}
