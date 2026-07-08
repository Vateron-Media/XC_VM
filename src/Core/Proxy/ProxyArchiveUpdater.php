<?php

namespace XcVm\Core\Proxy;

use XcVm\Core\Http\CurlClient;
use XcVm\Core\Updates\GitHubReleases;

/**
 * ProxyArchiveUpdater — single source of truth for the local proxy-node archive.
 *
 * proxy.tar.gz is no longer shipped in the panel tree (it lived in Git LFS). It is
 * fetched from XC_VM_Proxy GitHub releases at panel-install time and kept fresh by
 * cron:proxy, mirroring the GeoLite/MaxMind pattern. The archive and a small index
 * file live side by side under bin/install/:
 *
 *   bin/install/proxy.tar.gz        — the asset shipped to proxy nodes over SSH
 *   bin/install/proxy_version.json  — {"version": "X.Y.Z", "md5": "..."} watermark
 *
 * ensure() is idempotent: it downloads only when the local copy is missing or the
 * recorded version/hash no longer matches the latest release for the channel.
 * Downloads verify the release's hashes.md5 and publish atomically. When GitHub is
 * unreachable (or a release is broken) a valid local copy is kept as last-known-good.
 *
 * @package XC_VM_Core_Proxy
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ProxyArchiveUpdater {

	/** Asset file name published on every XC_VM_Proxy release. */
	private const ASSET = 'proxy.tar.gz';

	/** @var GitHubReleases Release-metadata client for GIT_REPO_PROXY (injected for tests). */
	private GitHubReleases $repo;

	/** @var string Directory holding the archive + index (trailing slash). */
	private string $installDir;

	/** @var string Absolute path to the cached archive. */
	private string $archivePath;

	/** @var string Absolute path to the version/hash index file. */
	private string $indexPath;

	/**
	 * @param GitHubReleases $repo       Client bound to GIT_OWNER / GIT_REPO_PROXY.
	 * @param string|null    $installDir Override the target dir (defaults to bin/install/); for tests.
	 */
	public function __construct(GitHubReleases $repo, ?string $installDir = null) {
		$this->repo        = $repo;
		$this->installDir  = rtrim($installDir ?? (BIN_PATH . 'install/'), '/') . '/';
		$this->archivePath = $this->installDir . self::ASSET;
		$this->indexPath   = $this->installDir . 'proxy_version.json';
	}

	public function archivePath(): string {
		return $this->archivePath;
	}

	/**
	 * Ensure a valid, fresh proxy.tar.gz (+ index) exists locally.
	 *
	 * @param bool $force      Re-check against GitHub even if the cache looks current.
	 * @param bool $forceLocal Kill-switch: never touch the network, trust the local file.
	 * @return array{version: ?string, action: string, error: ?string}
	 *         action ∈ {skip, download, local, stale-fallback, error}.
	 */
	public function ensure(bool $force = false, bool $forceLocal = false): array {
		$rIndex = $this->readIndex();

		// Kill-switch / air-gapped: trust the local file, do not touch the network.
		if ($forceLocal) {
			if ($this->localValid($rIndex)) {
				return $this->result($rIndex['version'] ?? null, 'local', null);
			}
			return $this->result(null, 'error', 'force-local is set but no valid local ' . self::ASSET);
		}

		// Resolve the latest eligible release for the channel.
		$rVersion = null;
		try {
			$rReleases = $this->repo->getReleases();
			if (!empty($rReleases) && GitHubReleases::isValidVersion((string) $rReleases[0])) {
				$rVersion = (string) $rReleases[0];
			}
		} catch (\Throwable $e) {
			$rVersion = null; // network / API failure — handled by the fallback below
		}

		// GitHub unreachable or no eligible release → fall back to the cached copy.
		if ($rVersion === null) {
			if ($this->localValid($rIndex)) {
				return $this->result($rIndex['version'] ?? null, 'stale-fallback', 'GitHub unavailable — using local copy, freshness not verified');
			}
			return $this->result(null, 'error', 'GitHub unavailable and no valid local ' . $this->archivePath);
		}

		// Cache hit: same version and the file still matches its recorded md5.
		if (!$force && ($rIndex['version'] ?? null) === $rVersion && $this->localValid($rIndex)) {
			return $this->result($rVersion, 'skip', null);
		}

		// Resolve the expected md5 from the release's hashes.md5.
		$rExpectedMd5 = $this->repo->getAssetHash($rVersion, self::ASSET);
		if ($rExpectedMd5 === null || $rExpectedMd5 === '') {
			// Hash missing (CI bug / partial upload) — never accept an unverifiable
			// download; keep last-known-good if we have one.
			if ($this->localValid($rIndex)) {
				return $this->result($rIndex['version'] ?? null, 'stale-fallback', 'hashes.md5 has no entry for ' . self::ASSET . " in {$rVersion} — kept local copy");
			}
			return $this->result(null, 'error', 'hashes.md5 has no entry for ' . self::ASSET . " in {$rVersion}");
		}

		// Index lagged but the on-disk bytes already match this release → adopt.
		if (is_file($this->archivePath) && hash_equals($rExpectedMd5, (string) md5_file($this->archivePath))) {
			$this->writeIndex($rVersion, $rExpectedMd5);
			return $this->result($rVersion, 'skip', null);
		}

		$rURL = 'https://github.com/' . GIT_OWNER . '/' . GIT_REPO_PROXY . '/releases/download/' . $rVersion . '/' . self::ASSET;

		$rError = null;
		for ($rAttempt = 1; $rAttempt <= 2; $rAttempt++) {
			// Temp file lives IN the destination dir so the later rename() is a
			// same-filesystem atomic move (not a cross-mount EXDEV copy), and the
			// unpredictable suffix avoids a symlink race in a shared temp dir.
			$rTmp = @tempnam($this->installDir, '.proxy_tmp_');
			if ($rTmp === false) {
				$rError = 'cannot create temp file in ' . $this->installDir;
				break;
			}
			try {
				CurlClient::downloadToFile($rURL, $rTmp);
				$rActualMd5 = (string) md5_file($rTmp);
				if (!hash_equals($rExpectedMd5, $rActualMd5)) {
					@unlink($rTmp);
					$rError = "checksum mismatch (expected {$rExpectedMd5}, got {$rActualMd5})";
					continue; // transient corruption / truncation — retry once
				}
				if (!@rename($rTmp, $this->archivePath)) {
					@unlink($rTmp);
					$rError = 'failed to move the archive into place';
					break;
				}
				@chmod($this->archivePath, 0644);
				$this->writeIndex($rVersion, $rExpectedMd5);
				return $this->result($rVersion, 'download', null);
			} catch (\Throwable $e) {
				@unlink($rTmp);
				$rError = $e->getMessage();
			}
		}

		// Download failed after retries — keep last-known-good if it is valid.
		if ($this->localValid($rIndex)) {
			return $this->result($rIndex['version'] ?? null, 'stale-fallback', 'download failed (' . $rError . ') — using local copy');
		}
		return $this->result(null, 'error', 'download failed: ' . $rError);
	}

	/** The archive exists and matches the md5 recorded in the index. */
	private function localValid(array $rIndex): bool {
		if (empty($rIndex['md5']) || !is_file($this->archivePath)) {
			return false;
		}
		return hash_equals((string) $rIndex['md5'], (string) md5_file($this->archivePath));
	}

	/** @return array{version?: string, md5?: string} */
	private function readIndex(): array {
		if (!is_file($this->indexPath)) {
			return array();
		}
		$rData = json_decode((string) file_get_contents($this->indexPath), true);
		return is_array($rData) ? $rData : array();
	}

	private function writeIndex(string $rVersion, string $rMd5): void {
		$rJson = json_encode(
			array('version' => $rVersion, 'md5' => $rMd5),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
		@file_put_contents($this->indexPath, $rJson . "\n");
		@chmod($this->indexPath, 0644);
	}

	/** @return array{version: ?string, action: string, error: ?string} */
	private function result(?string $rVersion, string $rAction, ?string $rError): array {
		return array('version' => $rVersion, 'action' => $rAction, 'error' => $rError);
	}
}
