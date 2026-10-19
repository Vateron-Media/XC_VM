<?php

namespace XcVm\Domain\Stream;

use XcVm\Core\Http\ApiClient;

/** Select and restart unhealthy live instances without touching healthy replicas. */
final class LiveStreamQuickTools {
	public const ONLINE = 'restart_online_streams';
	public const DOWN = 'restart_down_streams';
	public const PROGRESS = 'restart_abnormal_progress';
	public const CODECS = 'restart_missing_codecs';

	public static function matches(array $row, string $action): bool {
		if ($action === self::ONLINE) {
			return (int) ($row['pid'] ?? 0) > 0;
		}
		if ($action === self::DOWN) {
			return (int) ($row['pid'] ?? 0) <= 0 && (int) ($row['stream_status'] ?? 0) !== 0;
		}
		if ($action === self::CODECS) {
			return trim((string) ($row['audio_codec'] ?? '')) === '' || trim((string) ($row['video_codec'] ?? '')) === '';
		}
		if ($action !== self::PROGRESS) {
			return false;
		}
		$progress = json_decode((string) ($row['progress_info'] ?? ''), true);
		$speed = is_array($progress) ? ($progress['speed'] ?? null) : null;
		if (!is_scalar($speed) || !preg_match('/^(?:x)?([0-9]+(?:\.[0-9]+)?(?:e[+-]?[0-9]+)?)(?:x)?$/iD', trim((string) $speed), $match)) {
			return false;
		}
		$value = (float) $match[1];
		return is_finite($value) && ($value < 0.8 || $value > 5.0);
	}

	/** Group exact stream/server pairs; separate lists would restart their cross product. */
	public static function targets(array $rows, string $action): array {
		$targets = [];
		foreach ($rows as $row) {
			$server = (int) ($row['server_id'] ?? 0);
			$stream = (int) ($row['stream_id'] ?? 0);
			if ($server > 0 && $stream > 0 && self::matches($row, $action)) {
				$targets[$server][$stream] = $stream;
			}
		}
		return array_map('array_values', $targets);
	}

	public static function restart(object $db, string $action, ?callable $request = null): array {
		if (!in_array($action, [self::PROGRESS, self::CODECS, self::ONLINE, self::DOWN], true)) {
			throw new \InvalidArgumentException('Unknown live stream quick tool.');
		}
		$where = match ($action) {
			self::ONLINE => 'ss.pid > 0',
			self::DOWN => '(ss.pid IS NULL OR ss.pid <= 0) AND ss.stream_status <> 0',
			default => 's.direct_source = 0 AND ss.pid > 0 AND ss.stream_status = 0 AND ss.to_analyze = 0',
		};
		// A supervised producer has no PHP monitor pid. Select actual pairs,
		// including those daemon instances, rather than two independent ID lists.
		if (!$db->query('SELECT ss.stream_id, ss.server_id, ss.pid, ss.stream_status, ss.progress_info, ss.audio_codec, ss.video_codec FROM `streams_servers` ss INNER JOIN `streams` s ON s.id = ss.stream_id WHERE s.type = 1 AND ' . $where . ';')) {
			return ['result' => false, 'matched' => 0, 'submitted' => 0];
		}
		$targets = self::targets($db->get_rows(), $action);
		$request ??= [ApiClient::class, 'request'];
		$matched = $submitted = 0;
		$success = true;
		foreach ($targets as $server => $streams) {
			$matched += count($streams);
			try {
				$response = $request(['action' => 'stream', 'sub' => 'start', 'stream_ids' => $streams, 'servers' => [(int) $server], 'async' => true], 5);
				$answer = is_string($response) ? json_decode($response, true) : $response;
				if (is_array($answer) && isset($answer['accepted']) && is_array($answer['accepted'])) {
					$accepted = [];
					foreach ($answer['accepted'] as $pair) {
						if ((int) ($pair['server_id'] ?? 0) === (int) $server && in_array((int) ($pair['stream_id'] ?? 0), $streams, true)) {
							$accepted[(int) $pair['stream_id']] = true;
						}
					}
					$submitted += count($accepted);
				} elseif (is_array($answer) && ($answer['result'] ?? false) === true) {
					$submitted += count($streams);
				}
				if (!is_array($answer) || ($answer['result'] ?? false) !== true) {
					$success = false;
				}
			} catch (\Throwable $e) {
				$success = false;
			}
		}
		return ['result' => $success, 'matched' => $matched, 'submitted' => $submitted];
	}
}
