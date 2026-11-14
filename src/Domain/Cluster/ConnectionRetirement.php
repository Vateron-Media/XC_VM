<?php

declare(strict_types=1);

namespace XcVm\Domain\Cluster;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\QueryHelper;
use XcVm\Infrastructure\Database\DatabaseAware;

/** Atomic table-store retirement and durable, lifecycle-fenced delivery. */
final class ConnectionRetirement {
	use DatabaseAware;

	public static function generation(array $row): string {
		$token = $row['retire_token'] ?? null;
		return is_string($token) && preg_match('/^[0-9a-f]{32}\z/', $token) ? $token : 'legacy-' . (int) ($row['date_start'] ?? 0);
	}

	public static function lock(int $server, string $uuid): bool {
		$db = self::db();
		return $db->query('SELECT GET_LOCK(?, 0);', 'xcvm_retire_' . substr(hash('sha256', $server . ':' . $uuid), 0, 40)) && (int) $db->get_col() === 1;
	}

	public static function unlock(int $server, string $uuid): void {
		self::db()->query('SELECT RELEASE_LOCK(?);', 'xcvm_retire_' . substr(hash('sha256', $server . ':' . $uuid), 0, 40));
	}

	/** A current locking read, including inside a pre-existing events transaction. */
	public static function seen(int $server, array $record): bool {
		// Old agents cannot distinguish a same-second reopen; never reject an
		// unversioned open. New PHP registrations carry a random lifecycle token.
		if (self::generation($record) === 'legacy-' . (int) ($record['date_start'] ?? 0) && empty($record['hls_end'])) {
			return false;
		}
		$db = self::db();
		if (!$db->query('SELECT id FROM connection_retirements WHERE server_id = ? AND uuid = ? AND generation = ? LOCK IN SHARE MODE;', $server, (string) $record['uuid'], self::generation($record))) {
			throw new \RuntimeException('Cannot read connection retirements');
		}
		return $db->num_rows() > 0;
	}

	/** Delete, activity and notification intent commit together; no file/queue side effects. */
	public static function retire(array $row, int $end): bool {
		$db = self::db();
		$server = (int) $row['server_id'];
		$uuid = (string) $row['uuid'];
		if (!self::lock($server, $uuid)) {
			return false;
		}
		$own = !$db->isInTransaction();
		$save = false;
		try {
			if ($own ? !$db->beginTransaction() : !$db->query('SAVEPOINT xcvm_retirement;')) {
				return false;
			}
			$save = true;
			if (!$db->query('SET STATEMENT innodb_lock_wait_timeout=0 FOR DELETE FROM lines_live WHERE activity_id = ? AND hls_end = 1 AND server_id = ? AND uuid = ? AND date_start <=> ? AND hls_last_read <=> ? AND retire_token <=> ?;', $row['activity_id'], $server, $uuid, $row['date_start'], $row['hls_last_read'], $row['retire_token'] ?? null) || $db->num_rows() !== 1) {
				throw new \RuntimeException('Connection changed or is locked');
			}
			$generation = self::generation($row);
			$token = str_starts_with($generation, 'legacy-') ? null : $generation;
			if (!$db->query('SET STATEMENT innodb_lock_wait_timeout=0 FOR INSERT IGNORE INTO connection_retirements (server_id, uuid, generation, date_start, retired_at, retire_token, notify_done) VALUES (?, ?, ?, ?, ?, ?, ?);', $server, $uuid, $generation, (int) $row['date_start'], time(), $token, $token === null ? 1 : 0)) {
				throw new \RuntimeException('Cannot persist retirement');
			}
			$fresh = $db->num_rows() === 1;
			$settings = SettingsManager::getAll();
			$keep = (int) ($settings['keep_activity'] ?? 0);
			if ($fresh && !empty($settings['save_closed_connection']) && ($keep <= 0 || $end >= time() - $keep) && (!empty($row['user_id']) || !empty($row['hmac_id'])) && !empty($row['stream_id'])) {
				$activity = array_intersect_key($row, array_flip(['user_id', 'stream_id', 'server_id', 'proxy_id', 'date_start', 'user_agent', 'user_ip', 'container', 'geoip_country_code', 'isp', 'external_device', 'divergence', 'hmac_id', 'hmac_identifier']));
				$activity['date_end'] = $end;
				$prepared = QueryHelper::prepareArray($activity);
				if (!$db->query('INSERT INTO lines_activity (' . $prepared['columns'] . ') VALUES (' . $prepared['placeholder'] . ');', ...$prepared['data'])) {
					throw new \RuntimeException('Cannot persist connection activity');
				}
			}
			if ($own ? !$db->commit() : !$db->query('RELEASE SAVEPOINT xcvm_retirement;')) {
				throw new \RuntimeException('Cannot commit retirement');
			}
			$save = false;
			return true;
		} catch (\Throwable) {
			if ($save) {
				$own ? $db->rollback() : $db->query('ROLLBACK TO SAVEPOINT xcvm_retirement;');
			}
			return false;
		} finally {
			self::unlock($server, $uuid);
		}
	}

