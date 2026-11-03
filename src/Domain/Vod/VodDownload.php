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
		$rName = mb_strcut($rName ?: 'vod', 0, 140, 'UTF-8');
		return $rName . '-' . substr(hash('sha256', $rURL), 0, 16) . '.' . ($rExtension ?: 'mp4');
	}

	/** System API handler. No URL or credentials are included in status responses. */
	public static function request(array $rRequest, ?callable $rStart = null): array {
		$rFunction = $rRequest['function'] ?? '';
		if ($rFunction === 'status') {
			$rID = (string) ($rRequest['id'] ?? '');
			return preg_match('/^[a-f0-9]{64}$/D', $rID) ? self::status($rID) : ['result' => false];
		}
		$rDirectory = self::directory((string) ($rRequest['directory'] ?? ''));
		if ($rDirectory === null) {
			return ['result' => false, 'error' => 'vod_download_invalid_path'];
		}
		if ($rFunction === 'validate') {
			return ['result' => true];
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
		if (!is_dir(WATCH_TMP_PATH) && !mkdir(WATCH_TMP_PATH, 0750, true) && !is_dir(WATCH_TMP_PATH)) {
			return ['result' => false];
		}
		$rLock = fopen(self::file($rID, 'lock'), 'c');
		if ($rLock === false || !flock($rLock, LOCK_EX)) {
			return ['result' => false];
		}
		try {
			$rStatus = self::status($rID);
			if ((($rStatus['state'] ?? '') === 'complete' && is_file($rPath) && !is_link($rPath) && filesize($rPath) > 0) || (in_array($rStatus['state'] ?? '', ['queued', 'downloading'], true) && time() - ($rStatus['updated'] ?? 0) < self::TIMEOUT + 60)) {
				return ['result' => true, 'id' => $rID];
			}
			if (file_exists($rPath) || is_link($rPath)) {
				return ['result' => false, 'error' => 'vod_download_exists'];
			}
			$rJob = self::file($rID, 'job');
			if (!AtomicFile::write($rJob, (string) json_encode(['url' => $rURL, 'path' => $rPath]), 0600)) {
				return ['result' => false];
			}
			self::write($rID, ['result' => true, 'state' => 'queued', 'bytes' => 0]);
			$rStarted = $rStart !== null
				? $rStart($rID) !== false
				: ProcessRunner::start([PHP_BIN, MAIN_HOME . 'console.php', 'vod_download', $rID]);
			if (!$rStarted) {
				self::write($rID, ['result' => false, 'state' => 'failed', 'error' => 'vod_download_failed']);
				@unlink($rJob);
				return ['result' => false];
			}
			return ['result' => true, 'id' => $rID];
		} finally {
			flock($rLock, LOCK_UN);
			fclose($rLock);
		}
	}

	/** Wait in the bounded import pool, never in a web request or RPC handler. */
	public static function receive(array $rItem): ?string {
		$rTarget = $rItem['download'];
		$rServer = (int) $rTarget['server_id'];
		$rStart = json_decode((string) NodeRpc::request($rServer, ['action' => 'vod_download', 'function' => 'start', 'url' => $rItem['file'], 'title' => $rItem['title'], 'directory' => $rTarget['directory']]), true);
		if (empty($rStart['result']) || empty($rStart['id'])) {
			return null;
		}
		$rDeadline = time() + self::TIMEOUT + 30;
		$rFailures = 0;
		do {
			$rStatus = json_decode((string) NodeRpc::request($rServer, ['action' => 'vod_download', 'function' => 'status', 'id' => $rStart['id']]), true);
			$rFailures = isset($rStatus['state']) ? 0 : $rFailures + 1;
			if ($rFailures >= 3) {
				return null;
			}
			if (($rStatus['state'] ?? '') === 'complete' && !empty($rStatus['path'])) {
				return 's:' . $rServer . ':' . $rStatus['path'];
			}
			if (($rStatus['state'] ?? '') === 'failed') {
				return null;
			}
			sleep(2);
		} while (time() < $rDeadline);
		return null;
	}

	public static function run(string $rID): bool {
		if (!preg_match('/^[a-f0-9]{64}$/D', $rID)) {
			return false;
		}
		$rLock = fopen(self::file($rID, 'worker'), 'c');
		if ($rLock === false || !flock($rLock, LOCK_EX | LOCK_NB)) {
			return false;
		}
		$rJobFile = self::file($rID, 'job');
		$rJob = json_decode((string) @file_get_contents($rJobFile), true);
		$rPath = (string) ($rJob['path'] ?? '');
		$rURL = (string) ($rJob['url'] ?? '');
		$rTemp = '';
		try {
			if (!VodM3uImport::httpUrl($rURL) || self::directory(dirname($rPath)) === null || hash('sha256', $rPath . "\0" . $rURL) !== $rID || file_exists($rPath) || is_link($rPath)) {
				throw new \RuntimeException('Invalid download job');
			}
			$rTemp = tempnam(dirname($rPath), '.vod-download-');
			if ($rTemp === false) {
				throw new \RuntimeException('Cannot create download');
			}
			$rHandle = fopen($rTemp, 'wb');
			if ($rHandle === false) {
				throw new \RuntimeException('Cannot open download');
			}
			$rLast = 0;
			$rCurl = curl_init($rURL);
			curl_setopt_array($rCurl, [CURLOPT_FILE => $rHandle, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => self::TIMEOUT, CURLOPT_LOW_SPEED_LIMIT => 1024, CURLOPT_LOW_SPEED_TIME => 120, CURLOPT_FAILONERROR => true, CURLOPT_NOPROGRESS => false, CURLOPT_XFERINFOFUNCTION => static function ($rCurl, $rTotal, $rBytes) use ($rID, &$rLast): int {
				if (time() > $rLast) {
					self::write($rID, ['result' => true, 'state' => 'downloading', 'bytes' => (int) $rBytes, 'total' => (int) $rTotal]);
					$rLast = time();
				}
				return 0;
			}
			]);
			$rOK = curl_exec($rCurl);
			$rCode = curl_getinfo($rCurl, CURLINFO_HTTP_CODE);
			$rContentType = (string) curl_getinfo($rCurl, CURLINFO_CONTENT_TYPE);
			curl_close($rCurl);
			fclose($rHandle);
			if (!$rOK || preg_match('#^(text/html|application/(json|vnd.apple.mpegurl|x-mpegurl))#i', $rContentType) || $rCode < 200 || $rCode >= 300 || filesize($rTemp) === 0 || !link($rTemp, $rPath)) {
				throw new \RuntimeException('Download failed');
			}
			self::write($rID, ['result' => true, 'state' => 'complete', 'bytes' => filesize($rPath), 'path' => $rPath]);
			return true;
		} catch (\Throwable $rError) {
			self::write($rID, ['result' => false, 'state' => 'failed', 'error' => 'vod_download_failed']);
			return false;
		} finally {
			if ($rTemp !== '' && is_string($rTemp)) {
				@unlink($rTemp);
			}
			@unlink($rJobFile);
			flock($rLock, LOCK_UN);
			fclose($rLock);
		}
	}

	private static function file(string $rID, string $rSuffix): string {
		return WATCH_TMP_PATH . 'vod_download_' . $rID . '.' . $rSuffix;
	}

	private static function status(string $rID): array {
		$rStatus = json_decode((string) @file_get_contents(self::file($rID, 'json')), true);
		return is_array($rStatus) ? $rStatus : ['result' => false];
	}

	private static function write(string $rID, array $rStatus): void {
		AtomicFile::write(self::file($rID, 'json'), (string) json_encode($rStatus + ['updated' => time()]));
	}
}
