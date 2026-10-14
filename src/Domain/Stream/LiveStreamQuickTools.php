<?php

namespace XcVm\Domain\Stream;

use XcVm\Core\Http\ApiClient;

/** Select and restart unhealthy live instances without touching healthy replicas. */
final class LiveStreamQuickTools {
	public const PROGRESS = 'restart_abnormal_progress';
	public const CODECS = 'restart_missing_codecs';

	public static function matches(array $row, string $action): bool {
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
		if (!in_array($action, [self::PROGRESS, self::CODECS], true)) {
			throw new \InvalidArgumentException('Unknown live stream quick tool.');
		}
		if (!$db->query('SELECT ss.stream_id, ss.server_id, ss.progress_info, ss.audio_codec, ss.video_codec FROM `streams_servers` ss INNER JOIN `streams` s ON s.id = ss.stream_id WHERE s.type = 1 AND s.direct_source = 0 AND ss.pid > 0 AND ss.stream_status = 0 AND ss.to_analyze = 0;')) {
			return ['result' => false, 'matched' => 0, 'submitted' => 0];
		}
		$targets = self::targets($db->get_rows(), $action);
		$request ??= [ApiClient::class, 'request'];
		$matched = $submitted = 0;
		$success = true;
		foreach ($targets as $server => $streams) {
			$matched += count($streams);
			try {
				$response = $request(['action' => 'stream', 'sub' => 'start', 'stream_ids' => $streams, 'servers' => [(int) $server]], 120);
				$answer = is_string($response) ? json_decode($response, true) : $response;
				if (is_array($answer) && ($answer['result'] ?? false) === true) {
					$submitted += count($streams);
				} else {
					$success = false;
				}
			} catch (\Throwable $e) {
				$success = false;
			}
		}
		return ['result' => $success, 'matched' => $matched, 'submitted' => $submitted];
	}
}
