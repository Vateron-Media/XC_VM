<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Process\ProcessManager;
use XcVm\Core\Process\ProcessRunner;
use XcVm\Core\Util\AtomicFile;
use XcVm\Domain\Stream\StreamStateWriter;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * The node's own store of its streams' runtime state (plan, section 8: the
 * load balancer owns the `stream.state` fields "with a local copy"; section
 * 9, R2: its recordings "plus local recording.state override"; section 10:
 * a node in mode 2 reads no MAIN database). What the node's writers report
 * to MAIN (StreamStateWriter: pids, status, the current source, probe
 * results, progress, the created channel's build state; ContentSink: the
 * archive and thumbnail workers' pids, a recording's status) is kept here
 * too, while its STREAMS flow is on, so the node's readers take a stream's
 * runtime state from here and its definition from the R2 stream caches
 * (StreamSource, NodeStreams) instead of one row of MAIN's database.
 *
 * ```text
 * config/cluster/runtime/          0700, the node's user (the agent's directory's owner)
 *   .lock                          the writers and the seed take it (flock)
 *   seeded                         {"at", "server_id", "streams"}: the store is whole
 *   generation                     a count every kept write and every lapse bumps (seed())
 *   unsent                         {"at", "token"}: an entry holds what the agent did not take (resend())
 *   streams/<id>.json              {"id", "ssid", "fields": {column: value}, "unsent": [columns]}
 *   recordings/<id>.json           {"id", "status"}
 *   .<name>.<pid>.<rand>.tmp       a write in progress (AtomicFile; readers skip dot files)
 * ```
 *
 * - **Where.** On disk beside the agent's spool, not in tmp/ (a tmpfs):
 *   a reboot keeps what MAIN's row kept, so cron:streams restarts the
 *   streams that ran, and a created channel keeps what it built. Pids are
 *   stale after a reboot, as they were in MAIN's row; every reader checks a
 *   pid against the process it names.
 * - **Crash-safe.** Each file is written aside (a dot file in the same
 *   directory), flushed to disk (fdatasync) and renamed in: a reader sees the
 *   old file or the new one, never a torn one. A file that does not read is
 *   no entry.
 * - **Bounded.** An entry per stream and per recording the node holds:
 *   cron:cleanup prunes the others (prune()), at most MAX_STREAMS and
 *   MAX_RECORDINGS in any case, each value at most MAX_VALUE (what MAIN's
 *   column holds). A write past a bound is not kept, and a node whose rows
 *   on MAIN pass MAX_STREAMS is not seeded.
 * - **Owned by the node's user.** Written as the owner of the agent's
 *   directory (xc_vm); a root process switches to that user first
 *   (SettingsAudit::asAgentUser) and keeps nothing when it cannot.
 * - **The current source** is redacted (Redactor), as the `stream.state`
 *   event carries it and MAIN's row holds it on a node with STREAMS on.
 *
 * **Whole or not used.** A reader takes the store only once it is seeded
 * (ready()): a node that switches STREAMS on has run its streams with MAIN's
 * row, so its first CLI reader then copies that row once (seed(), mode 1:
 * one counted connect), once the agent has delivered every event the node
 * spooled (the P0 lane empty) so that row holds all the node wrote, and
 * only if no write landed while it read. A write that goes to MAIN's row
 * alone (STREAMS off, or the agent took no event in mode 0 or 1) lapses the
 * store once it landed (lapse()): the next reader seeds again. A node in
 * mode 2 cannot read MAIN's rows: it seeds from what it kept itself, while
 * none of its streams runs (seedLocal()), which is how a node born in mode 2
 * gets its store at all.
 */
final class StreamRuntime {
	use DirSeam;

	/** The `streams` columns the node keeps besides StreamStateWriter::STATE_FIELDS: its workers' pids. */
	public const WORKER_FIELDS = ['tv_archive_pid', 'vframes_pid'];

	/** The most streams one `stream.assign` names (assign(); MAIN splits longer lists). */
	public const ASSIGN_MAX = 500;

	/** At most this many streams have an entry. */
	public const MAX_STREAMS = 100000;

	/** At most this many recordings have an entry. */
	public const MAX_RECORDINGS = 100000;

