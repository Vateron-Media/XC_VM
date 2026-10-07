<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterRefusedException;
use XcVm\Core\Cluster\Crypto\Seal;
use XcVm\Core\Cluster\ReplicaEtagCache;
use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Core\Cluster\StrictQuery;
use XcVm\Core\Cluster\ViewerKey;
use XcVm\Core\Config\OpensslExtra;
use XcVm\Core\Config\StreamSecret;
use XcVm\Core\Util\AtomicFile;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * The node replica (plan, section 9, Phase 7): what MAIN sends a node so it
 * can serve without MAIN's database. Each record is panel-signed and sealed
 * to the node's X25519 key, so the node can keep it on disk as it came:
 *
 * ```text
 * record = SEAL(node_box_pub, purpose "replica", context node_uuid,
 *               u32(len) ‖ payload ‖ Ed25519 sig(tag, payload))
 * rep  {v, section, node, gen, etag, seq, iat, data}   a whole section (granting)
 * blk  {v, seq, iat, add, remove}                      a blocklist delta
 * ```
 *
 * A `rep` record names the node and its generation, so it opens and verifies
 * only there, and only until the node is re-enrolled. The ETag is the SHA-256
 * of the section's canonical data: a node that already holds it gets
 * `unchanged` instead of the section again.
 *
 * Today the replica holds these sections:
 *
 * - `blocklist` (BlocklistDelta): blocked IPs as `blk` deltas between whole
 *   sections. `blk` records only restrict while they add, so the extension
 *   signs them without a licence; removals and whole sections are granting.
 * - `settings`: the raw `settings` row, only the keys the LB build reads
 *   (Core/Cluster/lb_settings_keys.php, from tools/ci/lb-settings-keys.sh),
 *   never a secret.
 * - `servers`, `node`, `crontab`, `cluster` (Core\Cluster\ReplicaSections):
 *   every server's routing and relay fields with the node list, the node's
 *   own configuration, the crontab rows its mode runs, and MAIN's transport
 *   policy and keys.
 * - `secrets`: `live_streaming_pass` and OPENSSL_EXTRA, each as {kid,
 *   current, previous, previous_valid_until}, and the node's own viewer-token
 *   key (`viewer_key`, ViewerKey) in the same form: the only secrets a node gets.
 * - `bouquets`, `categories`: every bouquet and stream category, the
 *   catalogue the node's caches of those names hold for the viewer APIs.
 *
 * A blocklist section too large for one reply goes in parts too (blocklist()).
 *
 * All but the blocklist are sent whole, to an agent that names them in
 * `have`, whenever their ETag differs from the node's. The agent reads at
 * most 8 MiB of a reply, and one reply too large for it would stop every
 * section: one whose sealed record would pass MAX_WHOLE_BYTES is answered
 * `too_large` instead (whole()), and audited once per ETag, and the `config`
 * op keeps the whole reply within MAX_REPLY, in REPLY_ORDER. A section and its
 * ETag are reused for 10 s (ReplicaEtagCache); a change of the node list
 * drops the cache and is announced to the others through `config.changed`
 * (nodesChanged). `secrets` goes only to an active node in mode 1 or 2
 * (serves()) and is read afresh each time, never cached unsealed; like every
 * whole section it grants, so without a licence it is not signed.
 *
 * A section is never built from a failed read, a missing settings row or an
 * unset secret: it would be signed as MAIN's word (an empty settings row the
 * node takes as its whole settings, a crontab with no job, an empty `current`).
 * The builder throws instead, the `config` op answers `503 DB`, and the node
 * keeps what it holds and asks again.
 */
final class ReplicaBuilder {
	use DatabaseAware;

	public const PURPOSE = 'replica';

	public const SECTION_BLOCKLIST = 'blocklist';

	/** Sections sent whole whenever the node's ETag differs (and `secrets`, see serves()). */
	public const WHOLE = ReplicaSections::WHOLE;

	/**
	 * What an agent says at hello (`features`) when it runs `config.changed`
	 * itself: only those agents are sent it. Others would hand it to
	 * cluster:exec, which an LB's older PHP fails as an unknown type.
	 */
	public const FEATURE_CONFIG_CHANGED = 'config_changed';

	/**
	 * The largest sealed whole section served (base64): the agent reads at
	 * most 8 MiB of a reply, which also carries the blocklist and the other
	 * sections. A larger one (the bouquets of a panel with many resellers'
	 * packages) is answered `too_large`, and the node's readers go back to
	 * MAIN's database for it (ADR 0004, twelfth Phase 7 increment).
	 */
	public const MAX_WHOLE_BYTES = 4194304;

