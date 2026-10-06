<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\NodeCredentials;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * MAIN → node commands (plan, section 7, Phase 4). A command is a typed JSON
 * record, never shell, signed by the panel with tag `cmd`:
 *
 * ```text
 * {"v":1, "type", ["action",] "exp", "iat", "cmd_id", "seq", "node_uuid", "gen", "dedupe_key", "args"}
 * ```
 *
 * `action` is there only for ACTION_TYPES, at the top level: callers pass
 * it among the arguments ({action, …}, as NodeRpc and NodeActions carry it)
 * and enqueue() lifts it out of `args`. The extension derives the class from
 * `type` (and `node.root`'s action), and refuses any other shape: restrictive
 * ones (kills, stops) sign without a licence, granting ones need it. Its
 * registry is tests/Support/cluster_commands.json. Commands queue in
 * `cluster_commands`, FIFO per node by `seq`, and reach the node's agent
 * through the `commands` long-poll; the agent checks the signature, its own
 * uuid and generation, `seq` above its high-water and `exp`, runs the
 * command and `ack`s it with the result. Delivery is at least once.
 *
 * Only nodes with the COMMANDS flow on get commands; the others keep the
 * legacy transport (NodeRpc's HTTP, the signals table).
 */
final class CommandBus {
	use DatabaseAware;

	/** Lifetime of a command by type prefix (seconds). */
	public const TTL = ['conn.' => 300, 'node.root' => 86400, 'node.cache' => 86400, 'node.purge' => 86400, 'node.fence' => 3600, 'node.unfence' => 3600, 'node.quarantine' => 3600, 'artefact.' => 3600, 'default' => 600];

	/** Types MAIN sends today. */
	public const TYPES = [
		'node.rpc', 'node.root', 'node.cache', 'conn.kill_worker', 'conn.drop', 'conn.close', 'config.changed', 'artefact.fetch', 'token.rotate_now',
		'stream.stop', 'vod.stop', 'stream.start', 'vod.start', 'node.fence', 'node.unfence', 'node.quarantine', 'resync', 'policy.update', 'stream.assign', 'queue.poke', 'node.purge',
	];

	/**
	 * Types that are restrictive (always signable). The extension decides
	 * (the `class` column is its answer, ClusterCrypto::recordClass); this
	 * list is informational, and CommandBusRegistryTest holds it to the
	 * extension's registry.
	 */
	public const RESTRICTIVE = ['conn.drop', 'conn.drop_line', 'conn.kill_worker', 'conn.close', 'stream.stop', 'vod.stop', 'token.rotate_now', 'node.quarantine', 'node.fence', 'resync', 'config.changed', 'node.purge'];

	/** Types that name what they run in a top-level `action`, never among their arguments. */
	public const ACTION_TYPES = ['node.rpc', 'node.root'];

	/** Tries at a free `seq` before enqueue() gives up. */
	public const ATTEMPTS = 4;

	/** Largest result stored from an ack. */
	public const MAX_RESULT = 65536;

	/**
	 * Is this node taking commands over the cluster API?
	 *
	 * @param array<string, mixed>|null $rNode cluster_nodes row
	 */
	public static function accepts(?array $rNode): bool {
		return $rNode !== null && $rNode['state'] === 'active' && (int) $rNode['mode'] >= 1 && ((int) $rNode['flows'] & NodeRegistry::FLOW_COMMANDS) !== 0;
	}

	/**
	 * Does the node take root commands too? Only once its root-owned pin of
	 * the panel key is in place (cluster:root refuses without it).
	 *
	 * @param array<string, mixed>|null $rNode
	 */
	public static function acceptsRoot(?array $rNode): bool {
		return self::accepts($rNode) && !empty($rNode['root_ready']);
	}

	/**
	 * Sign and queue a command. Returns its cmd_id. An artefact grant the
	 * command carries (`args.artefact`, ArtefactGrants) expires with it.
	 * Throws when the extension refuses to sign it, and when no row could be
	 * stored after ATTEMPTS tries (a concurrent enqueue taking the seq, or
	 * the database refusing the write): nothing is then queued or woken.
	 *
	 * @param array<string, mixed> $rArgs {action, …} for ACTION_TYPES
	 */
	public static function enqueue(ClusterCrypto $rCrypto, int $rServerID, string $rType, array $rArgs, ?string $rDedupeKey = null, ?int $rTtl = null): string {
		if (!in_array($rType, self::TYPES, true)) {
			throw new \InvalidArgumentException('Unknown command type: ' . $rType);
		}
		$rAction = null;
		if (in_array($rType, self::ACTION_TYPES, true)) {
			$rAction = $rArgs['action'] ?? null;
			if (!is_string($rAction) || $rAction === '') {
				throw new \InvalidArgumentException('A ' . $rType . ' command needs an action');
			}
			unset($rArgs['action']);
		}
		$rNode = NodeRegistry::byServer($rServerID);
		if ($rNode === null) {
			throw new \InvalidArgumentException('Server ' . $rServerID . ' is not an enrolled node');
		}
		$rNow = ClusterClock::now();
		$rTtl ??= self::ttl($rType);
		$rCmdID = bin2hex(random_bytes(16));
		if (is_array($rArgs['artefact'] ?? null)) {
			$rArgs['artefact']['exp'] = $rNow + $rTtl;
		}
		$rError = null;
		for ($rAttempt = 1; $rAttempt <= self::ATTEMPTS; $rAttempt++) {
			self::db()->query('SELECT MAX(`seq`) AS `seq` FROM `cluster_commands` WHERE `server_id` = ?;', $rServerID);
			$rSeq = max((int) (self::db()->get_row()['seq'] ?? 0), (int) $rNode['cmd_seq']) + 1;
			$rDoc = (string) json_encode(self::envelope($rType, $rAction, [
				'exp' => $rNow + $rTtl, 'iat' => $rNow, 'cmd_id' => $rCmdID, 'seq' => $rSeq,
				'node_uuid' => (string) $rNode['node_uuid'], 'gen' => (int) $rNode['gen'], 'dedupe_key' => $rDedupeKey, 'args' => (object) $rArgs,
			]), JSON_UNESCAPED_SLASHES);
			$rSig = $rCrypto->sign('cmd', $rDoc);
			$rClass = $rCrypto->recordClass('cmd', $rDoc);
			if ($rDedupeKey !== null) {
				// A newer desired state supersedes a command not yet acked. An
				// acked one keeps its outcome (result()), but not the key, which
				// UNIQUE(server_id, dedupe_key) would otherwise refuse this one.
				self::db()->query("DELETE FROM `cluster_commands` WHERE `server_id` = ? AND `dedupe_key` = ? AND `state` <> 'acked';", $rServerID, $rDedupeKey);
				self::db()->query("UPDATE `cluster_commands` SET `dedupe_key` = NULL WHERE `server_id` = ? AND `dedupe_key` = ? AND `state` = 'acked';", $rServerID, $rDedupeKey);
			}
			// The panel's Database answers a refused write (the unique seq or
			// dedupe key a concurrent enqueue took, a lost connection) with
			// false; another handle throws. Either way the row is not there.
			try {
				$rStored = self::db()->query(
					'INSERT INTO `cluster_commands` (`server_id`, `seq`, `cmd_id`, `type`, `action`, `class`, `dedupe_key`, `payload`, `sig`, `state`, `created_at`, `exp`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);',
					$rServerID,
					$rSeq,
					$rCmdID,
					$rType,
					$rAction !== null ? substr($rAction, 0, 32) : null,
					$rClass === 'R' ? 'R' : 'G',
					$rDedupeKey,
					$rDoc,
					$rSig,
					'queued',
					$rNow,
					$rNow + $rTtl
				) !== false;
			} catch (\Throwable $rE) {
				$rStored = false;
				$rError = $rE;
			}
			if ($rStored) {
				ClusterBus::wakeNode($rServerID);
				return $rCmdID;
			}
		}
		throw new \RuntimeException('Command ' . $rType . ' for server ' . $rServerID . ' not stored after ' . self::ATTEMPTS . ' tries', 0, $rError);
	}

	/**
	 * A command's document in the extension's shape: `action`, for
	 * ACTION_TYPES only, right after `type`.
	 *
	 * @param array<string, mixed> $rRest the other envelope keys, in order
	 * @return array<string, mixed>
	 */
	private static function envelope(string $rType, ?string $rAction, array $rRest): array {
		$rDoc = ['v' => 1, 'type' => $rType];
		if ($rAction !== null) {
			$rDoc['action'] = $rAction;
		}
		return $rDoc + $rRest;
	}

	/**
	 * Is a command under this dedupe key still waiting for the node (queued
	 * or delivered, not expired)? The lease-fence path asks before it queues
	 * another, so a refusal answered every heartbeat queues one fence.
	 */
	public static function waiting(int $rServerID, string $rDedupeKey): bool {
		self::db()->query("SELECT `id` FROM `cluster_commands` WHERE `server_id` = ? AND `dedupe_key` = ? AND `state` IN ('queued', 'delivered') AND `exp` > ? LIMIT 1;", $rServerID, $rDedupeKey, ClusterClock::now());
		return self::db()->num_rows() > 0;
	}

	/**
	 * Commands for a node after its high-water, oldest first; marks them
	 * delivered. $rRestrictiveOnly hands out only the restrictive ones (a
	 * quarantined node: kills, stops and fences still reach it, nothing that
	 * grants). What granted and was queued before the quarantine was ended
	 * by it (endGranting()), so Trust again hands none of it out.
	 * A credential strip is judged once more as it is handed out
	 * (DbCredentials::strip() judged it as it was queued): it goes out only
	 * while the node's row says mode 2, since below it the node needs those
	 * credentials. Its row stays for an ack.
	 *
	 * @return list<array{doc: string, sig: string, seq: int}>
	 */
	public static function pending(int $rServerID, int $rAfterSeq, int $rLimit = 50, bool $rRestrictiveOnly = false): array {
		self::db()->query(
			"SELECT `id`, `seq`, `payload`, `sig` FROM `cluster_commands` WHERE `server_id` = ? AND `seq` > ? AND `state` IN ('queued', 'delivered') AND `exp` > ?" . ($rRestrictiveOnly ? " AND `class` = 'R'" : '')
			. " AND NOT (`type` = 'node.root' AND `action` = ? AND NOT EXISTS (SELECT 1 FROM `cluster_nodes` WHERE `cluster_nodes`.`server_id` = `cluster_commands`.`server_id` AND `cluster_nodes`.`mode` = 2))"
			. ' ORDER BY `seq` ASC LIMIT ' . max(1, min(200, $rLimit)) . ';',
			$rServerID,
			$rAfterSeq,
			ClusterClock::now(),
			NodeCredentials::STRIP
		);
		$rRows = self::db()->get_raw_rows();
		$rOut = [];
		$rIDs = [];
		foreach ($rRows as $rRow) {
			$rOut[] = ['doc' => (string) $rRow['payload'], 'sig' => Enc::b64url((string) $rRow['sig']), 'seq' => (int) $rRow['seq']];
			$rIDs[] = (int) $rRow['id'];
		}
		if ($rIDs !== []) {
			self::db()->query("UPDATE `cluster_commands` SET `state` = 'delivered', `delivered_at` = ? WHERE `state` = 'queued' AND `id` IN (" . implode(',', $rIDs) . ');', ClusterClock::now());
		}
		return $rOut;
	}

	/**
	 * End every granting command of a node that is not acked, handed out or
	 * not, as the node is quarantined (ADR 0004, "Granting commands across a
	 * quarantine"): MAIN no longer knows who holds the node's keys, so what
	 * was decided before is decided again after Trust again, not replayed.
	 * Its exp becomes now, as a mode down ends a strip
	 * (DbCredentials::cancelStrip()): it is handed out no more, its artefact
	 * grant ends with it (ArtefactGrants::live()), and an ack is taken until
	 * the next prune(), which keeps a strip that was handed out a day.
	 * Restrictive commands keep their life. Answers, for the quarantine's
	 * audit line, how many were ended and which (nothing when none was).
	 * Never throws: the quarantine stands.
	 *
	 * @return array{ended?: int, commands?: list<array{type: string, action: ?string, cmd_id: string, handed_out: bool}>}
	 */
	public static function endGranting(int $rServerID): array {
		try {
			$rNow = ClusterClock::now();
			self::db()->query("SELECT `cmd_id`, `type`, `action`, `state` FROM `cluster_commands` WHERE `server_id` = ? AND `class` <> 'R' AND `state` IN ('queued', 'delivered') AND `exp` > ? ORDER BY `seq` ASC;", $rServerID, $rNow);
			$rEnded = array_map(static fn(array $rRow): array => [
				'type' => (string) $rRow['type'], 'action' => $rRow['action'] === null ? null : (string) $rRow['action'], 'cmd_id' => (string) $rRow['cmd_id'], 'handed_out' => $rRow['state'] === 'delivered',
			], self::db()->get_raw_rows());
			if ($rEnded === []) {
				return [];
			}
			self::db()->query("UPDATE `cluster_commands` SET `exp` = ? WHERE `server_id` = ? AND `class` <> 'R' AND `state` IN ('queued', 'delivered') AND `exp` > ?;", $rNow, $rServerID, $rNow);
			// The count first: the audit keeps 4000 characters, about 45 named commands.
			return ['ended' => count($rEnded), 'commands' => $rEnded];
		} catch (\Throwable) {
			return []; // no command table: nothing was queued
		}
	}

	/**
	 * A node's pending restrictive commands (kills, drops, closes, stops),
	 * oldest first, for the hard revocation mode's denial: the extension
	 * refuses the node's session without a licence, so these travel in the
	 * panel-signed LICENCE_INVALID instead (plan section 4). Read only: the
	 * request that gets them is not authenticated, so nothing is marked
	 * delivered. Each keeps its own `cmd` signature, and the agent checks it,
	 * its uuid, generation, seq above its high-water and expiry as on the
	 * long-poll, but does not raise that high-water for them: it keeps their
	 * cmd_ids until they expire instead. So once the licence is back, the
	 * long-poll still hands out a granting command queued before them, and a
	 * kill it hands out again is acked with its result, not run twice.
	 *
	 * @return list<array{doc: string, sig: string, seq: int}>
	 */
	public static function restrictive(int $rServerID, int $rLimit = 50): array {
		self::db()->query(
			"SELECT `seq`, `payload`, `sig` FROM `cluster_commands` WHERE `server_id` = ? AND `class` = 'R' AND `state` IN ('queued', 'delivered') AND `exp` > ? ORDER BY `seq` ASC LIMIT " . max(1, min(200, $rLimit)) . ';',
			$rServerID,
			ClusterClock::now()
		);
		return array_map(static fn($rRow) => ['doc' => (string) $rRow['payload'], 'sig' => Enc::b64url((string) $rRow['sig']), 'seq' => (int) $rRow['seq']], self::db()->get_raw_rows());
	}

	/**
	 * A node's acknowledgement: only for its own commands. Raises its
	 * high-water (cmd_seq) so a restored queue is not replayed. $rFirst says
	 * whether this ack recorded the outcome (false for a repeated one), and
	 * $rType the command's type.
	 *
	 * @param-out bool $rFirst
	 * @param-out string $rType
	 */
	public static function ack(int $rServerID, string $rCmdID, bool $rOk, string $rResult, ?bool &$rFirst = null, ?string &$rType = null): bool {
		$rFirst = false;
		$rType = '';
		self::db()->query('SELECT `seq`, `state`, `type` FROM `cluster_commands` WHERE `server_id` = ? AND `cmd_id` = ?;', $rServerID, $rCmdID);
		$rRow = self::db()->num_rows() > 0 ? self::db()->get_row() : null;
		if ($rRow === null) {
			return false;
		}
		$rType = (string) $rRow['type'];
		if (in_array($rRow['state'], ['acked', 'failed'], true)) {
			return true; // a repeated ack
		}
		$rFirst = true;
		self::db()->query(
			'UPDATE `cluster_commands` SET `state` = ?, `acked_at` = ?, `result` = ? WHERE `server_id` = ? AND `cmd_id` = ?;',
			$rOk ? 'acked' : 'failed',
			ClusterClock::now(),
			self::result0($rResult),
			$rServerID,
			$rCmdID
		);
		self::db()->query('UPDATE `cluster_nodes` SET `cmd_seq` = ? WHERE `server_id` = ? AND `cmd_seq` < ?;', (int) $rRow['seq'], $rServerID, (int) $rRow['seq']);
		ClusterBus::wakeAck($rCmdID);
		return true;
	}

	/**
	 * The result as the row keeps it: a longer one is cut, and says so, rather
	 * than reading as a complete answer that happens to end mid-word (the admin
	 * sees this text, and `cluster:exec` output is how a root action is read).
	 */
	private static function result0(string $rResult): string {
		if (strlen($rResult) <= self::MAX_RESULT) {
			return $rResult;
		}
		$rMark = "\n[truncated: " . strlen($rResult) . ' bytes]';
		return substr($rResult, 0, self::MAX_RESULT - strlen($rMark)) . $rMark;
	}

	/**
	 * A command's outcome once acked: [ok, result], or null while pending.
	 *
	 * @return array{0: bool, 1: string}|null
	 */
	public static function result(string $rCmdID): ?array {
		self::db()->query('SELECT `state`, `result` FROM `cluster_commands` WHERE `cmd_id` = ?;', $rCmdID);
		$rRow = self::db()->num_rows() > 0 ? self::db()->get_row() : null;
		if ($rRow === null || !in_array($rRow['state'], ['acked', 'failed'], true)) {
			return null;
		}
		return [$rRow['state'] === 'acked', (string) $rRow['result']];
	}

	/**
	 * Wait for a command's outcome (the admin side of an RPC): woken by the
	 * ack through the cluster bus, or polling without it.
	 *
	 * @return array{0: bool, 1: string}|null null on timeout
	 */
	public static function await(string $rCmdID, float $rTimeout, int $rPollMs = 100): ?array {
		$rDeadline = microtime(true) + $rTimeout;
		do {
			$rResult = self::result($rCmdID);
			if ($rResult !== null) {
				return $rResult;
			}
			$rLeft = $rDeadline - microtime(true);
			if ($rLeft > 0 && ClusterBus::waitAck($rCmdID, min($rLeft, 1.0)) === null) {
				usleep($rPollMs * 1000);
			}
		} while (microtime(true) < $rDeadline);
		return self::result($rCmdID);
	}

	/**
	 * Drop expired commands and outcomes older than a day. A credential strip
	 * the node was handed keeps its row a day past its exp: root runs a
	 * command up to 300 s past it (RootPin::verify), and MAIN revokes the
	 * node's grant on that ack alone (DbCredentials::acked). pending() stops
	 * handing it out at exp.
	 */
	public static function prune(): void {
		$rNow = ClusterClock::now();
		self::db()->query(
			"DELETE FROM `cluster_commands` WHERE (`exp` <= ? AND `state` IN ('queued', 'delivered') AND NOT (`state` = 'delivered' AND `type` = 'node.root' AND `action` = ? AND `exp` > ?)) OR (`acked_at` IS NOT NULL AND `acked_at` < ?);",
			$rNow,
			NodeCredentials::STRIP,
			$rNow - 86400,
			$rNow - 86400
		);
	}

	private static function ttl(string $rType): int {
		foreach (self::TTL as $rPrefix => $rSeconds) {
			if ($rPrefix !== 'default' && str_starts_with($rType, $rPrefix)) {
				return $rSeconds;
			}
		}
		return self::TTL['default'];
	}
}
