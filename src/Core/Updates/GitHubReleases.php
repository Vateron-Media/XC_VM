<?php

namespace XcVm\Core\Updates;

/**
 * GitHubReleases PHP class - wrapper for GitHub Releases API
 *
 * @package VateronMedia_GitHubReleases
 * @author Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 *
 * A PHP class created specifically for the XC_VM project to interact with the GitHub Releases API.
 * Provides methods to fetch release versions, changelogs, asset hashes, and GeoLite database information
 * with caching support.
 */

class GitHubReleases {
	private string $owner;

	private string $repo;

	/** @var list<string> */
	private array $headers;

	private $timeout = 30; // Total transfer timeout in seconds (the /releases list ships changelogs and is tens of KB; 5s was too tight during installs when the link is saturated by apt/wget)

	private $connect_timeout = 10; // Connection-phase timeout in seconds — fail fast on an unreachable host without capping slow transfers

	private string $cache_prefix = '/home/xc_vm/tmp/gitapi';

	private $cache_file; // Cache file path

	private string $channel; // 'stable', 'beta' or 'dev' ('unstable' accepted as a legacy alias for 'beta')

	/**
	 * Releases-only repository holding the nightly builds (XC_VM_Dev). Consulted
	 * only on the 'dev' channel, where its releases are merged with the primary
	 * repository's. Null for repositories that have no nightly builds.
	 */
	private ?string $devRepo;

	private $hash_file = 'hashes.md5';

	private $cache_ttl = 1800; // Cache TTL in seconds (30 minutes)

	/**
	 * Initialize a GitHubReleases instance.
	 *
	 * @param string $owner Repository owner
	 * @param string $repo Repository name
	 * @param string $channel Update channel: 'stable', 'beta' or 'dev' ('unstable' is a legacy alias for 'beta')
	 * @param string|null $token GitHub API token
	 * @param string|null $devRepo Nightly-builds repository merged in on the 'dev' channel
	 */
	public function __construct(string $owner, string $repo, ?string $channel = 'stable', ?string $token = null, ?string $devRepo = null) {
		$this->owner = $owner;
		$this->repo = $repo;
		$this->devRepo = $devRepo;
		$this->channel = $this->normalizeChannel($channel);
		$this->cache_file = "{$this->cache_prefix}_{$repo}_{$this->channel}"; // Уникальный кэш для канала
		$this->headers = $token ? [
			"Authorization: Bearer {$token}",
			'Accept: application/vnd.github+json',
			'X-GitHub-Api-Version: 2022-11-28'
		] : [];
	}

	/**
	 * Normalize a channel name to the canonical set. 'unstable' is a legacy alias
	 * for 'beta'; anything unrecognized falls back to 'stable'.
	 *
	 * @param string|null $channel Raw channel value (e.g. from settings)
	 * @return string 'stable', 'beta' or 'dev'
	 */
	private function normalizeChannel(?string $channel): string {
		$channel = ($channel === 'unstable') ? 'beta' : (string) $channel;
		return in_array($channel, ['stable', 'beta', 'dev'], true) ? $channel : 'stable';
	}

	/**
	 * Releases API URL for a repository. per_page=100 (the API maximum) keeps
	 * stable releases in view when prereleases pile up; the default page of 30
	 * would push them off the list.
	 */
	private function releasesApiUrl(string $repo): string {
		return "https://api.github.com/repos/{$this->owner}/{$repo}/releases?per_page=100";
	}

	/**
	 * Whether a tag is a nightly build (X.Y.Z-dev.N). Nightly builds are
	 * published only to the dev repository, so the tag alone tells which
	 * repository serves its assets.
	 */
	public static function isDevVersion(string $version): bool {
		return (bool) preg_match('/^\d+\.\d+\.\d+-dev\.\d+$/', $version);
	}

	/**
	 * Whether a tag is a patch build (X.Y.Z-pN): release X.Y.Z with the fixes
	 * made since, built and published by the build-patch workflow with no
	 * release of its own. version_compare() puts it where it belongs:
	 * X.Y.Z < X.Y.Z-p1 < X.Y.Z-p2 < the next version's nightlies and release.
	 */
	public static function isPatchVersion(string $version): bool {
		return (bool) preg_match('/^\d+\.\d+\.\d+-p\d+\z/', $version);
	}

