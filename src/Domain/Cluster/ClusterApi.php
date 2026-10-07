<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\AgentConnections;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\Crypto\Box;
use XcVm\Core\Cluster\Crypto\Canonical;
use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterRefusedException;
use XcVm\Core\Cluster\Crypto\NodeSig;
use XcVm\Core\Cluster\Crypto\Seal;
use XcVm\Core\Cluster\Crypto\SessionKeys;
use XcVm\Core\Cluster\QueueSink;
use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Core\Database\DatabaseUnavailableException;
use XcVm\Core\Logging\FileLogger;
use XcVm\Core\Updates\ReleaseAsset;
use XcVm\Domain\Stream\RecordingFinalizer;
use XcVm\Domain\User\RtmpViewerAuth;
use XcVm\Infrastructure\Cache\CacheReader;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * MAIN's `/cluster/v1/<op>` API (Phase 2: health, challenge, enrol_complete,
 * enrol_code, enrol_code_status, token_refresh, token_rekey, hello, heartbeat;
 * Phase 4: commands, ack, artefact; Phase 5: events, recording_complete; Phase 6: conn_snapshot, conn_admit;
 * Phase 7: config, streams). Transport-free: handle() takes the request
 * as an array and returns status, headers and body, so it is tested without
 * a web server; Public/cluster/index.php is the HTTP shell around it.
 *
 * Order of checks (plan, "Signing, replay, revocation"): nothing about a node
 * is changed, and no nonce is recorded, before the request's MAC (and, for
 * token ops, the node signature) has verified. Refusals are panel-signed and
 * bound to the request nonce and node (DenialFactory).
 */
final class ClusterApi {
	public const PROTO_MIN = 1;
	public const PROTO_MAX = 1;

	/** A node may re-key once per this many seconds. */
	public const REKEY_INTERVAL = 60;

	/** Largest request body accepted (nginx also caps at 8 MB). */
	public const MAX_BODY = 8388608;

	/** Largest body of an op without a session: token_rekey and the code ops. */
	private const MAX_BODY_UNSESSIONED = 65536;

	/** The protocol range, as health, hello, a 426 PROTO and cluster.json name it. */
	public const PROTO_RANGE = ['min' => self::PROTO_MIN, 'max' => self::PROTO_MAX];

	/**
	 * How a request that names a node proves its sender (preflight()): a
	 * session's MAC, token_rekey's node signature alone, or an enrolment
	 * code's MAC.
	 */
	private const AUTH_SESSION = 'session';
	private const AUTH_REKEY = 'rekey';
	private const AUTH_CODE = 'code';

	/** SEAL purpose of the commands a hard-mode LICENCE_INVALID carries (killsFor()). */
	public const SEAL_COMMANDS = 'commands';

	/**
	 * Ops whose handlers never read MAIN's `servers` row: the entry point
	 * (Public/cluster/index.php) reads it for every other op alone, so a
	 * heartbeat on the cluster bus sends MySQL no query of its own (only the
	 * connection's setup).
	 */
	private const WITHOUT_MAIN = ['health', 'heartbeat', 'commands', 'ack', 'events', 'recording_complete', 'queue_enqueue', 'queue_claim', 'queue_update', 'conn_snapshot', 'conn_admit', 'rtmp_auth', 'streams', 'artefact', 'token_refresh', 'token_rekey', 'enrol_code', 'enrol_code_status'];

	/**
	 * Failed credential checks (`rtmp_auth`) a node may ask for in a minute:
	 * past it, MAIN checks none for the node until the minute ends, so a node
	 * cannot test line credentials at MAIN's pace under addresses it names.
	 */
	public const RTMP_FAIL_BUDGET = 300;

	/** op => [method, needs a node signature, allowed node states] */
	private const OPS = [
		'health' => ['GET', false, null],
		'challenge' => ['GET', false, null],
		'enrol_complete' => ['POST', true, ['enrolling']],
		'token_refresh' => ['POST', true, ['active', 'quarantined']],
		'token_rekey' => ['POST', true, ['active']],
		'enrol_code' => ['POST', true, null],
		'enrol_code_status' => ['POST', false, null],
		'hello' => ['POST', false, ['active', 'quarantined']],
		'commands' => ['POST', false, ['active', 'quarantined']],
		'ack' => ['POST', false, ['active', 'quarantined']],
		'events' => ['POST', false, ['active']],
		'recording_complete' => ['POST', false, ['active']],
		'queue_enqueue' => ['POST', false, ['active']],
		'queue_claim' => ['POST', false, ['active']],
		'queue_update' => ['POST', false, ['active']],
		'conn_snapshot' => ['POST', false, ['active']],
		'conn_admit' => ['POST', false, ['active']],
		'rtmp_auth' => ['POST', false, ['active']],
		'config' => ['POST', false, ['active']],
		'streams' => ['POST', false, ['active']],
		'artefact' => ['POST', false, ['active']],
		'heartbeat' => ['POST', false, ['active', 'quarantined']],
	];

	/**
	 * @param array{method: string, path: string, query?: string, headers: array<string, string>, body?: string, ip?: string, https?: bool, port?: int} $rReq
	 * @param array<string, mixed> $rSettings
	 * @param array<string, mixed> $rMain The main server's `servers` row.
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public static function handle(ClusterCrypto $rCrypto, array $rReq, array $rSettings, array $rMain): array {
		$rOp = self::op((string) $rReq['path']);
		if (!isset(self::OPS[$rOp])) {
			return DenialFactory::deny($rCrypto, 404, 'UNKNOWN_OP');
		}
		[$rMethod, $rNeedsNodeSig, $rStates] = self::OPS[$rOp];
		if (strtoupper((string) $rReq['method']) !== $rMethod) {
			return DenialFactory::deny($rCrypto, 405, 'BAD_REQUEST');
		}
		if ($rOp === 'health') {
			return self::health($rCrypto);
		}
		if (empty($rSettings['cluster_api_enabled'])) {
			return DenialFactory::deny($rCrypto, 503, 'DISABLED');
		}
		if (ClusterSettings::enum('cluster_transport', $rSettings['cluster_transport'] ?? null) === 'https_required' && empty($rReq['https']) && $rOp !== 'challenge') {
			// https_required (plan section 3): over plain HTTP only the challenge
			// is served, so a node whose HTTPS fails still fetches the signed
			// policy there, and with it an admin's switch back to auto.
			$rH = Canonical::parseHeaders($rReq['headers']);
			return DenialFactory::deny($rCrypto, 403, 'HTTPS_REQUIRED', $rH['node'] ?? null, $rH['nonce'] ?? null);
		}
		if ($rOp === 'challenge') {
			return self::challenge($rCrypto, (string) ($rReq['query'] ?? ''), $rSettings, $rMain);
		}
		if ($rOp === 'token_rekey') {
			return self::tokenRekey($rCrypto, $rReq, $rStates);
		}
		if ($rOp === 'enrol_code' || $rOp === 'enrol_code_status') {
			return self::enrolByCode($rCrypto, $rOp, $rReq);
		}

		// ── Authenticated ops ────────────────────────────────────────────
		if (($rDenied = self::preflight($rCrypto, $rReq, self::AUTH_SESSION, $rH, $rBody)) !== null) {
			return $rDenied;
		}
		// The node and its epoch's record: from the cluster bus while it holds
		// them, else MySQL (NodeAuthCache).
		[$rNode, $rEpochRow] = str_starts_with($rH['node'], 'sid:') ? [null, null] : NodeAuthCache::load($rH['node'], $rH['epoch']);
		if (($rDenied = self::nodeDenial($rCrypto, $rNode, $rH)) !== null) {
			return $rDenied;
		}
		try {
			$rKeys = TokenService::open($rCrypto, $rNode, $rEpochRow);
		} catch (ClusterRefusedException $rE) {
			return self::refusal($rCrypto, $rE->reason(), $rNode, $rH, true);
		}
		if (!$rKeys instanceof SessionKeys) {
			return self::deny($rCrypto, $rH, 401, 'TOKEN_EXPIRED');
		}
		// The extension-sealed record names its server and epoch: a row that
		// pairs it with another (an epoch row moved to another node or number)
		// opens no session, as a record for another node does not.
		if ($rKeys->rServerID !== (int) $rNode['server_id'] || $rKeys->rEpoch !== $rH['epoch']) {
			return self::refusal($rCrypto, 'RECORD', $rNode, $rH);
		}

		$rCtx = self::requestContext($rReq, $rH, $rMethod);
		if (!Canonical::verifyMac($rKeys->rMacUp, $rCtx, $rBody, $rH['sig'])) {
			return self::deny($rCrypto, $rH, 401, 'BAD_MAC');
		}
		// The key comes from the extension-sealed epoch record, not the DB row.
		if ($rNeedsNodeSig && ($rDenied = self::nodeSig($rCrypto, $rReq, $rH, $rKeys->rNodeSignPub, $rCtx, $rBody)) !== null) {
			return $rDenied;
		}
		// Authenticated from here on.
		if (($rDenied = self::admit($rCrypto, $rH, $rNode, $rStates)) !== null) {
			return $rDenied;
		}
		// hello, config, streams and conn_snapshot hold one of the op's bus permits.
		return ClusterSemaphore::run($rCrypto, $rOp, $rH, static fn(): array => self::dispatch($rCrypto, $rOp, $rReq, $rSettings, $rMain, $rNode, $rKeys, $rCtx, $rH, $rBody));
	}

	/**
	 * handle() for the HTTP shell (Public/cluster/index.php): whatever it
	 * throws is answered by failed(), never by PHP's bare 500.
	 *
	 * @param array{method: string, path: string, query?: string, headers: array<string, string>, body?: string, ip?: string, https?: bool, port?: int} $rReq
	 * @param array<string, mixed> $rSettings
	 * @param array<string, mixed> $rMain
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public static function serve(ClusterCrypto $rCrypto, array $rReq, array $rSettings, array $rMain): array {
		try {
			return self::handle($rCrypto, $rReq, $rSettings, $rMain);
		} catch (\Throwable $rE) {
			if ($rE instanceof DatabaseUnavailableException) {
				// The entry point opens MySQL at the first query: down there, as before it opened it eagerly.
				try {
					return DenialFactory::deny($rCrypto, 503, 'DB');
				} catch (\Throwable) {
					// Not even a denial could be signed: as below.
				}
			}
			return self::failed((string) ($rReq['path'] ?? ''), $rE);
		}
	}

	/**
	 * A request MAIN failed to answer (the extension refusing to sign even a
	 * denial, a TypeError from it, a lost connection): the throwable goes to
	 * the panel's error log, and the agent gets an unsigned 503 that names
	 * nothing of it, which it takes as a transport error and retries.
	 *
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public static function failed(string $rPath, \Throwable $rE): array {
		try {
			$rWhy = $rE instanceof ClusterRefusedException ? 'refused: ' . $rE->reason() : get_class($rE) . ': ' . $rE->getMessage();
			FileLogger::log('cluster', 'Cluster API ' . substr(preg_replace('/[^\x21-\x7e]/', '', $rPath) ?? '', 0, 64) . ' failed (' . $rWhy . ')', $rE->getFile() . ':' . $rE->getLine());
		} catch (\Throwable) {
			// The reply goes out regardless.
		}
		return DenialFactory::unsigned(503, 'ERROR');
	}

	/** Does the op at this path read MAIN's `servers` row (the policy, the replica)? */
	public static function readsMain(string $rPath): bool {
		return !in_array(self::op($rPath), self::WITHOUT_MAIN, true);
	}

