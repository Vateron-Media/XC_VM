<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\Crypto\Canonical;
use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Process\ProcessManager;
use XcVm\Core\Util\AtomicFile;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * MAIN's two PHP-FPM pools for the cluster API (plan, "Transport"), apart
 * from the panel's pools, so a fleet's long-polls and ingest never take the
 * workers that serve the panel and viewers:
 *
 * | Pool | Lanes | `pm.max_children` | Timeout |
 * | --- | --- | --- | --- |
 * | `cluster_ctl` | poll, ctl | 2·nodes + 24 | 60 s |
 * | `cluster_ingest` | p0, bulk | min(2·`cluster_ingest_concurrency` + 8, floor(0.25 · MariaDB `max_connections`)) | 90 s |
 *
 * `nodes` counts the streaming servers other than MAIN, enrolled or not, so
 * the pool is sized before a node's first long-poll holds a worker.
 *
 * Files, all MAIN only (the LB build strips Domain/Cluster):
 *
 * - configs in `bin/php/etc/cluster/<pool>.conf`, a directory of their own:
 *   `set_services` deletes and rewrites `bin/php/etc/*.conf` for the panel's
 *   pools;
 * - sockets in `bin/php/sockets/<pool>.sock`, where nginx's `cluster_ctl` and
 *   `cluster_ingest` upstreams point, with a panel pool as their backup;
 * - pid files in `bin/php/var/run/`, not `sockets/`, where `restart_php_fpm`
 *   counts the panel's pools.
 *
 * ensure() runs as the pool user (xc_vm): from `status` through
 * `cluster:pools` (at boot and after an update) and each minute from
 * cron:servers. It writes the configs, starts a pool that is not running and
 * reloads one whose size changed. The marker `tmp/cluster_ready` is written
 * once both pools answer FPM's own ping. Until then gate() answers every op
 * but `health` with a panel-signed 503 STARTING, so agents back off instead
 * of holding panel workers with their long-polls. The service removes the
 * marker whenever it (re)starts, and tmp/ is a tmpfs, so a reboot does too.
 * A request that cannot reach a pool's socket goes to a panel pool (nginx).
 *
 * listenQueueMs() tells MAIN's liveness loop how long `cluster_ctl` has had
 * requests waiting for a worker: a queue lasting over 5 s raises the fleet
 * guard (LivenessService).
 */
final class ClusterPool {
	use DatabaseAware;
	use InstallBase;

	/** pool => request_terminate_timeout (s) */
	public const POOLS = ['cluster_ctl' => 60, 'cluster_ingest' => 90];

	/** The plan's control ops (lanes poll and ctl), served by cluster_ctl; so is an unknown op, to be refused. */
	public const CTL_OPS = [
		'health', 'challenge', 'enrol_complete', 'enrol_code', 'enrol_code_status', 'token_refresh',
		'token_rekey', 'hello', 'heartbeat', 'conn_admit', 'commands', 'ack',
	];

	/**
	 * The plan's ingest ops (lanes p0 and bulk), served by cluster_ingest,
	 * including those the API does not serve. ClusterNginxConfig renders the
	 * /cluster/v1/ location's ingest lane from this list, and the list stays
	 * the plan's 24 ops (ClusterPoolTest) — an op nothing serves is refused by
	 * the API itself, so routing it costs nothing.
	 *
	 * Two of them will not be served, and the reasons are in ADR 0004:
	 * `rpc_result` (a command's result comes back inline with its `ack`, which
	 * takes 64 KiB) and `stream_bundle` (the R2 `streams` section carries a
	 * stream's whole record, and a cache miss reads it from the replica).
	 */
	public const INGEST_OPS = [
		'events', 'config', 'streams', 'conn_snapshot', 'stream_bundle', 'rpc_result',
		'recording_complete', 'vod_analysis', 'queue_claim', 'queue_update', 'queue_enqueue', 'artefact',
	];

	/** The ingest pool's floor: one P0 and one bulk request, however small MariaDB is. */
	public const MIN_INGEST = 2;

	/** The back-off the STARTING denial asks for. */
	public const RETRY_AFTER_MS = 5000;

	/** Relative to the install root; tmp/ is TMP_PATH. */
	public const MARKER = 'tmp/cluster_ready';

	private const CONF_DIR = 'bin/php/etc/cluster/';