	/**
	 * The most a `config` reply's JSON may take: the plan's boxed plaintext
	 * limit, 8 MiB less 64 KiB, within the agent's 8 MiB `MaxReply`.
	 */
	public const MAX_REPLY = 8323072;

	/**
	 * A section too large for one reply goes to an agent that says `parts`
	 * in parts of this size (base64), each its own `config {part}` reply
	 * (plan section 7, "large transfers in ≤ 4 MiB parts").
	 */
	public const PART_BYTES = self::MAX_WHOLE_BYTES;

	/** The most parts a section is staged in (128 MiB); a larger one is only `too_large`. */
	public const MAX_PARTS = 32;

	/** How long a staged section waits for its node, in seconds. */
	public const STAGE_TTL = 900;

	private static ?string $rXferDir = null;

	/**
	 * The order a `config` reply takes the sections sent whole in, within
	 * MAX_REPLY: the viewer catalogue, the largest, last.
	 */
	public const REPLY_ORDER = [
		ReplicaSections::SETTINGS, ReplicaSections::SERVERS, ReplicaSections::NODE, ReplicaSections::CRONTAB, ReplicaSections::CLUSTER,
		ReplicaSections::SECRETS, ReplicaSections::BOUQUETS, ReplicaSections::CATEGORIES,
	];

	/**
	 * What the agent's `config` poll says (`blocklist_parts: true`) when it
	 * fetches a whole blocklist section too large for one reply in parts.
	 */
	public const BLOCKLIST_PARTS = 'blocklist_parts';

	/**
	 * The node's blocklist from change $rSince (0: it has none).
	 *
	 * A whole section whose sealed record passes MAX_WHOLE_BYTES goes to an
	 * agent that fetches it in parts ($rParts) as `{too_large, etag, parts}`,
	 * staged for it; it is sealed afresh at each poll that needs it, so the
	 * staged record always carries the seq the reply names, which the agent
	 * checks. To an older agent it is sent whole while one reply can carry
	 * it. Past that, or past MAX_PARTS, MAIN answers $rSince with nothing:
	 * the node keeps the blocklist it holds, and the reply's other sections
	 * still reach it. Either is audited once per ETag.
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row
	 * @return array{seq: int, more: bool, unchanged?: bool, delta?: string, section?: array{etag: string, sealed?: string, too_large?: bool, parts?: int}}
	 */
	public static function blocklist(ClusterCrypto $rCrypto, array $rNode, int $rSince, string $rHave, bool $rParts = false): array {
		$rDelta = BlocklistDelta::since($rSince);
		if ($rDelta['full'] || !empty($rDelta['reload'])) {
			// The head before the snapshot: a change made in between comes again next time.
			$rSeq = BlocklistDelta::head();
			$rData = BlocklistDelta::snapshot();
			$rEtag = self::etag($rData);
			// Only an agent that drops the deltas it stored over the section it
			// holds (the one that says blocklist_parts) is told `unchanged`: an
			// older one kept them, and went on blocking what a flush or a
			// reload had cleared. It gets the section again.
			if ($rParts && hash_equals($rEtag, $rHave)) {
				return ['seq' => $rSeq, 'more' => false, 'unchanged' => true];
			}
			$rDoc = [
				'v' => 1, 'section' => self::SECTION_BLOCKLIST, 'node' => (string) $rNode['node_uuid'], 'gen' => (int) $rNode['gen'],
				'etag' => $rEtag, 'seq' => $rSeq, 'iat' => ClusterClock::now(), 'data' => self::canonical($rData),
			];
			try {
				$rSealed = base64_encode(self::record($rCrypto, $rNode, 'rep', self::json($rDoc)));
			} catch (ClusterRefusedException $rE) {
				// Without a licence no section is signed: one the node holds is unchanged, as it always was.
				if ($rE->reason() === 'LICENCE' && hash_equals($rEtag, $rHave)) {
					return ['seq' => $rSeq, 'more' => false, 'unchanged' => true];
				}
				throw $rE;
			}
			// An older agent reads it in the reply itself, with room left for the reply around it.
			if (strlen($rSealed) > ($rParts ? self::MAX_WHOLE_BYTES : self::MAX_REPLY - 65536)) {
				self::tooLarge(self::SECTION_BLOCKLIST, $rEtag, strlen($rSealed));
				$rCount = $rParts ? self::stage($rNode, self::SECTION_BLOCKLIST, $rEtag, $rSealed) : null;
				if ($rCount === null) {
					return ['seq' => $rSince, 'more' => false];
				}
				return ['seq' => $rSeq, 'more' => false, 'section' => ['too_large' => true, 'etag' => $rEtag, 'parts' => $rCount]];
			}
			return ['seq' => $rSeq, 'more' => false, 'section' => ['etag' => $rEtag, 'sealed' => $rSealed]];
		}
		$rOut = ['seq' => $rDelta['last'], 'more' => $rDelta['more']];
		if (($rDelta['add'] ?? []) === [] && ($rDelta['remove'] ?? []) === []) {
			return $rOut;
		}
		$rDoc = ['v' => 1, 'seq' => $rDelta['last'], 'iat' => ClusterClock::now(), 'add' => $rDelta['add'] ?? [], 'remove' => $rDelta['remove'] ?? []];
		$rOut['delta'] = base64_encode(self::record($rCrypto, $rNode, 'blk', self::json($rDoc)));
		return $rOut;
	}