	/** The op a path names: what follows Canonical::PATH_PREFIX, or '' for another path. */
	private static function op(string $rPath): string {
		return str_starts_with($rPath, Canonical::PATH_PREFIX) ? substr($rPath, strlen(Canonical::PATH_PREFIX)) : '';
	}

	/**
	 * ADR 0004 steps 1-3, the same for every op that names a node (a session
	 * op, token_rekey, the code ops): the headers parse and take the shape
	 * $rAuth proves the sender with, the body within that shape's cap (400
	 * BAD_REQUEST, naming nothing: no node is parsed yet); the protocol in
	 * range (426 PROTO); the stamp within the window (401 CLOCK_SKEW). Null
	 * when all pass, with the headers in $rH and the body in $rBody.
	 *
	 * @param self::AUTH_* $rAuth
	 * @param-out array{proto:int, agent:string, node:string, epoch:int, ts_ms:int, nonce:string, sig:?string}|null $rH
	 * @param-out string $rBody
	 * @return array{status: int, headers: array<string, string>, body: string}|null
	 */
	private static function preflight(ClusterCrypto $rCrypto, array $rReq, string $rAuth, ?array &$rH, ?string &$rBody): ?array {
		$rH = Canonical::parseHeaders($rReq['headers']);
		$rBody = (string) ($rReq['body'] ?? '');
		$rShaped = $rH !== null && match ($rAuth) {
			// MAC'd under the session, any epoch (a `sid:` node is unknown, step 4).
			self::AUTH_SESSION => $rH['sig'] !== null && strlen($rBody) <= self::MAX_BODY,
			// No session, so no MAC: epoch 0 and a SEALed body.
			self::AUTH_REKEY => $rH['sig'] === null && $rH['epoch'] === 0 && $rBody !== '' && strlen($rBody) <= self::MAX_BODY_UNSESSIONED,
			// MAC'd under the code's K_req: epoch 0, a node with no identity yet.
			self::AUTH_CODE => $rH['sig'] !== null && $rH['epoch'] === 0 && str_starts_with($rH['node'], 'sid:') && strlen($rBody) <= self::MAX_BODY_UNSESSIONED,
		};
		if (!$rShaped) {
			return DenialFactory::deny($rCrypto, 400, 'BAD_REQUEST');
		}
		if ($rH['proto'] < self::PROTO_MIN || $rH['proto'] > self::PROTO_MAX) {
			return self::deny($rCrypto, $rH, 426, 'PROTO', self::PROTO_RANGE);
		}
		if (!Canonical::withinWindow($rH['ts_ms'], ClusterClock::nowMs())) {
			return self::deny($rCrypto, $rH, 401, 'CLOCK_SKEW');
		}
		return null;
	}

	/**
	 * The request's canonical context (Canonical::request()), which its MAC,
	 * its node signature and a SEALed body are bound to.
	 *
	 * @param array{proto:int, agent:string, node:string, epoch:int, ts_ms:int, nonce:string, sig:?string} $rH
	 */
	private static function requestContext(array $rReq, array $rH, string $rMethod): string {
		return Canonical::request([
			'proto' => $rH['proto'], 'agent' => $rH['agent'], 'method' => $rMethod, 'path' => (string) $rReq['path'],
			'query' => (string) ($rReq['query'] ?? ''), 'content_type' => self::header($rReq['headers'], 'Content-Type'),
			'content_encoding' => self::header($rReq['headers'], 'Content-Encoding'), 'node' => $rH['node'],
			'epoch' => $rH['epoch'], 'ts_ms' => $rH['ts_ms'], 'nonce' => $rH['nonce'],
		]);
	}

	/**
	 * ADR 0004 step 4: the node is known (401 UNKNOWN_NODE) and not revoked
	 * (403 NODE_REVOKED). Null when it passes.
	 *
	 * @param array<string, mixed>|null $rNode
	 * @return array{status: int, headers: array<string, string>, body: string}|null
	 */
	private static function nodeDenial(ClusterCrypto $rCrypto, ?array $rNode, array $rH): ?array {
		if ($rNode === null) {
			return self::deny($rCrypto, $rH, 401, 'UNKNOWN_NODE');
		}
		return $rNode['state'] === 'revoked' ? self::revoked($rCrypto, $rNode, $rH) : null;
	}

	/**
	 * The node signature over the request context and body (X-XCVM-Node-Sig,
	 * 128 hex digits), verified with $rPub: null when it verifies, else 401
	 * BAD_NODE_SIG.
	 *
	 * @return array{status: int, headers: array<string, string>, body: string}|null
	 */
	private static function nodeSig(ClusterCrypto $rCrypto, array $rReq, array $rH, string $rPub, string $rCtx, string $rBody): ?array {
		$rNodeSig = self::header($rReq['headers'], Canonical::H_NODE_SIG);
		$rSig = preg_match('/^[0-9a-f]{128}\z/', $rNodeSig) ? (string) hex2bin($rNodeSig) : '';
		return NodeSig::verify($rPub, 'request', $rCtx . hash('sha256', $rBody, true), $rSig) ? null : self::deny($rCrypto, $rH, 401, 'BAD_NODE_SIG');
	}

	/**
	 * ADR 0004 steps 8-9 of an authenticated request: its nonce is claimed
	 * (claimNonce()), then the node's state is one the op allows (409
	 * NOT_ACTIVE). Null when both pass.
	 *
	 * @param array<string, mixed> $rNode
	 * @param list<string> $rStates
	 * @return array{status: int, headers: array<string, string>, body: string}|null
	 */
	private static function admit(ClusterCrypto $rCrypto, array $rH, array $rNode, array $rStates): ?array {
		if (($rReplay = self::claimNonce($rCrypto, $rH)) !== null) {
			return $rReplay;
		}
		return in_array($rNode['state'], $rStates, true) ? null : self::notActive($rCrypto, $rH, $rNode['state']);
	}

	/**
	 * Claim an authenticated request's nonce: null when claimed, else its 401
	 * REPLAY. When MAIN only cannot vouch for the nonce yet (a bus that just
	 * started or was lost, NonceStore), the refusal carries retry_after_ms: a
	 * request stamped anew that long after the denial's main_time_ms passes.
	 *
	 * @param array{node: string, nonce: string, ts_ms: int} $rH
	 * @return array{status: int, headers: array<string, string>, body: string}|null
	 */
	private static function claimNonce(ClusterCrypto $rCrypto, array $rH): ?array {
		if (NonceStore::claim($rH['node'], $rH['nonce'], $rH['ts_ms'], $rRetryMs)) {
			return null;
		}
		return self::deny($rCrypto, $rH, 401, 'REPLAY', $rRetryMs === null ? [] : ['retry_after_ms' => $rRetryMs]);
	}