	/** The largest value kept, in bytes: what MAIN's column (mediumtext) holds. */
	public const MAX_VALUE = 16777215;

	/** An entry written this recently is never pruned: its stream may be one assigned since the list was read. */
	public const PRUNE_GRACE = 600;

	/** How long the seed waits for the writers' lock, in seconds. */
	public const SEED_WAIT = 2.0;

	/** A process whose seed failed tries again after this many seconds (each try may connect to MAIN's database). */
	public const SEED_RETRY = 30;

	private const SEEDED = 'seeded';

	private const UNSENT = 'unsent';

	private const GENERATION = 'generation';

	private const LOCK = '.lock';

	/** When this process's last seed failed, or null. */
	private static ?int $rSeedFailed = null;

	/** @var array<int, int> server_stream_id => stream id, from the rows this process read */
	private static array $rSsids = [];

	/** @var array<int, int>|null the replica's server_stream_id => stream id, read once per process */
	private static ?array $rIndexSsids = null;

	/** @var array{streams: int, recordings: int, value: int}|null tests: other bounds */
	private static ?array $rLimits = null;

	/** Tests: another procfs for seedLocal()'s producer scan; null is /proc. */
	private static ?string $rProcRoot = null;

	/** Tests: another directory, and what this process read forgotten; null restores the default. */
	public static function useDir(?string $rDir): void {
		self::$rDir = $rDir;
		self::$rSsids = [];
		self::$rIndexSsids = null;
		self::$rSeedFailed = null;
	}

	/** Tests: another procfs for seedLocal()'s producer scan; null restores /proc. */
	public static function useProcRoot(?string $rProcRoot): void {
		self::$rProcRoot = $rProcRoot;
	}

	/** Tests: smaller bounds (streams, recordings, a value's bytes); null restores them. */
	public static function useLimits(?int $rStreams, ?int $rRecordings = null, ?int $rValue = null): void {
		self::$rLimits = $rStreams === null ? null : ['streams' => $rStreams, 'recordings' => $rRecordings ?? self::MAX_RECORDINGS, 'value' => $rValue ?? self::MAX_VALUE];
	}

	private static function defaultDir(): string {
		return ReplicaApply::configDir() . 'cluster/runtime/';
	}

	/** Do this node's writers keep their streams' state here? While its STREAMS flow is on. */
	public static function keeps(): bool {
		return NodeFlows::on(NodeFlows::STREAMS);
	}

	/**
	 * Keep what a writer reports about one of this node's streams, and hand
	 * it on ($rSend: the agent's event), both under the store's lock. $rKey
	 * names the row as the event does: `{stream_id, server_id?}` or `{ssid}`;
	 * another server's row is not the node's to keep. In mode 2 the fields
	 * $rSend did not take stay marked unsent, for resend(); elsewhere the
	 * writer then writes MAIN's row itself (and lapses the store), so nothing
	 * is left to resend.
	 *
	 * @param array<string, int> $rKey
	 * @param array<string, mixed> $rFields StreamStateWriter::STATE_FIELDS and WORKER_FIELDS
	 * @param callable(): bool $rSend
	 * @return bool what $rSend answered
	 */
	public static function keep(array $rKey, array $rFields, callable $rSend): bool {
		$rSent = null;
		$rID = false;
		$rKept = self::locked(static function () use ($rKey, $rFields, $rSend, &$rSent, &$rID): bool {
			$rID = self::streamOfKey($rKey);
			$rSent = (bool) $rSend();
			return is_int($rID) && self::merge($rID, $rKey['ssid'] ?? null, $rFields, $rSent || !NodeRole::refusesConnects());
		});
		if ($rSent === null) {
			// The store is out of reach (no directory, or root could not switch).
			$rSent = (bool) $rSend();
		}
		// Another server's row is not this node's to keep; anything else not kept is missed.
		if (!$rKept && $rID !== null) {
			self::missed($rKey);
		}
		return $rSent;
	}

