<?php

declare(strict_types=1);

namespace XcVm\Infrastructure\Cache;

/** Hold a batch's line locks from its database read through cache publication. */
final class LineCacheLocks {
	/** @param list<int> $ids */
	public static function run(array $ids, \Closure $work): bool {
		$directory = CACHE_TMP_PATH . 'line_locks/';
		if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
			return false;
		}
		$ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
		sort($ids, SORT_NUMERIC);
		$handles = [];
		try {
			foreach ($ids as $id) {
				$handle = @fopen($directory . $id . '.lock', 'c');
				if ($handle === false) {
					return false;
				}
				$handles[] = $handle;
				if (!flock($handle, LOCK_EX)) {
					return false;
				}
			}
			$work();
			return true;
		} finally {
			foreach ($handles as $handle) {
				fclose($handle);
			}
		}
	}
}