	/**
	 * A section sent whole: `unchanged` when the node holds its ETag, else the
	 * sealed `rep` record; `too_large` with the ETag when that record would
	 * pass MAX_WHOLE_BYTES (audited once per ETag). For an agent that fetches
	 * parts ($rParts), the record is staged (stage()) and `too_large` says how
	 * many `parts` it takes; a record already staged for the node under this
	 * ETag is not sealed again, so parts fetched across polls fit together.
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row
	 * @param array<string, mixed> $rSettings MAIN's settings (the `cluster` section's policy)
	 * @param array<string, mixed> $rMain MAIN's `servers` row
	 * @return array{unchanged?: bool, too_large?: bool, etag?: string, sealed?: string, parts?: int}
	 */
	public static function whole(ClusterCrypto $rCrypto, array $rNode, string $rSection, string $rHave, array $rSettings = [], array $rMain = [], bool $rParts = false): array {
		['etag' => $rEtag, 'data' => $rData] = self::section($rCrypto, $rNode, $rSection, $rSettings, $rMain);
		if (hash_equals($rEtag, $rHave)) {
			return ['unchanged' => true];
		}
		if ($rParts && ($rCount = self::staged($rNode, $rSection, $rEtag)) !== null) {
			return ['too_large' => true, 'etag' => $rEtag, 'parts' => $rCount];
		}
		$rDoc = [
			'v' => 1, 'section' => $rSection, 'node' => (string) $rNode['node_uuid'], 'gen' => (int) $rNode['gen'],
			'etag' => $rEtag, 'iat' => ClusterClock::now(), 'data' => $rData,
		];
		$rSealed = base64_encode(self::record($rCrypto, $rNode, 'rep', self::json($rDoc)));
		if (strlen($rSealed) > self::MAX_WHOLE_BYTES) {
			self::tooLarge($rSection, $rEtag, strlen($rSealed));
			$rCount = $rParts ? self::stage($rNode, $rSection, $rEtag, $rSealed) : null;
			return ['too_large' => true, 'etag' => $rEtag] + ($rCount === null ? [] : ['parts' => $rCount]);
		}
		return ['etag' => $rEtag, 'sealed' => $rSealed];
	}

	/**
	 * Part $rN of a section staged for this node: `{etag, n, parts, data}`,
	 * data being that part of the sealed record's base64. `{etag, gone}` when
	 * nothing is staged under that ETag any more (the section changed, the
	 * stage expired, or its last part was served): the node asks for the
	 * section again. Serving the last part removes the stage.
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row
	 * @return array{etag: string, gone?: bool, n?: int, parts?: int, data?: string}
	 */
	public static function part(array $rNode, string $rSection, string $rEtag, int $rN): array {
		$rCount = self::staged($rNode, $rSection, $rEtag);
		$rPath = self::stagePath($rNode, $rSection, $rEtag);
		$rData = $rCount === null || $rN >= $rCount ? false : @file_get_contents($rPath, false, null, $rN * self::PART_BYTES, self::PART_BYTES);
		if (!is_string($rData) || $rData === '') {
			return ['etag' => $rEtag, 'gone' => true];
		}
		if ($rN === $rCount - 1) {
			@unlink($rPath);
		}
		return ['etag' => $rEtag, 'n' => $rN, 'parts' => (int) $rCount, 'data' => $rData];
	}

	/** Tests: where sections are staged (null: TMP_PATH/cluster_xfer/). */
	public static function useXferDir(?string $rDir): void {
		self::$rXferDir = $rDir;
	}