	/**
	 * MAIN's own write to this node's streams (`stream.assign`: the admin's
	 * Rescan VOD, Recreate channels, the symlink tools, a re-encoded channel's
	 * reset): for each of these streams the store keeps, $rSet's columns as
	 * MAIN's row now holds them, and $rFill's where the entry's value is empty
	 * (Rescan VOD's `pid = IF(pid, pid, 1)`). Nothing is sent back: MAIN wrote
	 * them. A stream without an entry is left alone: its next write, or the
	 * seed, brings MAIN's row.
	 *
	 * @param list<int> $rStreamIDs
	 * @param array<string, mixed> $rSet StreamStateWriter::STATE_FIELDS
	 * @param array<string, mixed> $rFill StreamStateWriter::STATE_FIELDS
	 * @return int the entries changed
	 */
	public static function assign(array $rStreamIDs, array $rSet, array $rFill = []): int {
		$rCount = 0;
		self::locked(static function () use ($rStreamIDs, $rSet, $rFill, &$rCount): bool {
			foreach ($rStreamIDs as $rID) {
				$rEntry = self::read(self::dir() . 'streams/' . (int) $rID . '.json', true);
				if ($rEntry === null) {
					continue;
				}
				$rFields = $rSet;
				foreach ($rFill as $rColumn => $rValue) {
					if (empty($rEntry['fields'][$rColumn]) && !array_key_exists($rColumn, $rSet)) {
						$rFields[$rColumn] = $rValue;
					}
				}
				if ($rFields !== [] && self::merge((int) $rID, null, $rFields, true)) {
					$rCount++;
				}
			}
			return true;
		});
		return $rCount;
	}

	/**
	 * A recording's status as the node set it last: it wins over the one
	 * its stream's record carries (MAIN's, as last heard).
	 */
	public static function recording(int $rRecordingID, int $rStatus): bool {
		$rFile = self::dir() . 'recordings/' . $rRecordingID . '.json';
		return self::locked(static fn (): bool => (is_file($rFile) || self::room('recordings', self::$rLimits['recordings'] ?? self::MAX_RECORDINGS)) && self::put($rFile, ['id' => $rRecordingID, 'status' => $rStatus]));
	}

	/** The status the node set last for a recording, or null. */
	public static function recordingStatus(int $rRecordingID): ?int {
		$rDoc = self::read(self::dir() . 'recordings/' . $rRecordingID . '.json');
		return is_array($rDoc) && is_int($rDoc['status'] ?? null) ? $rDoc['status'] : null;
	}

	/**
	 * A recording's status was written while the store does not follow
	 * (STREAMS off): MAIN's row has it, so the node's own is no longer the
	 * latest. Under the store's lock, as the node's user.
	 */
	public static function forgetRecording(int $rRecordingID): void {
		$rFile = self::dir() . 'recordings/' . $rRecordingID . '.json';
		// Out of the lock's reach (root could not switch): gone all the same.
		if (is_file($rFile) && !self::locked(static fn (): bool => @unlink($rFile))) {
			@unlink($rFile);
		}
	}

	/**
	 * What the node kept for a stream: column => value, only the columns
	 * it wrote (or the seed copied).
	 *
	 * @return array<string, mixed>
	 */
	public static function get(int $rStreamID): array {
		$rDoc = self::read(self::dir() . 'streams/' . $rStreamID . '.json', true);
		if (!is_array($rDoc)) {
			return [];
		}
		if (is_int($rDoc['ssid'] ?? null)) {
			self::$rSsids[$rDoc['ssid']] = $rStreamID;
		}
		return $rDoc['fields'];
	}

	/**
	 * The runtime columns of the node's `streams_servers` row for a stream,
	 * in ReplicaSections::STREAM_SERVER_LOCAL's order: what the node kept,
	 * else the column's default as MAIN inserts the row (0 for the status,
	 * the analysis flag and `compatible`, `[]` for a created channel's build
	 * state, null otherwise). `updated` and `aes_pid` are never kept.
	 *
	 * @param int|null $rType the stream's `streams.type`
	 * @return array<string, mixed>
	 */
	public static function serverFields(int $rStreamID, ?int $rType): array {
		$rKept = self::get($rStreamID);
		$rOut = [];
		foreach (ReplicaSections::STREAM_SERVER_LOCAL as $rColumn) {
			$rOut[$rColumn] = in_array($rColumn, StreamStateWriter::STATE_FIELDS, true) && array_key_exists($rColumn, $rKept) ? $rKept[$rColumn] : self::defaultOf($rColumn, $rType);
		}
		return $rOut;
	}

