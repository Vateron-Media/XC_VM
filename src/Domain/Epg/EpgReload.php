<?php

namespace XcVm\Domain\Epg;

use XcVm\Core\Process\ProcessRunner;

/** Starts EPG work and serializes every import, including scheduled runs. */
final class EpgReload {
	public static function start(?int $rId = null): bool {
		if (!is_executable(PHP_BIN) || !is_file(MAIN_HOME . 'console.php')) {
			return false;
		}
		$rCommand = [PHP_BIN, MAIN_HOME . 'console.php', 'cron:epg'];
		if ($rId !== null) {
			$rCommand[] = (string) $rId;
		}
		return ProcessRunner::start($rCommand);
	}

	/**
	 * Keep the lock file's inode: removing it would let another run bypass the holder.
	 *
	 * @param callable(): int $rImport
	 * @param bool $rWait Wait for the active importer so a requested reload is not discarded.
	 */
	public static function run(callable $rImport, string $rPath, bool $rWait = false): int {
		$rLock = @fopen($rPath, 'c');
		if ($rLock === false) {
			return 1;
		}
		try {
			if (!flock($rLock, $rWait ? LOCK_EX : LOCK_EX | LOCK_NB, $rWouldBlock)) {
				return $rWouldBlock ? 0 : 1;
			}
			return $rImport();
		} finally {
			fclose($rLock);
		}
	}
}