	/**
	 * Whether a tag is a release or a patch build of one: what an update, a
	 * pin and a rollback may name (a nightly is asked for apart).
	 */
	public static function isReleaseVersion(string $version): bool {
		return (bool) preg_match('/^\d+\.\d+\.\d+(-p\d+)?\z/', $version);
	}

	private function repoFor(string $version): string {
		return ($this->devRepo !== null && self::isDevVersion($version)) ? $this->devRepo : $this->repo;
	}

	private function devCacheFile(): string {
		return "{$this->cache_prefix}_{$this->devRepo}";
	}

	/**
	 * Clear the cached release data by deleting the cache file(s).
	 */
	public function clearCache(): void {
		$files = $this->devRepo !== null ? [$this->cache_file, $this->devCacheFile()] : [$this->cache_file];
		foreach ($files as $file) {
			if (file_exists($file)) {
				unlink($file);
				error_log("Cache cleared for {$this->owner} by deleting {$file}");
			}
		}
	}

	/**
	 * Check if a cache file is still valid based on TTL.
	 *
	 * @param string $cacheFile Cache file path.
	 * @return bool True if cache is valid, False otherwise.
	 */
	private function isCacheValid(string $cacheFile): bool {
		if (!file_exists($cacheFile)) {
			return false;
		}
		$cache_timestamp = filemtime($cacheFile);
		return (time() - $cache_timestamp) < $this->cache_ttl;
	}