	/**
	 * An authenticated session op, past its nonce and node state: open the
	 * BOX and run the handler. An ingest op also holds an ingest permit, P0
	 * events from their reserve; a batch's lane is known only once its BOX is
	 * open, and opening it touches no database.
	 *
	 * @param 'enrol_complete'|'token_refresh'|'hello'|'heartbeat'|'commands'|'ack'|'events'|'recording_complete'|'queue_enqueue'|'queue_claim'|'queue_update'|'conn_snapshot'|'conn_admit'|'rtmp_auth'|'config'|'streams'|'artefact' $rOp
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private static function dispatch(ClusterCrypto $rCrypto, string $rOp, array $rReq, array $rSettings, array $rMain, array $rNode, SessionKeys $rKeys, string $rCtx, array $rH, string $rBody): array {
		$rPlain = Box::open($rKeys->rEncUp, $rCtx, $rBody);
		$rPayload = $rPlain === null ? null : json_decode($rPlain, true);
		if (!is_array($rPayload)) {
			return self::badRequest($rCrypto, $rH);
		}
		$rServe = static function () use ($rCrypto, $rOp, $rReq, $rSettings, $rMain, $rNode, $rKeys, $rCtx, $rH, $rPayload): array {
			TokenService::markUsed($rNode, $rH['epoch'], $rKeys->rExp);

			return match ($rOp) {
				'enrol_complete' => self::enrolComplete($rCrypto, $rNode, $rKeys, $rCtx, $rH, $rPayload, $rSettings, $rMain, (string) ($rReq['ip'] ?? '')),
				'token_refresh' => self::tokenRefresh($rCrypto, $rNode, $rKeys, $rCtx, $rH, $rPayload),
				'hello' => self::hello($rCrypto, $rNode, $rKeys, $rCtx, $rH, $rPayload, $rSettings, $rMain, (int) ($rReq['port'] ?? 0)),
				'heartbeat' => self::heartbeat($rNode, $rKeys, $rCtx, $rH, $rPayload, $rSettings, (int) ($rReq['port'] ?? 0)),
				'commands' => self::commands($rNode, $rKeys, $rCtx, $rPayload),
				'ack' => self::ack($rCrypto, $rNode, $rKeys, $rCtx, $rH, $rPayload),
				'events' => self::events($rCrypto, $rNode, $rKeys, $rCtx, $rH, $rPayload),
				'recording_complete' => self::recordingComplete($rCrypto, $rNode, $rKeys, $rCtx, $rH, $rPayload),
				'queue_enqueue', 'queue_claim', 'queue_update' => self::queue($rOp, $rCrypto, $rNode, $rKeys, $rCtx, $rH, $rPayload),
				'conn_snapshot' => self::connSnapshot($rCrypto, $rNode, $rKeys, $rCtx, $rH, $rPayload),
				'conn_admit' => self::connAdmit($rCrypto, $rNode, $rKeys, $rCtx, $rH, $rPayload, $rSettings),
				'rtmp_auth' => self::rtmpAuth($rCrypto, $rNode, $rKeys, $rCtx, $rH, $rPayload, $rSettings),
				'config' => self::config($rCrypto, $rNode, $rKeys, $rCtx, $rH, $rPayload, $rSettings, $rMain),
				'streams' => self::streams($rCrypto, $rNode, $rKeys, $rCtx, $rH, $rPayload),
				'artefact' => self::artefact($rCrypto, $rNode, $rKeys, $rCtx, $rH, $rPayload, $rSettings),
			};
		};
		$rLane = ClusterSemaphore::ingestLane($rOp, $rPayload);
		return $rLane === null ? $rServe() : ClusterSemaphore::runIngest($rCrypto, $rOp, $rLane, $rSettings['cluster_ingest_concurrency'] ?? null, $rH, $rServe);
	}

	/** @return array{status: int, headers: array<string, string>, body: string} */
	private static function health(ClusterCrypto $rCrypto): array {
		$rInfo = $rCrypto->info();
		return DenialFactory::signed($rCrypto, 200, 'hlt', [
			'v' => 1,
			'typ' => 'xcvm-health',
			'main_time_ms' => ClusterClock::nowMs(),
			'proto' => self::PROTO_RANGE,
			'api' => (int) ($rInfo['api'] ?? 0),
			'panel_sign_pub' => base64_encode((string) ($rInfo['panel_sign_pub'] ?? '')),
			'panel_box_pub' => base64_encode((string) ($rInfo['panel_box_pub'] ?? '')),
			'panel_fp' => bin2hex((string) ($rInfo['panel_fp'] ?? '')),
		]);
	}

	/** @return array{status: int, headers: array<string, string>, body: string} */
	private static function challenge(ClusterCrypto $rCrypto, string $rQuery, array $rSettings, array $rMain): array {
		parse_str($rQuery, $rQ);
		$rCn = is_string($rQ['cn'] ?? null) ? (string) $rQ['cn'] : '';
		if (!Canonical::validNode($rCn)) {
			return DenialFactory::deny($rCrypto, 400, 'BAD_REQUEST');
		}
		$rChallenge = random_bytes(32);
		// Single use, 180 s: token_rekey (later) consumes it by its hash.
		NonceStore::issue('chal:' . $rCn, self::nonceKey($rChallenge));
		return DenialFactory::signed($rCrypto, 200, 'hlt', [
			'v' => 1,
			'typ' => 'xcvm-challenge',
			'cn' => $rCn,
			'challenge' => base64_encode($rChallenge),
			'main_time_ms' => ClusterClock::nowMs(),
			'licence_ok' => (bool) ($rCrypto->info()['licensed'] ?? false),
			'policy' => ClusterPolicy::current($rSettings, $rMain),
		]);
	}

	private static function enrolComplete(ClusterCrypto $rCrypto, array $rNode, SessionKeys $rKeys, string $rCtx, array $rH, array $rP, array $rSettings, array $rMain, string $rIP): array {
		if ($rH['epoch'] !== 1 || ClusterClock::now() > (int) $rNode['enrol_deadline']) {
			return self::deny($rCrypto, $rH, 409, 'ENROL_EXPIRED');
		}
		// Only the row this request was authenticated against, still
		// `enrolling`: a revocation, or a re-enrolment's new row (same server,
		// next gen), that landed since it was read keeps its state, and the
		// node is not announced.
		$rActivated = NodeRegistry::update((int) $rNode['server_id'], [
			'state' => 'active',
			'instance_id' => self::short($rP['instance_id'] ?? null),
			'boot_id' => self::short($rP['boot_id'] ?? null),
			'agent_version' => self::short($rP['agent_version'] ?? null, 32),
			'proto' => $rH['proto'],
			'enrol_deadline' => null,
			'last_seen_at' => ClusterClock::nowMs(),
		], ['state' => 'enrolling', 'gen' => (int) $rNode['gen'], 'node_uuid' => (string) $rNode['node_uuid']]);
		if (!$rActivated) {
			$rNow = NodeRegistry::byServer((int) $rNode['server_id']);
			return self::nodeDenial($rCrypto, $rNow, $rH) ?? self::notActive($rCrypto, $rH, $rNow['state']);
		}
		ClusterAudit::log('node.enrol_complete', (int) $rNode['server_id'], ['node' => $rNode['node_uuid'], 'agent' => $rP['agent_version'] ?? null], 'node', $rIP ?: null);
		// Now active in the node list: parents and children learn its key at once.
		ReplicaBuilder::nodesChanged($rCrypto, (int) $rNode['server_id']);
		return ClusterReply::boxed($rKeys, $rCtx, [
			'state' => 'active', 'mode' => (int) $rNode['mode'], 'flows' => (int) $rNode['flows'], 'gen' => (int) $rNode['gen'],
			'main_time_ms' => ClusterClock::nowMs(), 'policy' => ClusterPolicy::current($rSettings, $rMain),
		]);
	}

	private static function tokenRefresh(ClusterCrypto $rCrypto, array $rNode, SessionKeys $rKeys, string $rCtx, array $rH, array $rP): array {
		$rEph = self::base64Field($rP, 'eph_pub');
		if ($rEph === false || strlen($rEph) !== 32) {
			return self::badRequest($rCrypto, $rH);
		}
		try {
			$rIssued = TokenService::refresh($rCrypto, NodeRegistry::byUuid((string) $rNode['node_uuid']) ?? $rNode, $rH['epoch'], $rEph);
		} catch (ClusterRefusedException $rE) {
			return self::refusal($rCrypto, $rE->reason(), $rNode, $rH);
		}
		return ClusterReply::boxed($rKeys, $rCtx, self::issued($rIssued));
	}

	/**
	 * A token MAIN minted (TokenService::refresh(), ::rekey()) as a reply
	 * carries it: the token sealed to the agent's per-epoch key, its epoch
	 * and times, MAIN's clock, and the lease it carries (LeaseService::wire()).
	 *
	 * @param array<string, mixed> $rIssued
	 * @return array<string, mixed>
	 */
	private static function issued(array $rIssued): array {
		return [
			'token_sealed' => base64_encode((string) $rIssued['token_sealed']),
			'epoch' => (int) $rIssued['epoch'], 'nbf' => (int) $rIssued['nbf'], 'exp' => (int) $rIssued['exp'],
			'refresh_at' => (int) $rIssued['refresh_at'], 'main_time_ms' => ClusterClock::nowMs(),
		] + LeaseService::wire($rIssued['lease'] ?? null);
	}

