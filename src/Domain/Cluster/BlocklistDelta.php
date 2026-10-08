<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\BlocklistChanges;
use XcVm\Core\Cluster\StrictQuery;
use XcVm\Core\Database\Database;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * What a node applies to bring its blocklist from change `since` to now (plan,
 * section 9), read from the log BlocklistChanges writes.
 *
 * ```text
 * {last, more, full: false, reload: [kind…], add: [ip…], remove: [ip…]}
 * {last, more: false, full: true}   since is 0, older than the log, or past it
 * ```
 *
 * Blocked IPs, the kind that changes all the time (the flood guard), travel as
 * a delta: each changed address is added if it is blocked now, else removed,
 * so the order of two changes to one address never matters. The delta is the
 * extension's `blk` record: additions only restrict and sign without a
 * licence, and removals grant. Every other kind (user agents, ISPs, ASNs, RTMP
 * publishers) changes by hand and rarely: a change names the kind in
 * `reload`, and the node takes the whole `blocklist` section again (snapshot()),
 * as it does on `full`. The log keeps seven days and always its newest row, so
 * a quiet week does not force every node into a full reload. A failed read
 * throws: an empty result would be signed as a blocklist with nothing blocked.
 */
final class BlocklistDelta {
	use DatabaseAware;

	/** Days the change log keeps. */
	public const KEEP_DAYS = 7;

	/** Changes read per call; the node asks again while `more` is set. */
	public const MAX_CHANGES = 5000;

	/** Kind => [table, key column, columns a node gets]. */
	private const KINDS = [
		'ip' => ['blocked_ips', 'ip', ['ip']],
		'ua' => ['blocked_uas', 'id', ['id', 'user_agent', 'exact_match']],
		'isp' => ['blocked_isps', 'id', ['id', 'isp', 'blocked']],
		'asn' => ['blocked_asns', 'id', ['id', 'asn']],
		// The RTMP password goes too: the node checks publishers against it.
		'rtmp' => ['rtmp_ips', 'id', ['id', 'ip', 'password', 'push', 'pull']],
	];

	/** The newest change id, 0 when nothing was logged. */
	public static function head(): int {
		self::read('SELECT MAX(`id`) AS `hi` FROM `cluster_changes` WHERE `section` = ?;', BlocklistChanges::SECTION);
		return (int) (self::db()->get_row()['hi'] ?? 0);
	}

	/**
	 * @return array{last: int, more: bool, full: bool, reload?: list<string>, add?: list<string>, remove?: list<string>}
	 */
	public static function since(int $rSince, int $rLimit = self::MAX_CHANGES): array {
		$db = self::db();
		self::read('SELECT MIN(`id`) AS `lo`, MAX(`id`) AS `hi` FROM `cluster_changes` WHERE `section` = ?;', BlocklistChanges::SECTION);
		$rRange = $db->get_row() ?: [];
		$rLo = (int) ($rRange['lo'] ?? 0);
		$rHi = (int) ($rRange['hi'] ?? 0);
		// Nothing to start from, a log pruned past it, or a log that went back.
		if ($rSince <= 0 || $rHi === 0 || $rSince + 1 < $rLo || $rSince > $rHi) {
			return ['last' => $rHi, 'more' => false, 'full' => true];
		}
		self::read('SELECT `id`, `op`, `kind`, `value` FROM `cluster_changes` WHERE `section` = ? AND `id` > ? ORDER BY `id` LIMIT ' . max(1, $rLimit) . ';', BlocklistChanges::SECTION, $rSince);
		$rRows = $db->get_rows() ?: [];
		$rLast = $rSince;
		$rReload = [];
		$rIPs = [];
		foreach ($rRows as $rRow) {
			$rLast = (int) $rRow['id'];
			$rKind = (string) $rRow['kind'];
			if (!isset(self::KINDS[$rKind])) {
				continue;
			}
			if ($rKind !== 'ip' || $rRow['op'] === 'reset') {
				$rReload[$rKind] = true;
			} else {
				$rIPs[(string) $rRow['value']] = true;
			}
		}
		$rOut = ['last' => $rLast, 'more' => count($rRows) >= $rLimit, 'full' => false, 'reload' => array_keys($rReload), 'add' => [], 'remove' => []];
		if ($rIPs !== [] && !isset($rReload['ip'])) {
			$rKeys = array_map('strval', array_keys($rIPs));
			$rOut['add'] = array_values(array_map(static fn(array $rRow): string => (string) $rRow['ip'], self::rows('ip', $rKeys)));
			$rOut['remove'] = array_values(array_diff($rKeys, $rOut['add']));
		}
		return $rOut;
	}

