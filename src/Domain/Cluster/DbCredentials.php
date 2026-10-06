<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\NodeActions;
use XcVm\Core\Cluster\NodeCredentials;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * MAIN's half of taking its database credentials off a load balancer (plan,
 * section 10, step 3; Phase 9): the node drops them (`node.root
 * strip_db_credentials`, or a credential-free `node.root install_config`),
 * then MAIN revokes the node's grant (`\XC_VM::db_revoke`) and records when
 * (`cluster_nodes.db_revoked_at`, migration 052).
 *
 * Stripping and revoking is the point of no return for a node (plan, section
 * 12), so strip() sends the command only to a node already in mode 2 — one that
 * reaches MAIN through the cluster API alone, and has done so for
 * ClusterAdmin::CUTOVER_CLEAN_DAYS. The revoke waits for the node's own word: the command's ack,
 * whose result carries the extension's report of the config afterwards
 * (NodeCredentials::outcome()). A config that still holds credentials, an ack
 * that only says root queued it, or a failed command revokes nothing.
 *
 * `api_mode_allowed` is not touched here: whether new nodes install in API
 * mode is the operator's cutover decision.
 *
 * @package XC_VM_Domain_Cluster
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class DbCredentials {
	use DatabaseAware;

	/** @var \Closure(string): bool|null Tests: `db_revoke`. */
	private static ?\Closure $rRevoke = null;

	/** Tests: revoke through $rRevoke; null restores `\XC_VM::db_revoke`. */
	public static function useRevoke(?\Closure $rRevoke): void {
		self::$rRevoke = $rRevoke;
	}

	/**
	 * How long a queued strip waits for its node, in seconds. The node is
	 * judged when the command is queued (strip()), so it does not wait the
	 * day another root command does: a node that is away when it is asked is
	 * asked again. One the node was handed keeps its row a day past its exp,
	 * for its ack (CommandBus::prune()).
	 */
	public const STRIP_TTL = 600;

	/**
	 * Ask a node in mode 2 to drop MAIN's credentials (the Cluster Nodes
	 * page's action, `cluster:strip-credentials`). Null when the signed
	 * command was queued, else the message key of why nothing was sent:
	 * `cluster_not_enrolled`, `cluster_strip_needs_mode2`,
	 * `cluster_strip_not_active`, `cluster_strip_too_soon`,
	 * `cluster_mode_needs_flows`, `cluster_strip_not_heard`,
	 * `cluster_strip_not_local`, `cluster_strip_not_queued`. Audited either
	 * way it reached the node's row (`node.strip_credentials`).
	 *
	 * This is the step with no way back, so the days stand here: a node moved
	 * to mode 2 by the page (ClusterAdmin::act() records when) has spent
	 * ClusterAdmin::CUTOVER_CLEAN_DAYS there, in which whatever on it still
	 * asked for MAIN's database was refused and shown. A node with no such
	 * record (born in mode 2, or moved before the record was kept) passes as
	 * before. And the node runs that way now, as the move to mode 2 asked
	 * (ClusterAdmin::modeGate): every flow on, heard a moment ago (its report
	 * is only as old as its last heartbeat), and saying that it reads its
	 * streams on itself. One that says it does not, or says nothing (a node
	 * with STREAMS off says nothing), is not asked: mode_down is how it heals,
	 * and that needs these credentials.
	 */
	public static function strip(int $rServerID, string $rActor = 'admin'): ?string {
		$rNode = NodeRegistry::byServer($rServerID);
		if ($rNode === null) {
			return 'cluster_not_enrolled';
		}
		if ((int) $rNode['mode'] !== 2) {
			return 'cluster_strip_needs_mode2';
		}
		if ($rNode['state'] !== 'active') {
			return 'cluster_strip_not_active';
		}
		$rSince = (int) ClusterMeta::get(ClusterAdmin::MODE2_AT . $rServerID);
		if ($rSince > 0 && ClusterClock::now() - $rSince < ClusterAdmin::CUTOVER_CLEAN_DAYS * 86400) {
			return 'cluster_strip_too_soon';
		}
		if (((int) ($rNode['flows'] ?? 0) & ClusterAdmin::MODE2_FLOWS) !== ClusterAdmin::MODE2_FLOWS) {
			return 'cluster_mode_needs_flows';
		}
		// As ClusterAdmin::act() reads it: MySQL's copy may be a flush behind the bus's.
		$rLastSeen = HeartbeatService::freshest($rNode['last_seen_at'] ?? null, HeartbeatService::lastSeen()[$rServerID] ?? null);
		if ($rLastSeen === null || ClusterClock::nowMs() - $rLastSeen > NodeHealth::SUSPECT_AFTER_MS) {
			return 'cluster_strip_not_heard';
		}
		if (NodeAudit::streamsLocal(NodeAudit::reports()[$rServerID] ?? null) !== true) {
			return 'cluster_strip_not_local';
		}
		$rQueued = NodeActions::stripDbCredentials($rServerID);
		ClusterAudit::log('node.strip_credentials', $rServerID, ['queued' => $rQueued], $rActor);
		return $rQueued ? null : 'cluster_strip_not_queued';
	}

	/**
	 * Withdraw the strip a node has not acked (ClusterAdmin::act(), when the
	 * node leaves mode 2): it was judged in mode 2, and below it the node
	 * needs these credentials. Its life ends here, so it goes out no more: a
	 * node moved back to mode 2 is not handed it, and below mode 2
	 * CommandBus::pending() hands out no strip at all. One the node was not
	 * handed goes at the next CommandBus::prune(). One it was handed, or is
	 * being handed as it is moved, keeps its row: the node may have run it,
	 * and its ack says what its config holds (acked()). Root on the node runs
	 * it only while the node is still in mode 2 (NodeCredentials::run). Never
	 * throws: the move down stands.
	 */
	public static function cancelStrip(int $rServerID): void {
		try {
			// Ended, not deleted: a hand-out that read the row a moment ago still marks it, and
			// CommandBus::prune() keeps a strip that was handed out a day past its exp, for its ack.
			self::db()->query("UPDATE `cluster_commands` SET `exp` = ? WHERE `server_id` = ? AND `type` = 'node.root' AND `action` = ? AND `state` IN ('queued', 'delivered') AND `exp` > ?;", ClusterClock::now(), $rServerID, NodeCredentials::STRIP, ClusterClock::now());
		} catch (\Throwable) {
			// No command table: nothing was queued.
		}
	}

	/**
	 * Send the node a config MAIN packed for it (`node.root install_config`):
	 * credential-free (`$rCredentials` false: a node in mode 2 that is to hold
	 * none of MAIN's credentials) or with them (the plan's rollback from mode 2,
	 * before `db_grant` and mode 0). Packed for the node's recorded install_id
	 * (CorePins) with `XC_VM::config_pack`, which the node's extension verifies
	 * before it writes anything. Null when queued, else the message key of why
	 * not.
	 *
	 * @param (callable(string, array<string, mixed>): (string|false))|null $rPack tests: `config_pack`
	 */
	public static function installConfig(int $rServerID, bool $rCredentials, string $rActor = 'admin', ?callable $rPack = null): ?string {
		$rNode = NodeRegistry::byServer($rServerID);
		if ($rNode === null || $rNode['state'] !== 'active') {
			return 'cluster_not_enrolled';
		}
		if (!$rCredentials && (int) $rNode['mode'] !== 2) {
			return 'cluster_strip_needs_mode2';
		}
		$rID = CorePins::installId($rServerID);
		if ($rID === null) {
			return 'cluster_config_needs_install_id';
		}
		self::db()->query('SELECT `server_ip` FROM `servers` WHERE `id` = ?;', (int) SERVER_ID);
		$rMainIP = self::db()->num_rows() > 0 ? (string) self::db()->get_row()['server_ip'] : '';
		if ($rMainIP === '') {
			return 'cluster_config_not_queued';
		}
		// As LbInstallFlow::configPackParams: MAIN's address, the node's slot.
		$rParams = ['hostname' => $rMainIP, 'database' => 'xc_vm', 'server_id' => $rServerID, 'is_lb' => 1, 'db_credentials' => $rCredentials];
		if ($rPack === null) {
			if (!class_exists('XC_VM') || !method_exists('XC_VM', 'install_config')) {
				// An extension that has install_config also packs db_credentials.
				return 'cluster_config_no_extension';
			}
			$rPack = static fn(string $rTarget, array $rP): string|false => @\XC_VM::config_pack($rTarget, $rP);
		}
		$rBlob = $rPack($rID, $rParams);
		if (!is_string($rBlob) || $rBlob === '') {
			return 'cluster_config_not_queued';
		}
		$rQueued = NodeActions::installConfig($rServerID, $rBlob);
		ClusterAudit::log('node.install_config', $rServerID, ['credentials' => $rCredentials, 'queued' => $rQueued], $rActor);
		return $rQueued ? null : 'cluster_config_not_queued';
	}

	/**
	 * Must this load balancer hold none of MAIN's DB credentials? True for a
	 * cluster node in mode 2 (it reaches MAIN through the cluster API alone:
	 * installed in API mode, or promoted) and for one whose grant MAIN revoked.
	 * Such a node is never granted again, and an SSH reinstall keeps it
	 * credential-free (LbInstallFlow::installsInApiMode). False without the
	 * cluster tables.
	 */
	public static function credentialFree(int $rServerID): bool {
		try {
			self::db()->query('SELECT `mode` FROM `cluster_nodes` WHERE `server_id` = ?;', $rServerID);
			$rNode = self::db()->num_rows() > 0 ? self::db()->get_row() : null;
		} catch (\Throwable) {
			return false;
		}
		if ($rNode === null) {
			return false;
		}
		return (int) $rNode['mode'] === 2 || self::revokedAt($rServerID) !== null;
	}

	/**
	 * Is $rHost the address of a load balancer that must hold no credentials
	 * (credentialFree())? A grant is per host, so one such node on it is
	 * enough: BackupService::grantPrivileges() then grants nothing.
	 */
	public static function credentialFreeHost(string $rHost): bool {
		try {
			self::db()->query('SELECT `id` FROM `servers` WHERE `server_ip` = ? AND `server_type` = 0;', $rHost);
			$rIDs = array_map(static fn(array $rRow): int => (int) $rRow['id'], self::db()->get_rows() ?: []);
		} catch (\Throwable) {
			return false;
		}
		foreach ($rIDs as $rServerID) {
			if (self::credentialFree($rServerID)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * When MAIN revoked the node's grant (`cluster_nodes.db_revoked_at`), or
	 * null. A table from before migration 052 reads as never.
	 */
	public static function revokedAt(int $rServerID): ?int {
		try {
			self::db()->query('SELECT `db_revoked_at` FROM `cluster_nodes` WHERE `server_id` = ?;', $rServerID);
			$rAt = self::db()->num_rows() > 0 ? self::db()->get_row()['db_revoked_at'] : null;
		} catch (\Throwable) {
			return null;
		}
		return $rAt === null ? null : (int) $rAt;
	}

	/**
	 * A `node.root` command's first ack (ClusterApi). For a credential action
	 * that succeeded and left the node's config without credentials, revoke
	 * the node's grant. True when it revoked.
	 */
	public static function acked(int $rServerID, string $rCmdID, bool $rOk, string $rResult): bool {
		if (!$rOk) {
			return false;
		}
		self::db()->query('SELECT `type`, `payload` FROM `cluster_commands` WHERE `server_id` = ? AND `cmd_id` = ?;', $rServerID, $rCmdID);
		$rRow = self::db()->num_rows() > 0 ? self::db()->get_raw_row() : null;
		$rDoc = $rRow !== null && $rRow['type'] === 'node.root' ? json_decode((string) $rRow['payload'], true) : null;
		if (!is_array($rDoc) || !in_array($rDoc['action'] ?? null, [NodeCredentials::STRIP, NodeCredentials::INSTALL], true)) {
			return false;
		}
		$rConfig = NodeCredentials::outcome($rResult);
		if ($rConfig === null || ($rConfig['db_credentials'] ?? true) !== false) {
			return false;
		}
		return self::revoke($rServerID);
	}

	/**
	 * Revoke the node's grant and record it. The extension refuses a host MAIN's
	 * own connection may use (loopback, its DB host), so a misconfigured row
	 * cannot take MAIN off its database.
	 */
	public static function revoke(int $rServerID): bool {
		self::db()->query('SELECT `server_ip` FROM `servers` WHERE `id` = ?;', $rServerID);
		$rIP = self::db()->num_rows() > 0 ? (string) self::db()->get_row()['server_ip'] : '';
		if ($rIP === '') {
			return false;
		}
		$rRevoke = self::$rRevoke ?? (class_exists('XC_VM') && method_exists('XC_VM', 'db_revoke') ? static fn(string $rHost): bool => (bool) \XC_VM::db_revoke($rHost) : null);
		$rDone = $rRevoke !== null && $rRevoke($rIP);
		if (!$rDone) {
			ClusterAudit::log('node.db_revoke_failed', $rServerID, ['ip' => $rIP], 'system');
			return false;
		}
		NodeRegistry::update($rServerID, ['db_revoked_at' => ClusterClock::now()]);
		ClusterAudit::log('node.db_revoked', $rServerID, ['ip' => $rIP], 'system');
		return true;
	}
}
