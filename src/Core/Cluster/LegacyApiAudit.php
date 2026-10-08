<?php

namespace XcVm\Core\Cluster;

/**
 * Legacy API Audit
 *
 * The calls this node's legacy `/api` (InternalApiController, the stream
 * password in its URL) still answers, by action and caller: what keeps it in
 * use before it answers 404 (api_legacy.conf, DataPlane::legacyApiRetired)
 * and before the plan's Phase 10 removes it. Counted on a load balancer in
 * mode 1 or 2, once the password and the caller's address passed; MAIN and
 * mode 0 count nothing.
 *
 * ```text
 * STORAGE_PATH/cluster/legacy_api/YYYYMMDD.json   the UTC day's counts,
 *     {"action caller": n}, at most MAX_KEYS keys, the rest under OTHER
 * ```
 *
 * The last WINDOW_DAYS days go into the audit.json the agent sends as the
 * heartbeat's `audit`, as `legacy_api` (SettingsAudit::publish, report()):
 * rewritten when a call adds a key to its day or the file is a minute old,
 * and by cron:cleanup every hour, which also prunes the days. A root process
 * counts as the owner of the agent's directory (SettingsAudit::asAgentUser).
 * Nothing here throws: an audit must not break the call it counts.
 *
 * Lives in Core: it ships to LBs, where Domain\Cluster does not.
 */
final class LegacyApiAudit {
	use OptionalDirSeam;

	/** Keys a day file, and the report, name at most; the rest count under OTHER. */
	public const MAX_KEYS = 32;

	/** A key past MAX_KEYS. */
	public const OTHER = AuditDays::OTHER;

	/** Days the report covers, today included. */
	public const WINDOW_DAYS = 7;

	/** Seconds after which a call rewrites audit.json without a new key. */
	public const PUBLISH_EVERY = 60;

	/** STORAGE_PATH's (useDir(): tests' own, false for none). */
	private static function defaultDir(): ?string {
		return defined('STORAGE_PATH') ? STORAGE_PATH . 'cluster/legacy_api/' : null;
	}

	/** "action caller": the action when it is a name ([A-Za-z0-9_]{1,32}), the caller when it is an address; "?" for either that is not. */
	public static function key(string $rAction, string $rCaller): string {
		return (preg_match('/^[A-Za-z0-9_]{1,32}\z/', $rAction) ? $rAction : '?') . ' ' . (filter_var($rCaller, FILTER_VALIDATE_IP) !== false ? $rCaller : '?');
	}

	/** Is $rKey one key() makes, or OTHER? */
	public static function isKey(string $rKey): bool {
		return $rKey === self::OTHER || preg_match('/^(?:[A-Za-z0-9_]{1,32}|\?) [0-9A-Fa-f.:?]{1,45}\z/', $rKey) === 1;
	}

	/** Count one call of $rAction from $rCaller in today's file, on a load balancer in mode 1 or 2. */
	public static function record(string $rAction, string $rCaller, ?int $rNow = null): void {
		$rDir = self::dir();
		try {
			if ($rDir === null || NodeRole::mainBuild() || NodeFlows::declared()['mode'] < 1) {
				return;
			}
			$rNow ??= time();
			$rKey = self::key($rAction, $rCaller);
			// Root counts as xc_vm: these directories are xc_vm's to write.
			SettingsAudit::asAgentUser(static function () use ($rDir, $rKey, $rNow): bool {
				if (!AuditDays::makeDir($rDir)) {
					return false;
				}
				$rNew = false;
				AuditDays::rewrite($rDir . gmdate('Ymd', $rNow) . '.json', static function (mixed $rDoc) use ($rKey, &$rNew): string {
					$rDay = self::counts($rDoc);
					$rKey = AuditDays::slot($rDay, $rKey, self::MAX_KEYS);
					$rNew = !isset($rDay[$rKey]);
					$rDay[$rKey] = ($rDay[$rKey] ?? 0) + 1;
					return (string) json_encode((object) $rDay, JSON_UNESCAPED_SLASHES);
				});
				SettingsAudit::publishIfDue($rNew, $rNow, self::PUBLISH_EVERY);
				return true;
			});
		} catch (\Throwable) {
			// Counted, not needed: the call goes on.
		}
	}

	/**
	 * This node's calls for audit.json: `legacy_api` over the last
	 * WINDOW_DAYS days (an empty object when none). [] when there is no
	 * directory (the member is left out); null when this process cannot read
	 * the days (the report there stays as it is).
	 *
	 * @return array{legacy_api?: object}|null
	 */
	public static function report(int $rNow): ?array {
		$rDir = self::dir();
		if ($rDir === null) {
			return [];
		}
		$rDocs = AuditDays::window($rDir, self::WINDOW_DAYS, $rNow);
		if ($rDocs === null) {
			return null;
		}
		$rSum = [];
		foreach ($rDocs as $rDoc) {
			foreach (self::counts($rDoc) as $rKey => $rCount) {
				$rSum[$rKey] = ($rSum[$rKey] ?? 0) + $rCount;
			}
		}
		return ['legacy_api' => (object) AuditDays::top($rSum, self::MAX_KEYS)];
	}

	/** Delete day files older than $rKeepDays. Returns how many went. */
	public static function prune(int $rKeepDays = 8, ?int $rNow = null): int {
		$rDir = self::dir();
		return $rDir === null ? 0 : AuditDays::prune($rDir, ['json'], $rKeepDays, $rNow ?? time());
	}

	/**
	 * The counts of a day file, or of a heartbeat's `legacy_api`: keys that
	 * are no key() and counts that are no positive integer are dropped.
	 *
	 * @return array<string, int>
	 */
	public static function counts(mixed $rDoc): array {
		$rOut = [];
		foreach (is_array($rDoc) ? $rDoc : [] as $rKey => $rCount) {
			if (is_int($rCount) && $rCount > 0 && self::isKey((string) $rKey)) {
				$rOut[(string) $rKey] = $rCount;
			}
		}
		return $rOut;
	}
}