	/**
	 * Load cache from file.
	 *
	 * @param string $cacheFile Cache file path.
	 * @return array|null The cached data, or null if the file doesn't exist or is invalid.
	 */
	private function loadCache(string $cacheFile): ?array {
		if (!file_exists($cacheFile)) {
			return null;
		}
		$content = file_get_contents($cacheFile);
		if ($content === false) {
			error_log("Failed to read cache file {$cacheFile}");
			return null;
		}
		$data = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			error_log("Failed to parse cache file {$cacheFile}: " . json_last_error_msg());
			return null;
		}
		return $data;
	}

	/**
	 * Save data to cache file with file locking to prevent race conditions.
	 *
	 * @param string $cacheFile Cache file path.
	 * @param array $data The data to cache.
	 * @return bool True on success, False on failure.
	 */
	private function saveCache(string $cacheFile, array $data): bool {
		$json = json_encode($data);
		if ($json === false) {
			error_log("Failed to encode cache data to JSON");
			return false;
		}

		$file = fopen($cacheFile, 'c');
		if ($file === false) {
			error_log("Failed to open cache file {$cacheFile} for writing");
			return false;
		}

		if (flock($file, LOCK_EX)) {
			ftruncate($file, 0);
			fwrite($file, $json);
			fflush($file);
			flock($file, LOCK_UN);
			fclose($file);
			error_log("Cache saved to {$cacheFile}");
			return true;
		}
		error_log("Failed to acquire lock on cache file {$cacheFile}");
		fclose($file);
		return false;
	}

	/**
	 * Fetch all release versions (tags) from the GitHub repository, using cache if valid.
	 *
	 * @return array List of version tags in descending order (latest first).
	 * @throws \Exception If the request fails.
	 */
	public function getReleases(): array {
		return array_values(array_map(fn($r) => $r['tag_name'], $this->getFilteredReleases()));
	}

	/**
	 * Fetch the channel-filtered release objects (with the tag_name AND the
	 * prerelease flag), newest first, using the cache when valid. Shared by
	 * getReleases() and the rollback picker, which needs the prerelease flag to
	 * mark beta builds.
	 *
	 * On the 'dev' channel the nightly builds of the dev repository are merged
	 * in. The result is ordered by version, not by the API's order, which is
	 * by publication: the two repositories' orders do not interleave, and a
	 * patch build of an older release (2.6.5-p3) is published after the
	 * release that follows it (2.6.6), which it must not hide. Date tags
	 * (the binaries' DDMMYYYY) have no version order: version_compare() put
	 * 29062026 above 21102026, so they keep the API's newest-first order.
	 *
	 * @return array<int,array<string,mixed>> Filtered GitHub release objects.
	 * @throws \Exception If the request fails.
	 */
	private function getFilteredReleases(): array {
		$releases = array_values($this->filterReleasesByChannel($this->fetchRawReleases($this->repo, $this->cache_file)));
		if ($this->channel === 'dev' && $this->devRepo !== null) {
			// A missing or unreachable dev repository must not cut the dev channel
			// off the regular releases.
			try {
				$releases = array_merge($releases, $this->fetchRawReleases($this->devRepo, $this->devCacheFile()));
			} catch (\Exception $e) {
				error_log("Failed to fetch dev releases from {$this->devRepo}: " . $e->getMessage());
			}
		}
		if (array_filter($releases, static fn($r) => !str_contains((string) ($r['tag_name'] ?? ''), '.')) === []) {
			usort($releases, static fn($a, $b) => version_compare((string) ($b['tag_name'] ?? ''), (string) ($a['tag_name'] ?? '')));
		}

		return $releases;
	}

	/**
	 * Fetch a repository's raw release list, using the cache when valid.
	 *
	 * @param string $repo      Repository name.
	 * @param string $cacheFile Cache file for this repository.
	 * @return array<int,array<string,mixed>> Unfiltered GitHub release objects, API order.
	 * @throws \Exception If the request fails.
	 */
	protected function fetchRawReleases(string $repo, string $cacheFile): array {
		if ($this->isCacheValid($cacheFile)) {
			error_log("Using cached releases (channel: {$this->channel}) from {$cacheFile}");
			$cache = $this->loadCache($cacheFile);
			if ($cache !== null) {
				return $cache;
			}
		}

		try {
			error_log("Fetching releases for {$this->owner}/{$repo} (channel: {$this->channel})");
			$data = json_decode($this->makeRequest($this->releasesApiUrl($repo)), true);
			if (!is_array($data)) {
				throw new \Exception("Failed to parse API response: " . json_last_error_msg());
			}

			$this->saveCache($cacheFile, $data); // Сохраняем полные данные, фильтруем при использовании
			error_log("Retrieved " . count($data) . " releases for {$repo}");
			return $data;
		} catch (\Exception $e) {
			error_log("Failed to fetch releases: " . $e->getMessage());
			throw $e;
		}
	}

	/**
	 * Get the latest available version that is newer than the current one.
	 *
	 * Unlike getNextVersion(), this skips intermediate versions and returns
	 * the newest release directly, allowing jump updates.
	 *
	 * @param string $current_version The current version tag (e.g., "1.0.0").
	 * @return string|null The latest version tag, or null if already up-to-date or version not found.
	 */
	public function getLatestVersion(string $current_version): ?string {
		$releases = $this->getReleases();

		if ($releases === []) {
			return null;
		}

		$latest = $releases[0];

		if (version_compare($latest, $current_version, '<=')) {
			return null;
		}

		// A patch build (X.Y.Z-pN) ships only the delta since its base release
		// X.Y.Z, so it is safe to overlay only on a panel already on X.Y.Z (or
		// a patch of it). A panel below the base must take the full base release
		// first — overlaying the patch delta on an older tree would stamp it
		// X.Y.Z-pN while everything X.Y.Z added (migrations included) is still
		// missing. So never hand a below-base panel a patch: give it the newest
		// full (non-patch) release it can take instead, which it reaches with a
		// full archive (safe from any version). It picks up the patch on the
		// next check, once it is on the base.
		if (self::isPatchVersion($latest)) {
			$base = (string) preg_replace('/-p\d+$/', '', $latest);
			if (version_compare($current_version, $base, '<')) {
				foreach ($releases as $release) {
					if (!self::isPatchVersion($release) && version_compare($release, $current_version, '>')) {
						return $release;
					}
				}
				return null;
			}
		}

		return $latest;
	}

	/**
	 * Retrieve the MD5 hash of a release asset from its corresponding hash file.
	 *
	 * @param string $version The release tag (e.g., "1.0.0").
	 * @param string $asset_name The asset file name to get the hash for (e.g., "update.tar.gz").
	 * @return string|null The MD5 hash string, or null if not found or invalid.
	 */
	public function getAssetHash(string $version, string $asset_name): ?string {
		$hashURL = $this->assetUrl($version, $this->hash_file);
		try {
			$hash_response = $this->makeRequest($hashURL);

			$hash_text = trim($hash_response);
			$lines = explode("\n", $hash_text);

			foreach ($lines as $line) {
				$line = trim($line);
				if (empty($line)) {
					continue;
				}
				$parts = preg_split('/\s+/', $line, 2);
				if (count($parts) === 2 && $parts[1] === $asset_name) {
					error_log("Retrieved MD5 hash for {$asset_name} in version {$version}");
					return $parts[0];
				}
			}
			error_log("Asset '{$asset_name}' not found in hash file for version {$version}");
			return null;
		} catch (\Exception $e) {
			error_log("Failed to fetch asset hash from {$hashURL}: " . $e->getMessage());
			return null;
		}
	}

	/**
	 * Retrieve the changelog for a specific release from its changelog.json.
	 *
	 * The file is fetched from the repository at the given tag via raw.githubusercontent.
	 * It contains a single object: {"version": "X.Y.Z", "changes": [...]}.
	 *
	 * @param string $version The release tag to fetch the changelog for.
	 * @return array A JSON-compatible array containing the changelog entry wrapped in an array.
	 */
	public function getChangelog(string $version): array {
		try {
			// The dev repository carries no source tree, so nightly builds ship
			// their changelog as a release asset instead. So does a patch build:
			// the file in the tree at its tag is still the release's.
			$url = (self::isDevVersion($version) && $this->devRepo !== null) || self::isPatchVersion($version)
				? $this->assetUrl($version, 'changelog.json')
				: "https://raw.githubusercontent.com/{$this->owner}/{$this->repo}/refs/tags/{$version}/changelog.json";
			$response = $this->makeRequest($url);
			$changelog = json_decode($response, true);
			if ($changelog === null) {
				error_log("Failed to parse changelog JSON for version {$version}");
				return [];
			}
			error_log("Successfully retrieved changelog for version {$version} with " . count($changelog['changes'] ?? []) . " changes");
			return [$changelog];
		} catch (\Exception $e) {
			error_log("Failed to fetch changelog for version {$version}: " . $e->getMessage());
			return [];
		}
	}

	/**
	 * Validate whether a version string follows the format X.Y.Z.
	 *
	 * @param string $version The version string to validate (e.g., "1.0.0").
	 * @return bool True if valid, False otherwise.
	 * @throws \InvalidArgumentException If the version string is too long or contains invalid parts.
	 */
	public static function isValidVersion(string $version): bool {
		if (strlen($version) > 20) {
			error_log("Version string too long");
			throw new \InvalidArgumentException("Version string is too long");
		}

		if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $version)) {
			error_log("Invalid version format: {$version}");
			return false;
		}

		$parts = explode('.', $version);
		if (count($parts) !== 3) {
			error_log("Version must have three parts: {$version}");
			return false;
		}

		foreach ($parts as $part) {
			$num = (int) $part;
			if ($num < 0) {
				error_log("Negative numbers are not allowed in version: {$version}");
				return false;
			}
			if (strlen($part) > 1 && $part[0] === '0') {
				error_log("Leading zeros are not allowed in version: {$version}");
				return false;
			}
		}

		return true;
	}

	/**
	 * Make an HTTP request using cURL.
	 *
	 * @param string $url The URL to request.
	 * @return string The response body.
	 * @throws \Exception If the request fails.
	 */
	protected function makeRequest(string $url): string {
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connect_timeout);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Vateron-Media/XC_VM');
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);

		if ($response === false) {
			throw new \Exception("cURL error: {$error}");
		}

		if ($http_code !== 200) {
			switch ($http_code) {
				case 404:
					error_log("Resource not found (404)");
					throw new \Exception("Resource not found (404)");
				case 403:
					error_log("Access forbidden (403) - Check API rate limits or permissions");
					throw new \Exception("Access forbidden (403)");
				case 500:
					error_log("Server error (500)");
					throw new \Exception("Server error (500)");
				default:
					error_log("Unexpected HTTP status code: {$http_code}");
					throw new \Exception("Unexpected HTTP status code: {$http_code}");
			}
		}

		return $response;
	}

	/**
	 * Get update file details for a specific file type and version.
	 *
	 * @param string $file_type The type of update file (main, lb, lb_update).
	 * @param string $version The current version tag (e.g., "1.0.0").
	 * @return array|null Array with URL and MD5 hash, or null if no next version.
	 * @throws \Exception If the file type is invalid.
	 */
	public function getUpdateFile(string $file_type, string $version) {
		switch ($file_type) {
			case "main":
				$update_file = "xc_vm.tar.gz";
				break;
			case "lb":
			case "lb_update":
				$update_file = "loadbalancer.tar.gz";
				break;
			default:
				throw new \Exception("Not valid file type");
		}
		$target_version = $this->getLatestVersion($version);
		if (is_null($target_version)) {
			$target_version = $version;
		}
		$upd_archive_url = $this->assetUrl($target_version, $update_file);
		$hash_md5 = $this->getAssetHash($target_version, $update_file);
		return ["url" => $upd_archive_url, "md5" => $hash_md5];
	}

	/**
	 * Build the download URL + MD5 for a SPECIFIC release version's asset.
	 *
	 * Unlike getUpdateFile(), which always resolves to the latest via
	 * getLatestVersion(), this targets the exact $version passed — used by the
	 * rollback flow to fetch an older release.
	 *
	 * @param string $file_type "main" (panel) or "lb"/"lb_update" (load balancer).
	 * @param string $version   Exact release tag to fetch (e.g. "2.4.0").
	 * @return array{url:string,md5:?string}
	 * @throws \Exception On an invalid file type.
	 */
	public function getVersionFile(string $file_type, string $version): array {
		switch ($file_type) {
			case "main":
				$update_file = "xc_vm.tar.gz";
				break;
			case "lb":
			case "lb_update":
				$update_file = "loadbalancer.tar.gz";
				break;
			default:
				throw new \Exception("Not valid file type");
		}
		$url = $this->assetUrl($version, $update_file);

		return ["url" => $url, "md5" => $this->getAssetHash($version, $update_file)];
	}

	/**
	 * List the newest $limit released versions strictly older than
	 * $current_version, newest first. Populates the rollback version picker. Each
	 * entry carries a `beta` flag (GitHub prerelease) so the UI can tag it.
	 *
	 * @param string $current_version Current version tag (e.g. "2.4.1").
	 * @param int    $limit           Max versions to return.
	 * @return array<int,array{version:string,beta:bool}> Older releases, newest first.
	 */
	public function getPreviousVersions(string $current_version, int $limit = 5): array {
		$older = [];
		foreach ($this->getFilteredReleases() as $rRelease) {
			$tag = (string) ($rRelease['tag_name'] ?? '');
			// Nightly builds are not rollback targets: the rollback flow takes a
			// release or a patch build of one.
			if ($tag !== '' && !self::isDevVersion($tag) && version_compare($tag, $current_version, '<')) {
				$older[] = ['version' => $tag, 'beta' => !empty($rRelease['prerelease'])];
			}
		}
		usort($older, static fn($a, $b) => version_compare($b['version'], $a['version']));

		return array_slice($older, 0, max(1, $limit));
	}

	/**
	 * Retrieves update information for the specified version
	 *
	 * @param string $version Current version to check for updates
	 * @return array|null Array with update information or null in case of error
	 * @throws \InvalidArgumentException If an incorrect version is passed
	 */
	public function getUpdate(string $version): ?array {
		try {
			$latest_version = $this->getLatestVersion($version);
			if ($latest_version === null) {
				error_log("No update available for version {$version} on channel '{$this->channel}'");
				return null;
			}

			$changelog = $this->getChangelog($latest_version);

			$url = "https://github.com/{$this->owner}/{$this->repoFor($latest_version)}/releases/tag/{$latest_version}";

			return [
				"version" => $latest_version,
				"changelog" => $changelog,
				"url" => $url
			];
		} catch (\Exception $e) {
			error_log("Error while fetching update: " . $e->getMessage());
			return null;
		}
	}

	/**
	 * Build the browser download URL for a release asset.
	 *
	 * @param string $version Release tag.
	 * @param string $asset   Asset filename.
	 */
	public function assetUrl(string $version, string $asset): string {
		return "https://github.com/{$this->owner}/{$this->repoFor($version)}/releases/download/{$version}/{$asset}";
	}

	/**
	 * Filter releases based on the selected channel.
	 *
	 * @param array $releases Raw releases from GitHub API
	 * @return array Filtered releases
	 */
	private function filterReleasesByChannel(array $releases): array {
		if ($this->channel !== 'stable') {
			return $releases; // beta/dev: все релизы
		}

		// stable: только не pre-release
		return array_filter($releases, function ($release) {
			return empty($release['prerelease']);
		});
	}

	/**
	 * Set the request timeout for cURL operations.
	 *
	 * @param int $seconds Timeout in seconds
	 */
	public function setTimeout(int $seconds): void {
		$this->timeout = max(1, $seconds);
	}

	/**
	 * Change the update channel and clear cache.
	 *
	 * @param string $channel 'stable', 'beta' or 'dev' ('unstable' is a legacy alias for 'beta')
	 */
	public function setChannel(string $channel): void {
		$channel = ($channel === 'unstable') ? 'beta' : $channel;
		if (!in_array($channel, ['stable', 'beta', 'dev'], true)) {
			throw new \InvalidArgumentException("Channel must be 'stable', 'beta' or 'dev'");
		}
		if ($this->channel !== $channel) {
			$oldCacheFile = $this->cache_file;
			$baseCacheFile = preg_replace('/_(stable|beta|dev|unstable)$/', '', $this->cache_file);
			if (!is_string($baseCacheFile) || $baseCacheFile === '') {
				$baseCacheFile = $this->cache_file;
			}

			$this->channel = $channel;

			// Remove old-channel cache explicitly before switching file path.
			if (file_exists($oldCacheFile)) {
				unlink($oldCacheFile);
			}

			$this->cache_file = $baseCacheFile . "_{$channel}";

			// Ensure target-channel cache is also reset.
			$this->clearCache();
			error_log("Update channel changed to '{$channel}' and cache cleared");
		}
	}
}

