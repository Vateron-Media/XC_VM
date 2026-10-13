<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\AgentPaths;
use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Cluster\Crypto\ClusterRefusedException;
use XcVm\Core\Cluster\Crypto\Ticket;
use XcVm\Core\Cluster\DataPlane;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\MainAgentFiles;
use XcVm\Core\Config\SettingsManager;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * MAIN's data-plane client (ADR 0004, Phase 9's eighth increment): MAIN pulls
 * what it reads from other servers through its own agent, as a node does
 * through its own, instead of with the legacy URLs and the stream secret.
 *
 * - **Its key.** `xc_agent keygen` makes MAIN a data-plane key of its own
 *   (`config/cluster/main_agent.json`, 0600, the agent's user's): not the
 *   panel key, which stays in xcvm_core. `cluster_meta` `main_dataplane`
 *   keeps its public halves, uuid and generation; a re-key raises the
 *   generation, so a ticket or proof of the old key no longer counts.
 * - **Its entry in the node list.** While MAIN's data plane is on, the signed
 *   node list (the `servers` section's `nodes`) carries MAIN too — its server
 *   id, generation, key, `active`, `dataplane` — so a parent's RelayGuard and
 *   an owner's FileTicketServer check MAIN's proofs as any node's.
 * - **Its tickets.** Minted here for MAIN as the child or fetcher
 *   (TicketService::relayTicket / fileTicket) into `replica/tickets.json`,
 *   which the agent re-reads: on demand, when DataPlane builds a URL
 *   (ensureRelay, ensureFile), and by `cron:cluster` at each new epoch for
 *   every stream MAIN holds and every file read on demand within a day
 *   (refresh). `replica/servers.json` is the servers section, for the
 *   agent's routes and the owners' keys.
 * - **Its agent.** `xc_agent run -role main -state main_agent.json`
 *   (XC_VM_Fanout, `mainrole.go`), from `bin/xc_agent/run.sh` whenever
 *   `main.json` exists: the loopback proxy on 127.0.0.1:31290, signing with
 *   MAIN's key, on MAIN's own clock. `main.json` says whether it serves.
 *
 * Off (the default), nothing changes: MAIN has no entry, mints nothing for
 * itself, and keeps the legacy URLs. `cluster:main-dataplane` switches it.
 */
final class MainDataPlane {
	use DatabaseAware;

	/** MAIN's data-plane identity: {node_uuid, sign_pub, box_pub (base64), gen, on, at}. */
	public const META = 'main_dataplane';

	/** The files MAIN read on demand: {ref: {o: owner, p: path, at: when last asked}}. */
	public const META_FILES = 'main_dataplane_files';

	/** An on-demand file is kept in the tickets while it was asked for within this long. */
	public const FILE_KEEP = 86400;

	/** At most this many on-demand files are kept (the least recently asked go first). */
	public const MAX_FILES = 500;

	/** A held ticket with less than this left is minted anew. */
	public const FRESH = 3600;

	/** The ticket slot of the files MAIN reads on demand (no stream). */
	public const ADHOC = '0';

	/** @var (\Closure(): ClusterCrypto)|null */
	private static ?\Closure $rCrypto = null;

	private static ?int $rNow = null;

	/** Tests: another crypto and clock; null restores the extension and time(). */
	public static function useSeams(?\Closure $rCrypto, ?int $rNow = null): void {
		self::$rCrypto = $rCrypto;
		self::$rNow = $rNow;
	}

	private static function crypto(): ClusterCrypto {
		return self::$rCrypto !== null ? (self::$rCrypto)() : ClusterCryptoFactory::create();
	}

	private static function now(): int {
		return self::$rNow ?? ClusterClock::now();
	}

	/**
	 * MAIN's identity as `cluster_meta` keeps it, or null before the first
	 * `cluster:main-dataplane on`.
	 *
	 * @return array{node_uuid: string, sign_pub: string, box_pub: string, gen: int, on: bool, at: int}|null
	 */
	public static function identity(): ?array {
		try {
			$rDoc = json_decode((string) ClusterMeta::get(self::META), true);
		} catch (\Throwable) {
			return null;
		}
		if (!is_array($rDoc) || !is_string($rDoc['node_uuid'] ?? null) || !is_string($rDoc['sign_pub'] ?? null) || !is_int($rDoc['gen'] ?? null)) {
			return null;
		}
		$rPub = base64_decode($rDoc['sign_pub'], true);
		if ($rPub === false || strlen($rPub) !== 32 || $rDoc['gen'] < 1) {
			return null;
		}
		return ['node_uuid' => $rDoc['node_uuid'], 'sign_pub' => $rDoc['sign_pub'], 'box_pub' => (string) ($rDoc['box_pub'] ?? ''), 'gen' => $rDoc['gen'], 'on' => ($rDoc['on'] ?? false) === true, 'at' => (int) ($rDoc['at'] ?? 0)];
	}

	/** MAIN's server id (`servers.is_main`), or 0. */
	public static function mainId(): int {
		self::db()->query('SELECT `id` FROM `servers` WHERE `is_main` = 1 ORDER BY `id` ASC LIMIT 1;');
		return self::db()->num_rows() > 0 ? (int) self::db()->get_row()['id'] : 0;
	}

	/**
	 * MAIN as TicketService takes a child or fetcher, while its data plane is
	 * on; null otherwise.
	 *
	 * @return array{server_id: int, gen: int, flows: int}|null
	 */
	public static function node(): ?array {
		$rID = self::identity();
		if ($rID === null || !$rID['on']) {
			return null;
		}
		$rMain = self::mainId();
		return $rMain > 0 ? ['server_id' => $rMain, 'gen' => $rID['gen'], 'flows' => NodeRegistry::FLOW_DATAPLANE] : null;
	}

	/**
	 * MAIN's entry in the signed node list while its data plane is on, in
	 * the list's shape (ReplicaBuilder::serversData); null otherwise.
	 *
	 * @return array{sid: int, gen: int, state: string, ed_pub: string, dataplane: bool}|null
	 */
	public static function nodeEntry(int $rMainID): ?array {
		$rID = self::identity();
		if ($rID === null || !$rID['on'] || $rMainID <= 0) {
			return null;
		}
		return ['sid' => $rMainID, 'gen' => $rID['gen'], 'state' => 'active', 'ed_pub' => $rID['sign_pub'], 'dataplane' => true];
	}

	/**
	 * Switch MAIN's data plane on: a key (made once with $rKeygen, or anew with
	 * $rRekey, which raises the generation), main.json, the servers section
	 * and MAIN's tickets for its agent, and the node list pushed to the nodes.
	 *
	 * @param \Closure(string, string): (array{node_uuid: string, sign_pub: string, box_pub: string}|null) $rKeygen
	 *        (state path, uuid) => the keys `xc_agent keygen` printed, or null
	 * @return string|null null when on, else why not
	 */
	public static function enable(\Closure $rKeygen, bool $rRekey = false, string $rActor = 'cli'): ?string {
		$rMain = self::mainId();
		if ($rMain <= 0) {
			return 'MAIN\'s server row is not known';
		}
		try {
			$rPanel = self::crypto()->info()['panel_sign_pub'] ?? null;
		} catch (\Throwable $rE) {
			return 'the cluster API is not set up (' . $rE->getMessage() . ')';
		}
		if (!is_string($rPanel) || strlen($rPanel) !== 32) {
			return 'the cluster API has no panel key (cluster:init)';
		}
		$rOld = self::identity();
		$rUuid = ($rOld === null || $rRekey) ? self::uuid4() : $rOld['node_uuid'];
		$rKeys = $rKeygen(AgentPaths::file(MainAgentFiles::STATE), $rUuid);
		if ($rKeys === null || ($rKeys['node_uuid'] ?? null) !== $rUuid) {
			return 'xc_agent keygen failed';
		}
		$rSign = @hex2bin((string) $rKeys['sign_pub']);
		$rBox = @hex2bin((string) $rKeys['box_pub']);
		if (!is_string($rSign) || strlen($rSign) !== 32 || !is_string($rBox) || strlen($rBox) !== 32) {
			return 'xc_agent keygen printed no keys';
		}
		$rSignB64 = base64_encode($rSign);
		// A new key is a new generation: tickets and proofs of the old one stop.
		$rGen = $rOld === null ? 1 : ($rOld['sign_pub'] !== $rSignB64 ? $rOld['gen'] + 1 : $rOld['gen']);
		$rID = ['node_uuid' => $rUuid, 'sign_pub' => $rSignB64, 'box_pub' => base64_encode($rBox), 'gen' => $rGen, 'on' => true, 'at' => self::now()];
		ClusterMeta::set(self::META, (string) json_encode($rID, JSON_UNESCAPED_SLASHES));
		self::writeIdentity($rMain, $rID, $rPanel);
		try {
			self::refresh(true);
		} catch (\Throwable) {
			// cron:cluster mints them at its next pass; the URLs mint on demand.
		}
		self::announce($rMain);
		ClusterAudit::log('cluster.main_dataplane', null, ['on' => true, 'gen' => $rGen, 'new_key' => $rOld === null || $rOld['sign_pub'] !== $rSignB64], $rActor);
		return null;
	}

	/** Switch it off: no entry in the node list, and the agent answers 503. The key is kept. */
	public static function disable(string $rActor = 'cli'): ?string {
		$rOld = self::identity();
		if ($rOld === null || !$rOld['on']) {
			return 'MAIN\'s data plane is not on';
		}
		$rOld['on'] = false;
		$rOld['at'] = self::now();
		ClusterMeta::set(self::META, (string) json_encode($rOld, JSON_UNESCAPED_SLASHES));
		$rMain = self::mainId();
		try {
			$rPanel = (string) (self::crypto()->info()['panel_sign_pub'] ?? '');
		} catch (\Throwable) {
			$rPanel = '';
		}
		self::writeIdentity($rMain, $rOld, $rPanel);
		self::announce($rMain);
		ClusterAudit::log('cluster.main_dataplane', null, ['on' => false, 'gen' => $rOld['gen']], $rActor);
		return null;
	}

	/**
	 * A file ticket for MAIN to read $rPath from $rOwner, minted unless a
	 * fresh one is held. False while MAIN's data plane is off, or when none
	 * can be minted (the owner cannot check one, no licence).
	 */
	public static function ensureFile(int $rOwner, string $rPath): bool {
		$rNode = self::node();
		if ($rNode === null || $rPath === '') {
			return false;
		}
		$rRef = DataPlane::ref($rOwner, $rPath);
		self::remember($rRef, $rOwner, $rPath);
		return self::withStore(function (array &$rStore) use ($rNode, $rOwner, $rPath, $rRef): bool {
			foreach ($rStore['streams'] as $rSlot) {
				if (isset($rSlot['files'][$rRef]) && self::fresh('fil', (string) $rSlot['files'][$rRef], $rNode)) {
					return true;
				}
			}
			$rWire = TicketService::fileTicket(self::crypto(), $rNode, $rOwner, $rPath, self::now());
			if ($rWire === null) {
				return false;
			}
			$rStore['streams'][self::ADHOC]['files'][$rRef] = $rWire;
			return true;
		});
	}

	/** A relay ticket for MAIN to pull $rStreamID from $rParent, as ensureFile(). */
	public static function ensureRelay(int $rParent, int $rStreamID): bool {
		$rNode = self::node();
		if ($rNode === null || $rStreamID <= 0) {
			return false;
		}
		return self::withStore(function (array &$rStore) use ($rNode, $rParent, $rStreamID): bool {
			$rHeld = (string) ($rStore['streams'][(string) $rStreamID]['relay'] ?? '');
			if ($rHeld !== '' && self::fresh('rly', $rHeld, $rNode, $rParent)) {
				return true;
			}
			$rWire = TicketService::relayTicket(self::crypto(), $rNode, $rParent, $rStreamID, self::now());
			if ($rWire === null) {
				return false;
			}
			$rStore['streams'][(string) $rStreamID]['relay'] = $rWire;
			return true;
		});
	}

	/**
	 * `cron:cluster`: keep the agent's servers section current, and at each
	 * new epoch (or $rAll) mint MAIN's tickets anew — for every stream MAIN
	 * holds that pulls from a parent or reads another server's file, and
	 * every file read on demand within FILE_KEEP. Nothing while it is off.
	 */
	public static function refresh(bool $rAll = false): void {
		$rNode = self::node();
		if ($rNode === null) {
			return;
		}
		self::writeServers();
		self::writeSettings();
		TicketService::forget(); // the servers that check tickets, read again
		$rNow = self::now();
		$rEpoch = DataPlane::epoch($rNow);
		$rFiles = self::remembered($rNow);
		self::withStore(function (array &$rStore) use ($rNode, $rNow, $rEpoch, $rAll, $rFiles): bool {
			if (!$rAll && (int) ($rStore['epoch'] ?? 0) >= $rEpoch) {
				return true;
			}
			$rCrypto = self::crypto();
			$rStreams = [];
			try {
				$rHeld = StreamReplica::held($rNode['server_id'], null);
				foreach (array_chunk($rHeld, TicketService::MAX_STREAMS) as $rIDs) {
					foreach (StreamReplica::data($rNode['server_id'], $rIDs) as $rStreamID => $rData) {
						$rTickets = TicketService::forRecord($rCrypto, $rNode, $rData, $rNow);
						if ($rTickets !== null) {
							$rStreams[(string) $rStreamID] = array_filter(['relay' => $rTickets['relay'], 'files' => $rTickets['files']], static fn($rV): bool => $rV !== null);
						}
					}
				}
				foreach ($rFiles as $rRef => $rFile) {
					$rWire = TicketService::fileTicket($rCrypto, $rNode, (int) $rFile['o'], (string) $rFile['p'], $rNow);
					if ($rWire !== null) {
						$rStreams[self::ADHOC]['files'][$rRef] = $rWire;
					}
				}
			} catch (ClusterRefusedException) {
				return true; // no licence: keep what is held, try again at the next pass
			}
			$rStore = ['epoch' => $rEpoch, 'streams' => $rStreams];
			return true;
		});
	}

	/**
	 * Is a held ticket still good for MAIN: it verifies, names MAIN at its
	 * generation (and the parent asked for), and has FRESH left?
	 *
	 * @param array{server_id: int, gen: int} $rNode
	 */
	private static function fresh(string $rTag, string $rWire, array $rNode, ?int $rParent = null): bool {
		try {
			$rPanel = self::crypto()->info()['panel_sign_pub'] ?? null;
		} catch (\Throwable) {
			return false;
		}
		$rNow = self::now();
		$rDoc = is_string($rPanel) ? Ticket::verify($rPanel, $rTag, $rWire, $rNow) : null;
		if ($rDoc === null || (int) ($rDoc['exp'] ?? 0) - $rNow < self::FRESH) {
			return false;
		}
		[$rWho, $rGen] = $rTag === 'rly' ? ['child_sid', 'child_gen'] : ['fetcher_sid', 'fetcher_gen'];
		return (int) ($rDoc[$rWho] ?? 0) === $rNode['server_id'] && (int) ($rDoc[$rGen] ?? 0) === $rNode['gen']
			&& ($rParent === null || (int) ($rDoc['parent_sid'] ?? 0) === $rParent);
	}

	/** Note an on-demand file, at most once an hour per file. */
	private static function remember(string $rRef, int $rOwner, string $rPath): void {
		$rNow = self::now();
		$rFiles = self::remembered($rNow);
		if (isset($rFiles[$rRef]) && $rNow - $rFiles[$rRef]['at'] < 3600) {
			return;
		}
		$rFiles[$rRef] = ['o' => $rOwner, 'p' => $rPath, 'at' => $rNow];
		uasort($rFiles, static fn(array $rA, array $rB): int => $rB['at'] <=> $rA['at']);
		ClusterMeta::set(self::META_FILES, (string) json_encode(array_slice($rFiles, 0, self::MAX_FILES, true), JSON_UNESCAPED_SLASHES));
	}

	/**
	 * The on-demand files asked for within FILE_KEEP.
	 *
	 * @return array<string, array{o: int, p: string, at: int}>
	 */
	private static function remembered(int $rNow): array {
		$rDoc = json_decode((string) ClusterMeta::get(self::META_FILES), true);
		$rOut = [];
		foreach (is_array($rDoc) ? $rDoc : [] as $rRef => $rFile) {
			if (is_string($rRef) && preg_match('/^[0-9a-f]{32}\z/', $rRef) && is_array($rFile) && is_int($rFile['o'] ?? null) && is_string($rFile['p'] ?? null) && is_int($rFile['at'] ?? null)
				&& $rNow - $rFile['at'] < self::FILE_KEEP && DataPlane::ref($rFile['o'], $rFile['p']) === $rRef
			) {
				$rOut[$rRef] = ['o' => $rFile['o'], 'p' => $rFile['p'], 'at' => $rFile['at']];
			}
		}
		return $rOut;
	}

	/**
	 * Read, change and write `replica/tickets.json` under a lock, so php-fpm
	 * and the cron do not lose each other's tickets. $rChange edits the store
	 * in place and says whether it succeeded; the file is written only when
	 * the store changed.
	 *
	 * @param \Closure(array{epoch: int, streams: array<string, array{relay?: string, files?: array<string, string>}>}): bool $rChange
	 */
	private static function withStore(\Closure $rChange): bool {
		$rDir = AgentPaths::file(MainAgentFiles::REPLICA);
		if (!self::directory($rDir)) {
			return false;
		}
		$rLock = @fopen($rDir . '.tickets.lock', 'c');
		if ($rLock === false) {
			return false;
		}
		try {
			flock($rLock, LOCK_EX);
			$rStore = json_decode((string) @file_get_contents($rDir . 'tickets.json'), true);
			$rStore = is_array($rStore) && is_array($rStore['streams'] ?? null) ? $rStore : ['epoch' => 0, 'streams' => []];
			$rBefore = $rStore;
			$rOk = $rChange($rStore);
			if ($rStore !== $rBefore) {
				self::write($rDir . 'tickets.json', (string) json_encode(['epoch' => (int) ($rStore['epoch'] ?? 0), 'streams' => (object) $rStore['streams']], JSON_UNESCAPED_SLASHES));
			}
			return $rOk;
		} finally {
			flock($rLock, LOCK_UN);
			fclose($rLock);
		}
	}

	/** main.json: which key, which generation, the panel key, and whether it serves. */
	private static function writeIdentity(int $rMain, array $rID, string $rPanel): void {
		self::write(AgentPaths::file(MainAgentFiles::IDENTITY), (string) json_encode([
			'v' => 1, 'server_id' => $rMain, 'node_uuid' => $rID['node_uuid'], 'gen' => $rID['gen'],
			'panel_sign_pub' => base64_encode($rPanel), 'dataplane' => $rID['on'],
		], JSON_UNESCAPED_SLASHES));
		MainAgentFiles::usePath(null);
	}

	/** `replica/servers.json`: the servers section as the nodes get it, written when it changed. */
	private static function writeServers(): void {
		$rData = ReplicaBuilder::serversData();
		$rJson = (string) json_encode(['etag' => hash('sha256', (string) json_encode($rData)), 'data' => $rData], JSON_UNESCAPED_SLASHES);
		$rPath = AgentPaths::file(MainAgentFiles::REPLICA) . 'servers.json';
		if (@file_get_contents($rPath) !== $rJson) {
			self::write($rPath, $rJson);
		}
	}

	/**
	 * `replica/settings.json`: the settings MAIN's agent reads as a node's
	 * reads its replica's (`lb_digest_nonce_required`), written when it changed.
	 */
	private static function writeSettings(): void {
		$rData = ['lb_digest_nonce_required' => ClusterSettings::int('lb_digest_nonce_required', SettingsManager::get('lb_digest_nonce_required'))];
		$rJson = (string) json_encode(['etag' => hash('sha256', (string) json_encode($rData)), 'data' => $rData], JSON_UNESCAPED_SLASHES);
		$rPath = AgentPaths::file(MainAgentFiles::REPLICA) . 'settings.json';
		if (@file_get_contents($rPath) !== $rJson) {
			self::write($rPath, $rJson);
		}
	}

	/** How old MAIN's agent's digest report may be and still be read (it writes it every 10 minutes). */
	public const DIGEST_N1_STALE = 1800;

	/**
	 * The owners MAIN's own agent took a chunk digest without a nonce from
	 * (its report, main_digest_n1.json), or null without a fresh report.
	 *
	 * @return list<int>|null
	 */
	public static function digestN1(?int $rNowMs = null): ?array {
		$rDoc = json_decode((string) @file_get_contents(AgentPaths::file(MainAgentFiles::DIGEST_N1)), true);
		$rNowMs ??= (int) round(microtime(true) * 1000);
		if (!is_array($rDoc) || !is_int($rDoc['at_ms'] ?? null) || $rNowMs - $rDoc['at_ms'] > self::DIGEST_N1_STALE * 1000) {
			return null;
		}
		return NodeDigestN1::normalise($rDoc['owners'] ?? null);
	}

	/** The node list changed: every node fetches the servers section now. */
	private static function announce(int $rMain): void {
		try {
			ReplicaBuilder::nodesChanged(self::crypto(), $rMain);
		} catch (\Throwable) {
			// Each node sees it at its next poll (60 s).
		}
	}

	/** Write atomically, 0600, and as the agent's user when root writes it. */
	private static function write(string $rPath, string $rData): void {
		self::directory(dirname($rPath));
		$rTmp = $rPath . '.' . bin2hex(random_bytes(4)) . '.tmp';
		if (@file_put_contents($rTmp, $rData) === false) {
			throw new \RuntimeException('cannot write ' . $rPath);
		}
		@chmod($rTmp, 0600);
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			@chown($rTmp, 'xc_vm');
			@chgrp($rTmp, 'xc_vm');
		}
		if (!@rename($rTmp, $rPath)) {
			@unlink($rTmp);
			throw new \RuntimeException('cannot write ' . $rPath);
		}
	}

	/** A directory of the agent's (0700), made as the agent's user when root makes it. */
	private static function directory(string $rDir): bool {
		if (is_dir($rDir)) {
			return true;
		}
		if (!@mkdir($rDir, 0700, true) && !is_dir($rDir)) {
			return false;
		}
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			@chown($rDir, 'xc_vm');
			@chgrp($rDir, 'xc_vm');
		}
		return true;
	}

	private static function uuid4(): string {
		$rB = random_bytes(16);
		$rB[6] = chr((ord($rB[6]) & 0x0f) | 0x40);
		$rB[8] = chr((ord($rB[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($rB), 4));
	}
}
