<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Config\ConfigReader;
use XcVm\Core\Gateway\GatewayShadow;
use XcVm\Core\Util\AtomicFile;

/**
 * Settings reads the node replica would not answer (plan, section 9, R1
 * `settings`: "API nodes log unknown-key reads to audit.settings_misses").
 *
 * On a node in mode 1 or 2, each read through SettingsManager of a key
 * outside `lb_settings_keys.php` (its `keys` and `withheld`) is counted.
 * Once the replica owns the settings such a key is absent, so the count
 * names a read the allowlist's scan missed (a key built at run time) before
 * it fails. Reading a known key costs one lookup; nothing reaches a database:
 *
 * ```text
 * a process      counts in memory, merged into the day's file at exit and
 *                at most every FLUSH_EVERY s while it runs
 * day file       STORAGE_PATH/cluster/settings_misses/YYYYMMDD.json (UTC),
 *                {key: count}, at most MAX_KEYS keys, the rest under OTHER
 * audit.json     config/cluster/audit.json, {"settings_misses": {key: count}}:
 *                the last WINDOW_DAYS days, most missed first, at most
 *                MAX_KEYS keys and OTHER, with ConnectAudit's counters; the
 *                agent sends it as the heartbeat's `audit`, and MAIN keeps
 *                it with the node
 * ```
 *
 * audit.json is rewritten when a merge adds a key to the day, or finds it
 * older than FLUSH_EVERY, and by cron:cleanup every hour, so days that leave
 * the window drop out. Only on a node with an agent (config/cluster/), and in
 * mode 1 or 2; in mode 0 it is removed. A miss costs one locked merge of the
 * day file per process: per request under PHP-FPM, which keeps no state
 * between requests.
 *
 * A root process does all of this as the owner of the agent's directory
 * (xc_vm), whose processes add to the same files (asAgentUser): root never
 * writes with its own rights where xc_vm can plant a link. A report is
 * written only from days this process can read, and a day file is read under
 * its lock, never half rewritten.
 *
 * Lives in Core: it ships to LBs, where Domain\Cluster does not.
 */
final class SettingsAudit {
	use OptionalDirSeam;

	/** Keys a day file, and the report, name at most; the rest count under OTHER. */
	public const MAX_KEYS = 64;

	/** A key past MAX_KEYS, or not a settings name ([a-z0-9_]{1,64}). */
	public const OTHER = AuditDays::OTHER;

	/** Days the report covers, today included. */
	public const WINDOW_DAYS = 7;

	/** Seconds a long-running process keeps its counts before it merges them. */
	public const FLUSH_EVERY = 60;

	/** Tests: another agent directory (where audit.json goes). */
	private static ?string $rAgentDir = null;

	/**
	 * The keys that are no miss (null: not decided yet in this process;
	 * false: this process counts nothing).
	 *
	 * @var array<string, true>|false|null
	 */
	private static array|false|null $rKnown = null;

	/** @var array<string, int> */
	private static array $rCounts = [];

	private static int $rFlushedAt = 0;

	private static bool $rAtExit = false;

	/**
	 * Tests: another directory for the day files, or false for none (the
	 * suite's default), and another agent directory; null restores
	 * STORAGE_PATH's and CONFIG_PATH's. The process decides again whether it
	 * counts.
	 */
	public static function useDir(string|false|null $rDir, ?string $rAgentDir = null): void {
		self::$rDir = $rDir;
		self::$rAgentDir = $rAgentDir;
		self::$rKnown = null;
		self::$rCounts = [];
		self::$rAtExit = false;
	}

	/** STORAGE_PATH's, for the day files (useDir(): tests' own, false for none). */
	private static function defaultDir(): ?string {
		return defined('STORAGE_PATH') ? STORAGE_PATH . 'cluster/settings_misses/' : null;
	}

	/** The agent's directory, where audit.json goes (config/cluster/). */
	public static function agentDir(): ?string {
		return self::$rAgentDir ?? AgentPaths::fileOrNull(AgentPaths::DIR);
	}

