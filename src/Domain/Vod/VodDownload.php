<?php

namespace XcVm\Domain\Vod;

use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\NodeRpc;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Process\ProcessRunner;
use XcVm\Core\Util\AtomicFile;

/** Downloads run on the selected node; RPC only starts or polls the worker. */
final class VodDownload {
	public const TIMEOUT = 21600;
	public const METADATA_TTL = 86400;

	public static function directory(string $rDirectory): ?string {
		$rReal = realpath($rDirectory);
		return $rReal !== false && is_dir($rReal) && is_writable($rReal) && ClusterSettings::pathAllowed($rReal, SettingsManager::get('lb_scan_roots')) ? $rReal : null;
	}

	public static function filename(string $rURL, string $rTitle): string {
		$rExtension = strtolower(pathinfo((string) parse_url($rURL, PHP_URL_PATH), PATHINFO_EXTENSION));
		if ($rExtension !== '' && !in_array($rExtension, VodM3uImport::MEDIA_EXTENSIONS, true)) {
			throw new \InvalidArgumentException('vod_download_unsupported');
		}
		$rName = trim((string) preg_replace('/[^\p{L}\p{N} ._-]+/u', '_', $rTitle), " .\t\r\n");
		return mb_strcut($rName ?: 'vod', 0, 140, 'UTF-8') . '-' . substr(hash('sha256', $rURL), 0, 16) . '.' . ($rExtension ?: 'mp4');
	}

	/** One permanent guard protects lock creation/removal; no per-job inode race. */
	public static function guard() {
		if (!is_dir(WATCH_TMP_PATH) && !mkdir(WATCH_TMP_PATH, 0750, true) && !is_dir(WATCH_TMP_PATH)) {
			throw new \RuntimeException('Cannot create download queue');
		}
		$rLock = fopen(WATCH_TMP_PATH . 'vod_download.guard', 'c');
		if ($rLock === false || !flock($rLock, LOCK_EX)) {
			throw new \RuntimeException('Cannot lock download queue');
		}
		return $rLock;
	}

	public static function request(array $rRequest, ?callable $rStart = null): array {
		self::cleanup();
		$rFunction = $rRequest['function'] ?? '';
		$rLock = self::guard();
		try {
			if ($rFunction === 'status') {
				$rID = (string) ($rRequest['id'] ?? '');
				return preg_match('/^[a-f0-9]{64}$/D', $rID) ? self::status($rID) : ['result' => false];
			}
			$rDirectory = self::directory((string) ($rRequest['directory'] ?? ''));
			if ($rDirectory === null || disk_free_space($rDirectory) < VodDownloadQuota::RESERVE) {
				return ['result' => false, 'error' => 'vod_download_invalid_path'];
			}
			if ($rFunction === 'validate') {
				return ['result' => true, 'directory' => $rDirectory];
			}
			$rURL = (string) ($rRequest['url'] ?? '');
			if ($rFunction !== 'start' || !VodM3uImport::httpUrl($rURL)) {
				return ['result' => false, 'error' => 'vod_m3u_invalid'];
			}
			try {
				$rPath = $rDirectory . '/' . self::filename($rURL, (string) ($rRequest['title'] ?? ''));
			} catch (\InvalidArgumentException $rError) {
				return ['result' => false, 'error' => $rError->getMessage()];
			}
			$rID = hash('sha256', $rPath . "\0" . $rURL);
			if (self::recover($rID, $rPath)) {
				return ['result' => true, 'id' => $rID];
			}
			$rStatus = self::status($rID);
			if (in_array($rStatus['state'] ?? '', ['queued', 'downloading'], true)) {
				return ['result' => true, 'id' => $rID];
			}
			if (file_exists($rPath) || is_link($rPath)) {
				return ['result' => false, 'error' => 'vod_download_exists'];
			}
			$rQuota = is_array($rRequest['quota'] ?? null) ? $rRequest['quota'] : VodDownloadQuota::fromForm([]);
			$rJob = self::file($rID, 'job');
			if (!AtomicFile::write($rJob, (string) json_encode(['url' => $rURL, 'path' => $rPath, 'quota' => $rQuota]), 0600)) {
				return ['result' => false];
			}
			self::write($rID, ['result' => true, 'state' => 'queued', 'bytes' => 0, 'path' => $rPath, 'batch_id' => $rQuota['batch_id'] ?? '']);
			$rStarted = $rStart !== null ? $rStart($rID) !== false : ProcessRunner::start([PHP_BIN, MAIN_HOME . 'console.php', 'vod_download', $rID]);
			if (!$rStarted) {
				self::write($rID, ['result' => false, 'state' => 'failed', 'error' => 'vod_download_failed']);
				@unlink($rJob);
				return ['result' => false, 'error' => 'vod_download_failed'];
			}
			return ['result' => true, 'id' => $rID];
		} finally {
			flock($rLock, LOCK_UN);
			fclose($rLock);
		}
	}