	/**
	 * The workers' pids on the `streams` row (0 as MAIN's default).
	 *
	 * @return array{tv_archive_pid: mixed, vframes_pid: mixed}
	 */
	public static function streamFields(int $rStreamID): array {
		$rKept = self::get($rStreamID);
		return ['tv_archive_pid' => $rKept['tv_archive_pid'] ?? 0, 'vframes_pid' => $rKept['vframes_pid'] ?? 0];
	}

	/**
	 * The streams with an entry, ascending.
	 *
	 * @return list<int>
	 */
	public static function ids(): array {
		return self::names('streams');
	}

	/**
	 * The recordings with an entry, ascending.
	 *
	 * @return list<int>
	 */
	public static function recordingIDs(): array {
		return self::names('recordings');
	}

	/** A row this process read names its stream: an update by server_stream_id finds it. */
	public static function remember(mixed $rServerStreamID, mixed $rStreamID): void {
		if (is_numeric($rServerStreamID) && is_numeric($rStreamID) && (int) $rServerStreamID > 0) {
			self::$rSsids[(int) $rServerStreamID] = (int) $rStreamID;
		}
	}

	/** Is the store whole: seeded for this server, and nothing written past it since? */
	public static function seeded(): bool {
		return defined('SERVER_ID') && self::seededFor((int) SERVER_ID);
	}

	/** seeded(), for a process that reports it before its boot defined SERVER_ID (SettingsAudit::publish). */
	public static function seededFor(int $rServerID): bool {
		$rDoc = self::read(self::dir() . self::SEEDED);
		return is_array($rDoc) && ($rDoc['server_id'] ?? null) === $rServerID;
	}

	/**
	 * May the node's readers take the store? Once it is seeded. A CLI
	 * process seeds it here the first time: from MAIN's rows on a node that
	 * may still reach MAIN's database (mode 1), from the node's own entries
	 * in mode 2 (seedLocal()). A streaming request never does (it reads
	 * MAIN's database until a CLI process has). A process whose seed failed
	 * does not try again for SEED_RETRY seconds.
	 */
	public static function ready(): bool {
		if (self::seeded()) {
			return true;
		}
		if (PHP_SAPI !== 'cli' || !self::keeps()) {
			return false;
		}
		if (self::$rSeedFailed !== null && time() - self::$rSeedFailed < self::SEED_RETRY) {
			return false;
		}
		$rSeeded = NodeRole::refusesConnects() ? self::seedLocal() : self::seed();
		self::$rSeedFailed = $rSeeded ? null : time();
		return $rSeeded;
	}

