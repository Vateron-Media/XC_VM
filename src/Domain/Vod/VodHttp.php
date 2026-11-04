<?php

namespace XcVm\Domain\Vod;

use XcVm\Core\Util\PublicAddress;
use XcVm\Core\Util\NetworkUtils;

/** HTTP for untrusted playlists/media: validate and pin DNS on every hop. */
final class VodHttp {
	private static $rResolver = null;

	private static $rTransport = null;

	/** Test seams; resolved addresses still pass the production address policy. */
	public static function useResolver(?callable $rResolver): void {
		self::$rResolver = $rResolver;
	}

	public static function useTransport(?callable $rTransport): void {
		self::$rTransport = $rTransport;
	}

	public static function addresses(string $rHost): array {
		$rHost = strtolower(trim($rHost, '[]'));
		if (filter_var($rHost, FILTER_VALIDATE_IP)) {
			$rIPs = [$rHost];
		} elseif (self::$rResolver !== null) {
			$rIPs = (self::$rResolver)($rHost);
		} else {
			$rIPs = [];
			foreach (@dns_get_record($rHost, DNS_A | DNS_AAAA) ?: [] as $rRecord) {
				$rIP = $rRecord['ip'] ?? $rRecord['ipv6'] ?? '';
				if ($rIP !== '') {
					$rIPs[] = $rIP;
				}
			}
			if ($rIPs === []) {
				$rIPs = @gethostbynamel($rHost) ?: [];
			}
		}
		if ($rIPs === []) {
			throw new \InvalidArgumentException('vod_m3u_invalid');
		}
		foreach ($rIPs as $rIP) {
			if (!PublicAddress::isPublic($rIP) || (filter_var($rIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && NetworkUtils::ipInAnyCIDR($rIP, ['192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4'])) || (filter_var($rIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && (!NetworkUtils::ipInCIDR($rIP, '2000::/3') || NetworkUtils::ipInAnyCIDR($rIP, ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'])))) {
				throw new \InvalidArgumentException('vod_m3u_invalid');
			}
		}
		return array_values(array_unique($rIPs));
	}

	/** RFC 3986 relative references, including queries and network-path URLs. */
	public static function resolve(string $rBase, string $rReference): string {
		$rReference = explode('#', trim($rReference), 2)[0];
		if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $rReference)) {
			return $rReference;
		}
		$rBaseParts = parse_url($rBase);
		if (!is_array($rBaseParts) || empty($rBaseParts['host'])) {
			return $rReference;
		}
		if (str_starts_with($rReference, '//')) {
			return $rBaseParts['scheme'] . ':' . $rReference;
		}
		$rParts = parse_url($rReference);
		if ($rParts === false) {
			throw new \InvalidArgumentException('vod_m3u_invalid');
		}
		$rAuthority = isset($rBaseParts['user']) ? $rBaseParts['user'] . (isset($rBaseParts['pass']) ? ':' . $rBaseParts['pass'] : '') . '@' : '';
		$rAuthority .= $rBaseParts['host'] . (isset($rBaseParts['port']) ? ':' . $rBaseParts['port'] : '');
		$rPath = $rParts['path'] ?? '';
		if ($rPath === '') {
			$rPath = $rBaseParts['path'] ?? '/';
			$rQuery = $rParts['query'] ?? $rBaseParts['query'] ?? null;
		} else {
			$rQuery = $rParts['query'] ?? null;
			if ($rPath[0] !== '/') {
				$rPath = preg_replace('#[^/]*$#', '', $rBaseParts['path'] ?? '/') . $rPath;
			}
		}
		$rSegments = [];
		foreach (explode('/', $rPath) as $rSegment) {
			if ($rSegment === '..') {
				array_pop($rSegments);
			} elseif ($rSegment !== '.') {
				$rSegments[] = $rSegment;
			}
		}
		$rPath = '/' . ltrim(implode('/', $rSegments), '/');
		if (preg_match('#/(?:\.|\.\.)$#', $rParts['path'] ?? '') && !str_ends_with($rPath, '/')) {
			$rPath .= '/';
		}
		return $rBaseParts['scheme'] . '://' . $rAuthority . $rPath . ($rQuery !== null ? '?' . $rQuery : '');
	}

	/** Body callbacks run only for a successful response, never a redirect body. */
	public static function get(string $rURL, array $rOptions, callable $rWrite): array {
		$rDeadline = microtime(true) + max(1, (int) ($rOptions[CURLOPT_TIMEOUT] ?? 30));
		for ($rHop = 0; $rHop <= 5; $rHop++) {
			if (!VodM3uImport::httpUrl($rURL)) {
				throw new \InvalidArgumentException('vod_m3u_invalid');
			}
			$rHost = strtolower(trim((string) parse_url($rURL, PHP_URL_HOST), '[]'));
			$rIPs = self::addresses($rHost);
			$rPort = (int) (parse_url($rURL, PHP_URL_PORT) ?: (strtolower((string) parse_url($rURL, PHP_URL_SCHEME)) === 'https' ? 443 : 80));
			$rCode = 0;
			$rLocation = '';
			$rType = '';
			$rTimeout = (int) ceil($rDeadline - microtime(true));
			if ($rTimeout <= 0) {
				return ['ok' => false, 'url' => $rURL, 'content_type' => '', 'code' => 0];
			}
			$rOptions = array_replace($rOptions, [
				CURLOPT_URL => $rURL, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
				CURLOPT_PROXY => '', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_TIMEOUT => $rTimeout,
				CURLOPT_HEADERFUNCTION => static function ($rCurl, string $rHeader) use (&$rCode, &$rLocation, &$rType): int {
					if (preg_match('#^HTTP/\S+\s+(\d+)#i', $rHeader, $rMatch)) {
						$rCode = (int) $rMatch[1];
						$rLocation = $rType = '';
					} elseif (stripos($rHeader, 'Location:') === 0) {
						$rLocation = trim(substr($rHeader, 9));
					} elseif (stripos($rHeader, 'Content-Type:') === 0) {
						$rType = trim(substr($rHeader, 13));
					}
					return strlen($rHeader);
				},
			]);
			// Capture the live status, not the value of $rCode before headers arrive.
			$rOptions[CURLOPT_WRITEFUNCTION] = static function ($rCurl, string $rChunk) use (&$rCode, $rWrite): int {
				return $rCode >= 200 && $rCode < 300 ? $rWrite($rChunk) : 0;
			};
			$rOptions[CURLOPT_RESOLVE] = filter_var($rHost, FILTER_VALIDATE_IP) ? [] : [$rHost . ':' . $rPort . ':' . implode(',', array_map(static fn(string $rIP): string => PublicAddress::forUrl($rIP), $rIPs))];
			if (self::$rTransport !== null) {
				$rOK = (self::$rTransport)($rURL, $rOptions);
			} else {
				$rCurl = curl_init();
				curl_setopt_array($rCurl, $rOptions);
				$rOK = curl_exec($rCurl);
				curl_close($rCurl);
			}
			if (in_array($rCode, [301, 302, 303, 307, 308], true) && $rLocation !== '' && $rHop < 5) {
				$rURL = self::resolve($rURL, $rLocation);
				continue;
			}
			return ['ok' => $rOK !== false && $rCode >= 200 && $rCode < 300, 'url' => $rURL, 'content_type' => $rType, 'code' => $rCode];
		}
		return ['ok' => false, 'url' => $rURL, 'content_type' => '', 'code' => 0];
	}
}