	private static function xferDir(): string {
		return self::$rXferDir ?? (defined('TMP_PATH') ? TMP_PATH : sys_get_temp_dir() . '/') . 'cluster_xfer/';
	}

	/** @param array<string, mixed> $rNode */
	private static function stagePath(array $rNode, string $rSection, string $rEtag): string {
		return self::xferDir() . (int) $rNode['server_id'] . '.' . $rSection . '.' . $rEtag;
	}

	/**
	 * How many parts the section staged for this node under this ETag takes,
	 * or null when none is staged (or it expired).
	 *
	 * @param array<string, mixed> $rNode
	 */
	private static function staged(array $rNode, string $rSection, string $rEtag): ?int {
		$rPath = self::stagePath($rNode, $rSection, $rEtag);
		clearstatcache(true, $rPath);
		$rSize = @filesize($rPath);
		if (!is_int($rSize) || $rSize === 0 || (int) @filemtime($rPath) < time() - self::STAGE_TTL) {
			return null;
		}
		return (int) ceil($rSize / self::PART_BYTES);
	}

	/**
	 * Stage a sealed section too large for one reply, for its node to fetch
	 * in parts: TMP_PATH/cluster_xfer/<server id>.<section>.<etag>, 0600
	 * (tmpfs: it is removed with its last part, and after STAGE_TTL). The
	 * node's other stages of the section and every expired stage go first.
	 * Null when it would take more than MAX_PARTS, or cannot be written.
	 *
	 * @param array<string, mixed> $rNode
	 */
	private static function stage(array $rNode, string $rSection, string $rEtag, string $rSealed): ?int {
		$rCount = (int) ceil(strlen($rSealed) / self::PART_BYTES);
		$rDir = self::xferDir();
		if ($rCount > self::MAX_PARTS || (!is_dir($rDir) && !@mkdir($rDir, 0700, true))) {
			return null;
		}
		$rMine = (int) $rNode['server_id'] . '.' . $rSection . '.';
		foreach (glob($rDir . '*') ?: [] as $rFile) {
			if (str_starts_with(basename($rFile), $rMine) || (int) @filemtime($rFile) < time() - self::STAGE_TTL) {
				@unlink($rFile);
			}
		}
		return AtomicFile::write(self::stagePath($rNode, $rSection, $rEtag), $rSealed, 0600) ? $rCount : null;
	}

	/** Audit a section too large to send, once per ETag. */
	private static function tooLarge(string $rSection, string $rEtag, int $rBytes): void {
		try {
			if (ClusterMeta::get('replica_too_large.' . $rSection) !== $rEtag) {
				ClusterMeta::set('replica_too_large.' . $rSection, $rEtag);
				ClusterAudit::log('replica.section_too_large', null, ['section' => $rSection, 'bytes' => $rBytes, 'max' => self::MAX_WHOLE_BYTES], 'system');
			}
		} catch (\Throwable) {
			// The section stays out regardless.
		}
	}

	/**
	 * Is this whole section served to this node? `secrets` only to an active
	 * node in mode 1 or 2: a legacy node (mode 0) reads MAIN's database.
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row (state, mode)
	 */
	public static function serves(array $rNode, string $rSection): bool {
		if ($rSection !== ReplicaSections::SECRETS) {
			return true;
		}
		return ($rNode['state'] ?? null) === 'active' && (int) ($rNode['mode'] ?? 0) >= 1;
	}