	private const RUN_DIR = 'bin/php/var/run/';

	private const PING_PATH = '/ping';

	private const PING_RESPONSE = 'pong';

	/** Seconds one ping may take. */
	private const PING_TIMEOUT = 2.0;

	/** Seconds the listen-queue probe waits for a worker to take its request. */
	public const QUEUE_PROBE_WAIT = 0.25;

	/** `pm.status_path`, asked for with `?json`. */
	private const STATUS_PATH = '/status';

	/** The most of an answer the FastCGI client reads. */
	private const MAX_ANSWER = 65536;

	/** Linux's EAGAIN, a unix socket's connect when its listen backlog is full. */
	private const EAGAIN = 11;

	/**
	 * The listen-queue probe, across one process's calls: its pool, its status
	 * request while no worker has answered it (null once answered), what it
	 * has read so far, when it was sent, and since when the pool has had a
	 * queue (hrtime ms, null: none).
	 *
	 * @var array{pool: string, conn: resource|null, buf: string, sent: int, queued: ?int}|null
	 */
	private static ?array $rProbe = null;

	private static ?\Closure $rProcs = null;

	private static string $rProcRoot = '/proc';

	/** Tests: another install root (null: MAIN_HOME), and the user the pools run as; the listen-queue probe dropped. */
	public static function useBase(?string $rBase, string $rUser = 'xc_vm'): void {
		self::$rBase = $rBase;
		self::$rUser = $rUser;
		self::dropProbe();
	}

	/**
	 * Tests: fake the pools' processes with fn(string $rAction, string $rPool): bool,
	 * for the actions alive, answers, start and reload (null: the real ones).
	 */
	public static function useProcs(?\Closure $rProcs): void {
		self::$rProcs = $rProcs;
	}

	/** Tests: the procfs where a pool's master is looked up. */
	public static function useProcRoot(string $rProcRoot = '/proc'): void {
		self::$rProcRoot = $rProcRoot;
	}

	/** The pool that serves an op: its lane's. */
	public static function poolFor(string $rOp): string {
		return in_array($rOp, self::INGEST_OPS, true) ? 'cluster_ingest' : 'cluster_ctl';
	}

	public static function ctlChildren(int $rNodes): int {
		return 2 * max(0, $rNodes) + 24;
	}

	/** @param int|null $rMaxConnections MariaDB's max_connections, null when unknown */
	public static function ingestChildren(int $rConcurrency, ?int $rMaxConnections): int {
		$rChildren = 2 * ClusterSettings::clampInt('cluster_ingest_concurrency', $rConcurrency) + 8;
		if ($rMaxConnections !== null && $rMaxConnections > 0) {
			$rChildren = min($rChildren, intdiv($rMaxConnections, 4));
		}
		return max(self::MIN_INGEST, $rChildren);
	}

	/**
	 * pool => pm.max_children, from the servers table, the settings and
	 * MariaDB's limit. What cannot be read falls back to its default.
	 *
	 * @return array<string, int>
	 */
	public static function sizes(): array {
		$rNodes = 0;
		$rMaxConnections = null;
		$rRead = static function (string $rQuery): ?array {
			try {
				$rDb = self::db();
				if (!$rDb->query($rQuery)) {
					return null;
				}
				$rRow = $rDb->get_row();
				return is_array($rRow) && $rRow !== [] ? $rRow : null;
			} catch (\Throwable) {
				return null;
			}
		};
		$rRow = $rRead('SELECT COUNT(*) AS `nodes` FROM `servers` WHERE `is_main` = 0 AND `server_type` = 0;');
		if ($rRow !== null) {
			$rNodes = (int) $rRow['nodes'];
		}
		$rRow = $rRead('SELECT `cluster_ingest_concurrency` FROM `settings` LIMIT 1;');
		$rConcurrency = ClusterSettings::int('cluster_ingest_concurrency', $rRow['cluster_ingest_concurrency'] ?? null);
		$rRow = $rRead('SELECT @@GLOBAL.max_connections AS `max_connections`;');
		if ($rRow !== null && is_numeric($rRow['max_connections'])) {
			$rMaxConnections = (int) $rRow['max_connections'];
		}
		return ['cluster_ctl' => self::ctlChildren($rNodes), 'cluster_ingest' => self::ingestChildren($rConcurrency, $rMaxConnections)];
	}

