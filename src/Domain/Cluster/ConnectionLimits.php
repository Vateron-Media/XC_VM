<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\AgentConnections;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Util\AtomicFile;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Database\DatabaseAware;
use XcVm\Streaming\Auth\StreamAuth;

/**
 * MAIN enforcing `max_connections` for a CONNECTIONS node's viewers (cluster
 * plan, Phase 6). The node no longer reads MAIN's store on each request to
 * find the line's other connections: it sends `conn.limit {uuid, ip,
 * user_agent, owner}` after recording its viewer, and MAIN runs the same rule
 * the node used to (StreamAuth::validateConnections → ConnectionLimiter): the
 * line's connections past its limit are closed, oldest first and the same IP
 * and agent before others, never the viewer that asked. The closes reach the
 * nodes as signed commands.
 *
 * The cluster endpoint takes no legacy globals, so ingest only queues the
 * check (a file under TMP_PATH/cluster_limits/) and MAIN's 1 s loop
 * (cron:signals) drains it with the full bootstrap.
 *
 * The queue is bounded, as tmp is a tmpfs every part of the panel needs. A
 * node asks on every request of a line with a limit, a playlist refresh
 * included, and a pass runs a fixed number of checks: with a file per request
 * the queue grew for as long as requests outran the loop (over a million
 * files in one report). So a viewer has one file, which its next request
 * replaces; passes take the files in turn (drain()); a check that waited
 * MAX_AGE is dropped unrun; and nothing is queued while no pass has run for
 * IDLE seconds.
 *
 * A node can ask only about its own viewer: the uuid must be in MAIN's store
 * as that node's, for the same line or HMAC identity. A line's limit and pair
 * come from `lines`, never from the node; an HMAC identity's limit is signed
 * into the client's request and stored nowhere, so it comes from the node.
 *
 * The same queue carries the cut of a `conn_admit` (queueAdmission(), written
 * by MAIN only, marked `admission`): run by ConnectionAdmission::cut(), for a
 * viewer that may not be open yet, and dropped when its uuid is another
 * node's.
 */
final class ConnectionLimits {
	use DatabaseAware;

	/** A check that waited this long is dropped unrun: the line is judged again on its viewers' next requests. */
	private const MAX_AGE = 300;

	/** No pass for this long (cron:signals stopped, the cluster API off): nothing is queued. */
	private const IDLE = 60;

	/** The most stale files one pass removes, so a backlog left by an older release goes in minutes without holding the loop. */
	private const SWEEP = 5000;

	/** The most names one pass lists; the queue holds a file per viewer, so more is a backlog being cleared. */
	private const LIST = 100000;

	/** Touched by every pass: what tells write() the queue is being drained. */
	private const MARK = '.drained';

	private static ?string $rDir = null;

	/** The last file a pass took: the next pass goes on after it. */
	private static string $rCursor = '';

	/** @var (callable(array<string, mixed>, mixed, string, string, string, string): mixed)|null */
	private static $rEnforce;

	/** Tests: another queue directory and enforcer; null restores the defaults. */
	public static function useQueue(?string $rDir, ?callable $rEnforce = null): void {
		self::$rDir = $rDir;
		self::$rEnforce = $rEnforce;
		self::$rCursor = '';
		if ($rDir !== null) {
			self::mark(); // a queue that is being drained
		}
	}

	/** The queue is being drained as of now (every pass, and a test's queue). */
	private static function mark(): bool {
		$rDir = self::dir();
		return (is_dir($rDir) || @mkdir($rDir, 0750, true) || is_dir($rDir)) && @touch($rDir . self::MARK);
	}

	public static function dir(): string {
		return self::$rDir ?? ((defined('TMP_PATH') ? TMP_PATH : sys_get_temp_dir() . '/') . 'cluster_limits/');
	}

	/**
	 * Queue a node's check (EventIngest).
	 *
	 * @param array<string, mixed> $rData {uuid, ip, user_agent, user_id | hmac_id + hmac_identifier + max_connections}
	 */
	public static function queue(int $rServerID, array $rData): bool {
		$rUUID = $rData['uuid'] ?? null;
		if (!is_string($rUUID) || !preg_match(AgentConnections::CONN_UUID, $rUUID)) {
			return false;
		}
		$rCheck = ['server_id' => $rServerID, 'uuid' => $rUUID, 'ip' => substr((string) ($rData['ip'] ?? ''), 0, 64), 'user_agent' => substr((string) ($rData['user_agent'] ?? ''), 0, 512)];
		if (!empty($rData['user_id'])) {
			$rCheck['user_id'] = (int) $rData['user_id'];
		} elseif (!empty($rData['hmac_id'])) {
			$rCheck += ['hmac_id' => (int) $rData['hmac_id'], 'hmac_identifier' => substr((string) ($rData['hmac_identifier'] ?? ''), 0, 255), 'max_connections' => max(0, (int) ($rData['max_connections'] ?? 0))];
		} else {
			return false;
		}
		return self::write('l', $rCheck);
	}