	/** Transient RPC errors never turn a still-running worker into a failed import. */
	public static function receive(array $rItem, ?callable $rWait = null, ?callable $rNow = null): ?string {
		$rNow ??= static fn(): int => time();
		$rWait ??= static function (): void {
			sleep(2);
		};
		$rTarget = $rItem['download'];
		$rServer = (int) $rTarget['server_id'];
		$rDeadline = $rNow() + self::TIMEOUT + 30;
		$rID = null;
		do {
			try {
				if ($rID === null) {
					$rStart = json_decode((string) NodeRpc::request($rServer, ['action' => 'vod_download', 'function' => 'start', 'url' => $rItem['file'], 'title' => $rItem['title'], 'directory' => $rTarget['directory'], 'quota' => $rTarget]), true);
					if (in_array($rStart['error'] ?? '', ['vod_m3u_invalid', 'vod_download_invalid_path', 'vod_download_exists', 'vod_download_failed'], true)) {
						return null;
					}
					$rID = !empty($rStart['result']) ? ($rStart['id'] ?? null) : null;
				}
				$rStatus = $rID !== null ? json_decode((string) NodeRpc::request($rServer, ['action' => 'vod_download', 'function' => 'status', 'id' => $rID]), true) : null;
				if (($rStatus['state'] ?? '') === 'complete' && !empty($rStatus['path'])) {
					return 's:' . $rServer . ':' . $rStatus['path'];
				}
				if (($rStatus['state'] ?? '') === 'failed') {
					return null;
				}
			} catch (\Throwable) {
/* Retry transport failures until the overall deadline. */
			}
			$rWait();
		} while ($rNow() < $rDeadline);
		return null;
	}

	/** All known playlist MIME aliases, plus manifest signatures when mislabeled. */
	public static function mediaBody(string $rType, string $rFile): bool {
		$rType = strtolower(trim(explode(';', $rType)[0]));
		if (in_array($rType, ['text/html', 'application/json', 'application/vnd.apple.mpegurl', 'application/mpegurl', 'application/x-mpegurl', 'audio/mpegurl', 'audio/x-mpegurl', 'audio/vnd.apple.mpegurl'], true)) {
			return false;
		}
		$rHead = ltrim((string) file_get_contents($rFile, false, null, 0, 512), "\xef\xbb\xbf \t\r\n");
		return !str_starts_with($rHead, '#EXTM3U') && !preg_match('/^<(?:!doctype\s+html|html)\b/i', $rHead);
	}

