<?php

namespace XcVm\Core\Cluster;

use XcVm\Domain\Cluster\MainDataPlane;
use XcVm\Domain\Server\ServerRepository;

/**
 * The data plane without bearer credentials (ADR 0004, Phase 8; plan,
 * section 7, "Local socket and data plane"): how a node's PHP names what it
 * pulls from another server once its DATAPLANE flow is on.
 *
 * ```text
 * relay  http://127.0.0.1:31290/relay/<k>/<stream id>.ts[?prebuffer=1]
 * file   http://127.0.0.1:31290/xfile/<k>/<ref>[.<ext>]
 * ```
 *
 * The node's agent listens there (relayproxy.go) and signs each upstream
 * connect: it picks the stream's current relay ticket, or the file ticket
 * whose `ref` this is, from the R2 streams section, and adds the node key's
 * `X-XCVM-Relay-Auth` (or `X-XCVM-File-Auth`). `k` is the loopback key the
 * agent publishes beside its state (`relay.key`, 0600) once it holds the
 * port, and removes when it lets go of it: it never leaves the host and
 * unlocks only that listener, so a local user who reads an encoder's
 * command line holds nothing replayable. Neither URL changes when a ticket
 * does, so a refresh restarts no encoder.
 *
 * The port is an unprivileged one any local user could bind while the agent
 * does not, and whoever holds it would read `k` and feed the encoders what
 * it likes. So a URL goes to it only while the listener on it is the
 * agent's: every socket listening on port 31290 (`/proc/net/tcp`, `tcp6`)
 * belongs to the uid that owns `relay.key`, which only the agent's user can
 * write. Otherwise the URL is UNAVAILABLE, on a privileged port no local
 * user can hold: the read fails at once, the monitor retries, and neither
 * the stream secret nor anything unauthenticated reaches the encoder.
 *
 * A server that cannot check a ticket keeps the legacy URL, password and all:
 * the parent or owner must be MAIN, or a node active in the signed node list
 * (the `servers` section's `nodes`). MAIN mints tickets for the same servers
 * only (TicketService), so both sides agree without asking each other.
 *
 * The ticket timing lives here because both sides compute it: MAIN mints
 * relay tickets valid RELAY_LIFE and file tickets FILE_LIFE from the start
 * of the EPOCH they are minted in, and a node asks for new ones at each new
 * epoch. The relay's refresh therefore always has hours to spare, and a file
 * ticket at least EPOCH.
 */
final class DataPlane {
	public const HOST = '127.0.0.1';
	public const PORT = 31290;

	/** The agent's loopback key, relative to the config directory. */
	public const KEY_FILE = AgentPaths::DIR . 'relay.key';

	/** A node asks for fresh tickets once per epoch (3 h). */
	public const EPOCH = 10800;

	/** A relay ticket's lifetime from its epoch's start (at most Ticket::KINDS' 24 h). */
	public const RELAY_LIFE = 86400;

	/** A file ticket's lifetime from its epoch's start (at most Ticket::KINDS' 6 h). */
	public const FILE_LIFE = 21600;

	/** A file ticket's owner-sealed path: to the owner node's box key, or sealed by MAIN to itself. */
	public const SEAL_PURPOSE = 'file';
	public const SEAL_NODE = 'n.';
	public const SEAL_MAIN = 'm.';

	/**
	 * Where a URL goes when the loopback cannot be trusted: a privileged port,
	 * which only root could listen on, so the connect is refused.
	 */
	public const UNAVAILABLE = 'http://127.0.0.1:1/xcvm-dataplane-unavailable';

	/** How long a verified (or refused) listener is taken as it was, in seconds. */
	private const CHECK_TTL = 5;

	private static ?string $rKeyFile = null;

	/** @var list<string>|null the socket tables read (tests); null: /proc/net/tcp and tcp6 */
	private static ?array $rProcNet = null;

	/** @var array{0: int, 1: ?string}|null [when, key] of the last check */
	private static ?array $rChecked = null;

	/** @var (callable(): array<int, array<string, mixed>>)|null */
	private static $rServers = null;

	/**
	 * Does this server pull through its agent? A node: its own DATAPLANE flow.
	 * MAIN, which has no flows: its data-plane client is on (main.json,
	 * MainAgentFiles; `cluster:main-dataplane on`).
	 */
	public static function on(): bool {
		return NodeFlows::on(NodeFlows::DATAPLANE) || (DataPlaneTrust::main() && MainAgentFiles::on());
	}