	/**
	 * A whole section for this node and its ETag, reused for 10 s; `secrets`
	 * is read each time, never cached.
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row (server_id, mode)
	 * @param array<string, mixed> $rSettings
	 * @param array<string, mixed> $rMain
	 * @return array{etag: string, data: array<mixed>}
	 */
	public static function section(ClusterCrypto $rCrypto, array $rNode, string $rSection, array $rSettings, array $rMain): array {
		if ($rSection === ReplicaSections::SECRETS) {
			$rData = self::canonical(self::secretsData((int) $rNode['server_id'], $rNode));
			return ['etag' => self::etag($rData), 'data' => $rData];
		}
		$rKey = match ($rSection) {
			ReplicaSections::NODE => $rSection . '.' . (int) $rNode['server_id'],
			ReplicaSections::CRONTAB => $rSection . '.' . ((int) $rNode['mode'] >= 2 ? 'api' : 'legacy'),
			default => $rSection,
		};
		$rNow = ClusterClock::nowMs();
		$rGen = ReplicaEtagCache::generation();
		$rHit = ReplicaEtagCache::get($rKey, $rNow);
		if ($rHit !== null) {
			return $rHit;
		}
		$rData = match ($rSection) {
			ReplicaSections::SETTINGS => self::settingsData(),
			ReplicaSections::SERVERS => self::serversData(),
			ReplicaSections::NODE => self::nodeData((int) $rNode['server_id']),
			ReplicaSections::CRONTAB => self::crontabData((int) $rNode['mode']),
			ReplicaSections::CLUSTER => self::clusterData($rCrypto, $rSettings, $rMain),
			ReplicaSections::BOUQUETS => self::bouquetsData(),
			ReplicaSections::CATEGORIES => self::categoriesData(),
			default => throw new \InvalidArgumentException('Not a whole replica section: ' . $rSection),
		};
		$rData = self::canonical($rData);
		$rEtag = self::etag($rData);
		ReplicaEtagCache::put($rKey, $rNow, $rGen, $rEtag, $rData);
		return ['etag' => $rEtag, 'data' => $rData];
	}

	/**
	 * The `servers` section: every server's routing and relay fields, and the
	 * node list parents check relay tickets against and children file-digest
	 * keys (`{sid, gen, state, ed_pub, dataplane}`, MAIN's own while its
	 * data-plane client is on, MainDataPlane; `dataplane`: the node's
	 * DATAPLANE flow is on, Phase 8). Never liveness, telemetry or a
	 * node's own settings (ReplicaSections::SERVER_LOCAL). `reseller_dns`:
	 * the active resellers' DNS names, the rest of verify_host's list
	 * (ServerRepository::allowedDomains), sorted.
	 *
	 * @return array{servers: list<array<string, int|string|null>>, nodes: list<array{sid: int, gen: int, state: string, ed_pub: string, dataplane: bool}>, reseller_dns: list<string>}
	 */
	public static function serversData(): array {
		self::read('SELECT * FROM `servers` ORDER BY `id` ASC;');
		$rServers = [];
		foreach (self::db()->get_rows() ?: [] as $rRow) {
			$rServers[] = ReplicaSections::typed($rRow, ReplicaSections::SERVER_FIELDS);
		}
		self::read('SELECT * FROM `cluster_nodes` ORDER BY `server_id` ASC;');
		$rNodes = [];
		foreach (self::db()->get_raw_rows() ?: [] as $rRow) {
			// dataplane: the node pulls through its agent, so a parent refuses the
			// legacy password from its address (RelayGuard).
			$rNodes[] = [
				'sid' => (int) $rRow['server_id'], 'gen' => (int) $rRow['gen'], 'state' => (string) $rRow['state'], 'ed_pub' => base64_encode((string) $rRow['node_sign_pub']),
				// box_pub: what a child seals a relay's session key to (RelaySeal).
				'box_pub' => base64_encode((string) ($rRow['node_box_pub'] ?? '')),
				'dataplane' => (int) ($rRow['mode'] ?? 0) >= 1 && ((int) ($rRow['flows'] ?? 0) & NodeRegistry::FLOW_DATAPLANE) !== 0,
			];
		}
		// MAIN itself, while its data-plane client is on (MainDataPlane): its
		// own key, so parents and owners check its proofs as any node's.
		foreach ($rServers as $rServer) {
			if ((int) ($rServer['is_main'] ?? 0) === 1) {
				$rMain = MainDataPlane::nodeEntry((int) $rServer['id']);
				if ($rMain !== null) {
					$rNodes[] = $rMain;
				}
				break;
			}
		}
		self::read("SELECT DISTINCT `reseller_dns` FROM `users` WHERE `status` = 1 AND `reseller_dns` <> '' ORDER BY `reseller_dns`;");
		$rDns = array_map('strval', array_column(self::db()->get_rows() ?: [], 'reseller_dns'));
		return ['servers' => $rServers, 'nodes' => $rNodes, 'reseller_dns' => $rDns];
	}

	/**
	 * The `node` section: the node's own row (ports, limits, services, disk,
	 * HTTPS and domains, interface, governor, sysctl, clock offset) and the
	 * settings that shape its nginx. Empty when the server row is gone.
	 *
	 * @return array<string, int|string|null>
	 */
	public static function nodeData(int $rServerID): array {
		self::read('SELECT * FROM `servers` WHERE `id` = ?;', $rServerID);
		$rRow = self::db()->get_row();
		if (!is_array($rRow) || $rRow === []) {
			return [];
		}
		return ReplicaSections::typed($rRow, ReplicaSections::NODE_FIELDS) + ReplicaSections::typed(self::settingsRow('*'), ReplicaSections::NODE_SETTINGS);
	}

