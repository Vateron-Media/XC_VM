<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\AgentConnections;
use XcVm\Core\Cluster\AgentPaths;
use XcVm\Core\Cluster\ArtefactStage;
use XcVm\Core\Cluster\CacheJobs;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\Crypto\PanelSig;
use XcVm\Core\Cluster\NodeRpc;
use XcVm\Core\Cluster\QueueSink;
use XcVm\Core\Cluster\RootPin;
use XcVm\Core\Cluster\StreamRuntime;
use XcVm\Core\Util\AtomicFile;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Domain\Stream\StreamStateWriter;
use XcVm\Public\Controllers\Api\InternalApiController;
use XcVm\Streaming\Fanout\FanoutClient;

/**
 * ClusterExecCommand — run one MAIN command on this node (Phase 4). The agent
 * pipes a command it has verified, `{"doc": "<json>", "sig": "<b64url>"}`, on
 * stdin; this checks it again against the panel key the agent pinned
 * (config/cluster/agent.json), runs it and writes its result to stdout for
 * the agent's ack. Exit 0 means done.
 *
 * - `node.rpc` — its `action` (the envelope's, never an argument) one of
 *   NodeRpc::ACTIONS, run with its `args` through the handlers of the legacy
 *   /api (InternalApiController::runCommand);
 * - `conn.drop {uuid}` — a viewer the fanout serves (the agent runs it
 *   itself when it can reach the fanout);
 * - `conn.kill_worker {pid, rtmp}` — a viewer's PHP worker (this user's
 *   processes only) or an RTMP client.
 *
 * - `node.root` (its `action` the envelope's too) — handed to root
 *   (cluster:root) through the root inbox; root checks it against its own
 *   pin of the panel key.
 * - `node.cache {jobs}` — cache jobs for a node in mode 2, whose signals
 *   daemon reads no `signals` row: run as the daemon ran the rows
 *   (CacheJobs::run), only when every job is in the form MAIN signs it
 *   (CacheJobs::job) and they name at most CacheJobs::MAX targets
 *   (CacheJobs::targets); otherwise refused whole (exit 2) before any runs.
 * - `node.purge {jobs}` — the same, with only the jobs that remove something
 *   (CacheJobs::PURGES): restrictive, so MAIN sends it without a licence.
 * - `config.changed {sections}` — the agent fetches its replica at once; an
 *   agent that hands it here instead is acked `{"deferred": true}`, and its
 *   next minute's poll fetches the change.
 * - `stream.stop {stream_id}`, `vod.stop {stream_id}` — restrictive stops
 *   (signed without a licence), run as the legacy /api's stop runs them
 *   (StreamProcess::stopStream(), stopMovie()).
 * - `stream.assign {stream_ids, set, fill?}` — MAIN's own write to this
 *   node's streams' runtime columns (Rescan VOD, Recreate channels, a
 *   re-encoded channel's reset), into the node's store (StreamRuntime::assign):
 *   only StreamStateWriter::STATE_FIELDS, scalar values, at most
 *   StreamRuntime::ASSIGN_MAX streams; otherwise refused whole (exit 2).
 * - `queue.poke` — MAIN queued encoding work for this node: the queue
 *   daemon's next pass comes now (QueueSink::POKE).
 * - `artefact.fetch {artefact}` — an off-air video MAIN granted, which the
 *   agent downloaded into config/cluster/artefacts/<cmd_id>: placed where
 *   the node's off-air code plays it once its size and SHA-256 are the
 *   grant's (ArtefactStage::placeOffAir), else refused and audited (exit 1).
 *
 * Runs as xc_vm. `console.php cluster:exec --types` prints the command
 * types this node's PHP runs (TYPES, a JSON array) and reads nothing: the
 * agent asks it before it says a feature at hello whose commands only a
 * newer PHP runs (`artefact`: `artefact.fetch`). A PHP from before this
 * option reads its empty stdin as a command and exits 2.
 *
 * Usage: `console.php cluster:exec < command.json`, `console.php cluster:exec --types`
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterExecCommand implements CommandInterface {
	/** Commands older than their exp by more than this (LB clock) are refused. */
	public const SKEW = 300;

	/** The command types run here (`--types`). */
	public const TYPES = ['node.rpc', 'node.root', 'node.cache', 'conn.kill_worker', 'conn.drop', 'config.changed', ArtefactStage::TYPE_FETCH, 'stream.stop', 'vod.stop', 'stream.start', 'vod.start', 'stream.assign', 'queue.poke', 'node.purge'];

	public function getName(): string {
		return 'cluster:exec';
	}

	public function getDescription(): string {
		return 'Run one signed MAIN command (from xc_agent) on this node';
	}

	public function execute(array $rArgs): int {
		if (in_array('--types', $rArgs, true)) {
			echo json_encode(self::TYPES);
			return 0;
		}
		$rIn = json_decode((string) stream_get_contents(STDIN), true);
		$rCmd = self::verify(is_array($rIn) ? $rIn : [], AgentPaths::readState(), time());
		if (is_string($rCmd)) {
			self::fail($rCmd);
			return 2;
		}
		if (($rCmd['type'] ?? '') === 'node.root') {
			return self::handToRoot($rIn, (int) ($rCmd['seq'] ?? 0), isset($rCmd['args']['artefact']) ? self::ROOT_WAIT_ARTEFACT : self::ROOT_WAIT);
		}
		return self::run($rCmd);
	}

	/** Seconds cluster:exec waits for cluster:root's result before acking "queued". */
	public const ROOT_WAIT = 5;

	/**
	 * The same for a root command that carries an artefact, which root
	 * stages and checks (up to 128 MiB) before its action runs: long enough
	 * for root's refusal to reach MAIN in the ack, within the agent's minute.
	 */
	public const ROOT_WAIT_ARTEFACT = 45;

	/**
	 * `node.root`: root runs it (cluster:root), after checking it against its
	 * own pin of the panel key; here the signed command is only handed over,
	 * and root's result waited for briefly. Root's result goes to stdout; a
	 * failure (exit 1) goes to stderr too, since the agent acks a non-zero
	 * exit with stderr alone.
	 *
	 * @param array<string, mixed> $rIn {doc, sig}
	 */
	public static function handToRoot(array $rIn, int $rSeq, int $rWait = self::ROOT_WAIT): int {
		$rInbox = RootPin::inbox();
		if ($rSeq <= 0 || !is_dir($rInbox)) {
			self::fail("no root inbox (the node's root pin is not in place)");
			return 2;
		}
		$rDone = $rInbox . $rSeq . '.done';
		@unlink($rDone);
		if (!AtomicFile::write($rInbox . $rSeq . '.json', (string) json_encode(['doc' => $rIn['doc'], 'sig' => $rIn['sig']]))) {
			self::fail("cannot write the root inbox");
			return 2;
		}
		$rDeadline = microtime(true) + $rWait;
		while (microtime(true) < $rDeadline) {
			if (is_file($rDone)) {
				$rOut = json_decode((string) file_get_contents($rDone), true);
				@unlink($rDone);
				$rResult = is_array($rOut) ? (string) ($rOut['result'] ?? '') : '';
				echo $rResult;
				if (!is_array($rOut) || empty($rOut['ok'])) {
					self::fail($rResult !== '' ? $rResult : 'root\'s result is unreadable');
					return 1;
				}
				return 0;
			}
			usleep(200000);
		}
		echo json_encode(['queued' => true]);
		return 0;
	}

	/**
	 * Check a command against the agent's pinned panel key and identity.
	 *
	 * @param array<string, mixed> $rIn {doc, sig}
	 * @param array<string, mixed> $rState the agent's state (base64 fields)
	 * @return array<string, mixed>|string the command, or why it is refused
	 */
	public static function verify(array $rIn, array $rState, int $rNow): array|string {
		$rPub = base64_decode((string) ($rState['panel_sign_pub'] ?? ''), true);
		$rDoc = is_string($rIn['doc'] ?? null) ? $rIn['doc'] : '';
		$rSig = Enc::b64urlDecode((string) ($rIn['sig'] ?? ''));
		if ($rPub === false || !PanelSig::verify($rPub, 'cmd', $rDoc, (string) $rSig)) {
			return 'bad signature';
		}
		$rCmd = json_decode($rDoc, true);
		if (!is_array($rCmd) || ($rCmd['node_uuid'] ?? null) !== ($rState['node_uuid'] ?? '') || (int) ($rCmd['exp'] ?? 0) + self::SKEW < $rNow) {
			return 'not for this node, or expired';
		}
		return $rCmd;
	}

	/** @var (callable(string, int): void)|null */
	private static $rStopper = null;

	/** Tests: run stops through this instead of StreamProcess; null restores it. */
	public static function useStopper(?callable $rStopper): void {
		self::$rStopper = $rStopper;
	}

	/** @var resource|null where refusals go; null is STDERR */
	private static $rErrors = null;

	/** Tests: write refusals to this stream instead of STDERR; null restores it. */
	public static function useErrors($rStream): void {
		self::$rErrors = $rStream;
	}

	/** A refusal, for the agent's log: `cluster:exec: <why>`. */
	private static function fail(string $rWhy): void {
		fwrite(self::$rErrors ?? STDERR, 'cluster:exec: ' . $rWhy . "\n");
	}

	/** A typed stop or start of one stream, as `node.rpc`'s stream and vod actions ran it. */
	private static function stopStream(string $rType, int $rStreamID, bool $rForce = false): void {
		if (self::$rStopper !== null) {
			(self::$rStopper)($rType, $rStreamID);
			return;
		}
		switch ($rType) {
			case 'vod.stop':
				StreamProcess::stopMovie($rStreamID);
				break;
			case 'stream.stop':
				StreamProcess::stopStream($rStreamID, true);
				break;
			case 'stream.start':
				StreamProcess::startMonitor($rStreamID, 1);
				break;
			case 'vod.start':
				StreamProcess::stopMovie($rStreamID, true);
				if ($rForce) {
					StreamProcess::startMovie($rStreamID);
				} else {
					StreamProcess::queueMovie($rStreamID);
				}
				break;
		}
	}

	/**
	 * A `node.cache`'s or `node.purge`'s jobs, when every one is in the form
	 * MAIN signs it (CacheJobs::job) and they name at most CacheJobs::MAX
	 * targets (what one run may take within the agent's minute: MAIN splits
	 * longer lists); a purge's only the jobs that remove something
	 * (CacheJobs::PURGES). Null refuses the command whole, before any runs.
	 *
	 * @return list<array<string, mixed>>|null
	 */
	private static function cacheJobs(mixed $rJobs, bool $rPurge): ?array {
		if (!is_array($rJobs) || $rJobs === [] || !array_is_list($rJobs) || count($rJobs) > CacheJobs::MAX) {
			return null;
		}
		foreach ($rJobs as $rJob) {
			// Exactly the job MAIN's form makes of it, whatever its keys' order.
			$rClean = CacheJobs::job($rJob);
			if ($rClean !== null && is_array($rJob)) {
				ksort($rClean);
				ksort($rJob);
			}
			if ($rClean === null || $rClean !== $rJob || ($rPurge && !in_array($rClean['type'], CacheJobs::PURGES, true))) {
				return null;
			}
		}
		return CacheJobs::targets($rJobs) > CacheJobs::MAX ? null : $rJobs;
	}

	/**
	 * A `stream.assign`'s arguments: 1 to StreamRuntime::ASSIGN_MAX stream ids,
	 * and columns of StreamStateWriter::STATE_FIELDS with scalar or null values,
	 * at least one.
	 */
	private static function assignable(mixed $rIDs, mixed $rSet, mixed $rFill): bool {
		if (!is_array($rIDs) || $rIDs === [] || !array_is_list($rIDs) || count($rIDs) > StreamRuntime::ASSIGN_MAX || !is_array($rSet) || !is_array($rFill) || $rSet + $rFill === []) {
			return false;
		}
		foreach ($rIDs as $rID) {
			if (!is_int($rID) || $rID <= 0) {
				return false;
			}
		}
		foreach ($rSet + $rFill as $rColumn => $rValue) {
			if (!in_array($rColumn, StreamStateWriter::STATE_FIELDS, true) || !(is_scalar($rValue) || $rValue === null)) {
				return false;
			}
		}
		return true;
	}

	/** @param array<string, mixed> $rCmd */
	public static function run(array $rCmd): int {
		$rArgs = is_array($rCmd['args'] ?? null) ? $rCmd['args'] : [];
		switch ($rCmd['type'] ?? '') {
			case 'node.rpc':
				// The action is the envelope's, as the extension classes it; one
				// among the arguments as well would be a second answer to what runs.
				$rAction = $rCmd['action'] ?? null;
				if (!in_array($rAction, NodeRpc::ACTIONS, true) || array_key_exists('action', $rArgs)) {
					self::fail("unknown action");
					return 2;
				}
				(new InternalApiController())->runCommand(['action' => $rAction] + $rArgs);
				return 0;

			case 'conn.kill_worker':
				$rPID = (int) ($rArgs['pid'] ?? 0);
				if ($rPID <= 0) {
					return 2;
				}
				if (!empty($rArgs['rtmp'])) {
					$rUrl = (string) (ServerRepository::getAll()[SERVER_ID]['rtmp_mport_url'] ?? '');
					if ($rUrl !== '') {
						@file_get_contents($rUrl . 'control/drop/client?clientid=' . $rPID, false, stream_context_create(['http' => ['timeout' => 2]]));
					}
				} elseif (file_exists('/proc/' . $rPID)) {
					posix_kill($rPID, 9);
				}
				echo json_encode(['result' => true]);
				return 0;

			case 'conn.drop':
				// The agent drops daemon viewers itself; this is its fallback.
				$rUUID = (string) ($rArgs['uuid'] ?? '');
				if (!preg_match(AgentConnections::CONN_UUID, $rUUID)) {
					return 2;
				}
				echo json_encode(['result' => FanoutClient::dropConnection($rUUID)]);
				return 0;

			case 'node.cache':
			case 'node.purge':
				$rJobs = self::cacheJobs($rArgs['jobs'] ?? null, $rCmd['type'] === 'node.purge');
				if ($rJobs === null) {
					self::fail("bad cache jobs");
					return 2;
				}
				CacheJobs::run($rJobs);
				echo json_encode(['result' => true, 'jobs' => count($rJobs)]);
				return 0;

			case 'stream.stop':
			case 'vod.stop':
			case 'stream.start':
			case 'vod.start':
				$rStreamID = $rArgs['stream_id'] ?? null;
				if (!is_int($rStreamID) || $rStreamID <= 0) {
					self::fail("bad stream id");
					return 2;
				}
				self::stopStream($rCmd['type'], $rStreamID, ($rArgs['force'] ?? false) === true);
				echo json_encode(['result' => true]);
				return 0;

			case 'stream.assign':
				$rIDs = $rArgs['stream_ids'] ?? null;
				$rSet = $rArgs['set'] ?? [];
				$rFill = $rArgs['fill'] ?? [];
				if (!self::assignable($rIDs, $rSet, $rFill)) {
					self::fail("bad stream assignment");
					return 2;
				}
				echo json_encode(['result' => true, 'kept' => StreamRuntime::assign($rIDs, $rSet, $rFill)]);
				return 0;

			case 'queue.poke':
				echo json_encode(['result' => @touch(SIGNALS_TMP_PATH . QueueSink::POKE)]);
				return 0;

			case 'config.changed':
				// PHP holds no key to fetch the replica: the agent's next poll does.
				echo json_encode(['deferred' => true]);
				return 0;

			case ArtefactStage::TYPE_FETCH:
				$rPlaced = ArtefactStage::placeOffAir($rCmd);
				if (is_string($rPlaced)) {
					self::fail($rPlaced);
					return 1;
				}
				echo json_encode($rPlaced);
				return 0;
		}
		self::fail("unknown command type");
		return 2;
	}
}