	/**
	 * `token_rekey`: recovery for a node whose tokens have all expired while
	 * its keys are intact. There is no session, so the request carries no MAC:
	 * it is node-signed (the enrolled key), its body is SEALed to the panel box
	 * key under the request context, and it must return a challenge from
	 * `GET challenge?cn=` once. The reply is panel-signed (`pre`, a granting
	 * record: the extension signs it only under a valid licence) and names
	 * this node and request; the token inside is sealed to the agent's new
	 * per-epoch key.
	 *
	 * Order, as for session ops: nothing is recorded before the node signature
	 * verifies. Then the nonce, the node state, the once-a-minute limit, the
	 * body, the challenge, the attestation, and finally the licence (the mint).
	 *
	 * @param list<string> $rStates
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private static function tokenRekey(ClusterCrypto $rCrypto, array $rReq, array $rStates): array {
		if (($rDenied = self::preflight($rCrypto, $rReq, self::AUTH_REKEY, $rH, $rBody)) !== null) {
			return $rDenied;
		}
		$rNode = str_starts_with($rH['node'], 'sid:') ? null : NodeRegistry::byUuid($rH['node']);
		if (($rDenied = self::nodeDenial($rCrypto, $rNode, $rH)) !== null) {
			return $rDenied;
		}
		// Epoch 0 (preflight()): there is no session.
		$rCtx = self::requestContext($rReq, $rH, 'POST');
		if (($rDenied = self::nodeSig($rCrypto, $rReq, $rH, (string) $rNode['node_sign_pub'], $rCtx, $rBody)) !== null) {
			return $rDenied;
		}
		// Authenticated from here on.
		if (($rDenied = self::admit($rCrypto, $rH, $rNode, $rStates)) !== null) {
			return $rDenied;
		}
		// A bus permit first: a busy MAIN spends neither the minute nor the challenge.
		return ClusterSemaphore::run($rCrypto, 'token_rekey', $rH, static fn(): array => self::rekeyAuthenticated($rCrypto, $rNode, $rH, $rCtx, $rBody));
	}

	/**
	 * `token_rekey` past its node signature, nonce and node state.
	 *
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private static function rekeyAuthenticated(ClusterCrypto $rCrypto, array $rNode, array $rH, string $rCtx, string $rBody): array {
		// Once a minute, counted per attempt: the slot is a claim on this minute.
		$rSlot = intdiv(ClusterClock::now(), self::REKEY_INTERVAL);
		if (!NonceStore::claim('rekey:' . $rH['node'], self::nonceKey((string) $rSlot))) {
			return self::deny($rCrypto, $rH, 429, 'RATE_LIMITED', ['retry_after_ms' => (($rSlot + 1) * self::REKEY_INTERVAL - ClusterClock::now()) * 1000]);
		}
		$rP = self::sealedPayload($rCrypto, 'rekey', $rBody, $rCtx);
		$rChallenge = self::base64Field($rP, 'challenge');
		$rEph = self::base64Field($rP, 'eph_pub');
		if ($rChallenge === false || strlen($rChallenge) !== 32 || $rEph === false || strlen($rEph) !== 32) {
			return self::badRequest($rCrypto, $rH);
		}
		if (!NonceStore::consume('chal:' . $rH['node'], self::nonceKey($rChallenge))) {
			return self::deny($rCrypto, $rH, 401, 'CHALLENGE');
		}
		$rInstance = self::short($rP['instance_id'] ?? null);
		if (!empty($rNode['instance_id']) && ($rInstance === null || !hash_equals((string) $rNode['instance_id'], $rInstance))) {
			// Authenticated evidence of a clone, as in hello: the admin decides.
			NodeRegistry::update((int) $rNode['server_id'], ['state' => 'quarantined', 'quarantine_reason' => 'instance_id changed (re-key)']);
			ClusterAudit::log('node.quarantine', (int) $rNode['server_id'], ['reason' => 'rekey attest', 'was' => $rNode['instance_id'], 'now' => $rInstance] + CommandBus::endGranting((int) $rNode['server_id']), 'node');
			// No longer active in the node list: its peers stop trusting it at once.
			ReplicaBuilder::nodesChanged($rCrypto, (int) $rNode['server_id']);
			return self::notActive($rCrypto, $rH, 'quarantined');
		}
		try {
			$rIssued = TokenService::rekey($rCrypto, $rNode, $rEph);
			NodeRegistry::update((int) $rNode['server_id'], [
				'boot_id' => self::short($rP['boot_id'] ?? null), 'agent_version' => self::short($rP['agent_version'] ?? null, 32),
				'proto' => $rH['proto'], 'last_seen_at' => ClusterClock::nowMs(),
			]);
			return DenialFactory::signed($rCrypto, 200, 'pre', [
				'v' => 1, 'typ' => 'xcvm-rekey', 'node' => $rH['node'], 'req_nonce' => bin2hex($rH['nonce']),
			] + self::issued($rIssued));
		} catch (ClusterRefusedException $rE) {
			return self::refusal($rCrypto, $rE->reason(), $rNode, $rH);
		}
	}

	/**
	 * `enrol_code` and `enrol_code_status`: a node with no identity on MAIN yet
	 * (`X-XCVM-Node: sid:<n>`, epoch 0), authenticated by the code's K_req.
	 * `enrol_code` is also node-signed with the key it asks MAIN to enrol, and
	 * its body is SEALed to the panel box key. Replies are MAC'd under K_res;
	 * an approved one also carries the panel's `pre` signature.
	 *
	 * A wrong MAC changes nothing: no nonce, no attempt counted (the attempts
	 * are the admin's wrong SAS entries), so a code cannot be burned without
	 * its secret.
	 *
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private static function enrolByCode(ClusterCrypto $rCrypto, string $rOp, array $rReq): array {
		if (($rDenied = self::preflight($rCrypto, $rReq, self::AUTH_CODE, $rH, $rBody)) !== null) {
			return $rDenied;
		}
		$rSid = (int) substr($rH['node'], 4);
		$rPending = $rOp === 'enrol_code_status' ? EnrolCodeService::request($rSid) : null;
		$rCode = $rOp === 'enrol_code' ? EnrolCodeService::code($rCrypto, $rSid)
			: ($rPending === null ? null : EnrolCodeService::code($rCrypto, $rSid, (int) $rPending['code_id'], false));
		if ($rCode === null) {
			return self::deny($rCrypto, $rH, 401, 'CODE_INVALID');
		}
		// Epoch 0 (preflight()): the node has no session yet.
		$rCtx = self::requestContext($rReq, $rH, 'POST');
		if (!Canonical::verifyMac($rCode['req'], $rCtx, $rBody, $rH['sig'])) {
			return self::deny($rCrypto, $rH, 401, 'BAD_MAC');
		}

		if ($rOp === 'enrol_code_status') {
			if (($rReplay = self::claimNonce($rCrypto, $rH)) !== null) {
				return $rReplay;
			}
			$rP = json_decode($rBody, true);
			if (!is_array($rP) || ($rP['node_uuid'] ?? null) !== $rPending['node_uuid']) {
				return self::badRequest($rCrypto, $rH);
			}
			if ($rPending['state'] === 'approved') {
				$rReply = json_decode((string) $rPending['reply'], true);
				return ClusterReply::maced($rCode['res'], $rCtx, (string) $rReply['doc'], [Canonical::H_PANEL_SIG => (string) $rReply['sig']]);
			}
			return self::enrolStatus($rCode, $rCtx, (string) $rPending['state']);
		}

		// The keys it asks MAIN to enrol, from its SEALed body: checked before
		// the node signature, which is verified with the one it names.
		$rP = self::sealedPayload($rCrypto, 'enrol_code', $rBody, $rCtx);
		$rNode = [
			'node_uuid' => is_string($rP['node_uuid'] ?? null) ? (string) $rP['node_uuid'] : '',
			'sign_pub' => self::base64Field($rP, 'sign_pub'), 'box_pub' => self::base64Field($rP, 'box_pub'), 'eph_pub' => self::base64Field($rP, 'eph_pub'),
			'instance_id' => self::short($rP['instance_id'] ?? null),
		];
		foreach (['sign_pub', 'box_pub', 'eph_pub'] as $rName) {
			if (!is_string($rNode[$rName]) || strlen($rNode[$rName]) !== 32) {
				return self::badRequest($rCrypto, $rH);
			}
		}
		if (!EnrolmentService::validUuid($rNode['node_uuid'])) {
			return self::badRequest($rCrypto, $rH);
		}
		if (($rDenied = self::nodeSig($rCrypto, $rReq, $rH, $rNode['sign_pub'], $rCtx, $rBody)) !== null) {
			return $rDenied;
		}
		if (($rReplay = self::claimNonce($rCrypto, $rH)) !== null) {
			return $rReplay;
		}
		$rRefused = EnrolCodeService::submit($rCode, $rSid, $rNode, (string) ($rReq['ip'] ?? ''));
		if ($rRefused !== null) {
			return self::deny($rCrypto, $rH, $rRefused === 'BAD_REQUEST' ? 400 : 409, $rRefused);
		}
		return self::enrolStatus($rCode, $rCtx, 'pending_approval');
	}

	/**
	 * A code op's reply naming the request's state (`pending_approval`, or
	 * the stored request's): MAC'd under the code's K_res.
	 *
	 * @param array{row: array<string, mixed>, req: string, res: string} $rCode
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private static function enrolStatus(array $rCode, string $rCtx, string $rState): array {
		return ClusterReply::maced($rCode['res'], $rCtx, (string) json_encode([
			'v' => 1, 'typ' => 'xcvm-enrol-status', 'state' => $rState, 'main_time_ms' => ClusterClock::nowMs(),
		]));
	}

	private static function hello(ClusterCrypto $rCrypto, array $rNode, SessionKeys $rKeys, string $rCtx, array $rH, array $rP, array $rSettings, array $rMain, int $rPort): array {
		// Its cursors as MySQL has them now: the row the request was
		// authenticated with may be the cluster bus's copy, whose event
		// cursors lag (NodeAuthCache::LAGGING).
		$rNode = NodeRegistry::byServer((int) $rNode['server_id']) ?? $rNode;
		$rInstance = self::short($rP['instance_id'] ?? null);
		$rFields = ['boot_id' => self::short($rP['boot_id'] ?? null), 'agent_version' => self::short($rP['agent_version'] ?? null, 32), 'proto' => $rH['proto'], 'last_seen_at' => ClusterClock::nowMs(), 'features' => self::features($rP['features'] ?? null)]
			+ self::arch($rNode, $rP)
			+ ClusterEndpoint::nodeUses($rNode, $rP, $rPort);
		$rState = (string) $rNode['state'];
		if ($rInstance !== null && !empty($rNode['instance_id']) && !hash_equals((string) $rNode['instance_id'], $rInstance) && $rState === 'active') {
			// Authenticated evidence of a clone: the same token from another install.
			$rState = 'quarantined';
			$rFields['state'] = $rState;
			$rFields['quarantine_reason'] = 'instance_id changed';
		} elseif (empty($rNode['instance_id'])) {
			$rFields['instance_id'] = $rInstance;
		}
		NodeRegistry::update((int) $rNode['server_id'], $rFields);
		if ($rState !== (string) $rNode['state']) {
			// Quarantined, which ends what grants (CommandBus::endGranting()) once the state is written.
			ClusterAudit::log('node.quarantine', (int) $rNode['server_id'], ['reason' => 'instance_id', 'was' => $rNode['instance_id'], 'now' => $rInstance] + CommandBus::endGranting((int) $rNode['server_id']), 'node');
			// No longer active in the node list, so its peers stop trusting it at once.
			ReplicaBuilder::nodesChanged($rCrypto, (int) $rNode['server_id']);
		}
		return ClusterReply::boxed($rKeys, $rCtx, [
			'state' => $rState, 'mode' => (int) $rNode['mode'], 'flows' => (int) $rNode['flows'], 'gen' => (int) $rNode['gen'],
			'epoch' => $rH['epoch'], 'main_time_ms' => ClusterClock::nowMs(),
			'proto' => self::PROTO_RANGE, 'policy' => ClusterPolicy::current($rSettings, $rMain),
			'cursors' => ['p0' => (int) $rNode['useq_p0'], 'p1' => (int) $rNode['useq_p1']],
			'offline_admission' => self::offlineAdmission($rSettings), 'p2_types' => EventIngest::p2Types(),
		]);
	}

	/**
	 * `lb_offline_admission`, as the agent applies it to a viewer without an
	 * `adm` claim while conn_admit gets no answer: `local`, `allow` or `deny`.
	 * In hello and every heartbeat, so a change reaches a node within one
	 * heartbeat and needs no CONFIG flow.
	 */
	private static function offlineAdmission(array $rSettings): string {
		return ClusterSettings::enum('lb_offline_admission', $rSettings['lb_offline_admission'] ?? null);
	}