	/**
	 * Before MAIN builds a loopback URL: its ticket for the pull, minted now
	 * if it holds no fresh one (MainDataPlane). A node's tickets come from
	 * MAIN's push, so there this is always true. False when MAIN could not
	 * mint one (no licence, the owner or parent not ticketable): the caller
	 * keeps the legacy URL.
	 */
	private static function mainTicket(?int $rOwnerID, ?string $rPath, ?int $rParentID = null, ?int $rStreamID = null): bool {
		if (!DataPlaneTrust::main() || NodeFlows::on(NodeFlows::DATAPLANE)) {
			return true;
		}
		if (!class_exists(MainDataPlane::class)) {
			return false;
		}
		try {
			return $rOwnerID !== null ? MainDataPlane::ensureFile($rOwnerID, (string) $rPath) : MainDataPlane::ensureRelay((int) $rParentID, (int) $rStreamID);
		} catch (\Throwable) {
			return false;
		}
	}

	/** The agent's loopback key, or null while it has written none. */
	public static function key(): ?string {
		$rKey = trim((string) @file_get_contents(self::$rKeyFile ?? AgentPaths::file(self::KEY_FILE)));
		return preg_match('/^[A-Za-z0-9_-]{22,64}\z/', $rKey) ? $rKey : null;
	}

	/**
	 * The loopback key, only while the listener on the port is the agent's
	 * (see above); null otherwise, and the caller builds no loopback URL.
	 */
	public static function loopback(): ?string {
		if (self::$rChecked !== null && time() - self::$rChecked[0] < self::CHECK_TTL) {
			return self::$rChecked[1];
		}
		$rFile = self::$rKeyFile ?? AgentPaths::file(self::KEY_FILE);
		$rKey = self::key();
		clearstatcache(true, $rFile);
		$rOwner = $rKey === null ? false : @fileowner($rFile);
		$rKey = $rOwner !== false && self::listenerOwnedBy($rOwner, self::$rProcNet ?? ['/proc/net/tcp', '/proc/net/tcp6']) ? $rKey : null;
		self::$rChecked = [time(), $rKey];
		return $rKey;
	}

