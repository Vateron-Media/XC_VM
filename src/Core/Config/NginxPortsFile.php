<?php

declare(strict_types=1);

namespace XcVm\Core\Config;

/** Serializes port replacements with status read/modify/write repairs. */
final class NginxPortsFile {
	public static function write(string $rPath, string $rConfig): bool {
		return self::update($rPath, static fn(string $rCurrent): string => $rConfig);
	}

	/** @param callable(string): string $rTransform */
	public static function update(string $rPath, callable $rTransform): bool {
		// Never truncate before acquiring the lock, and keep the inode so all
		// writers lock the same file. Existing ownership and permissions survive.
		$rHandle = fopen($rPath, 'c+');
		if ($rHandle === false) {
			throw new \RuntimeException('Cannot open nginx ports file: ' . $rPath);
		}
		try {
			if (!flock($rHandle, LOCK_EX)) {
				throw new \RuntimeException('Cannot lock nginx ports file: ' . $rPath);
			}
			$rCurrent = stream_get_contents($rHandle);
			if ($rCurrent === false) {
				throw new \RuntimeException('Cannot read nginx ports file: ' . $rPath);
			}
			$rWanted = $rTransform($rCurrent);
			if ($rWanted === $rCurrent) {
				return false;
			}
			if (!rewind($rHandle) || !ftruncate($rHandle, 0)
				|| fwrite($rHandle, $rWanted) !== strlen($rWanted) || !fflush($rHandle)) {
				throw new \RuntimeException('Cannot write nginx ports file: ' . $rPath);
			}
			return true;
		} finally {
			fclose($rHandle); // Also releases the lock on exceptions.
		}
	}
}