	/**
	 * Copy this node's runtime state from MAIN's database into the store:
	 * its `streams_servers` rows' runtime columns and the pids of the
	 * workers it runs. Only while the agent takes events and has delivered
	 * every one the node spooled (the P0 lane empty), so MAIN's rows hold all
	 * the node wrote before, and nothing is left unsent. MAIN's rows are read
	 * outside the store's lock, so a slow database never holds up a writer;
	 * the copy is made under it, only if no write was kept and the store did
	 * not lapse since the read began (its generation unchanged) and nothing
	 * is pending again. An entry left from an earlier seed and not in MAIN's
	 * rows goes. The recordings' statuses are not copied: the record carries
	 * MAIN's. False when it could not seed (the lock busy, the agent stopped,
	 * events pending or unsent, MAIN's database not answering, a write since
	 * the read, more rows than MAX_STREAMS): the readers then keep MAIN's
	 * database.
	 */
	public static function seed(?object $rDb = null): bool {
		if (!defined('SERVER_ID') || !EventSpool::agentAlive()) {
			return false;
		}
		$rServerID = (int) SERVER_ID;
		// The generation before the read (the directory made first, so a
		// write's lapse finds the store).
		$rGeneration = null;
		$rReachable = self::locked(static function () use (&$rGeneration): bool {
			$rGeneration = self::generation();
			return true;
		}, self::SEED_WAIT);
		if (!$rReachable) {
			return false;
		}
		if (self::seeded()) {
			return true;
		}
		if (!self::quiet()) {
			return false;
		}
		$rRows = self::mainRows($rDb, $rServerID);
		if ($rRows === null) {
			return false;
		}
		$rMax = self::$rLimits['streams'] ?? self::MAX_STREAMS;
		if (count($rRows) > $rMax) {
			error_log('XC_VM: stream state not seeded on this node: ' . count($rRows) . ' streams with state, more than ' . $rMax);
			return false;
		}
		$rSeeded = self::locked(static function () use ($rRows, $rServerID, $rGeneration): bool {
			if (self::seeded()) {
				return true;
			}
			// A write kept or a lapse since the read, or events pending again: MAIN's rows may lack it.
			if (self::generation() !== $rGeneration || !self::quiet()) {
				return false;
			}
			$rDir = self::dir();
			foreach ($rRows as $rID => $rEntry) {
				if (!self::put($rDir . 'streams/' . $rID . '.json', $rEntry, false)) {
					return false;
				}
			}
			foreach (self::ids() as $rID) {
				if (!isset($rRows[$rID])) {
					@unlink($rDir . 'streams/' . $rID . '.json');
				}
			}
			// One flush for every entry, before the marker says they are there (no shell; its errors to /dev/null).
			ProcessRunner::run(['sync', '-f', rtrim($rDir, '/')], true);
			return self::put($rDir . self::SEEDED, ['at' => time(), 'server_id' => $rServerID, 'streams' => count($rRows)]);
		}, self::SEED_WAIT);
		if ($rSeeded) {
			// The node reads its streams on itself again, and says so (as lapse() says it does not).
			SettingsAudit::republish();
		}
		return $rSeeded;
	}

	/**
	 * Seed the store in mode 2, where MAIN's rows are out of reach: whole
	 * with what the node kept itself (keep() takes its writes, seeded or
	 * not), nothing copied. Only while no stream producer runs on the node
	 * (ffmpeg, the native remuxer): MAIN's rows then hold nothing it still
	 * needs, as their pids name processes that are gone. A node born in
	 * mode 2 (lb_new_node_mode api) seeds here on its first CLI reader; one
	 * whose store lapsed while its streams run stays unseeded until they
	 * stop or it is seeded in mode 1. False when a producer runs, or the
	 * lock is busy.
	 */
	private static function seedLocal(): bool {
		if (!defined('SERVER_ID') || ProcessManager::countStreamProducers(self::$rProcRoot ?? '/proc') > 0) {
			return false;
		}
		$rServerID = (int) SERVER_ID;
		$rSeeded = self::locked(static fn (): bool => self::seeded() || self::put(self::dir() . self::SEEDED, ['at' => time(), 'server_id' => $rServerID, 'streams' => count(self::ids())]), self::SEED_WAIT);
		if ($rSeeded) {
			// The node reads its streams on itself, and says so (as seed() does).
			SettingsAudit::republish();
		}
		return $rSeeded;
	}

	/**
	 * A write that went to MAIN's row alone (STREAMS off, or the agent took
	 * no event and the node wrote MAIN's database itself), called once that
	 * write landed, or one the store did not keep: the store no longer
	 * follows this node's streams, and is seeded again before a reader takes
	 * it. Under the store's lock, as the node's user, so a seed that read
	 * MAIN's rows before the write landed is not left marked whole: it sees
	 * the generation move, or its marker goes here. A node with no store
	 * (MAIN, mode 0) has nothing to lapse, and none is made.
	 */
	public static function lapse(): void {
		if (!is_dir(self::dir())) {
			return;
		}
		$rFile = self::dir() . self::SEEDED;
		$rWas = is_file($rFile);
		$rDone = self::locked(static function () use ($rFile): bool {
			self::bump();
			@unlink($rFile);
			return true;
		});
		// Out of the lock's reach (root could not switch): never left marked whole.
		if (!$rDone) {
			@unlink($rFile);
		}
		if ($rWas) {
			// The node no longer reads its streams on itself, and says so at once.
			SettingsAudit::republish();
		}
	}