	/**
	 * Is every socket listening on PORT (in these `/proc/net/tcp`-format
	 * tables) owned by $rUid, and one of them on 127.0.0.1 or every address?
	 *
	 * @param list<string> $rTables
	 */
	public static function listenerOwnedBy(int $rUid, array $rTables): bool {
		$rPort = sprintf('%04X', self::PORT);
		$rOurs = false;
		foreach ($rTables as $rTable) {
			$rLines = @file($rTable, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			foreach (is_array($rLines) ? array_slice($rLines, 1) : [] as $rLine) {
				$rCols = preg_split('/\s+/', trim($rLine));
				if (!is_array($rCols) || count($rCols) < 8 || strtoupper($rCols[3]) !== '0A') {
					continue;
				}
				[$rAddr, $rLocalPort] = array_pad(explode(':', strtoupper($rCols[1]), 2), 2, '');
				if ($rLocalPort !== $rPort) {
					continue;
				}
				if ((int) $rCols[7] !== $rUid) {
					return false; // someone else listens on the port too
				}
				// 127.0.0.1 (either byte order) or 0.0.0.0; a v6 socket counts as another's only.
				$rOurs = $rOurs || in_array($rAddr, ['0100007F', '7F000001', '00000000'], true);
			}
		}
		return $rOurs;
	}

	/**
	 * Tests: another key file and other socket tables; null restores the
	 * agent's and /proc's. Either forgets the last check.
	 *
	 * @param list<string>|null $rProcNet
	 */
	public static function useKeyFile(?string $rPath, ?array $rProcNet = null): void {
		self::$rKeyFile = $rPath;
		self::$rProcNet = $rProcNet;
		self::$rChecked = null;
	}

	/** The epoch $rNow (unix seconds) is in. */
	public static function epoch(int $rNow): int {
		return intdiv($rNow, self::EPOCH);
	}

	/** A relay ticket's id: one per epoch, child and stream. */
	public static function relayTid(int $rEpoch, int $rChildSid, int $rStreamID): string {
		return 'r' . $rEpoch . '-' . $rChildSid . '-' . $rStreamID;
	}

	/** A file ticket's id: one per epoch, fetcher and file. */
	public static function fileTid(int $rEpoch, int $rFetcherSid, string $rRef): string {
		return 'f' . $rEpoch . '-' . $rFetcherSid . '-' . $rRef;
	}

	/**
	 * A file's stable name on the loopback: the same for every ticket that
	 * names it, so a URL an encoder holds outlives the ticket it was built
	 * with. It says nothing about the path.
	 */
	public static function ref(int $rOwnerSid, string $rPath): string {
		return substr(hash('sha256', 'xcvm-file-ref-v1' . pack('N', $rOwnerSid) . pack('N', strlen($rPath)) . $rPath), 0, 32);
	}

	/**
	 * Can $rServerID check a ticket: MAIN, or a node active in the signed
	 * node list? Null when the list cannot be read (the caller keeps the
	 * legacy URL, as before the data plane).
	 *
	 * @param array<int, array<string, mixed>> $rServers ServerRepository::getAll()
	 */
	public static function ticketable(array $rServers, int $rServerID): bool {
		if (!empty($rServers[$rServerID]['is_main'])) {
			return true;
		}
		$rNode = DataPlaneTrust::node($rServerID);
		return $rNode !== null && $rNode['state'] === 'active';
	}

	/**
	 * The URL a child pulls a stream from its parent with: the loopback relay
	 * with DATAPLANE on (and a parent that checks tickets), else the legacy
	 * `/admin/live` with the stream secret.
	 *
	 * @param array<int, array<string, mixed>> $rServers ServerRepository::getAll()
	 */
	public static function relayUrl(array $rServers, int $rParentID, int $rStreamID, string $rPassword, bool $rPrebuffer = false): string {
		if (self::on() && !self::isSelf($rParentID) && self::ticketable($rServers, $rParentID) && self::mainTicket(null, null, $rParentID, $rStreamID)) {
			$rKey = self::loopback();
			return $rKey === null ? self::UNAVAILABLE : 'http://' . self::HOST . ':' . self::PORT . '/relay/' . $rKey . '/' . $rStreamID . '.ts' . ($rPrebuffer ? '?prebuffer=1' : '');
		}
		$rSelf = defined('SERVER_ID') ? ($rServers[SERVER_ID] ?? []) : [];
		$rParent = $rServers[$rParentID] ?? [];
		$rLoopURL = !is_null($rSelf['private_url_ip'] ?? null) && !is_null($rParent['private_url_ip'] ?? null) ? $rParent['private_url_ip'] : ($rParent['public_url_ip'] ?? '');
		return $rLoopURL . 'admin/live?stream=' . $rStreamID . '&password=' . urlencode($rPassword) . '&extension=ts' . ($rPrebuffer ? '&prebuffer=1' : '');
	}

	/**
	 * The URL a node reads a file another server holds with (a movie's
	 * source, its subtitles, a created channel's item): the loopback `/xfile`
	 * with DATAPLANE on (and an owner that checks tickets), else the legacy
	 * `getFile` of its `/api`.
	 *
	 * @param array<int, array<string, mixed>> $rServers ServerRepository::getAll()
	 */
	public static function fileUrl(array $rServers, int $rOwnerID, string $rPath): string {
		if (self::on() && !self::isSelf($rOwnerID) && self::ticketable($rServers, $rOwnerID) && self::mainTicket($rOwnerID, $rPath)) {
			$rKey = self::loopback();
			if ($rKey === null) {
				return self::UNAVAILABLE;
			}
			$rExt = strtolower((string) pathinfo($rPath, PATHINFO_EXTENSION));
			return 'http://' . self::HOST . ':' . self::PORT . '/xfile/' . $rKey . '/' . self::ref($rOwnerID, $rPath) . (preg_match('/^[a-z0-9]{1,8}\z/', $rExt) ? '.' . $rExt : '');
		}
		return ($rServers[$rOwnerID]['api_url'] ?? '') . '&action=getFile&filename=' . urlencode($rPath);
	}

	/** Is $rServerID this server? Its own files and streams are never pulled through the proxy. */
	private static function isSelf(int $rServerID): bool {
		return defined('SERVER_ID') && $rServerID === (int) SERVER_ID;
	}

	/**
	 * May this node's legacy `/api` answer 404 (api_legacy.conf)? Only once
	 * nothing reads its files with `getFile` any more: its own DATAPLANE flow
	 * is on, and every server of the cluster — MAIN included, a proxy not,
	 * as it reads no node's files — is a node
	 * active in the signed node list with its DATAPLANE flow on, so each
	 * reads through `/xfile`. MAIN counts once its data-plane client is on
	 * (`cluster:main-dataplane on`): its entry in the node list is then
	 * active with DATAPLANE, and it reads a node's files (a source probe, the
	 * certbot log, what it runs) through its own agent.
	 */
	public static function legacyApiRetired(): bool {
		if (!self::on()) {
			return false;
		}
		try {
			$rServers = self::$rServers !== null ? (self::$rServers)() : ServerRepository::getAll();
		} catch (\Throwable) {
			return false;
		}
		if (!is_array($rServers) || $rServers === []) {
			return false;
		}
		foreach ($rServers as $rID => $rServer) {
			// A proxy reads no node's files: it forwards viewers and talks to MAIN alone.
			if ((int) ($rServer['server_type'] ?? 0) === 1) {
				continue;
			}
			$rNode = DataPlaneTrust::node((int) $rID);
			if ($rNode === null || $rNode['state'] !== 'active' || !$rNode['dataplane']) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Tests: the servers legacyApiRetired() goes through; null restores the repository.
	 *
	 * @param (callable(): array<int, array<string, mixed>>)|null $rServers
	 */
	public static function useServers(?callable $rServers): void {
		self::$rServers = $rServers;
	}
}