	public static function run(string $rID): bool {
		if (!preg_match('/^[a-f0-9]{64}$/D', $rID)) {
			return false;
		}
		$rGuard = self::guard();
		$rLock = fopen(self::file($rID, 'worker'), 'c');
		$rAcquired = $rLock !== false && flock($rLock, LOCK_EX | LOCK_NB);
		flock($rGuard, LOCK_UN);
		fclose($rGuard);
		if (!$rAcquired) {
			if (is_resource($rLock)) {
				fclose($rLock);
			} return false;
		}
		$rJobFile = self::file($rID, 'job');
		$rJob = json_decode((string) @file_get_contents($rJobFile), true);
		$rPath = (string) ($rJob['path'] ?? '');
		$rURL = (string) ($rJob['url'] ?? '');
		$rTemp = '';
		$rHandle = null;
		try {
			if (!VodM3uImport::httpUrl($rURL) || self::directory(dirname($rPath)) === null || hash('sha256', $rPath . "\0" . $rURL) !== $rID) {
				throw new \RuntimeException('Invalid download job');
			}
			if (self::recover($rID, $rPath)) {
				return true;
			}
			if (file_exists($rPath) || is_link($rPath)) {
				throw new \RuntimeException('Destination exists');
			}
			$rTemp = tempnam(dirname($rPath), '.vod-download-');
			if ($rTemp === false || ($rHandle = fopen($rTemp, 'wb')) === false) {
				throw new \RuntimeException('Cannot create download');
			}
			$rLast = 0;
			$rBytes = 0;
			$rReserved = 0;
			self::write($rID, ['result' => true, 'state' => 'downloading', 'bytes' => 0, 'path' => $rPath, 'temp' => $rTemp, 'batch_id' => $rJob['quota']['batch_id'] ?? '']);
			$rReply = VodHttp::get($rURL, [CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => self::TIMEOUT, CURLOPT_LOW_SPEED_LIMIT => 1024, CURLOPT_LOW_SPEED_TIME => 120, CURLOPT_FAILONERROR => true], static function (string $rChunk) use ($rJob, $rHandle, &$rBytes, &$rReserved, &$rLast, $rID, $rPath, $rTemp): int {
				$rWritten = VodDownloadQuota::write($rJob['quota'], dirname($rPath), $rHandle, $rChunk, $rBytes, $rReserved);
				if (time() > $rLast) {
					self::write($rID, ['result' => true, 'state' => 'downloading', 'bytes' => $rBytes, 'path' => $rPath, 'temp' => $rTemp, 'batch_id' => $rJob['quota']['batch_id'] ?? '']);
					$rLast = time();
				}
				return $rWritten;
			});
			fflush($rHandle);
			fdatasync($rHandle);
			fclose($rHandle);
			$rHandle = null;
			if (!$rReply['ok'] || $rBytes === 0 || !self::mediaBody($rReply['content_type'], $rTemp) || disk_free_space(dirname($rPath)) < VodDownloadQuota::RESERVE) {
				throw new \RuntimeException('Download failed');
			}
			$rStat = stat($rTemp);
			// A durable receipt precedes publication. The final hard link has the same inode.
			$rReceipt = ['id' => $rID, 'path' => $rPath, 'dev' => $rStat['dev'], 'ino' => $rStat['ino'], 'bytes' => $rBytes, 'temp' => $rTemp];
			if (!AtomicFile::write(self::receipt($rPath), (string) json_encode($rReceipt), 0600, true) || !link($rTemp, $rPath)) {
				throw new \RuntimeException('Publication failed');
			}
			if (!self::recover($rID, $rPath)) {
				throw new \RuntimeException('Receipt failed');
			}
			return true;
		} catch (\Throwable) {
			self::write($rID, ['result' => false, 'state' => 'failed', 'error' => 'vod_download_failed']);
			return false;
		} finally {
			if (is_resource($rHandle)) {
				fclose($rHandle);
			}
			if (is_string($rTemp) && $rTemp !== '') {
				@unlink($rTemp);
			}
			@unlink($rJobFile);
			flock($rLock, LOCK_UN);
			fclose($rLock);
		}
	}

	private static function receipt(string $rPath): string {
		return dirname($rPath) . '/.' . basename($rPath) . '.vod-receipt.json';
	}

	private static function recover(string $rID, string $rPath): bool {
		if (!is_file($rPath) || is_link($rPath)) {
			return false;
		}
		$rReceipt = json_decode((string) @file_get_contents(self::receipt($rPath)), true);
		clearstatcache(true, $rPath);
		$rStat = stat($rPath);
		if (($rReceipt['id'] ?? '') !== $rID || ($rReceipt['path'] ?? '') !== $rPath || ($rReceipt['dev'] ?? null) !== $rStat['dev'] || ($rReceipt['ino'] ?? null) !== $rStat['ino'] || ($rReceipt['bytes'] ?? 0) <= 0 || $rReceipt['bytes'] !== $rStat['size']) {
			return false;
		}
		$rTemp = $rReceipt['temp'] ?? '';
		if (is_string($rTemp) && str_starts_with(basename($rTemp), '.vod-download-') && dirname($rTemp) === dirname($rPath) && is_file($rTemp) && !is_link($rTemp)) {
			$rTempStat = stat($rTemp);
			if ($rTempStat['dev'] === $rStat['dev'] && $rTempStat['ino'] === $rStat['ino']) {
				@unlink($rTemp);
			}
		}
		self::write($rID, ['result' => true, 'state' => 'complete', 'bytes' => $rStat['size'], 'path' => $rPath]);
		return true;
	}

	private static function file(string $rID, string $rSuffix): string {
		return WATCH_TMP_PATH . 'vod_download_' . $rID . '.' . $rSuffix;
	}