	/** A pool's FPM config, in the panel template's style (bin/php/etc/template). */
	public static function render(string $rPool, int $rChildren, string $rBase, string $rUser = 'xc_vm'): string {
		return '; Generated by XC_VM (ClusterPool): the cluster API\'s ' . $rPool . " pool. Do not edit.\n"
			. "[global]\n"
			. 'pid = ' . $rBase . self::RUN_DIR . $rPool . ".pid\n"
			. "events.mechanism = epoll\n"
			. "daemonize = yes\n"
			. "rlimit_files = 4000\n"
			// A reload lets short requests finish; a held long-poll is cut and retried.
			. "process_control_timeout = 5s\n"
			. '[' . $rPool . "]\n"
			. 'listen = ' . self::socket($rPool, $rBase) . "\n"
			. 'listen.owner = ' . $rUser . "\n"
			. 'listen.group = ' . $rUser . "\n"
			. "listen.mode = 0660\n"
			. "pm = ondemand\n"
			. 'pm.max_children = ' . max(1, $rChildren) . "\n"
			. "pm.max_requests = 40000\n"
			. "pm.process_idle_timeout = 10s\n"
			. 'request_terminate_timeout = ' . self::POOLS[$rPool] . "s\n"
			. "security.limit_extensions = .php\n"
			. 'ping.path = ' . self::PING_PATH . "\n"
			. 'ping.response = ' . self::PING_RESPONSE . "\n"
			. "pm.status_path = /status\n";
	}

	public static function socket(string $rPool, ?string $rBase = null): string {
		return ($rBase ?? self::base()) . 'bin/php/sockets/' . $rPool . '.sock';
	}

	/** Both pools answered after their masters started, and ensure() has found neither master gone since. */
	public static function ready(): bool {
		$rBase = self::base();
		return $rBase !== null && is_file($rBase . self::MARKER);
	}

	/** The service is (re)starting: not ready until ensure() finds both pools answering. */
	public static function unmark(): void {
		$rBase = self::base();
		if ($rBase !== null) {
			@unlink($rBase . self::MARKER);
		}
	}

	/**
	 * What the API answers while it is starting, or null to serve the request:
	 * until ready(), every op but `health` gets a panel-signed 503 STARTING,
	 * bound to the node and the nonce when the request names them.
	 *
	 * @param array{path: string, headers?: array<string, string>} $rReq
	 * @return array{status: int, headers: array<string, string>, body: string}|null
	 */
	public static function gate(ClusterCrypto $rCrypto, array $rReq): ?array {
		if ((string) $rReq['path'] === Canonical::PATH_PREFIX . 'health' || self::ready()) {
			return null;
		}
		$rH = Canonical::parseHeaders($rReq['headers'] ?? []);
		return DenialFactory::deny($rCrypto, 503, 'STARTING', $rH['node'] ?? null, $rH['nonce'] ?? null, ['retry_after_ms' => self::RETRY_AFTER_MS]);
	}

	/** Does the pool answer FPM's ping now? */
	public static function answers(string $rPool): bool {
		return self::proc('answers', $rPool);
	}

	/** Is the pool's FPM master running? */
	public static function alive(string $rPool): bool {
		return self::proc('alive', $rPool);
	}