	/**
	 * A read of $rKey (SettingsManager). Counted when this is a node in mode 1
	 * or 2 and the key is outside the allowlist. Decided once per process,
	 * from the agent's flows.json alone (NodeFlows::declared): MAIN has no
	 * agent, so it counts nothing.
	 */
	public static function read(string $rKey): void {
		if (self::$rKnown === false || (self::$rKnown !== null && isset(self::$rKnown[$rKey]))) {
			return;
		}
		if (self::$rKnown === null) {
			self::$rKnown = false; // a read while deciding is not counted
			self::$rKnown = self::known();
			self::$rFlushedAt = time();
			if (self::$rKnown === false || isset(self::$rKnown[$rKey])) {
				return;
			}
		}
		// Bounded in memory too: past MAX_KEYS distinct keys, the rest are OTHER.
		$rKey = isset(self::$rCounts[$rKey]) || count(self::$rCounts) < self::MAX_KEYS ? $rKey : self::OTHER;
		self::$rCounts[$rKey] = (self::$rCounts[$rKey] ?? 0) + 1;
		if (!self::$rAtExit) {
			self::$rAtExit = true;
			register_shutdown_function([self::class, 'flush']);
		} elseif (time() - self::$rFlushedAt >= self::FLUSH_EVERY) {
			self::flush();
		}
	}

	/**
	 * Merge this process's counts into the day's file (under its lock), then
	 * rewrite audit.json when the merge added a key to the day or audit.json
	 * is older than FLUSH_EVERY (cron:cleanup rewrites it every hour too).
	 * Never throws: an audit must not break a read.
	 */
	public static function flush(?int $rNow = null): void {
		$rCounts = self::$rCounts;
		self::$rCounts = [];
		self::$rFlushedAt = $rNow ?? time();
		$rDir = self::dir();
		if ($rCounts === [] || $rDir === null) {
			return;
		}
		$rAt = self::$rFlushedAt;
		try {
			self::asAgentUser(static function () use ($rDir, $rCounts, $rAt): bool {
				if (!AuditDays::makeDir($rDir)) {
					return false;
				}
				$rNewKey = false;
				$rWritten = AuditDays::rewrite($rDir . gmdate('Ymd', $rAt) . '.json', static function (mixed $rDay) use ($rCounts, &$rNewKey): string {
					$rDay = is_array($rDay) ? $rDay : [];
					$rMerged = self::merge($rDay, $rCounts);
					$rNewKey = array_diff_key($rMerged, $rDay) !== [];
					return (string) json_encode((object) $rMerged);
				});
				if (!$rWritten) {
					return false;
				}
				self::publishIfDue($rNewKey, $rAt, self::FLUSH_EVERY);
				return true;
			});
		} catch (\Throwable) {
			// Counted, not needed: the next flush tries again.
		}
	}

	/**
	 * The misses of the last WINDOW_DAYS UTC days, today included: most missed
	 * first (then by name), at most MAX_KEYS names, the rest under OTHER.
	 *
	 * @return array<string, int>
	 */
	public static function summary(?int $rNow = null): array {
		return self::top(self::days($rNow ?? time()) ?? []);
	}

	/**
	 * Write `audit.json` for the agent in $rAgentDir (config/cluster/):
	 * `{"settings_misses": summary()}` and this node's connects
	 * (ConnectAudit::report: `sql_connects`, `redis_connects`, `sites`,
	 * `connects_since`) on a node in mode 1 or 2; removed in mode 0, where
	 * nothing is counted, and the connect audit's window with it. False when
	 * there is no agent (MAIN, a legacy node), the write failed, or this
	 * process cannot read the days (a directory or day file another user's):
	 * the report there stays. Root writes it as $rAgentDir's owner
	 * (asAgentUser), and returns false when it cannot.
	 */
	public static function publish(?string $rAgentDir = null, ?int $rNow = null): bool {
		$rAgentDir ??= self::agentDir();
		if ($rAgentDir === null || self::dir() === null || !is_dir($rAgentDir)) {
			return false;
		}
		return self::asAgentUser(static function () use ($rAgentDir, $rNow): bool {
			if (NodeFlows::declared()['mode'] < 1) {
				@unlink($rAgentDir . 'audit.json');
				ConnectAudit::forget();
				return false;
			}
			$rNow ??= time();
			$rSum = self::days($rNow);
			$rConnects = $rSum === null ? null : ConnectAudit::report($rNow);
			$rLegacy = LegacyApiAudit::report($rNow);
			if ($rSum === null || $rConnects === null || $rLegacy === null) {
				return false;
			}
			$rDoc = ['settings_misses' => (object) self::top($rSum)] + $rConnects + $rLegacy + GatewayShadow::report();
			// A load balancer's build only: MAIN's would ask the servers whether it
			// is MAIN, at a connect that has no database yet.
			if (!NodeRole::mainBuild() && StreamRuntime::keeps()) {
				// Does the node run from its own copy: it boots from the replica (its
				// settings and servers built), the replica owns its streams'
				// definitions, and its own store of their state is whole? MAIN asks it
				// before it moves the node to mode 2 (ClusterAdmin::modeGate), where it
				// can read none of that from MAIN, nor seed the store.
				$rDoc['streams_local'] = ReplicaBoot::ready() && ReplicaStreamCache::owned() && StreamRuntime::seededFor(self::serverID());
			}
			return AtomicFile::write($rAgentDir . 'audit.json', (string) json_encode($rDoc, JSON_UNESCAPED_SLASHES));
		}, $rAgentDir);
	}

