<?php

namespace XcVm\Domain\Vod;

use XcVm\Core\Util\AtomicFile;

/** Shared node-side budget for all workers of one import. */
final class VodDownloadQuota {
	public const GIB = 1073741824;
	public const RESERVE = self::GIB;

	public static function fromForm(array $rData): array {
		$rFile = (float) ($rData['vod_download_file_gib'] ?? 20);
		$rImport = (float) ($rData['vod_download_import_gib'] ?? 100);
		if (!is_finite($rFile) || !is_finite($rImport) || $rFile <= 0 || $rFile > 100 || $rImport < $rFile || $rImport > 1000) {
			throw new \InvalidArgumentException('vod_download_invalid_limits');
		}
		return ['batch_id' => bin2hex(random_bytes(16)), 'max_file_bytes' => (int) ($rFile * self::GIB), 'max_import_bytes' => (int) ($rImport * self::GIB)];
	}

	/** Serialized disk check/write keeps the reserve intact even across batches. */
	public static function write(array $rQuota, string $rDirectory, $rHandle, string $rChunk, int &$rBytes, int &$rReserved): int {
		$rLength = strlen($rChunk);
		$rMaxFile = min(100 * self::GIB, max(1, (int) ($rQuota['max_file_bytes'] ?? 20 * self::GIB)));
		$rMaxImport = min(1000 * self::GIB, max(1, (int) ($rQuota['max_import_bytes'] ?? 100 * self::GIB)));
		$rBatch = (string) ($rQuota['batch_id'] ?? '');
		if (!preg_match('/^[a-f0-9]{32}$/D', $rBatch) || $rBytes + $rLength > $rMaxFile) {
			return 0;
		}
		$rLock = VodDownload::guard();
		try {
			$rFree = disk_free_space($rDirectory);
			if ($rFree === false || $rFree - $rLength < self::RESERVE) {
				return 0;
			}
			if ($rReserved < $rBytes + $rLength) {
				$rFile = WATCH_TMP_PATH . 'vod_budget_' . $rBatch . '.json';
				$rUsed = max(0, (int) (json_decode((string) @file_get_contents($rFile), true)['reserved'] ?? 0));
				$rTake = (int) min(1048576, $rMaxFile - $rReserved, $rMaxImport - $rUsed);
				if ($rTake < $rBytes + $rLength - $rReserved || !AtomicFile::write($rFile, (string) json_encode(['reserved' => $rUsed + $rTake]), 0600)) {
					return 0;
				}
				$rReserved += $rTake;
			}
			$rWritten = fwrite($rHandle, $rChunk);
			if ($rWritten === false) {
				return 0;
			}
			$rBytes += $rWritten;
			return $rWritten;
		} finally {
			flock($rLock, LOCK_UN);
			fclose($rLock);
		}
	}
}
