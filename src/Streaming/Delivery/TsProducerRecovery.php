<?php

declare(strict_types=1);

namespace XcVm\Streaming\Delivery;

use XcVm\Core\Process\ProcessManager;
use XcVm\Streaming\AsyncFileOperations;

/** Resume an existing TS connection at a replacement producer's live edge. */
final class TsProducerRecovery {
	/** Stop waiting for a vanished segment as soon as its producer has died.
	 * @param callable(int, int): bool|null $alive
	 */
	public static function awaitSegment(string $segment, int $stream, int $pid, float $timeout, ?callable $alive = null): bool {
		$alive ??= [ProcessManager::class, 'isStreamAlive'];
		$deadline = hrtime(true) + (int) (max(0, $timeout) * 1_000_000_000);
		do {
			if (!self::producerAlive($alive, $pid, $stream)) {
				return false;
			}
			clearstatcache(true, $segment);
			if (is_file($segment)) {
				return true;
			}
			if (hrtime(true) >= $deadline) {
				break;
			}
			// Keep the existing inotify fast path; re-check producer liveness
			// at least once per second rather than waiting the full budget.
			$delay = min(1000, max(1, (int) (($deadline - hrtime(true)) / 1_000_000)));
			AsyncFileOperations::awaitFileExists($segment, 1, $delay);
		} while (true);
		return false;
	}

	/**
	 * A replacement pid alone is not readiness: its predecessor's playlist may
	 * still exist. Require a playlist and segment written after the pid marker.
	 * The strict timestamp comparison also rejects ambiguous same-second files.
	 *
	 * @param callable(int, int): bool|null $alive
	 * @return array{pid: int, current: int}|null current precedes the segment to send
	 */
	public static function wait(string $directory, int $stream, int $previousPid, float $timeout, ?callable $alive = null): ?array {
		$alive ??= [ProcessManager::class, 'isStreamAlive'];
		$deadline = hrtime(true) + (int) (max(0, $timeout) * 1_000_000_000);
		do {
			$result = self::ready($directory, $stream, $previousPid, $alive);
			if ($result !== null) {
				return $result;
			}
			if (hrtime(true) >= $deadline) {
				break;
			}
			usleep(100000);
		} while (true);
		return null;
	}

	/**
	 * Process state can change between the two sides of a file snapshot.
	 * @param callable(int, int): bool $alive
	 * @phpstan-impure
	 */
	private static function producerAlive(callable $alive, int $pid, int $stream): bool {
		return $alive($pid, $stream);
	}

	/** @param callable(int, int): bool $alive @return array{pid: int, current: int}|null */
	private static function ready(string $directory, int $stream, int $previousPid, callable $alive): ?array {
		$prefix = rtrim($directory, '/') . '/' . $stream;
		$pidPath = $prefix . '_.pid';
		$playlistPath = $prefix . '_.m3u8';
		clearstatcache(true, $pidPath);
		$pidRaw = @file_get_contents($pidPath);
		$pidStat = @stat($pidPath);
		$pid = (int) $pidRaw;
		if ($pid <= 1 || $pid === $previousPid || $pidStat === false || !self::producerAlive($alive, $pid, $stream)) {
			return null;
		}
		clearstatcache(true, $playlistPath);
		$playlistStat = @stat($playlistPath);
		$playlist = @file_get_contents($playlistPath);
		if ($playlistStat === false || $playlistStat['mtime'] <= $pidStat['mtime'] || !is_string($playlist)) {
			return null;
		}
		if (!preg_match_all('/^' . $stream . '_(\d+)\.ts\r?$/m', $playlist, $matches) || !str_contains($playlist, '#EXTINF:')) {
			return null;
		}
		$index = (int) end($matches[1]);
		$segment = $prefix . '_' . $index . '.ts';
		clearstatcache(true, $segment);
		$segmentStat = @stat($segment);
		// Re-read the marker: a second replacement during the snapshot invalidates it.
		if ($segmentStat === false || $segmentStat['size'] <= 0 || $segmentStat['mtime'] <= $pidStat['mtime'] || @file_get_contents($pidPath) !== $pidRaw || !self::producerAlive($alive, $pid, $stream)) {
			return null;
		}
		return ['pid' => $pid, 'current' => $index - 1];
	}
}
