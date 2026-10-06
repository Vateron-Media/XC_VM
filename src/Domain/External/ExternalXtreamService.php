<?php

namespace XcVm\Domain\External;

use XcVm\Core\Util\PublicAddress;

/**
 * ExternalXtreamService — Integration Service for External Xtream Codes Servers.
 *
 * Facilitates authentication, category retrieval, live stream listings,
 * VOD/Series catalogs, and stream URL resolution against remote Xtream Codes APIs.
 *
 * @package XC_VM_Domain_External
 */
class ExternalXtreamService {
	private string $serverUrl;

	private string $username;

	private string $password;

	private int $timeout;

	/** Fields of the remote server's answers that carry an image address. */
	private const IMAGE_FIELDS = ['stream_icon', 'cover', 'cover_big', 'movie_image'];

	/** Fields of the remote server's answers the pages calculate with. */
	private const NUMBER_FIELDS = ['category_id', 'duration_secs'];

	/**
	 * Constructor. If credentials omitted, loads from active external session.
	 */
	public function __construct(?string $serverUrl = null, ?string $username = null, ?string $password = null, int $timeout = 10) {
		if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
			@session_start();
		}

		$sessionExt = $_SESSION['external_xc'] ?? [];

		$this->serverUrl = self::normalizeUrl($serverUrl ?: ($sessionExt['server'] ?? ''));
		$this->username = trim($username ?: ($sessionExt['username'] ?? ''));
		$this->password = trim($password ?: ($sessionExt['password'] ?? ''));
		$this->timeout = $timeout;
	}

	/**
	 * Factory instance initialized from current session.
	 */
	public static function fromSession(): ?self {
		if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
			@session_start();
		}

		if (empty($_SESSION['is_external_xc']) || empty($_SESSION['external_xc']['server'])) {
			return null;
		}

		return new self();
	}

	/**
	 * Normalize URL string to include standard scheme and remove trailing slashes.
	 */
	public static function normalizeUrl(string $url): string {
		// A query or fragment is dropped: the API and stream paths are appended to
		// this string, and behind either they would no longer be the path requested.
		$url = trim(substr($url, 0, strcspn($url, '?#')));
		if ($url === '') {
			return '';
		}

		// Add scheme if missing
		if (!preg_match('#^https?://#i', $url)) {
			$url = 'http://' . $url;
		}

		return rtrim($url, '/');
	}

	/**
	 * SSRF guard — resolve the URL host and return its validated public IPs.
	 *
	 * The external server URL originates from an unauthenticated request, so
	 * before the panel issues any outbound call we resolve the host and reject
	 * it if ANY resolved address falls into a private or reserved range
	 * (loopback, RFC1918, link-local 169.254/16 — which includes cloud metadata
	 * at 169.254.169.254 — CGNAT, documentation ranges, etc.). A host that
	 * cannot be resolved at all is treated as unsafe.
	 *
	 * The vetted IPs are returned so the caller can pin them onto the actual
	 * request (CURLOPT_RESOLVE): otherwise libcurl re-resolves the host itself
	 * and a hostile DNS server could hand us a public IP here and an internal
	 * one to the fetch (DNS rebinding / TOCTOU).
	 *
	 * @return list<string>|null Validated IPs, or null if the host is unsafe.
	 */
	private function resolvePublicIps(string $url): ?array {
		$host = parse_url($url, PHP_URL_HOST);
		if (!is_string($host) || $host === '') {
			return null;
		}
		$host = trim($host, '[]'); // strip IPv6 literal brackets

		$ips = [];
		if (filter_var($host, FILTER_VALIDATE_IP)) {
			$ips[] = $host;
		} else {
			foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
				if (!empty($record['ip'])) {
					$ips[] = $record['ip'];
				}
				if (!empty($record['ipv6'])) {
					$ips[] = $record['ipv6'];
				}
			}
			if ($ips === []) {
				$resolved = gethostbyname($host);
				if ($resolved !== '' && $resolved !== $host) {
					$ips[] = $resolved;
				}
			}
		}

		if ($ips === []) {
			return null;
		}

		foreach ($ips as $ip) {
			if (!PublicAddress::isPublic($ip)) {
				return null;
			}
		}

		return $ips;
	}

	/**
	 * Parse any Xtream / M3U / Playlist URL into server, username, and password components.
	 *
	 * Supports formats:
	 * - http(s)://domain:port/player_api.php?username=xxx&password=yyy...
	 * - http(s)://domain:port/get.php?username=xxx&password=yyy...
	 * - http(s)://domain:port/live|movie|series/xxx/yyy/id.ts
	 * - http(s)://user:pass@domain:port/...
	 * - URLs without scheme (e.g. 104.243.37.202/player_api.php?...)
	 *
	 * @return array{server: string, username: string, password: string}|null
	 */
	public static function parsePlaylistUrl(string $rawUrl): ?array {
		$rawUrl = trim($rawUrl);
		if ($rawUrl === '') {
			return null;
		}

		// Add scheme if missing
		if (!preg_match('#^https?://#i', $rawUrl)) {
			$rawUrl = 'http://' . $rawUrl;
		}

		$parts = parse_url($rawUrl);
		if (!$parts || empty($parts['host'])) {
			return null;
		}

		$scheme = $parts['scheme'] ?? 'http';
		$host = $parts['host'];
		$port = !empty($parts['port']) ? ':' . $parts['port'] : '';
		$server = rtrim($scheme . '://' . $host . $port, '/');

		$username = '';
		$password = '';

		// 1. Check HTTP Basic Auth credentials in URL (http://user:pass@host:port)
		if (!empty($parts['user'])) {
			$username = urldecode($parts['user']);
		}
		if (!empty($parts['pass'])) {
			$password = urldecode($parts['pass']);
		}

		// 2. Check Query Parameters (?username=...&password=...)
		if (!empty($parts['query'])) {
			parse_str($parts['query'], $query);
			if (empty($username)) {
				$username = $query['username'] ?? $query['user'] ?? $query['u'] ?? '';
			}
			if (empty($password)) {
				$password = $query['password'] ?? $query['pass'] ?? $query['p'] ?? '';
			}
		}

		// 3. Check Stream Path Format (/live/user/pass/id.ext or /series/user/pass/id.ext or /movie/user/pass/id.ext)
		if ((empty($username) || empty($password)) && !empty($parts['path'])) {
			if (preg_match('#/(?:live|movie|series)/([^/]+)/([^/]+)/#i', $parts['path'], $m)) {
				if (empty($username)) {
					$username = urldecode($m[1]);
				}
				if (empty($password)) {
					$password = urldecode($m[2]);
				}
			}
		}

		$username = trim((string) $username);
		$password = trim((string) $password);

		if (empty($username) || empty($password)) {
			return null;
		}

		return [
			'server'   => $server,
			'username' => $username,
			'password' => $password,
		];
	}

	/**
	 * Authenticate and verify credentials against an external Xtream Codes server.
	 */
	public static function testAndAuthenticate(string $serverUrl, string $username, string $password): array {
		$cleanUrl = self::normalizeUrl($serverUrl);
		$username = trim($username);
		$password = trim($password);

		if (empty($cleanUrl)) {
			return ['success' => false, 'message' => 'Please provide a valid server host and port.'];
		}
		if (empty($username) || empty($password)) {
			return ['success' => false, 'message' => 'Username and password cannot be empty.'];
		}

		$instance = new self($cleanUrl, $username, $password, 10);
		$res = $instance->request([], 10);

		if (!$res) {
			return [
				'success' => false,
				'message' => 'Failed to connect to server (' . htmlspecialchars($cleanUrl) . '). Please verify host, port, and network connection.'
			];
		}

		$userInfo = $res['user_info'] ?? null;
		$serverInfo = $res['server_info'] ?? [];

		if (!$userInfo || empty($userInfo['auth'])) {
			$msg = $userInfo['message'] ?? 'Invalid username or password for this server.';
			return ['success' => false, 'message' => $msg];
		}

		$status = strtolower($userInfo['status'] ?? '');
		if ($status === 'banned') {
			return ['success' => false, 'message' => 'Your account has been banned on this server.'];
		}
		if ($status === 'disabled') {
			return ['success' => false, 'message' => 'Your account has been disabled on this server.'];
		}
		if ($status === 'expired' || (!empty($userInfo['exp_date']) && (int) $userInfo['exp_date'] > 0 && (int) $userInfo['exp_date'] <= time())) {
			$expFormatted = !empty($userInfo['exp_date']) ? date('Y-m-d H:i:s', (int) $userInfo['exp_date']) : 'Expired';
			return ['success' => false, 'message' => 'This account has expired on ' . $expFormatted . '.'];
		}

		return [
			'success' => true,
			'message' => 'Connected successfully! Launching player...',
			'user_info' => $userInfo,
			'server_info' => $serverInfo,
			'clean_server' => $cleanUrl,
		];
	}

	/**
	 * Send HTTP cURL request to external player_api.php.
	 */
	public function request(array $params = [], int $timeout = 10): ?array {
		if (empty($this->serverUrl) || empty($this->username) || empty($this->password)) {
			return null;
		}

		// SSRF guard: serverUrl is supplied by an unauthenticated visitor, so
		// refuse any host that resolves into a loopback/private/reserved range
		// (localhost, RFC1918, link-local 169.254/16 incl. cloud metadata, …).
		$safeIps = $this->resolvePublicIps($this->serverUrl);
		if ($safeIps === null) {
			return null;
		}

		$query = array_merge([
			'username' => $this->username,
			'password' => $this->password,
		], $params);

		$targetUrl = $this->serverUrl . '/player_api.php?' . http_build_query($query);

		$curlOpts = [
			CURLOPT_URL => $targetUrl,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => $timeout ?: $this->timeout,
			CURLOPT_CONNECTTIMEOUT => 6,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			// Redirects are NOT followed: a 30x from the remote server could point
			// back at an internal address and slip past the pre-flight host check.
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_USERAGENT => 'XC_VM_WebPlayer/2.0 (IPTVSmartersPlayer/1.0.0)',
			CURLOPT_HTTPHEADER => [
				'Accept: application/json',
			],
		];

		// Pin the vetted IPs onto the fetch so libcurl cannot re-resolve the host
		// to a different (internal) address after the guard passed — DNS rebinding
		// / TOCTOU. The URL keeps the hostname, so TLS SNI/cert validation is
		// unaffected. Skipped when the host is already an IP literal.
		$host = trim((string) parse_url($this->serverUrl, PHP_URL_HOST), '[]');
		if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) === false) {
			$scheme = strtolower((string) (parse_url($this->serverUrl, PHP_URL_SCHEME) ?: 'http'));
			$port = (int) (parse_url($this->serverUrl, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));
			$curlOpts[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . implode(',', $safeIps)];
		}

		$ch = curl_init();
		curl_setopt_array($ch, $curlOpts);

		$rawResponse = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($rawResponse === false || $httpCode < 200 || $httpCode >= 400) {
			return null;
		}

		$decoded = json_decode((string) $rawResponse, true);
		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * One record of the remote server's answer, down to what the pages can
	 * show: a text or a number as it came, an image address only when it is an
	 * http(s) one. A field of any other type is left out, so the caller's
	 * default for a missing field applies.
	 */
	private static function fields(mixed $record): array {
		$fields = [];

		foreach (is_array($record) ? $record : [] as $name => $value) {
			if ($name === 'backdrop_path') {
				// One address or a list of them; the pages take either.
				$fields[$name] = array_values(array_filter((array) $value, [self::class, 'isImageUrl']));
			} elseif (in_array($name, self::IMAGE_FIELDS, true)) {
				if ($value === '' || self::isImageUrl($value)) {
					$fields[$name] = $value;
				}
			} elseif (in_array($name, self::NUMBER_FIELDS, true)) {
				if (is_numeric($value)) {
					$fields[$name] = (int) $value;
				}
			} elseif (is_scalar($value)) {
				$fields[$name] = $value;
			}
		}

		return $fields;
	}

	private static function isImageUrl(mixed $value): bool {
		return is_string($value) && preg_match('#^\s*https?://#i', $value) === 1;
	}

	/**
	 * Fetch Live Categories with in-session caching.
	 */
	public function getLiveCategories(): array {
		$cacheKey = 'ext_live_cats_' . md5($this->serverUrl . $this->username);
		if (!empty($_SESSION[$cacheKey]) && is_array($_SESSION[$cacheKey])) {
			return $_SESSION[$cacheKey];
		}

		$data = $this->request(['action' => 'get_live_categories'], 12);
		$categories = is_array($data) ? $data : [];

		// Normalize categories format
		$normalized = [];
		foreach ($categories as $cat) {
			if (isset($cat['category_id'], $cat['category_name'])) {
				$normalized[] = [
					'id' => (int) $cat['category_id'],
					'category_id' => (int) $cat['category_id'],
					'category_name' => (string) $cat['category_name'],
					'name' => (string) $cat['category_name'],
				];
			}
		}

		$_SESSION[$cacheKey] = $normalized;
		return $normalized;
	}

	/**
	 * Fetch Live Streams for a category or all categories.
	 */
	public function getLiveStreams(?int $categoryId = null): array {
		$params = ['action' => 'get_live_streams'];
		if (!empty($categoryId) && $categoryId > 0) {
			$params['category_id'] = $categoryId;
		}

		$cacheKey = 'ext_live_str_' . md5($this->serverUrl . $this->username . '_' . ($categoryId ?: 'all'));
		if (!empty($_SESSION[$cacheKey]) && is_array($_SESSION[$cacheKey])) {
			return $_SESSION[$cacheKey];
		}

		$data = $this->request($params, 15);
		$streams = is_array($data) ? $data : [];

		$result = [];
		foreach ($streams as $s) {
			$s = self::fields($s);
			if (empty($s['stream_id'])) {
				continue;
			}
			$streamId = (int) $s['stream_id'];
			$result[] = [
				'id' => $streamId,
				'stream_id' => $streamId,
				'name' => (string) ($s['name'] ?? ('Channel #' . $streamId)),
				'stream_display_name' => (string) ($s['name'] ?? ('Channel #' . $streamId)),
				'logo' => $s['stream_icon'] ?? '',
				'stream_icon' => $s['stream_icon'] ?? '',
				'category_id' => (int) ($s['category_id'] ?? 0),
				'archive' => !empty($s['tv_archive']),
				'url' => $this->buildLiveUrl($streamId),
				'epg_channel_id' => $s['epg_channel_id'] ?? '',
			];
		}

		$_SESSION[$cacheKey] = $result;
		return $result;
	}

	/**
	 * Fetch VOD (Movies) Categories.
	 */
	public function getVodCategories(): array {
		$cacheKey = 'ext_vod_cats_' . md5($this->serverUrl . $this->username);
		if (!empty($_SESSION[$cacheKey]) && is_array($_SESSION[$cacheKey])) {
			return $_SESSION[$cacheKey];
		}

		$data = $this->request(['action' => 'get_vod_categories'], 12);
		$categories = is_array($data) ? $data : [];

		$normalized = [];
		foreach ($categories as $cat) {
			if (isset($cat['category_id'], $cat['category_name'])) {
				$normalized[] = [
					'id' => (int) $cat['category_id'],
					'category_id' => (int) $cat['category_id'],
					'category_name' => (string) $cat['category_name'],
					'name' => (string) $cat['category_name'],
				];
			}
		}

		$_SESSION[$cacheKey] = $normalized;
		return $normalized;
	}

	/**
	 * Fetch VOD Streams (Movies).
	 */
	public function getVodStreams(?int $categoryId = null): array {
		$params = ['action' => 'get_vod_streams'];
		if (!empty($categoryId) && $categoryId > 0) {
			$params['category_id'] = $categoryId;
		}

		$cacheKey = 'ext_vod_str_' . md5($this->serverUrl . $this->username . '_' . ($categoryId ?: 'all'));
		if (!empty($_SESSION[$cacheKey]) && is_array($_SESSION[$cacheKey])) {
			return $_SESSION[$cacheKey];
		}

		$data = $this->request($params, 15);
		$streams = is_array($data) ? $data : [];

		$result = [];
		foreach ($streams as $m) {
			$m = self::fields($m);
			if (empty($m['stream_id'])) {
				continue;
			}
			$streamId = (int) $m['stream_id'];
			$ext = !empty($m['container_extension']) ? $m['container_extension'] : 'mp4';
			$result[] = [
				'id' => $streamId,
				'stream_id' => $streamId,
				'title' => (string) ($m['name'] ?? ('Movie #' . $streamId)),
				'stream_display_name' => (string) ($m['name'] ?? ('Movie #' . $streamId)),
				'poster' => $m['stream_icon'] ?? '',
				'stream_icon' => $m['stream_icon'] ?? '',
				'category_id' => (int) ($m['category_id'] ?? 0),
				'rating' => $m['rating'] ?? '',
				'year' => !empty($m['year']) ? $m['year'] : null,
				'container_extension' => $ext,
				'url' => $this->buildVodUrl($streamId, $ext),
			];
		}

		$_SESSION[$cacheKey] = $result;
		return $result;
	}

	/**
	 * Fetch Movie Details via get_vod_info.
	 */
	public function getVodInfo(int $vodId): array {
		$data = $this->request([
			'action' => 'get_vod_info',
			'vod_id' => $vodId,
		], 10);

		return [
			'info' => self::fields($data['info'] ?? null),
			'movie_data' => self::fields($data['movie_data'] ?? null),
		];
	}

	/**
	 * Fetch TV Series Categories.
	 */
	public function getSeriesCategories(): array {
		$cacheKey = 'ext_series_cats_' . md5($this->serverUrl . $this->username);
		if (!empty($_SESSION[$cacheKey]) && is_array($_SESSION[$cacheKey])) {
			return $_SESSION[$cacheKey];
		}

		$data = $this->request(['action' => 'get_series_categories'], 12);
		$categories = is_array($data) ? $data : [];

		$normalized = [];
		foreach ($categories as $cat) {
			if (isset($cat['category_id'], $cat['category_name'])) {
				$normalized[] = [
					'id' => (int) $cat['category_id'],
					'category_id' => (int) $cat['category_id'],
					'category_name' => (string) $cat['category_name'],
					'name' => (string) $cat['category_name'],
				];
			}
		}

		$_SESSION[$cacheKey] = $normalized;
		return $normalized;
	}

	/**
	 * Fetch Series list.
	 */
	public function getSeries(?int $categoryId = null): array {
		$params = ['action' => 'get_series'];
		if (!empty($categoryId) && $categoryId > 0) {
			$params['category_id'] = $categoryId;
		}

		$cacheKey = 'ext_series_str_' . md5($this->serverUrl . $this->username . '_' . ($categoryId ?: 'all'));
		if (!empty($_SESSION[$cacheKey]) && is_array($_SESSION[$cacheKey])) {
			return $_SESSION[$cacheKey];
		}

		$data = $this->request($params, 15);
		$seriesList = is_array($data) ? $data : [];

		$result = [];
		foreach ($seriesList as $s) {
			$s = self::fields($s);
			if (empty($s['series_id'])) {
				continue;
			}
			$seriesId = (int) $s['series_id'];
			$result[] = [
				'id' => $seriesId,
				'series_id' => $seriesId,
				'title' => (string) ($s['name'] ?? ('Series #' . $seriesId)),
				'stream_display_name' => (string) ($s['name'] ?? ('Series #' . $seriesId)),
				'cover' => $s['cover'] ?? '',
				'plot' => $s['plot'] ?? '',
				'cast' => $s['cast'] ?? '',
				'director' => $s['director'] ?? '',
				'genre' => $s['genre'] ?? '',
				'year' => !empty($s['releaseDate']) ? substr((string) $s['releaseDate'], 0, 4) : null,
				'rating' => $s['rating'] ?? '',
				'category_id' => (int) ($s['category_id'] ?? 0),
			];
		}

		$_SESSION[$cacheKey] = $result;
		return $result;
	}

	/**
	 * Fetch Series detailed info and its episodes, by season.
	 */
	public function getSeriesInfo(int $seriesId): array {
		$data = $this->request([
			'action' => 'get_series_info',
			'series_id' => $seriesId,
		], 12);

		$episodes = [];
		foreach (is_array($data['episodes'] ?? null) ? $data['episodes'] : [] as $season => $list) {
			$episodes[$season] = [];
			foreach (is_array($list) ? $list : [] as $episode) {
				if (is_array($episode)) {
					$episodes[$season][] = ['info' => self::fields($episode['info'] ?? null)] + self::fields($episode);
				}
			}
		}

		return [
			'info' => self::fields($data['info'] ?? null),
			'episodes' => $episodes,
		];
	}

	/**
	 * Fetch short EPG data for channel.
	 */
	public function getShortEpg(int $streamId, int $limit = 10): array {
		$data = $this->request([
			'action' => 'get_short_epg',
			'stream_id' => $streamId,
			'limit' => $limit,
		], 8);

		return is_array($data) ? ($data['epg_listings'] ?? []) : [];
	}

	/**
	 * Build direct live playback URL.
	 */
	public function buildLiveUrl(int|string $streamId): string {
		return $this->serverUrl . '/live/' . $this->username . '/' . $this->password . '/' . $streamId . '.m3u8';
	}

	/**
	 * Build direct VOD playback URL.
	 */
	public function buildVodUrl(int|string $streamId, string $container = 'mp4'): string {
		$ext = ltrim($container ?: 'mp4', '.');
		return $this->serverUrl . '/movie/' . $this->username . '/' . $this->password . '/' . $streamId . '.' . $ext;
	}

	/**
	 * Build direct Series episode playback URL.
	 */
	public function buildSeriesUrl(int|string $streamId, string $container = 'mp4'): string {
		$ext = ltrim($container ?: 'mp4', '.');
		return $this->serverUrl . '/series/' . $this->username . '/' . $this->password . '/' . $streamId . '.' . $ext;
	}

	/**
	 * Clear all in-session external caches.
	 */
	public static function clearCache(): void {
		if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
			@session_start();
		}

		foreach (array_keys($_SESSION) as $key) {
			if (str_starts_with($key, 'ext_')) {
				unset($_SESSION[$key]);
			}
		}
	}
}