	/**
	 * Drop the entries of the streams and recordings the node no longer
	 * holds (cron:cleanup, from its whole R2 section), but never one
	 * written in the last PRUNE_GRACE seconds.
	 *
	 * @param list<int> $rStreams the streams the node holds
	 * @param list<int> $rRecordings the recordings scheduled on it
	 * @return array{streams: int, recordings: int} how many went
	 */
	public static function prune(array $rStreams, array $rRecordings): array {
		$rOut = ['streams' => 0, 'recordings' => 0];
		self::locked(static function () use ($rStreams, $rRecordings, &$rOut): bool {
			foreach (['streams' => $rStreams, 'recordings' => $rRecordings] as $rKind => $rHeld) {
				$rHeld = array_flip($rHeld);
				foreach (self::names($rKind) as $rID) {
					$rFile = self::dir() . $rKind . '/' . $rID . '.json';
					if (!isset($rHeld[$rID]) && time() - (int) @filemtime($rFile) > self::PRUNE_GRACE && @unlink($rFile)) {
						$rOut[$rKind]++;
					}
				}
			}
			return true;
		});
		return $rOut;
	}

	/**
	 * Hand on what the node kept but the agent did not take (it was
	 * stopped, in mode 2): each entry's unsent columns, once, as $rSend
	 * spools them. A column $rSend took is no longer unsent. The `unsent`
	 * marker goes only after a walk that left nothing unsent, and only if no
	 * write marked it again since the walk began (its token); a walk that
	 * died on the way leaves it for the next.
	 *
	 * @param callable(int, array<string, mixed>): bool $rSend
	 * @return int the entries handed on
	 */
	public static function resend(callable $rSend): int {
		// Only when a write left something unsent.
		$rMarker = self::dir() . self::UNSENT;
		if (!is_file($rMarker)) {
			return 0;
		}
		$rToken = self::read($rMarker)['token'] ?? null;
		$rDone = 0;
		$rLeft = false;
		foreach (self::ids() as $rID) {
			$rFile = self::dir() . 'streams/' . $rID . '.json';
			$rDoc = self::read($rFile, true);
			if (!is_array($rDoc) || $rDoc['unsent'] === []) {
				continue;
			}
			$rLeft = !self::locked(static function () use ($rFile, $rID, $rSend, &$rDone): bool {
				$rDoc = self::read($rFile, true);
				if (!is_array($rDoc) || $rDoc['unsent'] === []) {
					return true;
				}
				if (!$rSend($rID, array_intersect_key($rDoc['fields'], array_flip($rDoc['unsent'])))) {
					// Not taken this time: the marker stays, and the next resend() looks again.
					return false;
				}
				$rDoc['unsent'] = [];
				$rDone++;
				return self::put($rFile, $rDoc);
			}) || $rLeft;
		}
		if (!$rLeft) {
			self::locked(static function () use ($rMarker, $rToken): bool {
				$rNow = self::read($rMarker);
				return !is_array($rNow) || ($rNow['token'] ?? null) !== $rToken || @unlink($rMarker);
			});
		}
		return $rDone;
	}

