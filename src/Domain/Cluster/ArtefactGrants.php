<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\ArtefactStage;
use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterRefusedException;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Artefact grants and the `artefact` op on MAIN (plan, section 7: "off-air
 * videos, pinned binaries, ≤ 4 MB chunks; rare / bulk; token; granted by a
 * signed command").
 *
 * A grant is a `cmd`-signed command that carries, in `args.artefact`, what
 * the node may fetch:
 *
 * ```text
 * {"id": "offair/<name>" | "module/<name>/<version>",
 *  "name": "<file name>", "size": <bytes>, "sha256": "<hex>", "mtime": <MAIN's>, "ctime": <MAIN's>, "exp": <the command's exp>}
 * ```
 *
 * - `artefact.fetch {artefact}` grants an off-air video (offerOffAir(),
 *   every minute from cron:cluster); cluster:exec places it.
 * - `node.root {action, …, artefact}` grants the file a root action needs
 *   (forRoot(): a custom module's archive for install_module); root stages
 *   and checks it before it runs.
 *
 * Both are granting commands, so the extension signs them only under a
 * licence. Only a node whose agent says FEATURE at hello is granted one;
 * today's agent never is, and keeps the legacy paths.
 *
 * serve() is the op: a chunk of the artefact a grant names, for the node the
 * grant is for, while its command is neither acked nor expired, of the file
 * MAIN's registry resolves the grant's id to, while that file is the one the
 * grant describes. A node names a grant, an offset and a length, never a
 * path. The chunk travels in the BOX, and the node checks the whole against
 * the grant's size and SHA-256.
 */
final class ArtefactGrants {
	use DatabaseAware;

	/** What an agent says at hello (`features`) when it downloads artefacts. */
	public const FEATURE = 'artefact';

	/** The command that grants an off-air video. */
	public const TYPE = ArtefactStage::TYPE_FETCH;

	/** The commands that may carry a grant. */
	public const GRANT_TYPES = [ArtefactStage::TYPE_FETCH, 'node.root'];

	/**
	 * An off-air grant that failed (or was never fetched) is offered again
	 * after this long (s), its command's lifetime; each further try of the
	 * same video waits twice as long, up to RETRY_MAX.
	 */
	public const RETRY_AFTER = 3600;

	/** The longest wait (s) before an off-air video a node keeps failing is offered again. */
	public const RETRY_MAX = 86400;

	/** cluster_meta prefix: what each node was offered of the off-air videos, and how it went. */
	private const OFFERED = 'artefact_offair.';

	/**
	 * Does this node take artefact grants: commands on, and an agent that
	 * said FEATURE at hello?
	 *
	 * @param array<string, mixed>|null $rNode
	 */
	public static function takes(?array $rNode): bool {
		return CommandBus::accepts($rNode) && in_array(self::FEATURE, explode(',', (string) ($rNode['features'] ?? '')), true);
	}

	/**
	 * A grant for an artefact ArtefactRegistry::describe() found. Its `exp`
	 * is its command's (CommandBus::enqueue()).
	 *
	 * @param array<string, mixed> $rFound
	 * @return array{id: string, name: string, size: int, sha256: string, mtime: int, ctime: int}
	 */
	public static function grant(array $rFound): array {
		return [
			'id' => (string) $rFound['id'], 'name' => (string) $rFound['name'], 'size' => (int) $rFound['size'], 'sha256' => (string) $rFound['sha256'],
			'mtime' => (int) $rFound['mtime'], 'ctime' => (int) $rFound['ctime'],
		];
	}

	/**
	 * A root action's payload with the grant for the artefact it needs: a
	 * custom module's archive (install_module from `local`). Unchanged for
	 * the other actions, and for install_module when the node does not take
	 * artefacts or MAIN has no such archive: ClusterRoute::root() then queues
	 * nothing, since no other way to the archive is left.
	 *
	 * @param array<string, mixed> $rPayload {action, …}
	 * @return array<string, mixed>
	 */
	public static function forRoot(int $rServerID, array $rPayload): array {
		unset($rPayload['artefact']);
		if (($rPayload['action'] ?? null) !== 'install_module' || ($rPayload['source'] ?? null) !== 'local' || !self::takes(NodeRegistry::byServer($rServerID))) {
			return $rPayload;
		}
		$rFound = ArtefactRegistry::describe('module/' . (string) ($rPayload['name'] ?? '') . '/' . (string) ($rPayload['version'] ?? ''), []);
		return $rFound === null ? $rPayload : $rPayload + ['artefact' => self::grant($rFound)];
	}

	/**
	 * Offer every node that takes artefacts the admin's off-air videos it
	 * does not hold yet (cron:cluster, every minute): one `artefact.fetch`
	 * per video that is new to it (or to its generation: a re-enrolled node
	 * starts afresh), changed since, or whose last grant failed or went
	 * unfetched: RETRY_AFTER after the first try, then twice as long after
	 * each further one, up to RETRY_MAX (retryAfter()). A video whose file
	 * name another one shares with other bytes is offered to no node
	 * (withoutCollisions()). Without a licence nothing is signed, and
	 * nothing is recorded as offered; what was queued before is. The
	 * extension is asked for only once there is a grant to sign.
	 *
	 * @param ClusterCrypto|callable(): ClusterCrypto $rCrypto
	 * @param array<string, mixed> $rSettings
	 * @return int The grants queued.
	 */
	public static function offerOffAir(ClusterCrypto|callable $rCrypto, array $rSettings): int {
		$rVideos = [];
		foreach (ArtefactRegistry::offAirIds($rSettings) as $rId) {
			$rFound = ArtefactRegistry::describe($rId, $rSettings);
			if ($rFound !== null) {
				$rVideos[$rId] = $rFound;
			}
		}
		$rVideos = self::withoutCollisions($rVideos);
		if ($rVideos === []) {
			return 0;
		}
		self::db()->query("SELECT * FROM `cluster_nodes` WHERE `state` = 'active' ORDER BY `server_id` ASC;");
		$rNodes = self::db()->get_rows();
		$rQueued = 0;
		$rNow = ClusterClock::now();
		foreach ($rNodes as $rNode) {
			if (!self::takes($rNode)) {
				continue;
			}
			$rSid = (int) $rNode['server_id'];
			$rOffered = self::offered($rSid, (int) $rNode['gen']);
			$rKeep = array_intersect_key($rOffered, $rVideos);
			try {
				foreach ($rVideos as $rId => $rFound) {
					$rWas = $rOffered[$rId] ?? null;
					$rAgain = is_array($rWas) && ($rWas['sha256'] ?? null) === $rFound['sha256'];
					if ($rAgain && (!empty($rWas['ok']) || $rNow - (int) ($rWas['at'] ?? 0) < self::retryAfter((int) ($rWas['tries'] ?? 1)))) {
						continue;
					}
					if (!$rCrypto instanceof ClusterCrypto) {
						$rCrypto = $rCrypto();
					}
					try {
						$rCmdID = CommandBus::enqueue($rCrypto, $rSid, self::TYPE, ['artefact' => self::grant($rFound)]);
					} catch (ClusterRefusedException $rE) {
						if ($rE->reason() === 'LICENCE') {
							return $rQueued; // nothing grants without a licence; the next pass offers again
						}
						throw $rE;
					}
					$rKeep[$rId] = ['sha256' => $rFound['sha256'], 'ok' => false, 'at' => $rNow, 'cmd_id' => $rCmdID, 'tries' => $rAgain ? (int) ($rWas['tries'] ?? 1) + 1 : 1];
					$rQueued++;
				}
			} finally {
				// What was queued is recorded however the pass ends, so it is never queued twice.
				if ($rKeep !== $rOffered) {
					self::record($rSid, (int) $rNode['gen'], $rKeep);
				}
			}
		}
		return $rQueued;
	}

	/** How long (s) after its last try an off-air video offered $rTries times without success is offered again. */
	public static function retryAfter(int $rTries): int {
		return (int) min(self::RETRY_MAX, self::RETRY_AFTER * 2 ** min(16, max(0, $rTries - 1)));
	}

	/**
	 * The off-air videos without those whose file name another one shares
	 * with other bytes: a node finds its copy by the file name alone
	 * (ArtefactStage::offAirVideo), so it would play one in place of the
	 * other. Two settings naming the same bytes stay.
	 *
	 * @param array<string, array<string, mixed>> $rVideos
	 * @return array<string, array<string, mixed>>
	 */
	private static function withoutCollisions(array $rVideos): array {
		$rByName = [];
		foreach ($rVideos as $rFound) {
			$rByName[(string) $rFound['name']][(string) $rFound['sha256']] = true;
		}
		return array_filter($rVideos, static fn(array $rFound): bool => count($rByName[(string) $rFound['name']]) === 1);
	}

	/**
	 * The live grant a node names: its own command, one that may carry a
	 * grant, neither acked nor expired, for this node's current generation,
	 * whose artefact id is the registry's.
	 *
	 * @param array<string, mixed> $rNode
	 * @return array{id: string, name: string, size: int, sha256: string, mtime: int, ctime: int, exp: int}|null
	 */
	public static function live(array $rNode, string $rCmdID): ?array {
		self::db()->query("SELECT `type`, `payload`, `exp` FROM `cluster_commands` WHERE `server_id` = ? AND `cmd_id` = ? AND `state` IN ('queued', 'delivered');", (int) $rNode['server_id'], $rCmdID);
		$rRow = self::db()->num_rows() > 0 ? self::db()->get_raw_row() : null;
		if ($rRow === null || !in_array($rRow['type'], self::GRANT_TYPES, true)) {
			return null;
		}
		$rDoc = json_decode((string) $rRow['payload'], true);
		$rGrant = is_array($rDoc) ? ($rDoc['args']['artefact'] ?? null) : null;
		$rNow = ClusterClock::now();
		if (!is_array($rGrant) || ($rDoc['node_uuid'] ?? null) !== $rNode['node_uuid'] || (int) ($rDoc['gen'] ?? -1) !== (int) $rNode['gen'] || (int) $rRow['exp'] <= $rNow) {
			return null;
		}
		foreach (['id' => 'is_string', 'name' => 'is_string', 'size' => 'is_int', 'sha256' => 'is_string', 'mtime' => 'is_int', 'ctime' => 'is_int', 'exp' => 'is_int'] as $rKey => $rIs) {
			if (!$rIs($rGrant[$rKey] ?? null)) {
				return null;
			}
		}
		if ($rGrant['exp'] <= $rNow || !ArtefactStage::validId($rGrant['id'])) {
			return null;
		}
		return ['id' => $rGrant['id'], 'name' => $rGrant['name'], 'size' => $rGrant['size'], 'sha256' => $rGrant['sha256'], 'mtime' => $rGrant['mtime'], 'ctime' => $rGrant['ctime'], 'exp' => $rGrant['exp']];
	}

	/**
	 * The `artefact` op: a chunk of a live grant's artefact.
	 *
	 * Request `{grant: <cmd_id>, offset: <int ≥ 0>, length: <int 1..MAX_CHUNK>}`;
	 * reply `{grant, offset, length, size, sha256, data: <base64 of the
	 * chunk>, eof}`, `length` being what `data` holds (the last chunk stops
	 * at the end). Refusals: a malformed request 400 BAD_REQUEST; no live
	 * grant of this node's by that id 403 GRANT_INVALID; an offset at or past
	 * the end, or a length over MAX_CHUNK, 416 BAD_RANGE {size, max_chunk}; a
	 * file that is no longer the one the grant describes (its name, size,
	 * mtime or ctime) 409 ARTEFACT_CHANGED.
	 *
	 * @param array<string, mixed> $rNode
	 * @param array<string, mixed> $rP The opened BOX.
	 * @param array<string, mixed> $rSettings
	 * @return array{0: array<string, mixed>|null, 1: array{0: int, 1: string, 2: array<string, mixed>}|null} [reply, or [status, reason, extra]]
	 */
	public static function serve(array $rNode, array $rP, array $rSettings): array {
		$rCmdID = $rP['grant'] ?? null;
		$rOffset = $rP['offset'] ?? null;
		$rLength = $rP['length'] ?? null;
		if (!is_string($rCmdID) || !preg_match('/^[0-9a-f]{32}\z/', $rCmdID) || !is_int($rOffset) || $rOffset < 0 || !is_int($rLength) || $rLength < 1) {
			return [null, [400, 'BAD_REQUEST', []]];
		}
		$rGrant = self::live($rNode, $rCmdID);
		if ($rGrant === null) {
			return [null, [403, 'GRANT_INVALID', []]];
		}
		if ($rOffset >= $rGrant['size'] || $rLength > ArtefactStage::MAX_CHUNK) {
			return [null, [416, 'BAD_RANGE', ['size' => $rGrant['size'], 'max_chunk' => ArtefactStage::MAX_CHUNK]]];
		}
		$rFound = ArtefactRegistry::locate($rGrant['id'], $rSettings);
		if ($rFound === null || $rFound['size'] !== $rGrant['size'] || $rFound['mtime'] !== $rGrant['mtime'] || $rFound['ctime'] !== $rGrant['ctime'] || $rFound['name'] !== $rGrant['name']) {
			return [null, [409, 'ARTEFACT_CHANGED', []]];
		}
		$rLength = min($rLength, $rGrant['size'] - $rOffset);
		$rIn = @fopen($rFound['path'], 'rb');
		$rData = $rIn === false ? false : stream_get_contents($rIn, $rLength, $rOffset);
		if ($rIn !== false) {
			fclose($rIn);
		}
		if (!is_string($rData) || strlen($rData) !== $rLength) {
			return [null, [409, 'ARTEFACT_CHANGED', []]];
		}
		$rChunk = [
			'grant' => $rCmdID, 'offset' => $rOffset, 'length' => $rLength, 'size' => $rGrant['size'], 'sha256' => $rGrant['sha256'],
			'data' => base64_encode($rData), 'eof' => $rOffset + $rLength === $rGrant['size'],
		];
		return [$rChunk, null];
	}

	/**
	 * A command's first ack, for a command of GRANT_TYPES (ClusterApi calls
	 * it for those alone). For one that carried a grant: a failure is
	 * audited (`artefact.refused` when the node refused what it fetched,
	 * its size or SHA-256 not the grant's; `artefact.failed` otherwise), and
	 * an off-air grant's outcome is recorded for offerOffAir().
	 */
	public static function acked(int $rServerID, string $rCmdID, bool $rOk, string $rResult): void {
		self::db()->query('SELECT `type`, `payload` FROM `cluster_commands` WHERE `server_id` = ? AND `cmd_id` = ?;', $rServerID, $rCmdID);
		$rRow = self::db()->num_rows() > 0 ? self::db()->get_raw_row() : null;
		$rDoc = $rRow === null || !in_array($rRow['type'], self::GRANT_TYPES, true) ? null : json_decode((string) $rRow['payload'], true);
		$rGrant = is_array($rDoc) ? ($rDoc['args']['artefact'] ?? null) : null;
		if (!is_array($rGrant) || !is_string($rGrant['id'] ?? null)) {
			return;
		}
		if (!$rOk) {
			ClusterAudit::log(str_contains($rResult, 'artefact refused') ? 'artefact.refused' : 'artefact.failed', $rServerID, [
				'cmd_id' => $rCmdID, 'type' => $rRow['type'], 'id' => $rGrant['id'], 'sha256' => $rGrant['sha256'] ?? null, 'result' => mb_substr($rResult, 0, 300),
			], 'node');
		}
		if ($rRow['type'] !== self::TYPE) {
			return;
		}
		$rGen = (int) ($rDoc['gen'] ?? -1);
		$rOffered = self::offered($rServerID, $rGen);
		$rWas = $rOffered[$rGrant['id']] ?? null;
		if (is_array($rWas) && ($rWas['cmd_id'] ?? null) === $rCmdID) {
			$rOffered[$rGrant['id']] = ['ok' => $rOk, 'at' => ClusterClock::now()] + $rWas;
			self::record($rServerID, $rGen, $rOffered);
		}
	}

	/**
	 * What this node's generation was offered of the off-air videos, by
	 * artefact id: nothing for another generation (a re-enrolled node).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function offered(int $rServerID, int $rGen): array {
		$rDoc = json_decode((string) ClusterMeta::get(self::OFFERED . $rServerID), true);
		return is_array($rDoc) && ($rDoc['gen'] ?? null) === $rGen && is_array($rDoc['ids'] ?? null) ? $rDoc['ids'] : [];
	}

	/** @param array<string, array<string, mixed>> $rOffered */
	private static function record(int $rServerID, int $rGen, array $rOffered): void {
		ClusterMeta::set(self::OFFERED . $rServerID, (string) json_encode(['gen' => $rGen, 'ids' => (object) $rOffered], JSON_UNESCAPED_SLASHES));
	}
}
