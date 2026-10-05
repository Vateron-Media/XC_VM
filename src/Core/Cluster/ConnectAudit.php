<?php

namespace XcVm\Core\Cluster;

/**
 * Connect Audit
 *
 * Every connection this node opens to MAIN's MySQL or Redis passes guard()
 * first (Database::db_connect, RedisManager::connect; the plan's section 10,
 * step 1). On a node in mode 1 or 2 it is counted, with its site, and in
 * mode 2 it is refused (LbDatabaseAccessException) before anything opens.
 * The per-site counts show which code paths still connect. A node in mode 1
 * connects by design, so the counts say little there; when it enters mode 2
 * the window starts again (follow()), and what it then shows is what mode 2
 * turned away. MAIN and mode 0 nodes count nothing and refuse nothing
 * (NodeRole).
 *
 * ```text
 * STORAGE_PATH/cluster/sql_audit/   (the plan's var/cluster/sql_audit/)
 *   YYYYMMDD.json    the UTC day's counts, exact: {"sql": n, "redis": n,
 *                    "sites": {"sql path:line": n}}, at most MAX_SITES sites,
 *                    the rest under OTHER
 *   YYYYMMDD.ndjson  one line per connect: {"t": unix time, "k": "sql"|"redis",
 *                    "s": "path:line", "p": pid, "r": 1 when refused}; at
 *                    most LOG_MAX_BYTES a day, then connects are only counted
 *   since            when this node's audit began (unix time)
 * ```
 *
 * One file of each a day, eight days kept (cron:cleanup), so the whole
 * directory stays under 9 MiB. STORAGE_PATH survives reboots, unlike tmp/,
 * which the seven days need. The last seven days go into the audit.json the
 * agent sends as the heartbeat's `audit` (SettingsAudit::publish, report()),
 * rewritten when a connect adds a site to its day or the file is a minute
 * old, and by cron:cleanup every hour. A root process counts as the owner of
 * the agent's directory (xc_vm), as SettingsAudit does
 * (SettingsAudit::asAgentUser), and a day file is read under its lock.
 * Nothing here throws but the refusal.
 *
 * Lives in Core: it ships to LBs, where Domain\Cluster does not.
 *
 * @package XC_VM_Core_Cluster
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

final class ConnectAudit {
	use OptionalDirSeam;

	public const SQL = 'sql';
	public const REDIS = 'redis';

	/** Sites a day file, and the report, name at most; the rest count under OTHER. */
	public const MAX_SITES = 32;

	/** A site past MAX_SITES. */
	public const OTHER = AuditDays::OTHER;

	/** Longest site key ("kind path:line") in bytes; a longer one keeps its end. */
	public const MAX_SITE_LEN = 160;

	/** Days the report covers, today included. */
	public const WINDOW_DAYS = 7;

	/** Bytes a day's log holds at most. */
	public const LOG_MAX_BYTES = 1048576;

	/** Seconds after which a connect rewrites audit.json without a new site. */
	public const PUBLISH_EVERY = 60;

	/** A day's files: its counts and its log. */
	private const DAY_FILES = ['json', 'ndjson'];

	/** Frames that are the connect machinery itself, not the caller. */
	private const INTERNAL = [
		'XcVm\\Core\\Database\\Database',
		'XcVm\\Core\\Database\\DatabaseHandler',
		'XcVm\\Core\\Database\\LazyDatabaseHandler',
		'XcVm\\Infrastructure\\Database\\DatabaseFactory',
		'XcVm\\Infrastructure\\Redis\\RedisManager',
		'XcVm\\Core\\Cache\\RedisCache',
		self::class,
	];

	/** Tests: a fixed clock. */
	private static ?int $rNow = null;

	/** Tests: a fixed time for the connects recorded; null restores the clock. */
	public static function useClock(?int $rNow): void {
		self::$rNow = $rNow;
	}

	/** STORAGE_PATH's (useDir(): tests' own, false for none, the suite's default). */
	private static function defaultDir(): ?string {
		return defined('STORAGE_PATH') ? STORAGE_PATH . 'cluster/sql_audit/' : null;
	}

	/**
	 * A connect of $rKind to MAIN is about to be opened: count it where
	 * NodeRole says so, and refuse it on a node in mode 2. Costs a read of
	 * the agent's flows.json when neither applies.
	 *
	 * @throws LbDatabaseAccessException on a node in mode 2 (api)
	 */
	public static function guard(string $rKind): void {
		$rRefused = NodeRole::refusesConnects();
		if (!$rRefused && !NodeRole::auditConnects()) {
			return;
		}
		$rSite = self::site(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16));
		self::record($rKind, $rSite, $rRefused);
		if ($rRefused) {
			throw new LbDatabaseAccessException($rKind, $rSite);
		}
	}

	/**
	 * Count one connect of $rKind at $rSite in today's file and log it.
	 * Never throws: an audit must not break the connection it audits.
	 */
	public static function record(string $rKind, string $rSite, bool $rRefused = false): void {
		$rDir = self::dir();
		if ($rDir === null || !in_array($rKind, [self::SQL, self::REDIS], true)) {
			return;
		}
		$rNow = self::$rNow ?? time();
		try {
			// Root counts as xc_vm: these directories are xc_vm's to write.
			SettingsAudit::asAgentUser(static function () use ($rDir, $rKind, $rSite, $rRefused, $rNow): bool {
				if (!AuditDays::makeDir($rDir)) {
					return false;
				}
				self::follow($rDir);
				$rDay = $rDir . gmdate('Ymd', $rNow);
				$rKey = self::siteKey($rKind, $rSite);
				$rNew = self::count($rDay . '.json', $rKind, $rKey);
				self::log($rDay . '.ndjson', ['t' => $rNow, 'k' => $rKind, 's' => substr($rKey, strlen($rKind) + 1), 'p' => getmypid()] + ($rRefused ? ['r' => 1] : []));
				SettingsAudit::publishIfDue($rNew, $rNow, self::PUBLISH_EVERY);
				return true;
			});
		} catch (\Throwable) {
			// Counted, not needed: the connect goes on.
		}
	}

	/**
	 * The first frame outside the connect machinery, as "path:line" relative
	 * to MAIN_HOME.
	 *
	 * @param list<array<string, mixed>> $rTrace debug_backtrace() output.
	 */
	public static function site(array $rTrace): string {
		// Frame i's file:line is code inside frame i+1's function.
		foreach ($rTrace as $i => $rFrame) {
			if (!isset($rFrame['file']) || in_array($rTrace[$i + 1]['class'] ?? null, self::INTERNAL, true)) {
				continue;
			}
			$rFile = (string) $rFrame['file'];
			if (defined('MAIN_HOME') && str_starts_with($rFile, MAIN_HOME)) {
				$rFile = substr($rFile, strlen(MAIN_HOME));
			}
			return $rFile . ':' . ($rFrame['line'] ?? 0);
		}
		return 'unknown';
	}

	/**
	 * A site as the counts and the report name it: "kind path:line", in
	 * printable ASCII, at most MAX_SITE_LEN bytes (a longer path keeps its
	 * end, after "...").
	 */
	public static function siteKey(string $rKind, string $rSite): string {
		$rSite = (string) preg_replace('/[^\x20-\x7e]/', '?', $rSite);
		$rRoom = self::MAX_SITE_LEN - strlen($rKind) - 1;
		if (strlen($rSite) > $rRoom) {
			$rSite = '...' . substr($rSite, 3 - $rRoom);
		}
		return $rKind . ' ' . ($rSite === '' ? 'unknown' : $rSite);
	}

	/** Is $rKey a site key siteKey() makes (OTHER is not)? */
	public static function isSite(string $rKey): bool {
		return strlen($rKey) <= self::MAX_SITE_LEN && preg_match('/^(?:sql|redis) [\x20-\x7e]+\z/', $rKey) === 1;
	}

	/**
	 * Connect counts over the last $rDays UTC days, today included, the
	 * sites most connected first (then by name), at most MAX_SITES and OTHER.
	 *
	 * @return array{sql: int, redis: int, sites: array<string, int>}
	 */
	public static function summary(int $rDays = self::WINDOW_DAYS, ?int $rNow = null): array {
		return self::days($rDays, $rNow ?? self::$rNow ?? time()) ?? ['sql' => 0, 'redis' => 0, 'sites' => []];
	}

	/**
	 * This node's connects for audit.json: `sql_connects`, `redis_connects`
	 * and `sites` over the last WINDOW_DAYS days, and `connects_since`, when
	 * the audit began. The counts start at its UTC day: an earlier day in the
	 * window is left out. [] when there is no directory (the members are left
	 * out); null when this process cannot read the days (the report there
	 * stays as it is).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function report(int $rNow): ?array {
		if (self::dir() === null) {
			return [];
		}
		self::follow((string) self::dir());
		$rSince = self::since($rNow);
		$rSum = self::days(self::WINDOW_DAYS, $rNow, $rSince ?? 0);
		if ($rSum === null) {
			return null;
		}
		$rOut = ['sql_connects' => $rSum['sql'], 'redis_connects' => $rSum['redis'], 'sites' => (object) $rSum['sites']];
		return $rSince === null ? $rOut : $rOut + ['connects_since' => $rSince];
	}

	/**
	 * The window starts again when the node begins to refuse (mode 2). What it
	 * counted before were connects mode 1 is meant to make, tens of thousands
	 * a day, and they would bury the ones mode 2 turns away, which are what
	 * the operator has to see after the move. Leaving mode 2 keeps the
	 * window: those refusals are why it was left. Whether the window was
	 * counted while refusing is kept beside `since`; a release that finds no
	 * such note takes the window as it is.
	 */
	private static function follow(string $rDir): void {
		$rFile = $rDir . 'refusing';
		$rNow = NodeRole::refusesConnects() ? '1' : '0';
		$rWas = @file_get_contents($rFile);
		if ($rWas === $rNow) {
			return;
		}
		if ($rWas === '0' && $rNow === '1') {
			self::forget();
		}
		@file_put_contents($rFile, $rNow);
	}

	/**
	 * Mode 0: the audit is over. Its start goes, and the days it counted with
	 * it, so a later audit starts from nothing. Days a manual trace counted
	 * without an audit (no `since`) stay.
	 */
	public static function forget(): void {
		$rDir = self::dir();
		if ($rDir === null) {
			return;
		}
		@unlink($rDir . 'refusing'); // follow()'s note is the window's
		if (!@unlink($rDir . 'since')) {
			return;
		}
		foreach (array_keys(AuditDays::files($rDir, self::DAY_FILES)) as $rFile) {
			@unlink($rFile);
		}
	}

	/** Delete day files (counts and logs) older than $rKeepDays. Returns how many went. */
	public static function prune(int $rKeepDays = 8, ?int $rNow = null): int {
		$rDir = self::dir();
		if ($rDir === null) {
			return 0;
		}
		return AuditDays::prune($rDir, self::DAY_FILES, $rKeepDays, $rNow ?? self::$rNow ?? time());
	}

	/**
	 * Add one connect at $rKey to a day's counts, under the file's lock.
	 * True when the site (or OTHER) is new to the day.
	 */
	private static function count(string $rFile, string $rKind, string $rKey): bool {
		$rNew = false;
		AuditDays::rewrite($rFile, static function (mixed $rDoc) use ($rKind, $rKey, &$rNew): string {
			$rDay = self::day($rDoc);
			$rDay[$rKind]++;
			$rKey = AuditDays::slot($rDay['sites'], $rKey, self::MAX_SITES);
			$rNew = !isset($rDay['sites'][$rKey]);
			$rDay['sites'][$rKey] = ($rDay['sites'][$rKey] ?? 0) + 1;
			return (string) json_encode(['sql' => $rDay['sql'], 'redis' => $rDay['redis'], 'sites' => (object) $rDay['sites']], JSON_UNESCAPED_SLASHES);
		});
		return $rNew;
	}

	/**
	 * Append one line to a day's log while it is under LOG_MAX_BYTES.
	 *
	 * @param array<string, mixed> $rLine
	 */
	private static function log(string $rFile, array $rLine): void {
		clearstatcache(true, $rFile);
		$rSize = @filesize($rFile);
		if ($rSize !== false && $rSize >= self::LOG_MAX_BYTES) {
			return;
		}
		@file_put_contents($rFile, json_encode($rLine, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
	}

	/**
	 * A day's counts, whatever the file held: counts that are not positive
	 * integers, and sites that are not site keys, are dropped.
	 *
	 * @return array{sql: int, redis: int, sites: array<string, int>}
	 */
	private static function day(mixed $rDoc): array {
		$rOut = ['sql' => 0, 'redis' => 0, 'sites' => []];
		if (!is_array($rDoc)) {
			return $rOut;
		}
		foreach ([self::SQL, self::REDIS] as $rKind) {
			if (is_int($rDoc[$rKind] ?? null) && $rDoc[$rKind] > 0) {
				$rOut[$rKind] = $rDoc[$rKind];
			}
		}
		foreach (is_array($rDoc['sites'] ?? null) ? $rDoc['sites'] : [] as $rKey => $rCount) {
			$rKey = (string) $rKey;
			if (is_int($rCount) && $rCount > 0 && ($rKey === self::OTHER || self::isSite($rKey))) {
				$rOut['sites'][$rKey] = $rCount;
			}
		}
		return $rOut;
	}

	/**
	 * The last $rDays days' counts, summed, the sites ranked; a day before
	 * $rFrom's is left out. Null when this process cannot tell: the directory
	 * (or the nearest level above it) is not one it may search, or a day file
	 * is not one it may read. An absent day is a day without a connect. Each
	 * day file is read under its lock (AuditDays::window), so a count
	 * rewriting it is never seen half done.
	 *
	 * @return array{sql: int, redis: int, sites: array<string, int>}|null
	 */
	private static function days(int $rDays, int $rNow, int $rFrom = 0): ?array {
		$rOut = ['sql' => 0, 'redis' => 0, 'sites' => []];
		$rDir = self::dir();
		if ($rDir === null) {
			return $rOut;
		}
		$rDocs = AuditDays::window($rDir, $rDays, $rNow, $rFrom);
		if ($rDocs === null) {
			return null;
		}
		foreach ($rDocs as $rDoc) {
			$rDay = self::day($rDoc);
			$rOut['sql'] += $rDay['sql'];
			$rOut['redis'] += $rDay['redis'];
			foreach ($rDay['sites'] as $rKey => $rCount) {
				$rOut['sites'][$rKey] = ($rOut['sites'][$rKey] ?? 0) + $rCount;
			}
		}
		$rOut['sites'] = AuditDays::top($rOut['sites'], self::MAX_SITES);
		return $rOut;
	}

	/**
	 * When this node's audit began: the `since` file, written now when there
	 * is none (a node that just entered mode 1, or whose audit was reset).
	 * Null when it cannot be written.
	 */
	private static function since(int $rNow): ?int {
		$rDir = (string) self::dir();
		$rFile = $rDir . 'since';
		$rSince = (int) trim((string) @file_get_contents($rFile));
		if ($rSince > 0) {
			return $rSince;
		}
		if (!AuditDays::makeDir($rDir) || @file_put_contents($rFile, (string) $rNow) === false) {
			return null;
		}
		return $rNow;
	}
}
