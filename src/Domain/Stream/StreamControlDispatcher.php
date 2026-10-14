<?php

namespace XcVm\Domain\Stream;

use XcVm\Core\Cluster\NodeRpc;
use XcVm\Core\License\LicenseGate;
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
		$targets = !array_key_exists('servers', $request) ? array_map('intval', array_keys($servers))
			: self::positiveIds($request['servers']);
		if ($targets === null || $targets === []) {
			return self::failure('INVALID_SERVER_IDS');
		}
		foreach ($targets as $serverId) {
			if (!isset($servers[$serverId])) {
				return self::failure('UNKNOWN_SERVER');
			}
		}
		if ($function === 'start' && !LicenseGate::licensed()) {
			return self::failure('LICENSE_DENIED');
		}
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
		$errors = [];
		foreach ($ids as $streamId) {
			if (!isset($assigned[$streamId])) {
				$errors[] = ['stream_id' => $streamId, 'code' => 'STREAM_NOT_ASSIGNED_TO_TARGET'];
			} elseif (array_key_exists('servers', $request)) {
				foreach ($targets as $serverId) {
					if (!isset($byServer[$serverId][$streamId])) {
						$errors[] = ['server_id' => $serverId, 'stream_id' => $streamId, 'code' => 'STREAM_NOT_ASSIGNED_TO_TARGET'];
					}
				}
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
					// One RPC per stream: a typed batch acknowledges only its last command.
					foreach ($streamIds as $streamId) {
						$payload = ['action' => $action, 'function' => $function, 'stream_ids' => [$streamId]];
						if ($action === 'vod' && $function === 'start') {
							$payload['force'] = !empty($request['force']);
						}
						$reply = NodeRpc::request($serverId, $payload, max(1, $timeout));
						$answer = is_string($reply) ? json_decode($reply, true) : null;
						if (!is_array($answer) || ($answer['result'] ?? false) !== true) {
							$errors[] = ['server_id' => $serverId, 'stream_id' => $streamId, 'code' => 'REMOTE_CONTROL_FAILED'];
						} else {
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

	/** One node-local control, also used by signed RPC and typed cluster commands. */
	public static function local(string $action, string $function, int $streamId, bool $force = false): bool {
		if ($streamId <= 0 || !in_array($action, ['stream', 'vod'], true) || !in_array($function, ['start', 'stop'], true)) {
			return false;
		}
		$lock = StreamControlGuard::acquire($streamId, $action === 'vod');
		if ($lock === false) {
			return false;
		}
		try {
			if ($action === 'stream') {
				$generation = StreamControlGuard::advance($lock);
				if ($function === 'stop') {
					StreamProcess::stopStream($streamId, true);
					return true;
				}
				if (StreamSource::streamRow($streamId, true) === null) {
					return false;
				}
				return in_array(StreamProcess::startMonitor($streamId, 1, $generation), [StreamProcess::MONITOR_PHP, StreamProcess::MONITOR_FANOUT], true);
			}
			if ($function === 'stop') {
				StreamProcess::stopMovie($streamId);
				return true;
			}
			if (StreamSource::streamRow($streamId, false) === null) {
				return false;
			}
			// Queue acceptance must precede any destructive replacement work.
			return $force ? StreamControlGuard::replaceMovie($streamId) : StreamProcess::queueMovie($streamId) === true;
		} finally {
			StreamControlGuard::release($lock);
		}
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