	/**
	 * What the agent says it does (e.g. `hls_reaper`), as a comma-separated
	 * list for `cluster_nodes.features`; null when it says nothing (an older
	 * agent), so MAIN keeps doing everything itself.
	 */
	public static function features(mixed $rList): ?string {
		if (!is_array($rList)) {
			return null;
		}
		$rOut = [];
		foreach (array_slice($rList, 0, 16) as $rFeature) {
			if (is_string($rFeature) && preg_match('/^[a-z0-9_]{1,32}\z/', $rFeature)) {
				$rOut[] = $rFeature;
			}
		}
		$rOut = array_values(array_unique($rOut));
		sort($rOut);
		return $rOut === [] ? null : substr(implode(',', $rOut), 0, 255);
	}

	/**
	 * The machine architecture the agent reports, as the xc_agent assets name
	 * it, written only when it changes (it changes when a node is rebuilt on
	 * other hardware). MAIN offers it the agent binary it pinned for that arch.
	 *
	 * @param array<string, mixed> $rNode
	 * @param array<string, mixed> $rP
	 * @return array<string, string>
	 */
	private static function arch(array $rNode, array $rP): array {
		$rArch = self::short($rP['arch'] ?? null, 8);
		return $rArch !== null && in_array($rArch, ReleaseAsset::ARCH_MAP, true) && $rArch !== ($rNode['arch'] ?? null) ? ['arch' => $rArch] : [];
	}

	private static function heartbeat(array $rNode, SessionKeys $rKeys, string $rCtx, array $rH, array $rP, array $rSettings, int $rPort): array {
		HeartbeatService::record($rNode, $rP, $rH['ts_ms']);
		// The policy the node dials and the MAIN port it reached, written only
		// when either changed (ClusterEndpoint::nodeUses()).
		$rUses = ClusterEndpoint::nodeUses($rNode, $rP, $rPort);
		if ($rUses !== []) {
			NodeRegistry::update((int) $rNode['server_id'], $rUses);
		}
		// Its own audit (settings misses), kept only when it changed.
		NodeAudit::record($rNode, $rP['audit'] ?? null);
		// Whether its agent holds the relay proxy's port, kept only when it changed.
		$rNowMs = ClusterClock::nowMs();
		NodeRelay::record($rNode, $rP['relay'] ?? null, $rNowMs, HeartbeatService::nodeClockMs($rP, (int) $rH['ts_ms']) - $rNowMs);
		// Its event lanes' lag and the MAIN URLs it cannot reach, kept only when they changed.
		NodeLag::record($rNode, $rP, $rNowMs);
		// The owners whose chunk digest named no request, kept only when the list changed.
		NodeDigestN1::record($rNode, $rP['digest_n1'] ?? null);
		// A node that holds its viewers sends its registry's digest; a drift
		// that outlives the events in flight gets its snapshot asked for.
		$rWant = false;
		if (((int) $rNode['flows'] & NodeRegistry::FLOW_CONNECTIONS) !== 0 && isset($rP['conn_digest'])) {
			try {
				$rWant = ConnectionDigest::check((int) $rNode['server_id'], $rP['conn_digest']);
			} catch (\Throwable) {
				$rWant = false; // the store is down: the next heartbeat checks again
			}
		}
		// policy_ver lets the agent notice a new transport policy (MAIN URLs)
		// within one heartbeat; it then says hello again to fetch it.
		return ClusterReply::boxed($rKeys, $rCtx, [
			'state' => (string) $rNode['state'], 'mode' => (int) $rNode['mode'], 'flows' => (int) $rNode['flows'],
			'main_time_ms' => ClusterClock::nowMs(), 'pending' => 0, 'policy_ver' => ClusterPolicy::ver($rSettings),
			'offline_admission' => self::offlineAdmission($rSettings), 'p2_types' => EventIngest::p2Types(),
		] + ($rWant ? ['want_conn_snapshot' => true] : []));
	}

	/** Longest a `commands` long-poll is held (ms). */
	public const COMMANDS_WAIT_MAX_MS = 20000;

	/**
	 * `commands`: the node's queued commands after its high-water, panel-signed
	 * (`cmd`) each. Held up to `wait_ms` while there are none, so a command
	 * reaches the node within a poll step of being queued. A quarantined node
	 * gets the restrictive ones only (its node.quarantine, kills, stops, a
	 * fence). What granted was ended by the quarantine
	 * (CommandBus::endGranting()) and is not handed out after Trust again.
	 */
	private static function commands(array $rNode, SessionKeys $rKeys, string $rCtx, array $rP): array {
		$rAfter = max(0, (int) ($rP['after_seq'] ?? 0));
		$rWait = max(0, min(self::COMMANDS_WAIT_MAX_MS, (int) ($rP['wait_ms'] ?? 0)));
		$rDeadline = microtime(true) + $rWait / 1000;
		if ($rAfter > (int) $rNode['cmd_seq']) {
			self::recordHighWater((int) $rNode['server_id'], (int) $rNode['cmd_seq'], $rAfter);
		}
		while (true) {
			$rCommands = CommandBus::pending((int) $rNode['server_id'], $rAfter, 50, $rNode['state'] === 'quarantined');
			$rLeft = $rDeadline - microtime(true);
			if ($rCommands !== [] || $rLeft <= 0) {
				break;
			}
			// Woken by the cluster bus when a command is queued, with the DB
			// connection released meanwhile; polled without the bus.
			if (ClusterBus::waitNodeReleasing((int) $rNode['server_id'], min($rLeft, 5.0), DatabaseFactory::get()) === null) {
				usleep(250000);
			}
		}
		if ($rCommands !== []) {
			// Handed out: the node may run them and raise its high-water past
			// them though their ack never arrives.
			self::raiseCmdSeq((int) $rNode['server_id'], max(array_column($rCommands, 'seq')));
		}
		return self::ok($rKeys, $rCtx, ['commands' => $rCommands]);
	}

	/**
	 * The node's high-water (`after_seq`) as `cluster_nodes.cmd_seq`, the
	 * floor under the next command's seq (CommandBus::enqueue()). The agent
	 * raises it when it runs a command, so when the ack is lost for longer
	 * than the command lives and its row is pruned, a new command must still
	 * not take a seq at or below it: the long-poll, asking for seqs above
	 * `after_seq`, would never hand that one out. At most the highest seq
	 * MAIN issued this node: a node cannot push the floor past its commands.
	 * $rCmdSeq is the row's (the cluster bus's copy may lag): nothing is
	 * written when the bounded high-water is not above it.
	 */
	private static function recordHighWater(int $rServerID, int $rCmdSeq, int $rAfter): void {
		$rDb = DatabaseFactory::get();
		$rDb->query('SELECT MAX(`seq`) AS `seq` FROM `cluster_commands` WHERE `server_id` = ?;', $rServerID);
		$rSeq = min($rAfter, (int) ($rDb->get_row()['seq'] ?? 0));
		if ($rSeq > $rCmdSeq) {
			self::raiseCmdSeq($rServerID, $rSeq);
		}
	}

	/** Raise a node's `cmd_seq` to $rSeq; never lowers it. */
	private static function raiseCmdSeq(int $rServerID, int $rSeq): void {
		if ($rSeq > 0) {
			DatabaseFactory::get()->query('UPDATE `cluster_nodes` SET `cmd_seq` = ? WHERE `server_id` = ? AND `cmd_seq` < ?;', $rSeq, $rServerID, $rSeq);
		}
	}

	/**
	 * `ack`: a command's outcome, accepted only for this node's own commands.
	 * The first ack of a command type that may carry an artefact grant is
	 * audited when it failed (ArtefactGrants::acked), and a `node.root` that
	 * took MAIN's credentials off the node revokes its grant
	 * (DbCredentials::acked), and one of `pin_core` moves the node's pin on
	 * (CorePins::acked); no other ack reads more.
	 */
	private static function ack(ClusterCrypto $rCrypto, array $rNode, SessionKeys $rKeys, string $rCtx, array $rH, array $rP): array {
		$rCmdID = is_string($rP['cmd_id'] ?? null) && preg_match('/^[0-9a-f]{32}\z/', (string) $rP['cmd_id']) ? (string) $rP['cmd_id'] : null;
		$rOk = !empty($rP['ok']);
		$rResult = is_string($rP['result'] ?? null) ? (string) $rP['result'] : '';
		if ($rCmdID === null || !CommandBus::ack((int) $rNode['server_id'], $rCmdID, $rOk, $rResult, $rFirst, $rType)) {
			return self::badRequest($rCrypto, $rH);
		}
		if ($rFirst && in_array($rType, ArtefactGrants::GRANT_TYPES, true)) {
			try {
				ArtefactGrants::acked((int) $rNode['server_id'], $rCmdID, $rOk, $rResult);
			} catch (\Throwable) {
				// The ack stands; an off-air grant not recorded is offered again later.
			}
		}
		if ($rFirst && $rType === 'node.root') {
			try {
				// A node that dropped MAIN's credentials: revoke its grant (Phase 9).
				DbCredentials::acked((int) $rNode['server_id'], $rCmdID, $rOk, $rResult);
			} catch (\Throwable) {
				// The ack stands; the revoke is audited when it fails, and can be run again.
			}
			try {
				// The node's install_id or its new core.pin (Phase 9, CorePins).
				CorePins::acked((int) $rNode['server_id'], $rCmdID, $rOk, $rResult);
			} catch (\Throwable) {
				// The ack stands; cron:cluster offers the pin again.
			}
		}
		return self::ok($rKeys, $rCtx, ['ok' => true]);
	}