	/** Retry notification intent until the signed command is acknowledged. */
	public static function dispatch(int $limit = 20, float $seconds = 1.0): int {
		$db = self::db();
		if ($db->isInTransaction() || $limit <= 0 || $seconds <= 0) {
			return 0;
		}
		$until = microtime(true) + $seconds;
		$limit = min(100, $limit);
		if (!$db->query('SELECT id FROM connection_retirements WHERE notify_done = 0 AND retry_at <= ? ORDER BY retry_at, id LIMIT ' . $limit . ';', time())) {
			return 0;
		}
		$ids = $db->get_column();
		$sent = 0;
		foreach ($ids as $id) {
			if (microtime(true) >= $until || !$db->beginTransaction()) {
				break;
			}
			try {
				if (!$db->query('SELECT * FROM connection_retirements WHERE id = ? AND notify_done = 0 FOR UPDATE SKIP LOCKED;', $id)) {
					throw new \RuntimeException('Cannot claim retirement delivery');
				}
				$row = $db->get_raw_row();
				if ($row === null) {
					$db->rollback();
					continue;
				}
				if ($row['command_id'] !== null) {
					if (!$db->query('SELECT state, exp FROM cluster_commands WHERE cmd_id = ?;', $row['command_id'])) {
						throw new \RuntimeException('Cannot read retirement command');
					}
					$command = $db->get_raw_row();
					if ($command !== null && $command['state'] === 'acked') {
						if (!$db->query('UPDATE connection_retirements SET notify_done = 1, retry_at = ? WHERE id = ?;', time(), $id) || !$db->commit()) {
							throw new \RuntimeException('Cannot acknowledge retirement');
						}
						continue;
					}
					if ($command !== null && (int) $command['exp'] > time() && in_array($command['state'], ['queued', 'delivered'], true)) {
						if (!$db->query('UPDATE connection_retirements SET retry_at = ? WHERE id = ?;', time() + 30, $id) || !$db->commit()) {
							throw new \RuntimeException('Cannot defer retirement');
						}
						continue;
					}
				}
				$cmd = ClusterRoute::retireConnection((int) $row['server_id'], (string) $row['retire_token']);
				if (!$db->query('UPDATE connection_retirements SET command_id = ?, retry_at = ? WHERE id = ?;', $cmd, time() + 30, $id) || !$db->commit()) {
					throw new \RuntimeException('Cannot commit retirement delivery');
				}
				$sent += $cmd !== null ? 1 : 0;
			} catch (\Throwable) {
				$db->rollback();
			}
		}
		if (microtime(true) < $until) {
			self::cleanupCompleted();
		}
		return $sent;
	}

	/** Bounded history cleanup, independent of cluster mode or swept servers. */
	public static function cleanupCompleted(): int {
		$db = self::db();
		if ($db->isInTransaction()) {
			return 0;
		}
		// Once done, retry_at records acknowledgement time. Legacy tickets with
		// no delivery use retired_at; pending tickets never qualify for removal.
		$cutoff = time() - 86400;
		if (!$db->query('SET STATEMENT innodb_lock_wait_timeout=0 FOR DELETE FROM connection_retirements WHERE notify_done = 1 AND retired_at < ? AND retry_at < ? ORDER BY retired_at LIMIT 100;', $cutoff, $cutoff)) {
			return 0;
		}
		return $db->num_rows();
	}
}
