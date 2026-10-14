<?php

namespace XcVm\Domain\Stream;

/** Serialize controls and invalidate pending monitor starts without deleting the lock inode. */
final class StreamControlGuard {
	/** @return resource|false */
	public static function acquire(int $rID, bool $rVod = false) {
		$rFile = ($rVod ? VOD_PATH : STREAMS_PATH) . '.control-' . $rID;
		$rLock = @fopen($rFile, 'c+');
		if ($rLock === false) {
			return false;
		}
		if (!flock($rLock, LOCK_EX)) {
			fclose($rLock);
			return false;
		}
		return $rLock;
	}

	/** @param resource $rLock */
	public static function generation($rLock): string {
		rewind($rLock);
		return trim((string) stream_get_contents($rLock));
	}

	/** @param resource $rLock */
	public static function advance($rLock): string {
		$rToken = bin2hex(random_bytes(16));
		rewind($rLock);
		if (!ftruncate($rLock, 0) || fwrite($rLock, $rToken) !== strlen($rToken) || !fflush($rLock)) {
			throw new \RuntimeException('Stream control generation unavailable');
		}
		return $rToken;
	}

	/** @param resource $rLock */
	public static function release($rLock): void {
		flock($rLock, LOCK_UN);
		fclose($rLock);
	}

	/**
	 * Prepare the new encoder before stopping the old one. Preserve old media
	 * until a replacement was accepted, and restore it if launch fails.
	 * Caller holds the VOD control lock for the entire stop/start transaction.
	 */
	public static function replaceMovie(int $rID): bool {
		$rBackup = [];
		$rPrevious = StreamSource::serverRow($rID);
		$rAccepted = false;
		$rStopped = false;
		$rDirectory = VOD_PATH . '.replacement-' . $rID . '-' . bin2hex(random_bytes(8));
		try {
			$rPID = StreamProcess::startMovie($rID, static function () use ($rID, $rDirectory, &$rBackup, &$rStopped): void {
				if (!mkdir($rDirectory, 0700)) {
					throw new \RuntimeException('VOD replacement backup unavailable');
				}
				foreach (glob(VOD_PATH . $rID . '.*') ?: [] as $rFile) {
					$rSaved = $rDirectory . '/' . basename($rFile);
					if (!rename($rFile, $rSaved)) {
						throw new \RuntimeException('VOD output preservation failed');
					}
					$rBackup[$rFile] = $rSaved;
				}
				$rStopped = true;
				StreamProcess::stopMovie($rID, true);
			});
			$rAccepted = is_int($rPID) && $rPID > 0;
			return $rAccepted;
		} finally {
			if (!$rAccepted && $rStopped) {
				StreamProcess::stopMovie($rID, true);
			}
			foreach ($rBackup as $rFile => $rSaved) {
				if ($rAccepted) {
					unlink($rSaved);
				} else {
					if (file_exists($rFile) || is_link($rFile)) {
						unlink($rFile);
					}
					if (!rename($rSaved, $rFile)) {
						throw new \RuntimeException('VOD output restoration failed');
					}
				}
			}
			if (!$rAccepted && $rStopped && $rPrevious !== null) {
				$rRestore = array_intersect_key($rPrevious, array_flip(StreamStateWriter::STATE_FIELDS));
				// The previous encoder was stopped; never restore its obsolete PID.
				$rRestore['pid'] = null;
				$rRestore['to_analyze'] = 0;
				StreamStateWriter::update($rID, (int) SERVER_ID, $rRestore);
				StreamProcess::updateStream($rID);
			}
			if (is_dir($rDirectory)) {
				rmdir($rDirectory);
			}
		}
	}
}