/**
 * ------------------------------------------------------------
 * 🔧 Инициализация:
 *
 *   $gh = new GitHubReleases("Vateron-Media", "XC_VM", 'stable');
 *   // Можно передать токен для увеличения лимита API:
 *   // $gh = new GitHubReleases("owner", "repo", "ghp_XXXXXXX");
 *
 * ------------------------------------------------------------
 *
 * 1. Получить список релизов:
 *
 *   $releases = $gh->getReleases();
 *   print_r($releases);
 *
 * ------------------------------------------------------------
 * 2. Получить последнюю доступную версию (прыжок на latest):
 *
 *   $latest = $gh->getLatestVersion("1.0.0");
 *   echo $latest; // e.g. "2.0.2" — пропускает промежуточные версии
 *
 * ------------------------------------------------------------
 * 3. Проверить хэш файла из релиза:
 *
 *   $hash = $gh->getAssetHash("1.2.0", "update.tar.gz");
 *   echo $hash;
 *
 * ------------------------------------------------------------
 * 4. Загрузить changelog:
 *
 *   $changelog = $gh->getChangelog("1.2.0");
 *   print_r($changelog);
 *
 * ------------------------------------------------------------
 * 5. Проверка корректности версии:
 *
 *   var_dump(GitHubReleases::isValidVersion("1.0.0")); // true
 *   var_dump(GitHubReleases::isValidVersion("01.0.0")); // false
 *
 * ------------------------------------------------------------
 * 6. Получение архива обновления:
 *
 *   $upd = $gh->getUpdateFile("main", "1.0.0");
 *   print_r($upd);
 *
 * ------------------------------------------------------------
 * 7. Проверить наличие обновления:
 *
 *   $update = $gh->getUpdate("1.0.0");
 *   print_r($update);
 *
 * ------------------------------------------------------------
 * ⚠️ Важно:
 * - Кеш хранится в /home/xc_vm/tmp/gitapi_repo
 * - TTL кеша: 30 минут (1800 сек)
 */