	/**
	 * The `crontab` section: the enabled rows whose role fits a node in this
	 * mode (ReplicaSections::cronRoles), in the table's order. A row that is
	 * not a job as ReplicaSections::cronJob takes it (a hand-edited `@daily`,
	 * a six-field schedule) is left out and audited once, since a node
	 * refuses the whole section over it.
	 *
	 * @return array{jobs: list<array{filename: string, time: string}>}
	 */
	public static function crontabData(int $rMode): array {
		$rRoles = ReplicaSections::cronRoles($rMode);
		self::read('SELECT `filename`, `time`, `role` FROM `crontab` WHERE `enabled` = 1 ORDER BY `id` ASC;');
		$rJobs = [];
		$rSkipped = [];
		foreach (self::db()->get_rows() ?: [] as $rRow) {
			if (!in_array((string) ($rRow['role'] ?? 'all'), $rRoles, true)) {
				continue;
			}
			$rRow = ['filename' => (string) $rRow['filename'], 'time' => (string) $rRow['time']];
			$rJob = ReplicaSections::cronJob($rRow);
			if ($rJob === null) {
				$rSkipped[] = $rRow;
			} else {
				$rJobs[] = $rJob;
			}
		}
		if ($rSkipped !== []) {
			self::skippedJobs($rSkipped);
		}
		return ['jobs' => $rJobs];
	}

	/**
	 * Audit the crontab rows the section leaves out, once per change of them.
	 *
	 * @param list<array{filename: string, time: string}> $rSkipped
	 */
	private static function skippedJobs(array $rSkipped): void {
		try {
			$rHash = hash('sha256', (string) json_encode($rSkipped));
			if (ClusterMeta::get('replica_crontab_skipped') !== $rHash) {
				ClusterMeta::set('replica_crontab_skipped', $rHash);
				ClusterAudit::log('replica.crontab_skipped', null, ['jobs' => $rSkipped], 'system');
			}
		} catch (\Throwable) {
			// The section goes out regardless.
		}
	}

	/**
	 * The `cluster` section: the transport policy a node follows (as `hello`
	 * sends it; one counter versions the URLs and the policy), the panel keys,
	 * the lowest protocol MAIN speaks, and the off-air videos' file names.
	 *
	 * @param array<string, mixed> $rSettings
	 * @param array<string, mixed> $rMain
	 * @return array<string, mixed>
	 */
	public static function clusterData(ClusterCrypto $rCrypto, array $rSettings, array $rMain): array {
		$rPolicy = ClusterPolicy::current($rSettings, $rMain);
		$rInfo = $rCrypto->info();
		$rOffAir = [];
		foreach (ReplicaSections::OFF_AIR as $rName => $rKey) {
			$rPath = is_string($rSettings[$rKey] ?? null) ? trim($rSettings[$rKey]) : '';
			$rOffAir[$rName] = $rPath === '' ? null : basename((string) (parse_url($rPath, PHP_URL_PATH) ?: $rPath));
		}
		return [
			'main_urls' => $rPolicy['main_urls'], 'urls_ver' => $rPolicy['policy_ver'], 'policy_ver' => $rPolicy['policy_ver'],
			'transport' => $rPolicy['transport'], 'heartbeat_sec' => $rPolicy['heartbeat_sec'],
			'panel_sign_pub' => base64_encode((string) ($rInfo['panel_sign_pub'] ?? '')),
			'panel_box_pub' => base64_encode((string) ($rInfo['panel_box_pub'] ?? '')), 'min_proto' => ClusterApi::PROTO_MIN, 'off_air' => $rOffAir,
		];
	}

	/**
	 * The `bouquets` section: every bouquet, every column, in the order
	 * BouquetService::getAll reads them (bouquet_order, 0 last; then id), so
	 * a node builds its bouquets cache in cron:cache's shape.
	 *
	 * @return array{bouquets: list<array<string, int|string|null>>}
	 */
	public static function bouquetsData(): array {
		self::read('SELECT * FROM `bouquets` ORDER BY CASE WHEN `bouquet_order` > 0 THEN `bouquet_order` ELSE 999 END ASC, `id` ASC;');
		return ['bouquets' => array_map(static fn(array $rRow): array => ReplicaSections::typed($rRow, ReplicaSections::BOUQUET_FIELDS), self::db()->get_rows() ?: [])];
	}