	/**
	 * For how long the pool's listen queue has lasted, in ms: 0 when there is
	 * none, null when the pool cannot tell (no socket, nobody listening, a cut
	 * answer, no status page or another pool's). One status request per call,
	 * blocking for QUEUE_PROBE_WAIT at most, and one waiting at a time.
	 *
	 * FPM counts `listen queue` on TCP sockets only; on the pools' unix
	 * sockets it always says 0. So the probe times its own request: FPM
	 * serves the status page from a worker, and a request no worker takes
	 * within QUEUE_PROBE_WAIT waits in the listen queue. It is left there,
	 * and later calls read it without blocking. A late answer says only that
	 * the requests ahead of it were served, so a new request goes at once:
	 * the queue has lasted from the first request that waited until one is
	 * answered within QUEUE_PROBE_WAIT with FPM counting no queue. A connect
	 * refused because the backlog is full is a queue as well.
	 */
	public static function listenQueueMs(string $rPool): ?int {
		if (self::$rProbe !== null && self::$rProbe['pool'] !== $rPool) {
			self::dropProbe();
		}
		for ($rTry = 0; $rTry < 2; $rTry++) {
			$rProbe = self::$rProbe;
			$rFresh = $rProbe === null || $rProbe['conn'] === null;
			if ($rFresh) {
				$rErrNo = 0;
				$rConn = self::fcgiSend(self::socket($rPool), self::STATUS_PATH, 'json', self::QUEUE_PROBE_WAIT, $rErrNo);
				if ($rConn === null && $rErrNo === self::EAGAIN) {
					// The listen backlog is full: requests certainly wait.
					$rNow = self::monoMs();
					self::$rProbe = ['pool' => $rPool, 'conn' => null, 'buf' => '', 'sent' => $rNow, 'queued' => $rProbe['queued'] ?? $rNow];
					return max(1, $rNow - self::$rProbe['queued']);
				}
				if ($rConn === null) {
					self::dropProbe();
					return null;
				}
				$rProbe = ['pool' => $rPool, 'conn' => $rConn, 'buf' => '', 'sent' => self::monoMs(), 'queued' => $rProbe['queued'] ?? null];
				self::$rProbe = $rProbe;
			}
			$rEnded = self::fcgiRead($rProbe['conn'], $rProbe['buf'], $rFresh ? self::QUEUE_PROBE_WAIT : 0.0);
			$rNow = self::monoMs();
			if ($rEnded === false) {
				// No worker has taken it: it waits in the listen queue.
				$rProbe['queued'] ??= $rProbe['sent'];
				self::$rProbe = $rProbe;
				return max(1, $rNow - $rProbe['queued']);
			}
			$rQueue = $rEnded ? self::statusQueue((string) self::fcgiBody($rProbe['buf']), $rPool) : null;
			if ($rQueue === null) {
				self::dropProbe();
				return null;
			}
			fclose($rProbe['conn']);
			self::$rProbe = ['pool' => $rPool, 'conn' => null, 'buf' => '', 'sent' => $rNow, 'queued' => $rProbe['queued']];
			if ($rQueue > 0) {
				self::$rProbe['queued'] ??= $rNow;
				return max(1, $rNow - self::$rProbe['queued']);
			}
			if ($rFresh) {
				self::$rProbe = null;
				return 0;
			}
			// A late answer: ask again.
		}
		return null;
	}

	/**
	 * Bring both pools to their current size: write the configs, start a pool
	 * that is not running, reload one whose config changed. Without the
	 * marker, it waits up to $rWaitSec for both pools to answer and then
	 * writes it, which restarts the nodes' silence clock
	 * (ClusterMeta::markReady), since the API was not serving. It removes the
	 * marker only when a pool's master is gone. A reload keeps it: the socket
	 * stays open and requests queue on it while FPM lets the busy ones finish,
	 * which a held long-poll stretches to process_control_timeout. Returns
	 * whether the API is ready.
	 *
	 * Runs only as the pool user, and does nothing otherwise: the files live
	 * in directories that user owns, and root would follow a link it planted
	 * there. `status` (root) therefore runs `cluster:pools` as xc_vm. A lock
	 * serialises it with cron:servers.
	 */
	public static function ensure(float $rWaitSec = 10.0): bool {
		$rBase = self::base();
		if ($rBase === null || !self::runsAsUser()) {
			return false;
		}
		foreach ([self::CONF_DIR, self::RUN_DIR] as $rDir) {
			if (!is_dir($rBase . $rDir)) {
				@mkdir($rBase . $rDir, 0755, true);
			}
		}
		$rLock = @fopen($rBase . self::CONF_DIR . '.lock', 'c');
		if ($rLock !== false) {
			flock($rLock, LOCK_EX);
		}
		try {
			return self::ensureLocked($rBase, $rWaitSec);
		} finally {
			if ($rLock !== false) {
				flock($rLock, LOCK_UN);
				fclose($rLock);
			}
		}
	}