	/**
	 * Queue the cut a conn_admit decided (ConnectionAdmission::forNode): the
	 * line cut to leave room for the viewer and the reservations in flight
	 * when the cut runs. Only MAIN writes this: queue(), which takes a node's
	 * event, rebuilds the check from its own keys and never sets `admission`.
	 *
	 * @param int $rExp When the viewer's reservation runs out (the admission's `exp`).
	 */
	public static function queueAdmission(int $rServerID, string $rUUID, int $rLineID, string $rIP, string $rUserAgent, int $rExp): bool {
		if (!preg_match(AgentConnections::CONN_UUID, $rUUID) || $rLineID <= 0) {
			return false;
		}
		return self::write('a', ['server_id' => $rServerID, 'uuid' => $rUUID, 'ip' => substr($rIP, 0, 64), 'user_agent' => substr($rUserAgent, 0, 512), 'user_id' => $rLineID, 'admission' => true, 'exp' => $rExp]);
	}

	/**
	 * One file per viewer and kind ('l': a node's check, 'a': an admission's
	 * cut), named by them: the viewer's next request replaces the check its last
	 * one queued, so the queue never holds more files than there are viewers.
	 *
	 * @param array<string, mixed> $rCheck
	 */
	private static function write(string $rKind, array $rCheck): bool {
		$rDir = self::dir();
		clearstatcache(true, $rDir . self::MARK);
		if ((int) @filemtime($rDir . self::MARK) < time() - self::IDLE) {
			return false; // nothing drains the queue: a check kept for later only fills tmp
		}
		return AtomicFile::write($rDir . $rKind . '-' . (int) $rCheck['server_id'] . '-' . $rCheck['uuid'] . '.json', (string) json_encode($rCheck));
	}

	/**
	 * Run queued checks (MAIN's loop), $rMax a pass. The files are taken in the
	 * order of their names, each pass going on where the last one stopped: with
	 * more checks than a pass runs, every one waits a round and none is passed
	 * over for those that sort first. A file older than MAX_AGE is removed unrun.
	 *
	 * @return int How many were enforced.
	 */
	public static function drain(int $rMax = 200): int {
		if (!self::mark()) {
			return 0;
		}
		$rDir = self::dir();
		// At most LIST names a pass: a directory left far larger by an older
		// release costs a pass no more time or memory than a full queue does.
		$rNames = [];
		$rHandle = @opendir($rDir);
		while ($rHandle !== false && count($rNames) < self::LIST && ($rName = readdir($rHandle)) !== false) {
			if ($rName[0] !== '.' && str_ends_with($rName, '.json')) {
				$rNames[] = $rName;
			}
		}
		if ($rHandle !== false) {
			closedir($rHandle);
		}
		sort($rNames, SORT_STRING);
		// From the name after the last one taken, round to it.
		$rCount = $rFrom = count($rNames);
		foreach ($rNames as $rIndex => $rName) {
			if (strcmp($rName, self::$rCursor) > 0) {
				$rFrom = $rIndex;
				break;
			}
		}

		$rOld = time() - self::MAX_AGE;
		$rDone = $rRun = $rSwept = 0;
		for ($i = 0; $i < $rCount; $i++) {
			if ($rRun >= $rMax || $rSwept >= self::SWEEP) {
				break;
			}
			$rName = $rNames[($rFrom + $i) % $rCount];
			$rFile = $rDir . $rName;
			self::$rCursor = $rName;
			$rTime = @filemtime($rFile);
			// Too old, or a file per request as releases up to 2.6.4 named them
			// (a backlog of those is cleared, not run).
			if (($rTime !== false && $rTime < $rOld) || ($rName[0] !== 'a' && $rName[0] !== 'l') || ($rName[1] ?? '') !== '-') {
				@unlink($rFile);
				$rSwept++;
				continue;
			}
			$rRun++;
			$rCheck = json_decode((string) @file_get_contents($rFile), true);
			@unlink($rFile);
			if (is_array($rCheck) && self::enforce($rCheck)) {
				$rDone++;
			}
		}
		return $rDone;
	}

