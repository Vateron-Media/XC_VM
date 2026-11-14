<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\DirSeam;
use XcVm\Core\Config\SettingsManager;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * `conn_snapshot`: a CONNECTIONS node's whole registry, sent when MAIN asks
 * (ConnectionDigest), so MAIN's store for that node matches it again.
 *
 * ```text
 * {snap_id, seq, last, records: [record, …]}
 * ```
 *
 * The registry goes in chunks of up to MAX_RECORDS, numbered from 0. MAIN
 * keeps them aside and applies nothing until the last one arrives. Then every
 * record is upserted as a `conn.upsert` would be (ConnectionIngest), and every
 * open connection MAIN holds for the node that the snapshot lacks is removed.
 * A chunk 0 starts over; any other chunk out of order is refused with the
 * number MAIN expects, and the node starts the snapshot again.
 *
 * In MySQL mode the apply is one transaction, so readers see the store as it
 * was or as the snapshot left it, never in between. An apply that fails (the
 * connection drops, the commit fails; in Redis mode, which has no such
 * transaction, a Redis error) throws, so the node gets 503 DB, and keeps the
 * staged chunks: the last chunk sent again applies the whole. A record MAIN
 * refuses or cannot write is counted as dropped, and never removes the
 * connection MAIN holds under its uuid; the next heartbeat's digest check
 * (ConnectionDigest) finds any difference left.
 */
final class ConnectionSnapshot {
	use DatabaseAware;
	use DirSeam {
		dir as private;
	}

	/** Records per chunk. */
	public const MAX_RECORDS = 1000;

	/** Chunks per snapshot (50 000 connections). */
	public const MAX_CHUNKS = 50;

	/**
	 * @param array<string, mixed> $rP
	 * @return array{ok: bool, bad?: bool, expected_seq?: int, done?: bool, applied?: int, removed?: int, dropped?: int}
	 */
	public static function receive(int $rServerID, array $rP): array {
		$rSnap = $rP['snap_id'] ?? null;
		$rSeq = $rP['seq'] ?? null;
		$rRecords = $rP['records'] ?? null;
		if (!is_string($rSnap) || !preg_match('/^[0-9a-f]{16,32}\z/', $rSnap) || !is_int($rSeq) || $rSeq < 0 || $rSeq >= self::MAX_CHUNKS || !is_bool($rP['last'] ?? null) || !is_array($rRecords) || !array_is_list($rRecords) || count($rRecords) > self::MAX_RECORDS) {
			return ['ok' => false, 'bad' => true];
		}
		$rDir = self::dir() . $rServerID . '/';
		if (!is_dir($rDir) && !@mkdir($rDir, 0750, true) && !is_dir($rDir)) {
			throw new \RuntimeException('cannot stage the snapshot');
		}
		$rMeta = json_decode((string) @file_get_contents($rDir . 'meta.json'), true);
		if ($rSeq === 0) {
			self::clear($rDir);
			$rMeta = ['snap_id' => $rSnap, 'next' => 0];
		}
		if (!is_array($rMeta) || ($rMeta['snap_id'] ?? null) !== $rSnap || (int) ($rMeta['next'] ?? -1) !== $rSeq) {
			return ['ok' => false, 'expected_seq' => is_array($rMeta) && ($rMeta['snap_id'] ?? null) === $rSnap ? (int) $rMeta['next'] : 0];
		}
		if (file_put_contents($rDir . $rSeq . '.json', json_encode($rRecords)) === false) {
			throw new \RuntimeException('cannot stage the snapshot');
		}
		if (!$rP['last']) {
			file_put_contents($rDir . 'meta.json', json_encode(['snap_id' => $rSnap, 'next' => $rSeq + 1]));
			return ['ok' => true, 'done' => false];
		}
		$rAll = [];
		for ($i = 0; $i <= $rSeq; $i++) {
			$rChunk = json_decode((string) @file_get_contents($rDir . $i . '.json'), true);
			if (!is_array($rChunk)) {
				self::clear($rDir);
				return ['ok' => false, 'expected_seq' => 0];
			}
			array_push($rAll, ...$rChunk);
		}
		$rAppliedDigest = null;
		$rOut = self::apply($rServerID, $rAll, $rAppliedDigest); // throws: the chunks stay staged
		ConnectionDigest::snapshotApplied($rServerID, $rAll, $rAppliedDigest, $rSnap);
		self::clear($rDir);
		return ['ok' => true, 'done' => true] + $rOut;
	}

	/**
	 * Make MAIN's store for the node match its registry.
	 *
	 * @param list<mixed> $rRecords
	 * @param array{count: int, users: int, xor64: string}|null $rAppliedDigest
	 * @param-out array{count: int, users: int, xor64: string} $rAppliedDigest
	 * @return array{applied: int, removed: int, dropped: int}
	 */
	public static function apply(int $rServerID, array $rRecords, ?array &$rAppliedDigest = null): array {
		$rDb = SettingsManager::get('redis_handler') ? null : self::db();
		$rTx = $rDb !== null && method_exists($rDb, 'beginTransaction') && $rDb->beginTransaction();
		try {
			$rApplied = $rDropped = $rRemoved = 0;
			$rSeen = [];
			foreach ($rRecords as $rRecord) {
				if (is_array($rRecord) && is_scalar($rRecord['uuid'] ?? null)) {
					$rSeen[(string) $rRecord['uuid']] = true; // the node holds it, whether MAIN takes it or not
				}
				if (is_array($rRecord) && ConnectionIngest::upsert($rServerID, $rRecord)) {
					$rApplied++;
				} else {
					$rDropped++;
				}
			}
			$rStored = ConnectionDigest::stored($rServerID);
			foreach (array_keys($rStored) as $rUUID) {
				if (!isset($rSeen[(string) $rUUID]) && ConnectionIngest::remove($rServerID, (string) $rUUID)) {
					$rRemoved++;
					unset($rStored[$rUUID]);
				}
			}
			$rAppliedDigest = ConnectionDigest::of($rStored);
			if ($rTx && !$rDb->commit()) {
				throw new \RuntimeException('cannot commit the snapshot');
			}
		} catch (\Throwable $rE) {
			if ($rTx) {
				$rDb->rollback();
			}
			throw $rE;
		}
		return ['applied' => $rApplied, 'removed' => $rRemoved, 'dropped' => $rDropped];
	}

	private static function clear(string $rDir): void {
		foreach (glob($rDir . '*.json') ?: [] as $rFile) {
			@unlink($rFile);
		}
	}

	/** The staging directory (useDir(): tests' own). */
	private static function defaultDir(): string {
		return (defined('TMP_PATH') ? TMP_PATH : sys_get_temp_dir() . '/') . 'cluster_snapshots/';
	}
}