	private static function ensureLocked(string $rBase, float $rWaitSec): bool {
		$rMarked = self::ready();
		foreach (self::sizes() as $rPool => $rChildren) {
			$rChanged = self::write(self::conf($rPool), self::render($rPool, $rChildren, $rBase, self::$rUser));
			if (!self::proc('alive', $rPool)) {
				if ($rMarked) {
					self::unmark();
					$rMarked = false;
				}
				self::proc('start', $rPool);
			} elseif ($rChanged) {
				self::proc('reload', $rPool);
			}
		}
		if ($rMarked) {
			// Both masters run and have answered since they started. Touched each
			// pass: cron:tmp deletes files in tmp/ ten minutes old, and the API
			// would answer STARTING until the next pass wrote the marker again.
			@touch($rBase . self::MARKER);
			return true;
		}

		$rDeadline = microtime(true) + max(0.0, $rWaitSec);
		while (true) {
			$rAnswer = self::proc('answers', 'cluster_ctl') && self::proc('answers', 'cluster_ingest');
			if ($rAnswer || microtime(true) >= $rDeadline) {
				break;
			}
			usleep(200000);
		}
		if (!$rAnswer || @file_put_contents($rBase . self::MARKER, (string) ClusterClock::nowMs()) === false) {
			return false;
		}
		try {
			ClusterMeta::markReady();
		} catch (\Throwable) {
			// Liveness then counts from the last ready_at; the pools serve regardless.
		}
		return true;
	}

	private static function conf(string $rPool): string {
		return self::base() . self::CONF_DIR . $rPool . '.conf';
	}

	private static function proc(string $rAction, string $rPool): bool {
		if (self::$rProcs !== null) {
			return (bool) (self::$rProcs)($rAction, $rPool);
		}
		switch ($rAction) {
			case 'alive':
				return self::masterPid($rPool) !== null;
			case 'answers':
				return self::ping(self::socket($rPool));
			case 'start':
				return self::start($rPool);
			case 'reload':
				// FPM re-reads its config and replaces its workers, on the same socket.
				$rPid = self::masterPid($rPool);
				return $rPid !== null && posix_kill($rPid, defined('SIGUSR2') ? SIGUSR2 : 12);
		}
		return false;
	}

	/** The pool's FPM master, found by the config it was started with. */
	private static function masterPid(string $rPool): ?int {
		$rPIDs = ProcessManager::findProcessPIDs(['php-fpm: master process (' . self::conf($rPool) . ')'], 1, self::$rProcRoot);
		return $rPIDs === [] ? null : (int) $rPIDs[0];
	}

	/** Start the pool's FPM master, which daemonizes, as the pool user ensure() runs as. */
	private static function start(string $rPool): bool {
		$rBin = self::base() . 'bin/php/sbin/php-fpm';
		if (!is_executable($rBin)) {
			return false;
		}
		$rArgv = [$rBin, '--daemonize', '--fpm-config', self::conf($rPool)];
		$rNull = ['file', '/dev/null', 'w'];
		$rProc = proc_open($rArgv, [0 => ['file', '/dev/null', 'r'], 1 => $rNull, 2 => $rNull], $rPipes);
		return is_resource($rProc) && proc_close($rProc) === 0;
	}

	/**
	 * FPM's own ping (`ping.path`), as one FastCGI request over the pool's
	 * socket: a worker answered when the body is the ping response. nginx
	 * never forwards it, since the API's location fixes SCRIPT_NAME.
	 */
	private static function ping(string $rSocket): bool {
		$rConn = self::fcgiSend($rSocket, self::PING_PATH, '', self::PING_TIMEOUT);
		if ($rConn === null) {
			return false;
		}
		$rBuf = '';
		$rEnded = self::fcgiRead($rConn, $rBuf, self::PING_TIMEOUT);
		fclose($rConn);
		return $rEnded === true && trim((string) self::fcgiBody($rBuf)) === self::PING_RESPONSE;
	}

	/**
	 * FPM's `listen queue` from its JSON status page for $rPool, or null
	 * when the body is not one.
	 */
	private static function statusQueue(string $rBody, string $rPool): ?int {
		$rDoc = json_decode($rBody, true);
		if (!is_array($rDoc) || ($rDoc['pool'] ?? null) !== $rPool || !is_int($rDoc['listen queue'] ?? null)) {
			return null;
		}
		return max(0, $rDoc['listen queue']);
	}