	/**
	 * `events`: a batch from one lane, applied in order (EventIngest). A P0 gap
	 * is refused with the number MAIN expects, and the node resends from there.
	 * A P0 batch that goes back past the cursor, and is no copy of one
	 * applied, quarantines the node.
	 * P2 has no number: `first_useq` is not read, and the reply's `useq` is 0.
	 */
	private static function events(ClusterCrypto $rCrypto, array $rNode, SessionKeys $rKeys, string $rCtx, array $rH, array $rP): array {
		$rLane = $rP['lane'] ?? null;
		$rFirst = $rLane === 'p2' ? 0 : ($rP['first_useq'] ?? null);
		$rEvents = $rP['events'] ?? null;
		if (!in_array($rLane, ['p0', 'p1', 'p2'], true) || !is_int($rFirst) || ($rLane !== 'p2' && $rFirst < 1) || !is_array($rEvents) || !array_is_list($rEvents) || count($rEvents) > EventIngest::MAX_EVENTS) {
			return self::badRequest($rCrypto, $rH);
		}
		try {
			$rOut = EventIngest::ingest($rNode, $rLane, $rFirst, $rEvents);
		} catch (\Throwable) {
			return self::dbDown($rCrypto, $rH);
		}
		if (!empty($rOut['backwards'])) {
			// Authenticated evidence of a clone, as in hello: another install
			// numbering P0 from where this node was. The admin decides.
			NodeRegistry::update((int) $rNode['server_id'], ['state' => 'quarantined', 'quarantine_reason' => 'P0 sequence went backwards']);
			ClusterAudit::log('node.quarantine', (int) $rNode['server_id'], ['reason' => 'p0 backwards', 'first_useq' => $rFirst, 'count' => count($rEvents), 'cursor' => $rOut['useq']] + CommandBus::endGranting((int) $rNode['server_id']), 'node');
			// No longer active in the node list: its peers stop trusting it at once.
			ReplicaBuilder::nodesChanged($rCrypto, (int) $rNode['server_id']);
			return self::notActive($rCrypto, $rH, 'quarantined');
		}
		if (!$rOut['ok']) {
			return self::deny($rCrypto, $rH, 409, 'USEQ_GAP', ['expected_useq' => $rOut['expected_useq'] ?? $rOut['useq'] + 1]);
		}
		return self::ok($rKeys, $rCtx, $rOut);
	}

	/**
	 * `recording_complete`: the VOD for a recording the node finished, created
	 * once (RecordingFinalizer). The node names its file after the id, then
	 * reports `recording.state` 2 once it has converted it.
	 */
	private static function recordingComplete(ClusterCrypto $rCrypto, array $rNode, SessionKeys $rKeys, string $rCtx, array $rH, array $rP): array {
		if (($rOff = self::requireFlow($rCrypto, $rNode, $rH, NodeRegistry::FLOW_CONTENT, 'content')) !== null) {
			return $rOff;
		}
		$rRecording = $rP['recording_id'] ?? null;
		$rIcon = $rP['stream_icon'] ?? null;
		if (!is_int($rRecording) || $rRecording <= 0 || ($rIcon !== null && !is_string($rIcon))) {
			return self::badRequest($rCrypto, $rH);
		}
		try {
			$rID = RecordingFinalizer::create($rRecording, (int) $rNode['server_id'], $rIcon);
		} catch (\Throwable) {
			return self::dbDown($rCrypto, $rH);
		}
		if ($rID === null) {
			return self::badRequest($rCrypto, $rH);
		}
		return self::ok($rKeys, $rCtx, ['stream_id' => $rID]);
	}

	/**
	 * The encoding queue of one node ({@see NodeQueue}; `queue` is MAIN's table):
	 *
	 * - `queue_enqueue` {type, stream_ids}: the node's `cron:vod` and its
	 *   created-channel builder adding work;
	 * - `queue_claim` {type, limit}: what is running (with the pid the node
	 *   reported, so it checks its own processes) and what waits;
	 * - `queue_update` {pids, delete}: after starting a job, or losing one.
	 *
	 * `server_id` comes from the authenticated node, never from the payload, so
	 * a node neither reads nor touches another's work.
	 */
	private static function queue(string $rOp, ClusterCrypto $rCrypto, array $rNode, SessionKeys $rKeys, string $rCtx, array $rH, array $rP): array {
		if (($rOff = self::requireFlow($rCrypto, $rNode, $rH, NodeRegistry::FLOW_CONTENT, 'content')) !== null) {
			return $rOff;
		}
		$rServerID = (int) $rNode['server_id'];
		$rType = is_string($rP['type'] ?? null) ? $rP['type'] : '';
		if ($rOp !== 'queue_update' && !in_array($rType, QueueSink::TYPES, true)) {
			return self::badRequest($rCrypto, $rH);
		}

		try {
			if ($rOp === 'queue_enqueue') {
				$rIDs = is_array($rP['stream_ids'] ?? null) ? $rP['stream_ids'] : [];
				if ($rIDs === [] || count($rIDs) > QueueSink::MAX_CLAIM) {
					return self::badRequest($rCrypto, $rH);
				}
				$rOut = ['queued' => NodeQueue::enqueue($rServerID, $rType, $rIDs)];
			} elseif ($rOp === 'queue_claim') {
				$rOut = NodeQueue::claim($rServerID, $rType, (int) ($rP['limit'] ?? 0));
			} else {
				$rPids = is_array($rP['pids'] ?? null) ? $rP['pids'] : [];
				$rDelete = is_array($rP['delete'] ?? null) ? $rP['delete'] : [];
				if (count($rPids) + count($rDelete) > 2 * QueueSink::MAX_CLAIM) {
					return self::badRequest($rCrypto, $rH);
				}
				$rOut = ['applied' => NodeQueue::update($rServerID, $rPids, $rDelete)];
			}
		} catch (\Throwable) {
			return self::dbDown($rCrypto, $rH);
		}

		return self::ok($rKeys, $rCtx, $rOut);
	}

	/**
	 * `conn_snapshot`: one chunk of a CONNECTIONS node's registry, applied
	 * with the last (ConnectionSnapshot). A chunk out of order is refused with
	 * the number MAIN expects; the node then starts over.
	 */
	private static function connSnapshot(ClusterCrypto $rCrypto, array $rNode, SessionKeys $rKeys, string $rCtx, array $rH, array $rP): array {
		if (($rOff = self::requireFlow($rCrypto, $rNode, $rH, NodeRegistry::FLOW_CONNECTIONS, 'connections')) !== null) {
			return $rOff;
		}
		try {
			$rOut = ConnectionSnapshot::receive((int) $rNode['server_id'], $rP);
		} catch (\Throwable) {
			return self::dbDown($rCrypto, $rH);
		}
		if (!empty($rOut['bad'])) {
			return self::badRequest($rCrypto, $rH);
		}
		if (!$rOut['ok']) {
			return self::deny($rCrypto, $rH, 409, 'SNAP_GAP', ['expected_seq' => (int) $rOut['expected_seq']]);
		}
		if (!empty($rOut['done'])) {
			ClusterAudit::log('conn.snapshot', (int) $rNode['server_id'], ['applied' => $rOut['applied'], 'removed' => $rOut['removed'], 'dropped' => $rOut['dropped']], 'node');
		}
		unset($rOut['ok']);
		return self::ok($rKeys, $rCtx, $rOut);
	}

	/**
	 * `conn_admit`: admission for a viewer the node is about to record whose
	 * token has no `adm` claim (ConnectionAdmission::forNode), for a node that
	 * holds its viewers. The line is MAIN's, never the node's; the answer is
	 * {admit, exp, reason?}.
	 */
	private static function connAdmit(ClusterCrypto $rCrypto, array $rNode, SessionKeys $rKeys, string $rCtx, array $rH, array $rP, array $rSettings): array {
		if (($rOff = self::requireFlow($rCrypto, $rNode, $rH, NodeRegistry::FLOW_CONNECTIONS, 'connections')) !== null) {
			return $rOff;
		}
		try {
			$rOut = ConnectionAdmission::forNode($rSettings, (int) $rNode['server_id'], $rP);
		} catch (\Throwable) {
			return self::dbDown($rCrypto, $rH);
		}
		if ($rOut === null) {
			return self::badRequest($rCrypto, $rH);
		}
		return self::ok($rKeys, $rCtx, $rOut);
	}

