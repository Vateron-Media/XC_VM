<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Logging\FileLogger;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Enrolled nodes (`cluster_nodes`): identity, state, mode and flow bits.
 *
 * States: `enrolling` (first token issued at install, waiting for
 * enrol_complete), `active`, `quarantined` (authenticated evidence of a clone;
 * the admin decides), `revoked`. Liveness (suspect/offline) is derived from
 * last_seen_at by NodeHealth, not stored.
 *
 * Revocation raises the extension's floor first (cluster_node_gen), so a node
 * whose row is restored from a backup still cannot open a session.
 */
final class NodeRegistry {
	use DatabaseAware;

	public const STATES = ['enrolling', 'active', 'quarantined', 'revoked'];

	/** Flow bits (plan, section 12): NodeFlows' (Core, so LBs have them too). */
	public const FLOW_TELEMETRY = NodeFlows::TELEMETRY;
	public const FLOW_COMMANDS = NodeFlows::COMMANDS;
	public const FLOW_LOGS = NodeFlows::LOGS;
	public const FLOW_STREAMS = NodeFlows::STREAMS;
	public const FLOW_CONTENT = NodeFlows::CONTENT;
	public const FLOW_CONFIG = NodeFlows::CONFIG;
	public const FLOW_CONNECTIONS = NodeFlows::CONNECTIONS;
	public const FLOW_DATAPLANE = NodeFlows::DATAPLANE;

	/** @return array<string, mixed>|null */
	public static function byUuid(string $rUuid): ?array {
		self::db()->query('SELECT * FROM `cluster_nodes` WHERE `node_uuid` = ?;', $rUuid);
		return self::db()->num_rows() > 0 ? self::db()->get_raw_row() : null;
	}

	/** @return array<string, mixed>|null */
	public static function byServer(int $rServerID): ?array {
		self::db()->query('SELECT * FROM `cluster_nodes` WHERE `server_id` = ?;', $rServerID);
		return self::db()->num_rows() > 0 ? self::db()->get_raw_row() : null;
	}

	/**
	 * Every node MAIN counts as enrolled (`active` or `quarantined`), by
	 * server id: what the fleet-wide gates and rotations go over.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function enrolled(): array {
		self::db()->query("SELECT * FROM `cluster_nodes` WHERE `state` IN ('active', 'quarantined') ORDER BY `server_id`;");
		$rOut = [];
		foreach (self::db()->get_raw_rows() as $rRow) {
			$rOut[(int) $rRow['server_id']] = $rRow;
		}
		return $rOut;
	}

	/**
	 * Create (or re-create, on re-enrolment) the row of a node that is about to
	 * receive its first token. A re-enrolment increments gen, so every token of
	 * the previous generation stops working, drops what the cluster bus still
	 * holds of it (its heartbeats, and the row and epochs its requests were
	 * authenticated with), and announces the new key to the other nodes
	 * (ReplicaBuilder::nodesChanged). $rFlows are the flow bits it starts
	 * with (none, but for a node enrolled straight into mode 2).
	 *
	 * @return array{gen: int} The generation the first token must carry.
	 */
	public static function startEnrolment(int $rServerID, string $rUuid, string $rSignPub, string $rBoxPub, int $rMode, ?ClusterCrypto $rCrypto = null, int $rFlows = 0): array {
		$rNow = ClusterClock::now();
		$rExisting = self::byServer($rServerID);
		$rGen = $rExisting ? (int) $rExisting['gen'] + 1 : 1;
		if ($rExisting && $rCrypto instanceof \XcVm\Core\Cluster\Crypto\ClusterCrypto) {
			$rCrypto->nodeGen((string) $rExisting['node_uuid'], $rGen);
		}
		self::db()->query('DELETE FROM `cluster_node_epochs` WHERE `server_id` = ?;', $rServerID);
		self::db()->query('DELETE FROM `cluster_nodes` WHERE `server_id` = ?;', $rServerID);
		self::db()->query(
			'INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`, `state`, `mode`, `flows`, `gen`, `node_sign_pub`, `node_box_pub`, `epoch`, `enrol_deadline`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);',
			$rServerID,
			$rUuid,
			'enrolling',
			$rMode,
			$rFlows,
			$rGen,
			$rSignPub,
			$rBoxPub,
			0,
			$rNow + 1800,
			$rNow,
			$rNow
		);
		// A re-enrolment in mode 2 keeps MAIN's record of a revoked grant: the
		// grant stays revoked, and DbCredentials::credentialFree() still reads it.
		if ($rExisting && $rMode === 2 && !empty($rExisting['db_revoked_at'])) {
			self::db()->query('UPDATE `cluster_nodes` SET `db_revoked_at` = ? WHERE `server_id` = ?;', (int) $rExisting['db_revoked_at'], $rServerID);
		}
		NodeAuthCache::forget($rServerID);
		HeartbeatService::forget($rServerID);
		if ($rExisting && $rCrypto instanceof \XcVm\Core\Cluster\Crypto\ClusterCrypto) {
			ReplicaBuilder::nodesChanged($rCrypto, $rServerID);
		}
		return ['gen' => $rGen];
	}

