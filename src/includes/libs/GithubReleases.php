<?php

class GitHubReleases {
    private $owner;
    private $repo;
    private $api_url;
    private $headers;
    private $timeout = 5; // Request timeout in seconds
    private $cache_file = '/home/xc_vm/tmp/gitapi'; // Cache file path
    private $hash_file = 'hashes.md5';
    private $cache_ttl = 1800; // Cache TTL in seconds (30 minutes)

    /**
     * Initialize a GitHubReleases instance for accessing release data of a GitHub repository.
     *
     * @param string $owner Repository owner (e.g., "Vateron-Media").
     * @param string $repo Repository name (e.g., "XC_VM").
     * @param string|null $token GitHub API token for authentication (optional).
     */
    public function __construct(string $owner, string $repo, ?string $token = null) {
        $this->owner = $owner;
        $this->repo = $repo;
        $this->cache_file = "{$this->cache_file}_$repo";
        $this->api_url = "https://api.github.com/repos/{$owner}/{$repo}/releases";
        $this->headers = $token ? [
            "Authorization: Bearer {$token}",
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28'
        ] : [];
    }

    /**
     * Clear the cached release data by deleting the cache file.
     */
    public function clearCache(): void {
        if (file_exists($this->cache_file)) {
            unlink($this->cache_file);
            error_log("Cache cleared for {$this->owner}/{$this->repo} by deleting {$this->cache_file}");
        }
    }

    /**
     * Check if the cache file is still valid based on TTL.
     *
     * @return bool True if cache is valid, False otherwise.
     */
    private function isCacheValid(): bool {
        if (!file_exists($this->cache_file)) {
            return false;
        }
        $cache_timestamp = filemtime($this->cache_file);
        return (time() - $cache_timestamp) < $this->cache_ttl;
    }