	/**
	 * `rtmp_auth`: an RTMP viewer on the node (nginx-rtmp's on_play), whose
	 * line a load balancer cannot look up. RtmpViewerAuth makes rtmp.php's
	 * checks for the authenticated node's server and logs a refusal here, as
	 * rtmp.php logs it on MAIN; the node then records the viewer itself. The
	 * viewer's address is the node's to say, as in its other reports, so the
	 * node's failed checks have a budget (RTMP_FAIL_BUDGET), and a refusal
	 * tells it only whether the credentials named a line (its flood guard
	 * counts those that did not): why is in MAIN's client log.
	 */
	private static function rtmpAuth(ClusterCrypto $rCrypto, array $rNode, SessionKeys $rKeys, string $rCtx, array $rH, array $rP, array $rSettings): array {
		$rStreamID = $rP['stream_id'] ?? null;
		$rIP = $rP['ip'] ?? null;
		$rRestream = $rP['restream'] ?? false;
		$rUUID = $rP['uuid'] ?? null;
		$rCreds = array_key_exists('token', $rP) ? ['token' => $rP['token']] : ['username' => $rP['username'] ?? null, 'password' => $rP['password'] ?? null];
		foreach ($rCreds as $rValue) {
			if (!is_string($rValue) || strlen($rValue) > 512) {
				return self::badRequest($rCrypto, $rH);
			}
		}
		if (!is_int($rStreamID) || $rStreamID <= 0 || !is_string($rIP) || filter_var($rIP, FILTER_VALIDATE_IP) === false || !is_bool($rRestream)
			|| ($rUUID !== null && (!is_string($rUUID) || !preg_match(AgentConnections::CONN_UUID, $rUUID)))
		) {
			return self::badRequest($rCrypto, $rH);
		}
		$rServerID = (int) $rNode['server_id'];
		if (self::rtmpFails($rServerID, false) >= self::RTMP_FAIL_BUDGET) {
			return self::ok($rKeys, $rCtx, ['ok' => false, 'reason' => 'REFUSED']);
		}
		try {
			$rOut = RtmpViewerAuth::check($rSettings, !empty($rSettings['enable_cache']) && CacheReader::isReady($rSettings), null, null, $rStreamID, $rIP, $rCreds, $rRestream, $rServerID);
			if ($rOut['ok'] === true && $rUUID !== null) {
				// MAIN's mint for this viewer, as auth.php's for an HTTP viewer's token: the
				// node records the viewer with its proof and admission (ConnectionTracker::
				// openRecord), so the record is MAIN's own under cluster_conn_binding = enforce.
				$rOut['token'] = RtmpViewerAuth::mint($rSettings, $rOut, $rStreamID, $rIP, $rUUID);
			}
		} catch (\Throwable) {
			return self::dbDown($rCrypto, $rH);
		}
		unset($rOut['channel']);
		if ($rOut['ok'] !== true) {
			if ($rOut['reason'] === RtmpViewerAuth::AUTH_FAILED && self::rtmpFails($rServerID, true) === self::RTMP_FAIL_BUDGET) {
				ClusterAudit::log('rtmp.auth_budget', $rServerID, ['fails' => self::RTMP_FAIL_BUDGET], 'node');
			}
			$rOut = ['ok' => false, 'reason' => $rOut['reason'] === RtmpViewerAuth::AUTH_FAILED ? RtmpViewerAuth::AUTH_FAILED : 'REFUSED'];
		}
		return self::ok($rKeys, $rCtx, $rOut);
	}

	/**
	 * The node's failed `rtmp_auth` checks in this minute of MAIN's clock, one
	 * more first when $rAdd (TMP_PATH/cluster_rtmp/<server id>, under a lock).
	 * A counter that cannot be opened counts nothing.
	 */
	private static function rtmpFails(int $rServerID, bool $rAdd): int {
		$rDir = (defined('TMP_PATH') ? TMP_PATH : sys_get_temp_dir() . '/') . 'cluster_rtmp/';
		if (!is_dir($rDir)) {
			@mkdir($rDir, 0700, true);
		}
		$rFP = @fopen($rDir . $rServerID, 'c+');
		if ($rFP === false) {
			return 0;
		}
		try {
			flock($rFP, LOCK_EX);
			$rMinute = intdiv(ClusterClock::nowMs(), 60000);
			[$rAt, $rFails] = array_map('intval', array_pad(explode(' ', trim((string) stream_get_contents($rFP))), 2, '0'));
			$rFails = $rAt === $rMinute ? $rFails : 0;
			if ($rAdd) {
				$rFails++;
				ftruncate($rFP, 0);
				rewind($rFP);
				fwrite($rFP, $rMinute . ' ' . $rFails);
			}
			return $rFails;
		} finally {
			fclose($rFP);
		}
	}

	/**
	 * `config`: the node's replica (ReplicaBuilder), in shadow until its CONFIG
	 * flow is on. The blocklist: a `blk` delta from `blocklist_since`, or the
	 * whole section when there is no delta to give. `have` maps each section to
	 * the ETag the node holds, so a section it already has is not sent again;
	 * a section sent whole (settings, servers, node, crontab, cluster,
	 * bouquets, categories, secrets) goes only to an agent that names it, and
	 * `secrets` only to a node in mode 1 or 2 (ReplicaBuilder::serves). A name
	 * MAIN does not serve and a whole section it cannot sign without a licence
	 * are left out of the reply. The agent reads at most 8 MiB of a reply: a
	 * section whose sealed record passes ReplicaBuilder::MAX_WHOLE_BYTES is
	 * answered `{too_large, etag}` to an agent that names `bouquets` or
	 * `categories` (the contract that takes it) and left out for an older
	 * one, and the sections are added in ReplicaBuilder::REPLY_ORDER while
	 * the reply stays within ReplicaBuilder::MAX_REPLY; one past it is left
	 * out, and the node asks again at its next poll, when what this reply
	 * carried is `unchanged`. To an agent that says `parts`, `too_large`
	 * also says how many parts MAIN staged the section in, which it fetches
	 * with `config {part}` (configPart()); so does a whole blocklist section
	 * to an agent that says `blocklist_parts`. A section MAIN cannot read (a failed read, no
	 * settings row, an unset secret) answers `503 DB`: the node keeps what it
	 * holds.
	 */
	private static function config(ClusterCrypto $rCrypto, array $rNode, SessionKeys $rKeys, string $rCtx, array $rH, array $rP, array $rSettings, array $rMain): array {
		if (array_key_exists('part', $rP)) {
			return self::configPart($rCrypto, $rNode, $rKeys, $rCtx, $rH, $rP['part']);
		}
		// An agent that fetches a section too large for one reply in parts.
		$rParts = ($rP['parts'] ?? false) === true;
		$rSince = $rP['blocklist_since'] ?? 0;
		$rHave = is_array($rP['have'] ?? null) ? $rP['have'] : [];
		if (!is_int($rSince) || $rSince < 0) {
			return self::badRequest($rCrypto, $rH);
		}
		foreach ($rHave as $rEtag) {
			if (!is_string($rEtag) || ($rEtag !== '' && !preg_match('/^[0-9a-f]{64}\z/', $rEtag))) {
				return self::badRequest($rCrypto, $rH);
			}
		}
		try {
			$rBlockParts = ($rP[ReplicaBuilder::BLOCKLIST_PARTS] ?? false) === true;
			$rOut = [ReplicaBuilder::SECTION_BLOCKLIST => ReplicaBuilder::blocklist($rCrypto, $rNode, $rSince, $rHave[ReplicaBuilder::SECTION_BLOCKLIST] ?? '', $rBlockParts)];
			// What the sections sent whole may take of the reply, after the blocklist.
			$rRoom = ReplicaBuilder::MAX_REPLY - strlen((string) json_encode($rOut, JSON_UNESCAPED_SLASHES));
			// An agent that names the catalogue takes `too_large` for any section it names.
			$rTooLarge = array_key_exists(ReplicaSections::BOUQUETS, $rHave) || array_key_exists(ReplicaSections::CATEGORIES, $rHave);
			// Sent whole: only to an agent that asks for them (have names the section).
			foreach (ReplicaBuilder::REPLY_ORDER as $rSection) {
				if (!array_key_exists($rSection, $rHave) || !ReplicaBuilder::serves($rNode, $rSection)) {
					continue;
				}
				try {
					$rPart = ReplicaBuilder::whole($rCrypto, $rNode, $rSection, (string) $rHave[$rSection], $rSettings, $rMain, $rParts);
				} catch (ClusterRefusedException $rE) {
					// A whole section grants: without a licence it is left out and
					// the node keeps what it holds, while the blocklist's bans in
					// the same reply still reach it.
					if ($rE->reason() !== 'LICENCE') {
						throw $rE;
					}
					continue;
				}
				// An older agent: left out, as before; it keeps what it holds.
				if (!empty($rPart['too_large']) && !$rTooLarge) {
					continue;
				}
				// No room left in this reply: the next poll has it, the sections
				// sent now being `unchanged` then.
				$rSize = strlen((string) json_encode([$rSection => $rPart], JSON_UNESCAPED_SLASHES));
				if ($rSize > $rRoom) {
					continue;
				}
				$rRoom -= $rSize;
				$rOut[$rSection] = $rPart;
			}
		} catch (ClusterRefusedException $rE) {
			return self::refusal($rCrypto, $rE->reason(), $rNode, $rH);
		} catch (\Throwable) {
			return self::dbDown($rCrypto, $rH);
		}
		return self::ok($rKeys, $rCtx, $rOut);
	}