	/**
	 * Publish again because what `streams_local` says may have stopped being
	 * true (the store lapsed, a section is no longer the replica's): MAIN then
	 * hears it with the node's next heartbeat, not a minute later. Never throws.
	 */
	public static function republish(): void {
		try {
			self::publish();
		} catch (\Throwable) {
			// The next counted connect or the hourly cron publishes it.
		}
	}

	/** This node's server id: a process may publish at its first connect, before its boot defined SERVER_ID. */
	private static function serverID(): int {
		if (defined('SERVER_ID')) {
			return (int) SERVER_ID;
		}
		return class_exists(\XC_VM::class, false) ? (int) ConfigReader::get('server_id', 0) : 0;
	}

	/**
	 * Rewrite audit.json after a count at $rAt (this audit's, or
	 * ConnectAudit's) when it added a name to its day ($rNew), or audit.json
	 * is older than $rEvery seconds. Nothing without an agent directory.
	 */
	public static function publishIfDue(bool $rNew, int $rAt, int $rEvery): void {
		$rAgentDir = self::agentDir();
		if ($rAgentDir === null) {
			return;
		}
		clearstatcache(true, $rAgentDir . 'audit.json');
		if ($rNew || (int) @filemtime($rAgentDir . 'audit.json') <= $rAt - $rEvery) {
			self::publish($rAgentDir, $rAt);
		}
	}

	/** Delete day files older than $rKeepDays. Returns how many went. */
	public static function prune(int $rKeepDays = 8, ?int $rNow = null): int {
		$rDir = self::dir();
		return $rDir === null ? 0 : AuditDays::prune($rDir, ['json'], $rKeepDays, $rNow ?? time());
	}

	/**
	 * The keys that are no miss: the allowlist's, secrets withheld included
	 * (their reads are known). False when this process counts nothing: no
	 * directory, or not a node in mode 1 or 2.
	 *
	 * @return array<string, true>|false
	 */
	private static function known(): array|false {
		if (self::dir() === null || NodeFlows::declared()['mode'] < 1) {
			return false;
		}
		$rList = @include __DIR__ . '/lb_settings_keys.php';
		if (!is_array($rList)) {
			return false;
		}
		return array_fill_keys(array_merge((array) ($rList['keys'] ?? []), (array) ($rList['withheld'] ?? [])), true);
	}

	/**
	 * $rCounts added to a day's counts, each key a settings name or OTHER, at
	 * most MAX_KEYS names.
	 *
	 * @param array<mixed> $rDay
	 * @param array<string, int> $rCounts
	 * @return array<string, int>
	 */
	private static function merge(array $rDay, array $rCounts): array {
		$rOut = [];
		foreach ($rDay as $rKey => $rCount) {
			if (is_int($rCount) && $rCount > 0) {
				$rOut[self::name((string) $rKey)] = $rCount;
			}
		}
		foreach ($rCounts as $rKey => $rCount) {
			$rKey = AuditDays::slot($rOut, self::name((string) $rKey), self::MAX_KEYS);
			$rOut[$rKey] = ($rOut[$rKey] ?? 0) + $rCount;
		}
		return $rOut;
	}