    /**
     * Load cache from file.
     *
     * @return array|null The cached data, or null if the file doesn't exist or is invalid.
     */
    private function loadCache(): ?array {
        if (!file_exists($this->cache_file)) {
            return null;
        }
        $content = file_get_contents($this->cache_file);
        if ($content === false) {
            error_log("Failed to read cache file {$this->cache_file}");
            return null;
        }
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Failed to parse cache file {$this->cache_file}: " . json_last_error_msg());
            return null;
        }
        return $data;
    }

    /**
     * Save data to cache file with file locking to prevent race conditions.
     *
     * @param array $data The data to cache.
     * @return bool True on success, False on failure.
     */
    private function saveCache(array $data): bool {
        $json = json_encode($data);
        if ($json === false) {
            error_log("Failed to encode cache data to JSON");
            return false;
        }

        $file = fopen($this->cache_file, 'c');
        if ($file === false) {
            error_log("Failed to open cache file {$this->cache_file} for writing");
            return false;
        }

        if (flock($file, LOCK_EX)) {
            ftruncate($file, 0);
            fwrite($file, $json);
            fflush($file);
            flock($file, LOCK_UN);
            fclose($file);
            error_log("Cache saved to {$this->cache_file}");
            return true;
        } else {
            error_log("Failed to acquire lock on cache file {$this->cache_file}");
            fclose($file);
            return false;
        }
    }

    /**
     * Fetch all release versions (tags) from the GitHub repository, using cache if valid.
     *
     * @return array List of version tags in descending order (latest first).
     * @throws Exception If the request fails.
     */
    public function getReleases(): array {
        if ($this->isCacheValid()) {
            error_log("Using cached releases from {$this->cache_file} for {$this->owner}/{$this->repo}");
            $cache = $this->loadCache();
            if ($cache === null) {
                error_log("Invalid cache, fetching new data");
            } else {
                $releases = array_filter(array_map(function ($release) {
                    return $release['tag_name'] ?? '';
                }, $cache));
                return array_values($releases);
            }
        }

        try {
            error_log("Fetching releases for {$this->owner}/{$this->repo}");
            $response = $this->makeRequest($this->api_url);
            $data = json_decode($response, true);
            if ($data === null) {
                throw new Exception("Failed to parse API response: " . json_last_error_msg());
            }
            $this->saveCache($data);
            $releases = array_filter(array_map(function ($release) {
                return $release['tag_name'] ?? '';
            }, $data));
            $releases = array_values($releases);
            error_log("Retrieved and cached " . count($releases) . " releases from {$this->owner}/{$this->repo}");
            return $releases;
        } catch (Exception $e) {
            error_log("Failed to fetch releases: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get the next version tag that comes after the specified current version.
     *
     * @param string $current_version The current version tag (e.g., "1.0.0").
     * @return string|null The next version tag, or null if it's the latest or not found.
     */
    public function getNextVersion(string $current_version): ?string {
        $releases = $this->getReleases();
        $index = array_search($current_version, $releases);
        if ($index === false) {
            error_log("Version {$current_version} not found in releases");
            return null;
        }
        return $index > 0 ? $releases[$index - 1] : null;
    }

    /**
     * Retrieve the MD5 hash of a release asset from its corresponding hash file.
     *
     * @param string $version The release tag (e.g., "1.0.0").
     * @param string $asset_name The asset file name to get the hash for (e.g., "update.tar.gz").
     * @return string|null The MD5 hash string, or null if not found or invalid.
     */
    public function getAssetHash(string $version, string $asset_name): ?string {
        try {
            $hashURL = "https://github.com/{$this->owner}/{$this->repo}/releases/download/{$version}/{$this->hash_file}";
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
            return null;
        } catch (Exception $e) {
            error_log("Failed to fetch asset hash: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Retrieve the changelog for all releases from changelog.json files in JSON format.
     *
     * @param string $changelog_file_url Link to file with changelog.
     * @return array A JSON-compatible array containing the changelog.
     */
    public function getChangelog(string $changelog_file_url): array {
        try {
            if (!$this->isCacheValid()) {
                $response = $this->makeRequest($this->api_url);
                $data = json_decode($response, true);
                if ($data === null) {
                    throw new Exception("Failed to parse API response: " . json_last_error_msg());
                }
                $this->saveCache($data);
                error_log("Updated cache for {$this->owner}/{$this->repo}");
            }
            $releases = $this->loadCache();
            if ($releases === null) {
                error_log("Invalid cache, unable to proceed");
                return [];
            }

            $response = $this->makeRequest($changelog_file_url);
            $changelog = json_decode($response, true);
            if ($changelog === null) {
                error_log("Failed to parse changelog JSON");
                return [];
            }

            $valid_versions = array_map(function ($release) {
                return $release['tag_name'] ?? '';
            }, $releases);
            $filtered_changelog = array_filter($changelog, function ($entry) use ($valid_versions) {
                return in_array($entry['version'] ?? '', $valid_versions);
            });
            $filtered_changelog = array_values($filtered_changelog);
            error_log("Successfully retrieved changelog with " . count($filtered_changelog) . " versions after filtering (original: " . count($changelog) . " versions)");
            return $filtered_changelog;
        } catch (Exception $e) {
            error_log("Failed to fetch changelog: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Validate whether a version string follows the format X.Y.Z.
     *
     * @param string $version The version string to validate (e.g., "1.0.0").
     * @return bool True if valid, False otherwise.
     * @throws InvalidArgumentException If the version string is too long or contains invalid parts.
     */
    public static function isValidVersion(string $version): bool {
        if (!is_string($version)) {
            error_log("Version must be a string");
            return false;
        }

        if (strlen($version) > 20) {
            error_log("Version string too long");
            throw new InvalidArgumentException("Version string is too long");
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
            $num = (int)$part;
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
     * @throws Exception If the request fails.
     */
    private function makeRequest(string $url): string {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Vateron-Media/XC_VM');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception("cURL error: {$error}");
        }

        if ($http_code !== 200) {
            switch ($http_code) {
                case 404:
                    error_log("Resource not found (404)");
                    throw new Exception("Resource not found (404)");
                case 403:
                    error_log("Access forbidden (403) - Check API rate limits or permissions");
                    throw new Exception("Access forbidden (403)");
                case 500:
                    error_log("Server error (500)");
                    throw new Exception("Server error (500)");
                default:
                    error_log("Unexpected HTTP status code: {$http_code}");
                    throw new Exception("Unexpected HTTP status code: {$http_code}");
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
     * @throws Exception If the file type is invalid.
     */
    public function getUpdateFile(string $file_type, string $version) {
        switch ($file_type) {
            case "main":
                $update_file = "update.tar.gz";
                break;
            case "lb":
                $update_file = "loadbalancer.tar.gz";
                break;
            case "lb_update":
                $update_file = "loadbalancer_update.tar.gz";
                break;
            default:
                throw new Exception("Not valid file type");
        }
        $next_version = $this->getNextVersion($version);
        if (is_null($next_version)) {
            return null;
        }
        $upd_archive_url = "https://github.com/{$this->owner}/{$this->repo}/releases/download/{$next_version}/{$update_file}";
        $hash_md5 = $this->getAssetHash($next_version, $update_file);

        $data = ["url" => $upd_archive_url, "md5" => $hash_md5];
        return $data;
    }

    /**
     * Retrieves update information for the specified version
     * 
     * @param string $version Current version to check for updates
     * @return array|null Array with update information or null in case of error
     * @throws InvalidArgumentException If an incorrect version is passed
     */
    public function getUpdate(string $version): ?array {
        try {
            // Get the next version
            $next_version = $this->getNextVersion($version);

            // Form the URL for changelog
            $changelogUrl = "https://raw.githubusercontent.com/{$this->owner}/{$this->repo}_Update/refs/heads/main/changelog.json";
            $changelog = $this->getChangelog($changelogUrl);

            // Form the release URL
            $url = "https://github.com/{$this->owner}/{$this->repo}/releases/tag/{$next_version}";

            return [
                "version" => $next_version,
                "changelog" => $changelog,
                "url" => $url
            ];
        } catch (Exception $e) {
            error_log("Error while fetching update information: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Retrieve the latest GeoLite database release information.
     *
     * This method fetches the latest release version from the repository,
     * builds download URLs for GeoLite2 database files (ASN, City, Country),
     * and prepares metadata including file paths, permissions, and MD5 hashes.
     *
     * @return array|null Returns an associative array with the latest version and file data,
     *                    or null if no releases are available.
     */
    public function getGeolite(): ?array {
        // Get all available releases from the repository
        $releases = $this->getReleases();

        // If there are no releases, return null
        if (empty($releases)) {
            return null;
        }

        // Take the latest release (the first in the list)
        $latest_version = $releases[0];

        // Prepare the list of data files
        $data_files = array();

        // Iterate over required GeoLite2 database files
        foreach (["GeoLite2-City.mmdb", "GeoLite2-Country.mmdb"] as $file) {
            // Construct the GitHub release download URL
            $file_url = "https://github.com/{$this->owner}/{$this->repo}/releases/download/{$latest_version}/{$file}";

            // Fetch the MD5 hash for file integrity verification
            $hash_md5 = $this->getAssetHash($latest_version, $file);

            // Add file information to the list
            $data_files[] = [
                "fileurl"   => $file_url,                                // Remote file URL
                "path"      => "/home/xc_vm/bin/maxmind/{$file}",        // Local path where the file should be stored
                "permission" => "0750",                                   // File permission
                "md5"       => $hash_md5                                // File hash (MD5)
            ];
        }

        // Prepare final data structure containing version and files metadata
        $data = [
            "version" => $latest_version,
            "files"   => $data_files,
        ];

        // Return the release data
        return $data;
    }
}

/**
 * ------------------------------------------------------------
 * 🔧 Инициализация:
 *
 *   $gh = new GitHubReleases("Vateron-Media", "XC_VM");
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
 * 2. Проверить следующую версию после текущей:
 *
 *   $next = $gh->getNextVersion("1.0.0");
 *   echo $next;
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
 *   $changelog = $gh->getChangelog("https://raw.githubusercontent.com/Vateron-Media/XC_VM_Update/refs/heads/main/changelog.json");
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
 * 8. Получить информацию о GeoLite базах:
 *
 *   $gh = new GitHubReleases("Vateron-Media", "XC_VM_Update");
 *   $geo = $gh->getGeolite();
 *   print_r($geo);
 *
 * ------------------------------------------------------------
 * ⚠️ Важно:
 * - Кеш хранится в /home/xc_vm/tmp/gitapi_repo
 * - TTL кеша: 30 минут (1800 сек)
 */