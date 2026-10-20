<?php

namespace XcVm\Domain\Stream;

use XcVm\Core\Cluster\NodeRpc;
use XcVm\Core\Logging\FileLogger;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;

/** Backend dispatch for already-authorized admin stream/VOD controls. */
final class StreamControlDispatcher {
	/** @return array<string, mixed> Accepted does not mean the producer is running. */
	public static function request(array $request, int $timeout = 5): array {
		$action = $request['action'] ?? '';
		$function = $request['sub'] ?? '';
		if ($function === 'restart') {
			$function = 'start';
		}
		if (!in_array($action, ['stream', 'vod'], true)
			|| !in_array($function, ['start', 'stop'], true)
		) {
			return self::failure('INVALID_STREAM_ACTION');
		}
		$ids = self::positiveIds($request['stream_ids'] ?? null);
		if ($ids === null || $ids === []) {
			return self::failure('INVALID_STREAM_IDS');
		}
		$servers = ServerRepository::getAll();
		$targets = !array_key_exists('servers', $request) || $request['servers'] === [] ? array_map('intval', array_keys($servers))
			: self::positiveIds($request['servers']);
		if ($targets === null || $targets === []) {
			return self::failure('INVALID_SERVER_IDS');
		}
		foreach ($targets as $serverId) {
			if (!isset($servers[$serverId])) {
				return self::failure('UNKNOWN_SERVER');
			}
		}
		// No licence gate here: enforcement is deliberately soft (LicenseGate), so a
		// local MAIN start must fall back to the PHP monitor rather than be hard
		// blocked; the signed cluster transport (ClusterRoute) still enforces the
		// licence for remote typed starts.
		$db = DatabaseFactory::get();
		if ($db === null || !$db->query(
			'SELECT `stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` IN ('
			. implode(',', array_fill(0, count($ids), '?')) . ');',
			...$ids
		)
		) {
			return self::failure('STREAM_ASSIGNMENTS_UNAVAILABLE');
		}
		$rows = $db->get_rows();
		if (!is_array($rows)) {
			return self::failure('STREAM_ASSIGNMENTS_UNAVAILABLE');
		}
		$byServer = [];
		$assigned = [];
		foreach ($rows as $row) {
			$serverId = (int) $row['server_id'];
			$streamId = (int) $row['stream_id'];
			if (in_array($serverId, $targets, true) && in_array($streamId, $ids, true)) {
				$byServer[$serverId][$streamId] = $streamId;
				$assigned[$streamId] = true;
			}
		}
		// An id assigned to none of the requested targets is reported and skipped,
		// not a reason to abort the whole batch (and never expanded to unrelated
		// servers): the other ids still start or stop, and the client sees which
		// id was not assigned in `errors`.
		$errors = [];
		foreach ($ids as $streamId) {
			if (!isset($assigned[$streamId])) {
				$errors[] = ['stream_id' => $streamId, 'code' => 'STREAM_NOT_ASSIGNED_TO_TARGET'];
			}
		}
		$accepted = [];
		foreach ($byServer as $serverId => $streamIds) {
			$streamIds = array_values($streamIds);
			try {
				if ($serverId === (int) SERVER_ID) {
					foreach ($streamIds as $streamId) {
						if (!self::local($action, $function, $streamId, !empty($request['force']))) {
							$errors[] = ['server_id' => $serverId, 'stream_id' => $streamId, 'code' => 'LOCAL_CONTROL_REFUSED'];
						} else {
							$accepted[] = ['server_id' => $serverId, 'stream_id' => $streamId];
						}
						if ($action === 'stream' && $function === 'start' && count($streamIds) > 1) {
							usleep(50000);
						}
					}
				} else {
					if (empty($servers[$serverId]['server_online'])) {
						$errors[] = ['server_id' => $serverId, 'code' => 'SERVER_OFFLINE'];
						continue;
					}
					$payload = ['action' => $action, 'function' => $function, 'stream_ids' => $streamIds];
					if ($action === 'vod' && $function === 'start') {
						$payload['force'] = !empty($request['force']);
					}
					// Keep the existing signed cluster transport and its licence checks.
					if (!empty($request['async'])) {
						$answer = ['result' => NodeRpc::submit($serverId, $payload)];
					} else {
						$reply = NodeRpc::request($serverId, $payload, max(1, $timeout));
						$answer = is_string($reply) ? json_decode($reply, true) : null;
					}
					if (!is_array($answer) || ($answer['result'] ?? false) !== true) {
						$errors[] = ['server_id' => $serverId, 'code' => 'REMOTE_CONTROL_FAILED'];
					} else {
						foreach ($streamIds as $streamId) {
							$accepted[] = ['server_id' => $serverId, 'stream_id' => $streamId];
						}
					}
				}
			} catch (\Throwable $error) {
				// Never expose source URLs, credentials or driver exception text.
				FileLogger::log('stream_control', 'Control failed on server ' . $serverId . ': ' . get_class($error));
				$errors[] = ['server_id' => $serverId, 'code' => 'STREAM_CONTROL_EXCEPTION'];
			}
		}
		return ['result' => $errors === [] && $accepted !== [], 'accepted' => $accepted, 'errors' => $errors];
	}

	private static function local(string $action, string $function, int $streamId, bool $force): bool {
		if ($action === 'stream') {
			if ($function === 'stop') {
				StreamProcess::stopStream($streamId, true);
				return true;
			}
			// No streamRow existence/type pre-check: it filtered direct_source = 0
			// and so wrongly refused direct-source live streams, which the former
			// path (startMonitor directly) started. startMonitor resolves the row
			// itself; an unknown id simply yields no running monitor.
			return in_array(
				StreamProcess::startMonitor($streamId, 1),
				[StreamProcess::MONITOR_PHP, StreamProcess::MONITOR_FANOUT],
				true
			);
		}
		if ($function === 'stop') {
			StreamProcess::stopMovie($streamId);
			return true;
		}
		StreamProcess::stopMovie($streamId, true);
		if ($force) {
			$pid = StreamProcess::startMovie($streamId);
			return is_int($pid) && $pid > 0;
		}
		return StreamProcess::queueMovie($streamId) === true;
	}

	private static function positiveIds($value): ?array {
		if (!is_array($value)) {
			return null;
		}
		$result = [];
		foreach ($value as $id) {
			if ((!is_int($id) && !is_string($id)) || filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id <= 0) {
				return null;
			}
			$result[(int) $id] = (int) $id;
		}
		return array_values($result);
	}

	private static function failure(string $code): array {
		return ['result' => false, 'error' => $code, 'accepted' => [], 'errors' => [['code' => $code]]];
	}
}