	/**
	 * Most counted first (then by name), at most $rMax names (MAX_KEYS for
	 * the misses); the rest, and OTHER, last under OTHER.
	 *
	 * @param array<string, int> $rCounts
	 * @return array<string, int>
	 */
	public static function top(array $rCounts, int $rMax = self::MAX_KEYS): array {
		return AuditDays::top($rCounts, $rMax);
	}

	/** A settings name, or OTHER. */
	public static function name(string $rKey): string {
		return preg_match('/^[a-z0-9_]{1,64}\z/', $rKey) ? $rKey : self::OTHER;
	}

	/**
	 * The last WINDOW_DAYS days' counts, summed per name. Null when this
	 * process cannot tell: the directory (or, while it does not exist, the
	 * level above it that does) is not one it may search, or a day file is
	 * not one it may read. An absent day is a day without a miss.
	 *
	 * @return array<string, int>|null
	 */
	private static function days(int $rNow): ?array {
		$rDir = self::dir();
		if ($rDir === null) {
			return [];
		}
		$rDays = AuditDays::window($rDir, self::WINDOW_DAYS, $rNow);
		if ($rDays === null) {
			return null;
		}
		$rSum = [];
		foreach ($rDays as $rDay) {
			foreach (is_array($rDay) ? $rDay : [] as $rKey => $rCount) {
				if (is_int($rCount) && $rCount > 0) {
					$rKey = self::name((string) $rKey);
					$rSum[$rKey] = ($rSum[$rKey] ?? 0) + $rCount;
				}
			}
		}
		return $rSum;
	}

	/**
	 * Run $rWork with the rights of the owner of $rAgentDir (the agent's
	 * directory, config/cluster/: xc_vm on a node) and return what it returns.
	 * Anyone but root runs it as itself. Root switches its effective gid, its
	 * groups and its uid to that user's around it, and back after. The
	 * audits' files sit in directories xc_vm can write, where root must not
	 * create, truncate, append to or chown a file, nor follow a link xc_vm
	 * planted, with its own rights: root trusts nothing xc_vm can write, as
	 * RootPin has it. The kernel then applies xc_vm's permissions, and what
	 * root makes is xc_vm's, as xc_vm's processes need. (ConnectAudit's files
	 * too.)
	 *
	 * False, with nothing done, when root cannot become another user that
	 * way: no agent directory, one that is a link or root's, an owner without
	 * a passwd entry or whose group is root's, or a switch that fails.
	 *
	 * @param \Closure(): bool $rWork
	 */
	public static function asAgentUser(\Closure $rWork, ?string $rAgentDir = null): bool {
		if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
			return $rWork();
		}
		$rOf = $rAgentDir ?? self::agentDir();
		if ($rOf === null || !function_exists('posix_initgroups')) {
			return false;
		}
		$rOf = rtrim($rOf, '/');
		clearstatcache(true, $rOf);
		$rStat = @lstat($rOf);
		$rUser = is_array($rStat) && ($rStat['mode'] & 0170000) === 0040000 && $rStat['uid'] !== 0 ? posix_getpwuid($rStat['uid']) : false;
		if (!is_array($rUser) || (int) $rUser['gid'] === 0) {
			return false;
		}
		$rUid = (int) $rStat['uid'];
		$rGid = (int) $rUser['gid'];
		$rRootGid = posix_getegid();
		$rRoot = posix_getpwuid(0);
		$rRootName = is_array($rRoot) ? (string) $rRoot['name'] : 'root';
		// Groups and gid while still root, the uid last; back in the reverse order.
		if (!posix_initgroups((string) $rUser['name'], $rGid) || !posix_setegid($rGid) || !posix_seteuid($rUid)) {
			self::backToRoot($rRootGid, $rRootName);
			return false;
		}
		try {
			return $rWork();
		} finally {
			self::backToRoot($rRootGid, $rRootName);
		}
	}

	/**
	 * Root's own ids back after asAgentUser: the uid first (the saved uid is
	 * root's), then the gid and the groups. Logged when that fails: the rest
	 * of the process would run without root's rights.
	 */
	private static function backToRoot(int $rGid, string $rName): void {
		if (!posix_seteuid(0) || !posix_setegid($rGid) || !posix_initgroups($rName, $rGid)) {
			error_log('XC_VM: the node audit could not restore root\'s user and groups');
		}
	}
}