	/**
	 * Connect to a pool's socket and send a FastCGI GET for $rScript, its
	 * whole request (BEGIN_REQUEST as a responder that closes, PARAMS, end of
	 * PARAMS, empty STDIN). Null when the socket does not take it; then
	 * $rErrNo is the connect's errno (0 when the connect was not the cause).
	 *
	 * @return resource|null
	 */
	private static function fcgiSend(string $rSocket, string $rScript, string $rQuery, float $rTimeout, int &$rErrNo = 0) {
		$rConn = @stream_socket_client('unix://' . $rSocket, $rCode, $rErrStr, $rTimeout);
		$rErrNo = $rConn === false ? $rCode ?? 0 : 0;
		if ($rConn === false) {
			return null;
		}
		$rRecord = static fn(int $rType, string $rBody): string => pack('CCnnCC', 1, $rType, 1, strlen($rBody), 0, 0) . $rBody;
		$rParams = '';
		foreach (['REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => $rScript, 'SCRIPT_FILENAME' => $rScript, 'QUERY_STRING' => $rQuery] as $rName => $rValue) {
			$rParams .= chr(strlen($rName)) . chr(strlen($rValue)) . $rName . $rValue;
		}
		if (@fwrite($rConn, $rRecord(1, pack('nCx5', 1, 0)) . $rRecord(4, $rParams) . $rRecord(4, '') . $rRecord(5, '')) === false) {
			fclose($rConn);
			return null;
		}
		stream_set_blocking($rConn, false);
		return $rConn;
	}

	/**
	 * Read an answer's records into $rBuf for up to $rWaitSec: true once its
	 * END_REQUEST is in, false while it is still to come, null when the
	 * connection ends or breaks first, or the answer passes MAX_ANSWER.
	 *
	 * @param resource $rConn a non-blocking connection from fcgiSend()
	 */
	private static function fcgiRead($rConn, string &$rBuf, float $rWaitSec): ?bool {
		$rDeadline = microtime(true) + $rWaitSec;
		while (true) {
			$rChunk = @fread($rConn, 8192);
			if ($rChunk === false) {
				return null;
			}
			if ($rChunk !== '') {
				$rBuf .= $rChunk;
				if (self::fcgiRecords($rBuf)[0]) {
					return true;
				}
				if (strlen($rBuf) > self::MAX_ANSWER) {
					return null;
				}
				continue;
			}
			if (feof($rConn)) {
				return null;
			}
			$rLeft = $rDeadline - microtime(true);
			if ($rLeft <= 0) {
				return false;
			}
			$rRead = [$rConn];
			$rWrite = $rExcept = null;
			if (@stream_select($rRead, $rWrite, $rExcept, 0, max(1, (int) ($rLeft * 1000000))) === false) {
				return null;
			}
		}
	}

	/**
	 * The complete records in $rBuf: whether END_REQUEST is among them, and
	 * their STDOUT.
	 *
	 * @return array{0: bool, 1: string}
	 */
	private static function fcgiRecords(string $rBuf): array {
		$rOut = '';
		$rAt = 0;
		while ($rAt + 8 <= strlen($rBuf)) {
			$rRec = (array) unpack('Cversion/Ctype/nid/nlength/Cpadding', $rBuf, $rAt);
			$rNext = $rAt + 8 + (int) $rRec['length'] + (int) $rRec['padding'];
			if ($rNext > strlen($rBuf)) {
				break;
			}
			if ((int) $rRec['type'] === 6) {
				$rOut .= substr($rBuf, $rAt + 8, (int) $rRec['length']);
			} elseif ((int) $rRec['type'] === 3) {
				return [true, $rOut];
			}
			$rAt = $rNext;
		}
		return [false, $rOut];
	}

	/** An answer's body: its STDOUT after the headers, or null. */
	private static function fcgiBody(string $rBuf): ?string {
		$rParts = preg_split('/\r?\n\r?\n/', self::fcgiRecords($rBuf)[1], 2);
		return is_array($rParts) && count($rParts) === 2 ? $rParts[1] : null;
	}

	/** Close the probe's waiting request, if any, and forget the queue it saw. */
	private static function dropProbe(): void {
		if (self::$rProbe !== null && is_resource(self::$rProbe['conn'])) {
			fclose(self::$rProbe['conn']);
		}
		self::$rProbe = null;
	}

	/** A monotonic clock (ms): the probe times waits, not dates. */
	private static function monoMs(): int {
		return intdiv(hrtime(true), 1000000);
	}

	/** Write $rContent when it differs from the file; true when it changed. */
	private static function write(string $rPath, string $rContent): bool {
		if (@file_get_contents($rPath) === $rContent) {
			return false;
		}
		return AtomicFile::write($rPath, $rContent, 0644);
	}
}