	/**
	 * The `categories` section: every stream category, every column, in the
	 * order CategoryService reads them (cat_order, then id).
	 *
	 * @return array{categories: list<array<string, int|string|null>>}
	 */
	public static function categoriesData(): array {
		self::read('SELECT * FROM `streams_categories` ORDER BY `cat_order` ASC, `id` ASC;');
		return ['categories' => array_map(static fn(array $rRow): array => ReplicaSections::typed($rRow, ReplicaSections::CATEGORY_FIELDS), self::db()->get_rows() ?: [])];
	}

	/**
	 * Does this node take `config.changed` now: it takes commands, and its
	 * agent says it runs the command (FEATURE_CONFIG_CHANGED)?
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row
	 */
	public static function takesConfigChanged(array $rNode): bool {
		return CommandBus::accepts($rNode) && in_array(self::FEATURE_CONFIG_CHANGED, explode(',', (string) ($rNode['features'] ?? '')), true);
	}

	/**
	 * The node list changed (a node revoked, re-enrolled, activated or
	 * quarantined): drop the cached sections and tell every other node at
	 * once whose agent takes `config.changed` (COMMANDS on, and the
	 * FEATURE_CONFIG_CHANGED feature said at hello), so a parent stops
	 * trusting a revoked child's key before its next poll. The command is
	 * restrictive, so it signs without a licence, and a newer announcement
	 * supersedes one not yet acked. Every other node sees the change at its
	 * next poll. Never fails the change: a node that cannot be told is
	 * skipped.
	 *
	 * @return int the nodes told
	 */
	public static function nodesChanged(ClusterCrypto $rCrypto, int $rServerID): int {
		ReplicaEtagCache::bump();
		try {
			self::db()->query("SELECT * FROM `cluster_nodes` WHERE `server_id` <> ? AND `state` = 'active';", $rServerID);
			$rNodes = self::db()->get_rows() ?: [];
		} catch (\Throwable) {
			return 0;
		}
		$rSent = 0;
		foreach ($rNodes as $rNode) {
			if (!self::takesConfigChanged($rNode)) {
				continue;
			}
			try {
				CommandBus::enqueue($rCrypto, (int) $rNode['server_id'], 'config.changed', ['sections' => [ReplicaSections::SERVERS]], 'config.changed');
				$rSent++;
			} catch (\Throwable) {
				// This node sees the change at its next poll (60 s).
			}
		}
		return $rSent;
	}

	/**
	 * The `settings` section: the raw row, allowlisted keys only.
	 *
	 * @return array<string, mixed>
	 */
	public static function settingsData(): array {
		$rAllow = self::settingsKeys();
		$rRow = self::settingsRow('*');
		$rOut = [];
		foreach ($rAllow as $rKey) {
			if (array_key_exists($rKey, $rRow)) {
				$rOut[$rKey] = $rRow[$rKey] === null ? null : (string) $rRow[$rKey];
			}
		}
		return $rOut;
	}

	/**
	 * The `secrets` section: the viewer-token secret (`live_streaming_pass`,
	 * from the settings row) and OPENSSL_EXTRA (the value this php-fpm mints
	 * with), each with its kid, and the value MAIN replaced while it is still
	 * accepted on MAIN's clock. Only OPENSSL_EXTRA has one today
	 * (config/openssl_extra.prev) and the stream secret its own
	 * (config/stream_secret.prev, written when a settings save replaces it).
	 * An unset value throws: a node refuses an
	 * empty `current` (ReplicaSections::secret), and cron:root_signals sets a
	 * missing stream secret on MAIN within the minute.
	 *
	 * With $rServerID, also that node's own viewer-token key (ViewerKey,
	 * `viewer_key`), which a node on older code leaves alone; and for a node
	 * withholdsStreamPass() names ($rNode), the stream secret's hash
	 * (`streaming_pass_hash`) in place of its value.
	 *
	 * @param array<string, mixed>|null $rNode cluster_nodes row (server_id, mode)
	 * @return array<string, array{current: string, kid: string, previous: ?string, previous_valid_until: ?int}>
	 */
	public static function secretsData(?int $rServerID = null, ?array $rNode = null): array {
		$rLive = (string) (self::settingsRow('`live_streaming_pass`')['live_streaming_pass'] ?? '');
		$rExtra = defined('OPENSSL_EXTRA') ? (string) OPENSSL_EXTRA : '';
		if ($rLive === '' || $rExtra === '') {
			throw new \RuntimeException('replica: a secret is not set');
		}
		$rLivePrevious = StreamSecret::previousEntry(ClusterClock::now());
		$rData = [
			'live_streaming_pass' => self::secret('live_streaming_pass', $rLive, $rLivePrevious),
			'openssl_extra' => self::secret('openssl_extra', $rExtra, OpensslExtra::previousEntry(ClusterClock::now())),
		];
		if ($rServerID !== null) {
			$rData[ViewerKey::NAME] = ViewerKey::entry($rLive, $rServerID, $rLivePrevious);
			if ($rNode !== null && self::withholdsStreamPass($rNode, $rLive)) {
				unset($rData['live_streaming_pass']);
				$rData[ViewerKey::PASS_HASH] = self::secret(ViewerKey::PASS_HASH, hash('sha256', $rLive), null);
			}
		}
		return $rData;
	}