	/**
	 * The whole blocklist, or the named kinds, for a full reload.
	 *
	 * @param list<string>|null $rKinds
	 * @return array<string, list<mixed>>
	 */
	public static function snapshot(?array $rKinds = null): array {
		$rOut = [];
		foreach ($rKinds ?? array_keys(self::KINDS) as $rKind) {
			if (!isset(self::KINDS[$rKind])) {
				continue;
			}
			if ($rKind === 'ip') {
				$rOut['ip'] = self::ips();
				continue;
			}
			$rRows = self::rows($rKind, null);
			$rOut[$rKind] = match ($rKind) {
				'asn' => array_map(static fn(array $rRow): int => (int) $rRow['asn'], $rRows),
				default => array_map([self::class, 'typed'], $rRows),
			};
		}
		return $rOut;
	}

	/**
	 * Numbers as numbers, whichever driver read them: the section's ETag hashes this.
	 *
	 * @param array<string, mixed> $rRow
	 * @return array<string, mixed>
	 */
	private static function typed(array $rRow): array {
		foreach (['id', 'exact_match', 'blocked', 'push', 'pull'] as $rColumn) {
			if (array_key_exists($rColumn, $rRow)) {
				$rRow[$rColumn] = (int) $rRow[$rColumn];
			}
		}
		return $rRow;
	}

	/** Drop changes past KEEP_DAYS, always keeping the newest. */
	public static function prune(?int $rNow = null): void {
		$rHi = self::head();
		if ($rHi > 0) {
			self::db()->query('DELETE FROM `cluster_changes` WHERE `section` = ? AND `id` < ? AND `time` < ?;', BlocklistChanges::SECTION, $rHi, ($rNow ?? time()) - self::KEEP_DAYS * 86400);
		}
	}

	/**
	 * @param list<string>|null $rKeys null: every row
	 * @return list<array<string, mixed>>
	 */
	private static function rows(string $rKind, ?array $rKeys): array {
		[$rTable, $rKey, $rColumns] = self::KINDS[$rKind];
		$rSql = 'SELECT `' . implode('`, `', $rColumns) . '` FROM `' . $rTable . '`';
		$rArgs = [];
		if ($rKind === 'asn') {
			$rSql .= ' WHERE `blocked` = 1'; // the catalog lists every ASN; only blocked ones matter
		}
		if ($rKeys !== null) {
			if ($rKeys === []) {
				return [];
			}
			$rSql .= ($rKind === 'asn' ? ' AND ' : ' WHERE ') . '`' . $rKey . '` IN (' . implode(', ', array_fill(0, count($rKeys), '?')) . ')';
			$rArgs = $rKeys;
		}
		self::read($rSql . ' ORDER BY `' . $rKey . '`;', ...$rArgs);
		return self::db()->get_rows() ?: [];
	}

	/**
	 * Every blocked IP, as rows() reads them (cleaned, in key order), one
	 * column streamed into a list: a row array each held about 0.7 KB, and
	 * half a million of them passed MAIN's 512 MB before the section was sealed.
	 *
	 * @return list<string>
	 */
	private static function ips(): array {
		self::read('SELECT `ip` FROM `blocked_ips` ORDER BY `ip`;');
		$rIPs = self::db()->get_column();
		foreach ($rIPs as $i => $rIP) {
			$rIPs[$i] = $rIP ? Database::parseCleanValue((string) $rIP) : (string) $rIP;
		}
		return $rIPs;
	}

	/** Run a read: a failed one throws, never an empty result. */
	private static function read(string $rQuery, mixed ...$rArgs): void {
		StrictQuery::run(self::db(), 'blocklist', $rQuery, ...$rArgs);
	}
}
