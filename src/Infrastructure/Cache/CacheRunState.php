<?php

namespace XcVm\Infrastructure\Cache;

/**
 * CacheRunState — whether cron:cache_engine's scheduled runs finish cleanly,
 * told by empty marker files in the cache directory: on a full tmpfs touch and
 * unlink still work where a write does not.
 *
 * A run that starts while the previous one never finished (killed by the next
 * run, a restart, an update) or ended failed leaves STALLED until a run
 * finishes cleanly; FAILED is the run in progress' own: a worker could not read
 * the database or write a cache file.
 *
 * @package XC_VM_Infrastructure_Cache
 */
class CacheRunState {
	public const FAILED = 'cache_engine_failed';

	public const UNFINISHED = 'cache_engine_unfinished';

	public const STALLED = 'cache_engine_stalled';

	/** A scheduled run starts: the last one did not finish cleanly when its marker is still there. */
	public static function started(string $rDir): void {
		@touch($rDir . (is_file($rDir . self::UNFINISHED) || is_file($rDir . self::FAILED) ? self::STALLED : self::UNFINISHED));
		@unlink($rDir . self::FAILED);
	}

	/** A run ends: a clean one clears the trail, a failed one leaves it. */
	public static function finished(string $rDir, bool $rFailed): void {
		if (!$rFailed) {
			@unlink($rDir . self::UNFINISHED);
			@unlink($rDir . self::STALLED);
		}
	}

	/** The cache is off: no run to judge. */
	public static function clear(string $rDir): void {
		foreach ([self::FAILED, self::UNFINISHED, self::STALLED] as $rName) {
			@unlink($rDir . $rName);
		}
	}

	/** @return array{failed: bool, stalled: bool} */
	public static function state(string $rDir): array {
		clearstatcache();
		return ['failed' => is_file($rDir . self::FAILED), 'stalled' => is_file($rDir . self::STALLED)];
	}
}