	/** @param array<string, mixed> $rCheck */
	private static function enforce(array $rCheck): bool {
		if (($rCheck['admission'] ?? null) === true) {
			// A conn_admit's cut: the viewer may not be open yet. If it is, it
			// must be the node's, and it counts among the open ones.
			$rStored = self::stored((string) $rCheck['uuid']);
			if ($rStored !== null && (int) ($rStored['server_id'] ?? 0) !== (int) $rCheck['server_id']) {
				return false;
			}
			$rOpen = $rStored !== null && empty($rStored['hls_end']);
			return ConnectionAdmission::cut((bool) SettingsManager::get('redis_handler'), (int) $rCheck['user_id'], $rOpen, (string) $rCheck['ip'], (string) $rCheck['user_agent'], (string) $rCheck['uuid'], (int) ($rCheck['exp'] ?? 0));
		}
		$rOwner = self::owner((int) $rCheck['server_id'], (string) $rCheck['uuid']);
		if ($rOwner === null) {
			return false; // not that node's viewer (or gone already)
		}
		if (isset($rCheck['user_id'])) {
			if ((int) ($rOwner['user_id'] ?? 0) !== (int) $rCheck['user_id']) {
				return false;
			}
			self::db()->query('SELECT `id`, `max_connections`, `pair_id`, `is_restreamer` FROM `lines` WHERE `id` = ?;', (int) $rCheck['user_id']);
			if (self::db()->num_rows() !== 1) {
				return false;
			}
			$rLine = self::db()->get_row();
			$rUserInfo = ['id' => (int) $rLine['id'], 'max_connections' => (int) $rLine['max_connections'], 'pair_id' => $rLine['pair_id'] ?: null, 'is_restreamer' => (int) $rLine['is_restreamer']];
			$rHMAC = null;
			$rIdentifier = '';
		} else {
			if ((int) ($rOwner['hmac_id'] ?? 0) !== (int) $rCheck['hmac_id'] || (string) ($rOwner['hmac_identifier'] ?? '') !== (string) $rCheck['hmac_identifier']) {
				return false;
			}
			$rUserInfo = ['id' => null, 'max_connections' => (int) $rCheck['max_connections'], 'pair_id' => null, 'is_restreamer' => 0];
			$rHMAC = (int) $rCheck['hmac_id'];
			$rIdentifier = (string) $rCheck['hmac_identifier'];
		}
		if ($rUserInfo['max_connections'] <= 0) {
			return true; // unlimited
		}
		if (self::$rEnforce !== null) {
			(self::$rEnforce)($rUserInfo, $rHMAC, $rIdentifier, (string) $rCheck['ip'], (string) $rCheck['user_agent'], (string) $rCheck['uuid']);
			return true;
		}
		// ConnectionLimiter prefers closing the connections from the viewer's
		// own IP, which it reads from REMOTE_ADDR, as on the node.
		$rWas = $_SERVER['REMOTE_ADDR'] ?? null;
		$_SERVER['REMOTE_ADDR'] = (string) $rCheck['ip'];
		try {
			StreamAuth::validateConnections($rUserInfo, $rHMAC, $rIdentifier, (string) $rCheck['ip'], (string) $rCheck['user_agent'], (string) $rCheck['uuid']);
		} finally {
			if ($rWas === null) {
				unset($_SERVER['REMOTE_ADDR']);
			} else {
				$_SERVER['REMOTE_ADDR'] = $rWas;
			}
		}
		return true;
	}

	/**
	 * The viewer as MAIN's store holds it, when it is that node's.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function owner(int $rServerID, string $rUUID): ?array {
		if (SettingsManager::get('redis_handler')) {
			$rConnection = ConnectionTracker::getConnection($rUUID);
		} else {
			self::db()->query('SELECT `server_id`, `user_id`, `hmac_id`, `hmac_identifier` FROM `lines_live` WHERE `uuid` = ? AND `hls_end` = 0;', $rUUID);
			$rConnection = self::db()->num_rows() > 0 ? self::db()->get_row() : null;
		}
		return is_array($rConnection) && (int) ($rConnection['server_id'] ?? 0) === $rServerID ? $rConnection : null;
	}

	/**
	 * The viewer as MAIN's store holds it, open or ended, whichever node's.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function stored(string $rUUID): ?array {
		if (SettingsManager::get('redis_handler')) {
			$rConnection = ConnectionTracker::getConnection($rUUID);
			return is_array($rConnection) ? $rConnection : null;
		}
		self::db()->query('SELECT `server_id`, `hls_end` FROM `lines_live` WHERE `uuid` = ?;', $rUUID);
		return self::db()->num_rows() > 0 ? self::db()->get_row() : null;
	}
}
