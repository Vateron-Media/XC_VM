<?php

namespace XcVm\Core\Util;

/**
 * ImageResizeService — HTTP image resize handler
 *
 * Resizes a remote/local image on demand, caches the result as PNG,
 * and outputs the file directly to the HTTP response.
 *
 * Caller sets up options and delegates:
 *
 *   ImageResizeService::serve([
 *     'cacheDir'     => IMAGES_PATH . 'admin/',   // required
 *     'placeholder'  => null,                     // optional path, null → 1×1 transparent
 *     'extraParams'  => false,                    // true → support ?w, ?h, ?icon (player)
 *   ]);
 *
 * @package XC_VM_Core_Util
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ImageResizeService {
	/** Longest side of an output image, in pixels, whatever the request asks for. */
	private const MAX_SIDE = 1920;

	/** Largest remote body taken, in bytes. */
	private const MAX_BODY_BYTES = 16 * 1024 * 1024;

	/** Most pixels a remote source may have to be decoded (a 4K frame is 8.3 million). */
	private const MAX_SOURCE_PIXELS = 12000000;

	/**
	 * Process resize request and send image response.
	 *
	 * @param array $rOptions {
	 *   string      cacheDir    Cache directory (trailing slash). Default: IMAGES_PATH . 'admin/'
	 *   string|null placeholder Path to placeholder file. Default: null (1×1 transparent)
	 *   bool        extraParams Support ?w, ?h, ?icon params (player panel). Default: false
	 * }
	 */
	public static function serve(array $rOptions = []): void {
		set_time_limit(15);
		ini_set('default_socket_timeout', 10);
		ini_set('user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

		$rCacheDir     = $rOptions['cacheDir'] ?? (defined('IMAGES_PATH') ? IMAGES_PATH . 'admin/' : '');
		$rPlaceholder  = $rOptions['placeholder'] ?? null;
		$rExtraParams  = $rOptions['extraParams'] ?? false;

		if ($rCacheDir && !is_dir($rCacheDir)) {
			@mkdir($rCacheDir, 0755, true);
		}

		if (empty($_GET['url']) && !empty($_SERVER['REQUEST_URI']) && str_contains($_SERVER['REQUEST_URI'], '?')) {
			$qStr = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
			if ($qStr !== '') {
				parse_str($qStr, $parsedParams);
				$_GET = array_merge($parsedParams, $_GET);
			}
		}

		$rURL  = $_GET['url'] ?? '';
		$rMaxW = 0;
		$rMaxH = 0;

		if (isset($_GET['maxw'])) {
			$rMaxW = intval($_GET['maxw']);
		}
		if (isset($_GET['maxh'])) {
			$rMaxH = intval($_GET['maxh']);
		}
		if (isset($_GET['max'])) {
			$rMaxW = intval($_GET['max']);
			$rMaxH = intval($_GET['max']);
		}

		$rImageSize = null;

		if ($rExtraParams) {
			$reqW = isset($_GET['w']) ? intval($_GET['w']) : (isset($_GET['width']) ? intval($_GET['width']) : 0);
			$reqH = isset($_GET['h']) ? intval($_GET['h']) : (isset($_GET['height']) ? intval($_GET['height']) : 0);

			if ($reqW > 0 && $reqH > 0) {
				$rImageSize = ['width' => $reqW, 'height' => $reqH];
			} elseif ($reqW > 0) {
				$rMaxW = $reqW;
				$rMaxH = $reqW * 2;
			} elseif ($reqH > 0) {
				$rMaxH = $reqH;
				$rMaxW = $reqH * 2;
			}

			if (isset($_GET['icon'])) {
				$rMaxH = $rMaxW = 48;
			}
		}

		if ($rMaxW > 0 && $rMaxH === 0) {
			$rMaxH = $rMaxW * 2;
		} elseif ($rMaxH > 0 && $rMaxW === 0) {
			$rMaxW = $rMaxH * 2;
		} elseif ($rMaxW === 0 && $rMaxH === 0 && $rImageSize === null) {
			$rMaxW = 600;
			$rMaxH = 900;
		}

		// The canvas and the cache file name follow these sizes: cap every side.
		$rMaxW = min($rMaxW, self::MAX_SIDE);
		$rMaxH = min($rMaxH, self::MAX_SIDE);
		if ($rImageSize !== null) {
			$rImageSize = ['width' => min($rImageSize['width'], self::MAX_SIDE), 'height' => min($rImageSize['height'], self::MAX_SIDE)];
		}

		// Resolve server-prefixed URL (s:<id>:<path>) only when needed. A URL
		// resolved from the admin-configured server list is trusted (it may point
		// at a private LB address); a raw user-supplied URL is not (SSRF risk).
		$rTrustedSource = false;
		if (substr($rURL, 0, 2) === 's:') {
			$rServers = $GLOBALS['rServers'] ?? null;
			if ($rServers === null && class_exists(\XcVm\Domain\Server\ServerRepository::class)) {
				try {
					$rServers = \XcVm\Domain\Server\ServerRepository::getAll();
				} catch (\Throwable $e) {
					$rServers = [];
				}
			}
			$rSplit    = explode(':', $rURL, 3);
			$rServerID = intval($rSplit[1] ?? 0);
			if (isset($rServers[$rServerID])) {
				$rSrv      = $rServers[$rServerID];
				$rDomain   = empty($rSrv['domain_name'])
					? $rSrv['server_ip']
					: explode(',', $rSrv['domain_name'])[0];
				$rProtocol = (!empty($rSrv['server_protocol']) ? $rSrv['server_protocol'] : 'http');
				$rPort = intval($rSrv['request_port'] ?? 0);
				$rServerURL = $rProtocol . '://' . $rDomain;
				if (0 < $rPort) {
					$rServerURL .= ':' . $rPort;
				}
				$rServerURL .= '/';
				$rURL = $rServerURL . 'images/' . basename($rURL);
				$rTrustedSource = true;
			}
		}

		header('Content-Type: image/png');
		header('X-Content-Type-Options: nosniff');

		if ($rURL && ($rMaxW > 0 && $rMaxH > 0 || $rImageSize !== null)) {
			$wKey = $rImageSize ? $rImageSize['width'] : $rMaxW;
			$hKey = $rImageSize ? $rImageSize['height'] : $rMaxH;
			$rImagePath = $rCacheDir . md5($rURL) . '_' . $wKey . '_' . $hKey . '.png';

			if (!file_exists($rImagePath) || filesize($rImagePath) === 0) {
				$rActURL = ImageUtils::isAbsoluteUrl($rURL)
					? $rURL
					: (defined('IMAGES_PATH') ? IMAGES_PATH . basename($rURL) : $rURL);

				$rImage = null;
				$rawImageData = null;

				if (ImageUtils::isAbsoluteUrl($rActURL)) {
					// null = the SSRF guard refused it or the fetch failed, '' = empty
					// body. Either way $rImage stays null and the placeholder tail below
					// takes over, so neither needs a branch of its own.
					$rawImageData = self::fetchRemoteImage($rActURL, $rTrustedSource);
					if (!empty($rawImageData)) {
						// Decoding takes memory by the pixel, however small the file is:
						// decode only a source whose size is known and within the limit.
						$rSourceSize = @getimagesizefromstring($rawImageData);
						if ($rSourceSize && $rSourceSize[0] > 0 && $rSourceSize[1] > 0 && $rSourceSize[0] * $rSourceSize[1] <= self::MAX_SOURCE_PIXELS) {
							$rImage = @imagecreatefromstring($rawImageData);
						}
					}
				} else {
					if (file_exists($rActURL)) {
						$rawImageData = @file_get_contents($rActURL);
						if ($rawImageData !== false && $rawImageData !== '') {
							$rImage = @imagecreatefromstring($rawImageData);
						}
					}
				}

				if ($rImage) {
					$origW = imagesx($rImage);
					$origH = imagesy($rImage);

					if ($rImageSize === null) {
						$rImageSize = ImageUtils::getImageSizeKeepAspectRatio(
							$origW,
							$origH,
							$rMaxW,
							$rMaxH
						);
					}

					if (!empty($rImageSize['width']) && !empty($rImageSize['height'])) {
						$rImageP = imagecreatetruecolor($rImageSize['width'], $rImageSize['height']);
						imagealphablending($rImageP, false);
						imagesavealpha($rImageP, true);
						imagecopyresampled(
							$rImageP,
							$rImage,
							0,
							0,
							0,
							0,
							$rImageSize['width'],
							$rImageSize['height'],
							$origW,
							$origH
						);
						@imagepng($rImageP, $rImagePath);
						imagedestroy($rImageP);
					}
					imagedestroy($rImage);
				} elseif (is_string($rawImageData) && strlen($rawImageData) > 12 && substr($rawImageData, 0, 4) === 'RIFF' && substr($rawImageData, 8, 4) === 'WEBP') {
					// GD could not decode it and the bytes are WebP — the bundled PHP GD
					// has no WebP support, so cache the raw file and let the browser,
					// which renders WebP natively, do the decoding.
					$webpCache = $rCacheDir . md5($rURL) . '.webp';
					@file_put_contents($webpCache, $rawImageData);
					header('Content-Type: image/webp');
					header('X-Content-Type-Options: nosniff');
					header('Content-Length: ' . strlen($rawImageData));
					header('Cache-Control: public, max-age=604800');
					// nosemgrep: php.lang.security.injection.echoed-request.echoed-request
					echo $rawImageData;
					exit();
				}
			}

			if (file_exists($rImagePath) && filesize($rImagePath) > 0) {
				header('Content-Length: ' . filesize($rImagePath));
				header('Cache-Control: public, max-age=604800');
				readfile($rImagePath);
				exit();
			}
		}

		// Nothing served yet: fall back to the placeholder, else a 1x1 transparent PNG.
		if ($rPlaceholder && file_exists($rPlaceholder) && !isset($_GET['icon'])) {
			header('Content-Length: ' . filesize($rPlaceholder));
			header('Cache-Control: public, max-age=86400');
			readfile($rPlaceholder);
			exit();
		}

		$rImg = imagecreatetruecolor(1, 1);
		imagesavealpha($rImg, true);
		imagefill($rImg, 0, 0, imagecolorallocatealpha($rImg, 0, 0, 0, 127));
		imagepng($rImg);
		imagedestroy($rImg);
		exit();
	}

	/**
	 * SSRF guard for outbound image fetches — returns the URL host's validated
	 * public IPs, or null if the URL is not http(s), the host does not resolve,
	 * or any resolved address is loopback/private/reserved/link-local (cloud
	 * metadata) or CGNAT. The IPs are returned so the fetch can pin them and
	 * never re-resolve (DNS rebinding / TOCTOU).
	 *
	 * @return list<string>|null
	 */
	private static function resolvePublicIps(string $url): ?array {
		$scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
		if ($scheme !== 'http' && $scheme !== 'https') {
			return null;
		}
		$host = parse_url($url, PHP_URL_HOST);
		if (!is_string($host) || $host === '') {
			return null;
		}
		$host = trim($host, '[]');

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
	 * Fetch a remote image via cURL.
	 *
	 * Raw user URL ($trusted = false): the host is SSRF-validated and the vetted
	 * IPs are pinned with CURLOPT_RESOLVE so libcurl cannot re-resolve to an
	 * internal address after the check (DNS rebinding); TLS is verified and
	 * redirects are refused. Admin-configured server URL ($trusted = true):
	 * self-signed internal certs are tolerated.
	 *
	 * @return string|null Raw bytes, or null on failure / blocked host.
	 */
	private static function fetchRemoteImage(string $url, bool $trusted): ?string {
		$scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
		if ($scheme !== 'http' && $scheme !== 'https') {
			return null;
		}

		$opts = [
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 10,
			CURLOPT_CONNECTTIMEOUT => 6,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
			CURLOPT_HTTPHEADER     => ['Accept: image/jpeg,image/png,image/*;q=0.5'],
			// Give up on a body over MAX_BODY_BYTES, announced or already received.
			CURLOPT_NOPROGRESS       => false,
			CURLOPT_PROGRESSFUNCTION => static fn($ch, $dlTotal, $dlNow): int => (int) (max($dlTotal, $dlNow) > self::MAX_BODY_BYTES),
		];

		if ($trusted) {
			// Admin-configured internal server: self-signed certs are expected.
			$opts[CURLOPT_SSL_VERIFYPEER] = false;
			$opts[CURLOPT_SSL_VERIFYHOST] = false;
		} else {
			$safeIps = self::resolvePublicIps($url);
			if ($safeIps === null) {
				return null;
			}
			$opts[CURLOPT_SSL_VERIFYPEER] = true;
			$opts[CURLOPT_SSL_VERIFYHOST] = 2;
			$host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
			if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) === false) {
				$port = (int) (parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));
				$opts[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . implode(',', $safeIps)];
			}
		}

		$ch = curl_init();
		curl_setopt_array($ch, $opts);
		$data = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($data === false || $code < 200 || $code >= 400) {
			return null;
		}

		return (string) $data;
	}
}