	/**
	 * `config {part: {section, etag, n}}`: part n of a section MAIN staged
	 * for this node when it answered it `too_large` with `parts`
	 * (ReplicaBuilder::part): `{part: {section, etag, n, parts, data}}`, or
	 * `{part: {section, etag, gone: true}}` when it is no longer staged. The
	 * node joins the parts' data into the sealed record and opens it as a
	 * section sent whole. Only a section MAIN serves this node.
	 *
	 * @param array<string, mixed> $rNode
	 * @param array<string, mixed> $rH
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private static function configPart(ClusterCrypto $rCrypto, array $rNode, SessionKeys $rKeys, string $rCtx, array $rH, mixed $rAsk): array {
		$rSection = is_array($rAsk) ? ($rAsk['section'] ?? null) : null;
		$rEtag = is_array($rAsk) ? ($rAsk['etag'] ?? null) : null;
		$rN = is_array($rAsk) ? ($rAsk['n'] ?? null) : null;
		$rValid = is_string($rSection) && ($rSection === ReplicaBuilder::SECTION_BLOCKLIST || (in_array($rSection, ReplicaBuilder::REPLY_ORDER, true) && ReplicaBuilder::serves($rNode, $rSection)));
		if (!$rValid || !is_string($rEtag) || !preg_match('/^[0-9a-f]{64}\z/', $rEtag) || !is_int($rN) || $rN < 0) {
			return self::badRequest($rCrypto, $rH);
		}
		return self::ok($rKeys, $rCtx, ['part' => ['section' => $rSection] + ReplicaBuilder::part($rNode, $rSection, $rEtag, $rN)]);
	}

	/**
	 * `streams`: the node's R2 streams section (StreamReplica), for a node
	 * whose STREAMS flow is on and whose agent said `streams` at hello. With
	 * `since` alone, what changed past the node's cursor (`full` when it must
	 * check every stream); with `resync` ({from, to, hashes}), the records of
	 * the streams it holds in that range whose ETag differs from the one it
	 * names, and the removals. A delta's `tickets` ({epoch, from}) asks for
	 * the relay and file tickets of the current epoch (TicketService). Each
	 * record is `rep`-signed and sealed to the node; one that cannot be signed without a licence is left out
	 * (`withheld`). A section MAIN cannot read answers `503 DB`.
	 */
	private static function streams(ClusterCrypto $rCrypto, array $rNode, SessionKeys $rKeys, string $rCtx, array $rH, array $rP): array {
		$rMissing = StreamReplica::refused($rNode);
		if ($rMissing !== null) {
			// An older agent never asks; one whose hello did not say it keeps the section is told which.
			return self::deny($rCrypto, $rH, 409, 'FLOW_OFF', ['flow' => 'streams'] + ($rMissing === 'feature' ? ['feature' => StreamReplica::FEATURE] : []));
		}
		$rSince = $rP['since'] ?? null;
		// `resync: null` is a delta, as if absent (an agent's empty field).
		$rAsk = $rP['resync'] ?? null;
		$rResync = $rAsk === null ? null : StreamReplica::resyncRequest($rAsk);
		// A delta may ask for the epoch's tickets ({epoch, from}; Phase 8).
		$rTickets = TicketService::request($rP['tickets'] ?? null);
		if (!is_int($rSince) || $rSince < 0 || ($rAsk !== null && $rResync === null) || $rTickets === false) {
			return self::badRequest($rCrypto, $rH);
		}
		try {
			$rOut = $rResync === null ? StreamReplica::delta($rCrypto, $rNode, $rSince, $rTickets) : StreamReplica::resync($rCrypto, $rNode, $rSince, $rResync['from'], $rResync['to'], $rResync['hashes']);
		} catch (ClusterRefusedException $rE) {
			return self::refusal($rCrypto, $rE->reason(), $rNode, $rH);
		} catch (\Throwable) {
			return self::dbDown($rCrypto, $rH);
		}
		return self::ok($rKeys, $rCtx, $rOut);
	}

	/**
	 * `artefact`: a chunk (at most 4 MiB, in the BOX) of the file a live grant
	 * of this node's names (ArtefactGrants::serve): an off-air video, a custom
	 * module's archive, the pinned agent. Bulk lane, under an ingest permit.
	 * The node names the grant's command id, an offset and a length; never a
	 * path. A grant MAIN cannot read answers `503 DB`.
	 */
	private static function artefact(ClusterCrypto $rCrypto, array $rNode, SessionKeys $rKeys, string $rCtx, array $rH, array $rP, array $rSettings): array {
		try {
			[$rOut, $rDenied] = ArtefactGrants::serve($rNode, $rP, $rSettings);
		} catch (\Throwable) {
			return self::dbDown($rCrypto, $rH);
		}
		if ($rDenied !== null) {
			return self::deny($rCrypto, $rH, $rDenied[0], $rDenied[1], $rDenied[2]);
		}
		return self::ok($rKeys, $rCtx, (array) $rOut);
	}

	/**
	 * Map an extension refusal to a signed denial. $rSession: the extension
	 * refused the node's session itself (a licence refusal there is the hard
	 * revocation mode's), so the denial also carries the node's pending
	 * restrictive commands (killsFor()).
	 */
	private static function refusal(ClusterCrypto $rCrypto, string $rReason, array $rNode, array $rH, bool $rSession = false): array {
		return match (true) {
			$rReason === 'REVOKED' => self::revoked($rCrypto, $rNode, $rH),
			$rReason === 'LICENCE' => self::deny($rCrypto, $rH, 403, 'LICENCE_INVALID', $rSession ? self::killsFor($rNode) : []),
			$rReason === 'CLOCK' => self::deny($rCrypto, $rH, 503, 'CLOCK'),
			default => self::deny($rCrypto, $rH, 401, 'TOKEN_EXPIRED', ['detail' => substr($rReason, 0, 32)]),
		};
	}

	/**
	 * `lb_revocation_mode=hard` (plan section 4): without a licence the
	 * extension refuses the node's session, so neither the long-poll nor a
	 * MAC'd reply can reach it, yet kills, drops and stops must. Its pending
	 * restrictive commands then ride the panel-signed LICENCE_INVALID that its
	 * next request gets, in the long-poll's shape, each under its own `cmd`
	 * signature (CommandBus::restrictive() says how the agent takes them).
	 *
	 * The request is not authenticated (no session, so no MAC to check), so
	 * the list is SEALed to the node's box key, purpose SEAL_COMMANDS, the
	 * node uuid as context: whoever names the node learns only its size, as
	 * a sniffer does of a BOXed reply. Nothing is marked delivered. Only for
	 * a node that takes commands.
	 *
	 * @param array<string, mixed> $rNode
	 * @return array{commands_sealed?: string} base64 of SEAL(JSON list)
	 */
	private static function killsFor(array $rNode): array {
		if (!CommandBus::accepts($rNode)) {
			return [];
		}
		try {
			$rCommands = CommandBus::restrictive((int) $rNode['server_id']);
			if ($rCommands === []) {
				return [];
			}
			return ['commands_sealed' => base64_encode(Seal::seal((string) $rNode['node_box_pub'], self::SEAL_COMMANDS, (string) $rNode['node_uuid'], (string) json_encode($rCommands, JSON_UNESCAPED_SLASHES)))];
		} catch (\Throwable) {
			return []; // the denial goes out regardless
		}
	}

	/**
	 * A denial past preflight()'s header check: panel-signed, naming the
	 * request's node and nonce.
	 *
	 * @param array{node: string, nonce: string} $rH
	 * @param array<string, mixed> $rExtra
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private static function deny(ClusterCrypto $rCrypto, array $rH, int $rStatus, string $rReason, array $rExtra = []): array {
		return DenialFactory::deny($rCrypto, $rStatus, $rReason, $rH['node'], $rH['nonce'], $rExtra);
	}

	/**
	 * 400 BAD_REQUEST for a request whose headers named its node.
	 *
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private static function badRequest(ClusterCrypto $rCrypto, array $rH): array {
		return self::deny($rCrypto, $rH, 400, 'BAD_REQUEST');
	}

	/**
	 * 503 DB: what a handler needs MAIN could not read or write; the node
	 * keeps what it holds and asks again.
	 *
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private static function dbDown(ClusterCrypto $rCrypto, array $rH): array {
		return self::deny($rCrypto, $rH, 503, 'DB');
	}

	/**
	 * 403 NODE_REVOKED, naming the generation revoked.
	 *
	 * @param array<string, mixed> $rNode
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private static function revoked(ClusterCrypto $rCrypto, array $rNode, array $rH): array {
		return self::deny($rCrypto, $rH, 403, 'NODE_REVOKED', ['revoked_gen' => (int) $rNode['gen']]);
	}

	/**
	 * 409 NOT_ACTIVE, naming the node's state.
	 *
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private static function notActive(ClusterCrypto $rCrypto, array $rH, mixed $rState): array {
		return self::deny($rCrypto, $rH, 409, 'NOT_ACTIVE', ['state' => $rState]);
	}

	/**
	 * 409 FLOW_OFF (naming $rName) unless the node's $rFlow flow is on; null
	 * when it is.
	 *
	 * @param array<string, mixed> $rNode
	 * @return array{status: int, headers: array<string, string>, body: string}|null
	 */
	private static function requireFlow(ClusterCrypto $rCrypto, array $rNode, array $rH, int $rFlow, string $rName): ?array {
		return ((int) $rNode['flows'] & $rFlow) === 0 ? self::deny($rCrypto, $rH, 409, 'FLOW_OFF', ['flow' => $rName]) : null;
	}

	/**
	 * A handler's BOXed reply: $rOut with MAIN's clock (`main_time_ms`) last,
	 * unless $rOut carries it.
	 *
	 * @param array<string, mixed> $rOut
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private static function ok(SessionKeys $rKeys, string $rCtx, array $rOut): array {
		return ClusterReply::boxed($rKeys, $rCtx, $rOut + ['main_time_ms' => ClusterClock::nowMs()]);
	}

	/**
	 * A body SEALed to the panel box key under the request context, for
	 * $rPurpose, as JSON: null when it does not open or is no JSON array.
	 *
	 * @return array<mixed>|null
	 */
	private static function sealedPayload(ClusterCrypto $rCrypto, string $rPurpose, string $rBody, string $rCtx): ?array {
		try {
			$rPlain = $rCrypto->openSealed($rPurpose, $rBody, $rCtx);
		} catch (ClusterRefusedException) {
			return null;
		}
		$rP = json_decode($rPlain, true);
		return is_array($rP) ? $rP : null;
	}

	/**
	 * A payload's base64 field, strictly decoded: false when it is absent,
	 * not a string or not base64.
	 *
	 * @param array<mixed>|null $rP
	 */
	private static function base64Field(?array $rP, string $rName): string|false {
		return is_string($rP[$rName] ?? null) ? base64_decode((string) $rP[$rName], true) : false;
	}

	/** A value as the 16-byte nonce NonceStore keeps for it (a challenge, a re-key minute). */
	private static function nonceKey(string $rValue): string {
		return substr(hash('sha256', $rValue, true), 0, 16);
	}

	private static function header(array $rHeaders, string $rName): string {
		foreach ($rHeaders as $rKey => $rValue) {
			if (strcasecmp((string) $rKey, $rName) === 0) {
				return trim((string) $rValue);
			}
		}
		return '';
	}

	private static function short(mixed $rValue, int $rMax = 64): ?string {
		return is_string($rValue) && $rValue !== '' ? substr(preg_replace('/[^\x21-\x7e]/', '', $rValue) ?? '', 0, $rMax) : null;
	}
}