	/**
	 * Does node $rNode get the stream secret's hash in place of its value (H1,
	 * third increment)? Once MAIN is locked down (DbAllowlist::lockedDown: no node below
	 * mode 2, every relay on tickets), a node in mode 2 that reports holding a
	 * key MAIN mints its tokens with (ViewerKey::keyFor: under the current
	 * secret, or the replaced one inside its window, so a rotation never hands
	 * the new value out). It reads MAIN's tokens and its own with that key, and
	 * checks a legacy password against the hash: without the value, a node
	 * mints nothing another server reads.
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row (server_id, mode)
	 */
	public static function withholdsStreamPass(array $rNode, string $rLive): bool {
		if ((int) ($rNode['mode'] ?? 0) < 2 || !DbAllowlist::lockedDown()) {
			return false;
		}
		self::read('SELECT `viewer_key_fp` FROM `servers` WHERE `id` = ?;', (int) $rNode['server_id']);
		$rFp = (self::db()->get_row() ?: [])[ViewerKey::FP] ?? null;
		return ViewerKey::keyFor($rFp, $rLive, (int) $rNode['server_id'], ClusterClock::now()) !== null;
	}

	/**
	 * @param array{value: string, valid_until: int}|null $rPrevious
	 * @return array{current: string, kid: string, previous: ?string, previous_valid_until: ?int}
	 */
	private static function secret(string $rName, string $rValue, ?array $rPrevious): array {
		return ['current' => $rValue, 'kid' => ReplicaSections::kid($rName, $rValue), 'previous' => $rPrevious['value'] ?? null, 'previous_valid_until' => $rPrevious['valid_until'] ?? null];
	}

	/**
	 * MAIN's settings row, these columns of it; a failed read or no row throws.
	 *
	 * @return array<string, mixed>
	 */
	private static function settingsRow(string $rColumns): array {
		self::read('SELECT ' . $rColumns . ' FROM `settings` LIMIT 1;');
		$rRow = self::db()->get_row();
		if (!is_array($rRow) || $rRow === []) {
			throw new \RuntimeException('replica: no settings row');
		}
		return $rRow;
	}

	/** Run one of a section's reads: a failed one throws, never an empty result. */
	private static function read(string $rQuery, mixed ...$rArgs): void {
		StrictQuery::run(self::db(), 'replica', $rQuery, ...$rArgs);
	}

	/** @return list<string> the settings keys a node's replica may carry */
	public static function settingsKeys(): array {
		$rList = require dirname(__DIR__, 2) . '/Core/Cluster/lb_settings_keys.php';
		return array_values(array_diff($rList['keys'], $rList['withheld']));
	}

	/** Sign a record and seal it to the node's box key. */
	public static function record(ClusterCrypto $rCrypto, array $rNode, string $rTag, string $rPayload): string {
		$rSig = $rCrypto->sign($rTag, $rPayload);
		return Seal::seal((string) $rNode['node_box_pub'], self::PURPOSE, (string) $rNode['node_uuid'], pack('N', strlen($rPayload)) . $rPayload . $rSig);
	}

	/** SHA-256 (hex) of a section's canonical data. */
	public static function etag(array $rData): string {
		return hash('sha256', self::json(self::canonical($rData)));
	}

	/** Keys sorted at every level; lists keep their order (ReplicaSections::canonical). */
	public static function canonical(mixed $rValue): mixed {
		return ReplicaSections::canonical($rValue);
	}

	/** A record's payload, as signed: JSON with slashes and Unicode as they are. */
	public static function json(array $rDoc): string {
		return (string) json_encode($rDoc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
	}
}