	/**
	 * MAIN's rows for this node, as the store's entries: stream id =>
	 * entry, only the streams with state (a row whose columns all hold
	 * their default needs no entry). Null when MAIN's database does not
	 * answer.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	private static function mainRows(?object $rDb, int $rServerID): ?array {
		try {
			$rDb ??= DatabaseFactory::get();
			if (!is_object($rDb) || !$rDb->query('SELECT `server_stream_id`, `stream_id`, `' . implode('`, `', StreamStateWriter::STATE_FIELDS) . '` FROM `streams_servers` WHERE `server_id` = ?;', $rServerID)) {
				return null;
			}
			$rOut = [];
			foreach ($rDb->get_rows() as $rRow) {
				$rOut[(int) $rRow['stream_id']] = ['id' => (int) $rRow['stream_id'], 'ssid' => (int) $rRow['server_stream_id'], 'fields' => StreamStateFields::pick($rRow, StreamStateWriter::STATE_FIELDS), 'unsent' => []];
			}
			if (!$rDb->query('SELECT `id`, `tv_archive_server_id`, `tv_archive_pid`, `vframes_server_id`, `vframes_pid` FROM `streams` WHERE `tv_archive_server_id` = ? OR `vframes_server_id` = ?;', $rServerID, $rServerID)) {
				return null;
			}
			foreach ($rDb->get_rows() as $rRow) {
				$rID = (int) $rRow['id'];
				foreach (['tv_archive', 'vframes'] as $rWorker) {
					if ((int) $rRow[$rWorker . '_server_id'] === $rServerID) {
						$rOut[$rID] ??= ['id' => $rID, 'ssid' => null, 'fields' => [], 'unsent' => []];
						$rOut[$rID]['fields'][$rWorker . '_pid'] = $rRow[$rWorker . '_pid'];
					}
				}
			}
		} catch (\Throwable) {
			return null;
		}
		return array_filter($rOut, static function (array $rEntry): bool {
			foreach ($rEntry['fields'] as $rColumn => $rValue) {
				if ($rValue !== null && !(in_array($rColumn, ['to_analyze', 'stream_status', 'compatible', 'tv_archive_pid', 'vframes_pid'], true) && (int) $rValue === 0)) {
					return true;
				}
			}
			return false;
		});
	}

	/**
	 * The stream a writer's key names on this node: its id, null when the
	 * key names another server's row, false when a server_stream_id names
	 * none this process or the replica knows.
	 *
	 * @param array<string, int> $rKey
	 */
	private static function streamOfKey(array $rKey): int|false|null {
		if (isset($rKey['stream_id'])) {
			return isset($rKey['server_id']) && (!defined('SERVER_ID') || $rKey['server_id'] !== (int) SERVER_ID) ? null : (int) $rKey['stream_id'];
		}
		$rSsid = (int) ($rKey['ssid'] ?? 0);
		if (isset(self::$rSsids[$rSsid])) {
			return self::$rSsids[$rSsid];
		}
		if (self::$rIndexSsids === null || !isset(self::$rIndexSsids[$rSsid])) {
			self::$rIndexSsids = [];
			foreach (ReplicaStreamCache::index() as $rID => $rMeta) {
				if (is_int($rMeta['ssid'] ?? null)) {
					self::$rIndexSsids[$rMeta['ssid']] = (int) $rID;
				}
			}
		}
		return self::$rIndexSsids[$rSsid] ?? false;
	}

	/**
	 * A write for this node's stream that the store did not keep: logged,
	 * and on a node that may still reach MAIN's database the store lapses,
	 * to be seeded again. A node in mode 2 cannot seed, so it keeps it (the
	 * readers may then miss that write: a known limit past MAX_STREAMS).
	 *
	 * @param array<string, int> $rKey
	 */
	private static function missed(array $rKey): void {
		error_log('XC_VM: stream state not kept on this node (' . json_encode($rKey) . ')');
		if (!NodeRole::refusesConnects()) {
			self::lapse();
		}
	}

	/**
	 * Merge a write into its stream's entry, and bump the generation.
	 *
	 * @param array<string, mixed> $rFields
	 * @param bool $rSent nothing to resend: the agent took it, or the writer writes MAIN's row itself
	 */
	private static function merge(int $rID, ?int $rSsid, array $rFields, bool $rSent): bool {
		$rFile = self::dir() . 'streams/' . $rID . '.json';
		$rEntry = self::read($rFile, true);
		if ($rEntry === null) {
			if (!self::room('streams', self::$rLimits['streams'] ?? self::MAX_STREAMS)) {
				return false;
			}
			$rEntry = ['id' => $rID, 'ssid' => null, 'fields' => [], 'unsent' => []];
		}
		foreach ($rFields as $rColumn => $rValue) {
			if (is_string($rValue) && strlen($rValue) > (self::$rLimits['value'] ?? self::MAX_VALUE)) {
				return false;
			}
			$rEntry['fields'][$rColumn] = StreamStateFields::value($rColumn, $rValue);
		}
		if ($rSsid !== null) {
			$rEntry['ssid'] = $rSsid;
		}
		$rColumns = array_keys($rFields);
		$rEntry['unsent'] = array_values($rSent ? array_diff($rEntry['unsent'], $rColumns) : array_unique(array_merge($rEntry['unsent'], $rColumns)));
		// The marker before the entry: an entry holding unsent columns is never left without it.
		if (!$rSent && !self::put(self::dir() . self::UNSENT, ['at' => time(), 'token' => bin2hex(random_bytes(8))])) {
			return false;
		}
		if (!self::put($rFile, $rEntry)) {
			return false;
		}
		self::bump();
		return true;
	}