	/**
	 * Write a node's columns. Then the node's next request reads its row
	 * from MySQL again (NodeAuthCache::forget()), unless only columns the
	 * cluster bus's copy may lag on were written (a heartbeat's, without the
	 * bus's flusher).
	 *
	 * With $rWhere (column => value), only a row that still holds those
	 * values is written, and the answer says whether it was: a change that
	 * must not overwrite one made meanwhile (a revocation, or a re-enrolment's
	 * new row). Otherwise the answer is true.
	 *
	 * @param array<string, mixed> $rFields
	 * @param array<string, int|string> $rWhere
	 */
	public static function update(int $rServerID, array $rFields, array $rWhere = []): bool {
		if ($rFields === []) {
			return true;
		}
		$rFields['updated_at'] = ClusterClock::now();
		$rSet = implode(', ', array_map(static fn($rKey) => '`' . $rKey . '` = ?', array_keys($rFields)));
		if ($rWhere === []) {
			self::db()->query('UPDATE `cluster_nodes` SET ' . $rSet . ' WHERE `server_id` = ?;', ...array_values($rFields), ...[$rServerID]);
			$rWritten = true;
		} else {
			$rCond = implode('', array_map(static fn($rKey) => ' AND `' . $rKey . '` = ?', array_keys($rWhere)));
			$rWritten = self::db()->query('UPDATE `cluster_nodes` SET ' . $rSet . ' WHERE `server_id` = ?' . $rCond . ';', ...array_values($rFields), ...[$rServerID], ...array_values($rWhere)) !== false && self::db()->num_rows() > 0;
		}
		if (NodeAuthCache::changes(array_keys($rFields))) {
			NodeAuthCache::forget($rServerID);
		}
		return $rWritten;
	}

	/**
	 * Do all the active nodes report a feature (their agent's `features` at
	 * hello)? True with no active node at all: there is nobody to lose.
	 */
	public static function allActiveHaveFeature(string $rFeature): bool {
		self::db()->query("SELECT `features` FROM `cluster_nodes` WHERE `state` = 'active';");
		foreach (self::db()->get_rows() ?: [] as $rRow) {
			if (!in_array($rFeature, explode(',', (string) ($rRow['features'] ?? '')), true)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Revoke a node: raise the extension's floor to the next generation, drop
	 * its epochs and the heartbeats the cluster bus holds of it, and mark the
	 * row (which drops the bus's copy of it, update()). Every later request
	 * gets a signed NODE_REVOKED, and the other nodes are told at once
	 * (ReplicaBuilder::nodesChanged).
	 */
	public static function revoke(int $rServerID, ClusterCrypto $rCrypto, string $rActor = 'admin'): bool {
		$rNode = self::byServer($rServerID);
		if ($rNode === null) {
			return false;
		}
		$rGen = (int) $rNode['gen'] + 1;
		$rCrypto->nodeGen((string) $rNode['node_uuid'], $rGen);
		self::db()->query('DELETE FROM `cluster_node_epochs` WHERE `server_id` = ?;', $rServerID);
		self::update($rServerID, ['state' => 'revoked', 'gen' => $rGen]);
		HeartbeatService::forget($rServerID);
		ClusterAudit::log('node.revoke', $rServerID, ['node' => $rNode['node_uuid'], 'gen' => $rGen], $rActor);
		ReplicaBuilder::nodesChanged($rCrypto, $rServerID);
		return true;
	}

	/**
	 * Its server was deleted: the node, if it has one, is revoked, so an
	 * agent still running there is refused (NODE_REVOKED) rather than handed
	 * tokens, leases and commands. Its unused enrolment code and a request
	 * still waiting for approval go too: they could only enrol a node for a
	 * server that is gone. Never throws: the delete stands.
	 */
	public static function serverDeleted(int $rServerID, ?ClusterCrypto $rCrypto = null): bool {
		try {
			$rNode = self::byServer($rServerID);
			$rRevoked = $rNode !== null && $rNode['state'] !== 'revoked' && self::revoke($rServerID, $rCrypto ?? ClusterCryptoFactory::create(), 'admin');
			self::db()->query("DELETE FROM `cluster_enrol_requests` WHERE `server_id` = ? AND `state` = 'pending_approval';", $rServerID);
			self::db()->query('DELETE FROM `cluster_enrol_codes` WHERE `server_id` = ? AND `used_at` IS NULL;', $rServerID);
			return $rRevoked;
		} catch (\Throwable $rE) {
			FileLogger::log('cluster', 'Server ' . $rServerID . ' deleted, its cluster node or enrolment not cleaned up', $rE->getMessage());
			return false;
		}
	}

	/** Flows a node may have, given the dependency rules (CONNECTIONS needs COMMANDS+STREAMS; DATAPLANE needs STREAMS+CONTENT). */
	public static function validFlows(int $rFlows): bool {
		if (($rFlows & self::FLOW_CONNECTIONS) && (($rFlows & (self::FLOW_COMMANDS | self::FLOW_STREAMS)) !== (self::FLOW_COMMANDS | self::FLOW_STREAMS))) {
			return false;
		}
		if (($rFlows & self::FLOW_DATAPLANE) && (($rFlows & (self::FLOW_STREAMS | self::FLOW_CONTENT)) !== (self::FLOW_STREAMS | self::FLOW_CONTENT))) {
			return false;
		}
		return $rFlows >= 0 && $rFlows <= 255;
	}
}