	private static function status(string $rID): array {
		$rStatus = json_decode((string) @file_get_contents(self::file($rID, 'json')), true);
		if (!is_array($rStatus)) {
			return ['result' => false];
		}
		if (!empty($rStatus['path']) && self::directory(dirname($rStatus['path'])) !== null && self::recover($rID, $rStatus['path'])) {
			return json_decode((string) file_get_contents(self::file($rID, 'json')), true);
		}
		if (in_array($rStatus['state'] ?? '', ['queued', 'downloading'], true) && time() - ($rStatus['updated'] ?? 0) > 60) {
			$rWorker = fopen(self::file($rID, 'worker'), 'c');
			if ($rWorker !== false && flock($rWorker, LOCK_EX | LOCK_NB)) {
				$rTemp = $rStatus['temp'] ?? '';
				if (is_string($rTemp) && str_starts_with(basename($rTemp), '.vod-download-') && self::directory(dirname($rTemp)) !== null) {
					@unlink($rTemp);
				}
				$rStatus = ['result' => false, 'state' => 'failed', 'error' => 'vod_download_failed'];
				self::write($rID, $rStatus);
				@unlink(self::file($rID, 'job'));
				flock($rWorker, LOCK_UN);
			}
			if (is_resource($rWorker)) {
				fclose($rWorker);
			}
		}
		return $rStatus;
	}

	private static function write(string $rID, array $rStatus): void {
		if (!AtomicFile::write(self::file($rID, 'json'), (string) json_encode($rStatus + ['updated' => time()]), 0600)) {
			throw new \RuntimeException('Cannot write download status');
		}
	}

	/** Terminal/stale metadata expires; active workers and their budgets survive. */
	public static function cleanup(bool $rForce = false): void {
		$rGuard = self::guard();
		try {
			$rMarker = WATCH_TMP_PATH . 'vod_download.gc';
			if (!$rForce && (int) @filemtime($rMarker) > time() - 300) {
				return;
			}
			touch($rMarker);
			$rActive = [];
			foreach (new \FilesystemIterator(WATCH_TMP_PATH, \FilesystemIterator::SKIP_DOTS) as $rEntry) {
				if (!preg_match('/^vod_download_([a-f0-9]{64})\.(json|job|worker|lock)$/D', $rEntry->getFilename(), $rMatch) || !is_file($rEntry->getPathname())) {
					continue;
				}
				$rID = $rMatch[1];
				// Visit an orphan through its first remaining suffix; no unbounded in-memory job list.
				$rFiles = [];
				foreach (['json', 'job', 'worker', 'lock'] as $rSuffix) {
					if (is_file(self::file($rID, $rSuffix))) {
						$rFiles[$rSuffix] = self::file($rID, $rSuffix);
					}
				}
				if ($rFiles === [] || array_key_first($rFiles) !== $rMatch[2]) {
					continue;
				}
				$rLast = max(array_map(static fn(string $rFile): int => (int) @filemtime($rFile), $rFiles));
				$rStatus = json_decode((string) @file_get_contents(self::file($rID, 'json')), true);
				$rJob = json_decode((string) @file_get_contents(self::file($rID, 'job')), true);
				$rWorker = fopen(self::file($rID, 'worker'), 'c');
				if ($rWorker === false) {
					continue;
				}
				if (!flock($rWorker, LOCK_EX | LOCK_NB)) {
					$rActive[$rStatus['batch_id'] ?? $rJob['quota']['batch_id'] ?? ''] = true;
					fclose($rWorker);
					continue;
				}
				if ($rLast < time() - self::METADATA_TTL) {
					$rTemp = $rStatus['temp'] ?? '';
					if (is_string($rTemp) && str_starts_with(basename($rTemp), '.vod-download-') && self::directory(dirname($rTemp)) !== null) {
						@unlink($rTemp);
					}
					foreach (['job', 'json', 'worker', 'lock'] as $rSuffix) {
						@unlink(self::file($rID, $rSuffix));
					}
				}
				flock($rWorker, LOCK_UN);
				fclose($rWorker);
			}
			foreach (new \FilesystemIterator(WATCH_TMP_PATH, \FilesystemIterator::SKIP_DOTS) as $rEntry) {
				if (preg_match('/^vod_budget_([a-f0-9]{32})\.json$/D', $rEntry->getFilename(), $rMatch) && !isset($rActive[$rMatch[1]]) && $rEntry->getMTime() < time() - self::METADATA_TTL) {
					@unlink($rEntry->getPathname());
				}
			}
		} finally {
			flock($rGuard, LOCK_UN);
			fclose($rGuard);
		}
	}
}