	/** The store's generation (under its lock): 0 before any write. */
	private static function generation(): int {
		return (int) @file_get_contents(self::dir() . self::GENERATION);
	}

	/** A write was kept or the store lapsed: a seed that read MAIN's rows before it must not copy them. Under the lock. */
	private static function bump(): void {
		@file_put_contents(self::dir() . self::GENERATION, (string) (self::generation() + 1));
	}

	/** Nothing the node spooled is still pending (the P0 lane empty), and nothing it kept is unsent. */
	private static function quiet(): bool {
		return (glob(EventSpool::dir() . 'p0/*.ndjson') ?: []) === [] && !is_file(self::dir() . self::UNSENT);
	}

	private static function defaultOf(string $rColumn, ?int $rType): mixed {
		return match ($rColumn) {
			'to_analyze', 'stream_status', 'compatible' => 0,
			'pids_create_channel', 'cchannel_rsources' => $rType === 3 ? '[]' : null,
			default => null,
		};
	}

	/** Is there room for one more entry of this kind? */
	private static function room(string $rKind, int $rMax): bool {
		return count(glob(self::dir() . $rKind . '/*.json') ?: []) < $rMax;
	}

	/**
	 * @return list<int>
	 */
	private static function names(string $rKind): array {
		return FileIds::of(glob(self::dir() . $rKind . '/*.json') ?: [], '.json', false);
	}

	/**
	 * A file as written; a stream's entry ($rEntry) has `fields` and `unsent`.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function read(string $rFile, bool $rEntry = false): ?array {
		$rDoc = json_decode((string) @file_get_contents($rFile), true);
		if (!is_array($rDoc)) {
			return null;
		}
		if ($rEntry && (!is_array($rDoc['fields'] ?? null) || !is_array($rDoc['unsent'] ?? null))) {
			return null;
		}
		return $rDoc;
	}

	/**
	 * Write a file aside and rename it in: 0600, flushed to disk first
	 * unless $rSync is false (the seed flushes all its entries at once).
	 *
	 * @param array<string, mixed> $rDoc
	 */
	private static function put(string $rFile, array $rDoc, bool $rSync = true): bool {
		$rDir = dirname($rFile);
		if (!is_dir($rDir) && !@mkdir($rDir, 0700, true) && !is_dir($rDir)) {
			return false;
		}
		$rJson = json_encode($rDoc, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		if ($rJson === false) {
			return false;
		}
		return AtomicFile::write($rFile, $rJson, 0600, $rSync);
	}

	/**
	 * Run $rWork under the store's lock, as the node's user: false when the
	 * store cannot be reached (no directory, root could not switch) or the
	 * lock was not free within $rWait seconds.
	 *
	 * @param \Closure(): bool $rWork
	 */
	private static function locked(\Closure $rWork, float $rWait = INF): bool {
		$rDir = self::dir();
		return SettingsAudit::asAgentUser(static function () use ($rWork, $rWait, $rDir): bool {
			if (!is_dir($rDir) && !@mkdir($rDir, 0700, true) && !is_dir($rDir)) {
				return false;
			}
			if ((fileperms($rDir) & 0777) !== 0700) {
				@chmod($rDir, 0700);
			}
			$rLock = @fopen($rDir . self::LOCK, 'c');
			if ($rLock === false) {
				return false;
			}
			try {
				$rUntil = microtime(true) + $rWait;
				while (!flock($rLock, $rWait === INF ? LOCK_EX : LOCK_EX | LOCK_NB)) {
					if ($rWait === INF || microtime(true) >= $rUntil) {
						return false;
					}
					usleep(20000);
				}
				try {
					return $rWork();
				} finally {
					flock($rLock, LOCK_UN);
				}
			} finally {
				fclose($rLock);
			}
		}, dirname(rtrim($rDir, '/')) . '/');
	}
}
