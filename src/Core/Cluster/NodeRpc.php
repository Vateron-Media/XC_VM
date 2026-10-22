<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Http\ApiClient;
use XcVm\Core\Process\ProcessRunner;
use XcVm\Domain\Cluster\ClusterRoute;

/**
 * Node RPC
 *
 * MAIN asking a node to do or report something, with the answer coming back:
 * process lists, free space, directory scans, a probe, starting or stopping a
 * stream. Callers used ApiClient::systemRequest() and asyncRequest() directly,
 * which POST to the node's /api with the live-streaming password.
 *
 * The legacy transport still does exactly that. In API mode (Phase 4) these
 * actions become signed `node.rpc{action}` commands, answered through
 * `ack` / `rpc_result`, and only the transport here changes. ACTIONS is the
 * catalogue the callers use, so a new call is a deliberate addition rather
 * than a string that reaches the node unseen.
 */
final class NodeRpc {
	/** Every action MAIN sends to a node's system API. */
	public const ACTIONS = [
		'closeConnection', 'force_stream', 'fpm_status', 'free_streams', 'free_temp',
		'get_archive_files', 'get_certificate_info', 'get_free_space', 'get_pids',
		'kill_pid', 'kill_plex', 'probe', 'reload_nginx', 'restore_images',
		'rtmp_kill', 'rtmp_stats', 'scandir', 'scandir_recursive', 'signal_send',
		'ssl_log', 'stats', 'stream', 'streams_ramdisk', 'vod',
	];

	/** @var (callable(string, array<int>, array<string, mixed>, int): mixed)|null */
	private static $rTransport;

	/**
	 * Ask one node and wait for its answer.
	 *
	 * @param array<string, mixed> $rData Payload; `action` must be in ACTIONS.
	 * @return mixed The node's raw response body, or null when it is offline or unknown.
	 */
	public static function request(int $rServerID, array $rData, int $rTimeout = 5) {
		self::check($rData);
		if (self::$rTransport !== null) {
			return (self::$rTransport)('request', [$rServerID], $rData, $rTimeout);
		}
		if (self::isSelf($rServerID)) {
			return self::local($rData, $rTimeout);
		}
		// A node with the COMMANDS flow gets a signed node.rpc command (MAIN only).
		if (class_exists(ClusterRoute::class)) {
			[$rRouted, $rResult] = ClusterRoute::rpc($rServerID, $rData, $rTimeout);
			if ($rRouted) {
				return $rResult;
			}
		}
		return ApiClient::systemRequest($rServerID, $rData, $rTimeout);
	}

	/** Queue a cluster command without waiting for its ack; legacy nodes keep their HTTP response. */
	public static function submit(int $rServerID, array $rData): bool {
		self::check($rData);
		if (self::$rTransport !== null) {
			$rResult = (self::$rTransport)('broadcast', [$rServerID], $rData, 0);
			return is_array($rResult) && ($rResult['result'] ?? false) === true;
		}
		if (class_exists(ClusterRoute::class)) {
			[$rRouted, $rQueued] = ClusterRoute::send($rServerID, $rData);
			if ($rRouted) {
				return $rQueued;
			}
		}
		$rReply = self::isSelf($rServerID) ? self::local($rData, 5) : ApiClient::systemRequest($rServerID, $rData, 5);
		$rAnswer = is_string($rReply) ? json_decode($rReply, true) : null;
		return is_array($rAnswer) && ($rAnswer['result'] ?? false) === true;
	}

	/**
	 * Send to several nodes at once without waiting for answers (offline nodes
	 * are skipped).
	 *
	 * @param list<int> $rServerIDs
	 * @param array<string, mixed> $rData Payload; `action` must be in ACTIONS.
	 * @return array{result: bool}
	 */
	public static function broadcast(array $rServerIDs, array $rData): array {
		self::check($rData);
		if (self::$rTransport !== null) {
			return (self::$rTransport)('broadcast', array_map('intval', $rServerIDs), $rData, 0);
		}
		// MAIN's own share runs here and is waited for (local()); the nodes' is not.
		$rOthers = array_values(array_filter($rServerIDs, static fn($rServerID): bool => !self::isSelf((int) $rServerID)));
		if (count($rOthers) !== count($rServerIDs)) {
			self::local($rData, 5);
			if ($rOthers === []) {
				return ['result' => true];
			}
			$rServerIDs = $rOthers;
		}
		if (class_exists(ClusterRoute::class)) {
			$rLegacy = [];
			foreach ($rServerIDs as $rServerID) {
				if (!ClusterRoute::send((int) $rServerID, $rData)[0]) {
					$rLegacy[] = $rServerID;
				}
			}
			if ($rLegacy === []) {
				return ['result' => true];
			}
			$rServerIDs = $rLegacy;
		}
		return ApiClient::asyncRequest($rServerIDs, $rData);
	}

	/** The most a local action may answer: a recursive directory scan. */
	private const LOCAL_MAX = 16 << 20;

	private static function isSelf(int $rServerID): bool {
		return defined('SERVER_ID') && $rServerID === (int) SERVER_ID;
	}

	/**
	 * MAIN asking itself. It has no agent to take a signed command, and its own
	 * /api went with the load balancers' (PR #392), so the action runs here: in
	 * a console process, since its handler ends with exit(), and as xc_vm, the
	 * php-fpm user /api ran it as.
	 *
	 * @param array<string, mixed> $rData
	 * @return string|null the action's response body; null when it failed or outran $rTimeout
	 */
	private static function local(array $rData, int $rTimeout): ?string {
		$rArgv = ['timeout', (string) max(1, $rTimeout), PHP_BIN, MAIN_HOME . 'console.php', 'cluster:exec', '--local'];
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			$rArgv = ['runuser', '-u', 'xc_vm', '--', ...$rArgv];
		}
		[$rStatus, $rOut] = ProcessRunner::capture($rArgv, self::LOCAL_MAX, (string) json_encode($rData));
		return $rStatus === 0 ? $rOut : null;
	}

	/** Replace the transport (tests; later the cluster API). Null restores the HTTP transport. */
	public static function useTransport(?callable $rTransport): void {
		self::$rTransport = $rTransport;
	}

	/** @param array<string, mixed> $rData */
	private static function check(array $rData): void {
		$rAction = $rData['action'] ?? null;
		if (!is_string($rAction) || !in_array($rAction, self::ACTIONS, true)) {
			throw new \InvalidArgumentException('Unknown node RPC action: ' . (is_string($rAction) ? $rAction : gettype($rAction)));
		}
	}
}
