<?php

namespace XcVm\Streaming\Fanout;

use XcVm\Streaming\Codec\FfmpegPaths;

/**
 * FanoutClient — PHP-side control client for the xc_fanout daemon (ADR 0002, P2).
 *
 * The daemon exposes a PHP-only control surface on a unix socket
 * (FANOUT_CTL_SOCK): `PUT /streams/<id>` registers/updates a stream's pull
 * config, `DELETE /streams/<id>` removes it. The daemon owns the puller and
 * starts it on the first viewer, so registering is idempotent and cheap — it
 * only stores the source config; no bytes flow until nginx proxies a viewer to
 * `/live/<id>` on the client socket.
 *
 * This mirrors ProxyCommand's source resolution: the daemon reimplements
 * `getActiveStream` (direct video/mp2t vs ffmpeg remux) in Go, so PHP only needs
 * to hand it the source URLs and the per-stream user_agent/proxy/cookie.
 *
 * @package XC_VM_Streaming_Fanout
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class FanoutClient {
	/** Default source User-Agent, matching ProxyCommand::startProxy(). */
	private const DEFAULT_UA = 'Mozilla/5.0';

	/**
	 * Build the daemon source config from a `streams` row and its keyed
	 * `streams_arguments` (as ProxyCommand reads them). Pure function — no I/O —
	 * so it is unit-testable against the exact shapes ProxyCommand uses.
	 *
	 * @param array $rStreamRow       Row from `streams` (needs `stream_source`).
	 * @param array $rStreamArguments Rows from streams_options⨝streams_arguments,
	 *                                keyed by `argument_key` (user_agent/proxy/cookie).
	 * @return array{urls:string[],ua:string,proxy:string,cookie:string,ffmpeg:string}
	 */
	public static function buildSource(array $rStreamRow, array $rStreamArguments): array {
		$rURLs = [];
		if (!empty($rStreamRow['stream_source'])) {
			$rDecoded = json_decode($rStreamRow['stream_source'], true);
			if (is_array($rDecoded)) {
				foreach ($rDecoded as $rURL) {
					$rURL = trim((string) $rURL);
					if ($rURL !== '') {
						$rURLs[] = $rURL;
					}
				}
			}
		}

		$rUA = self::DEFAULT_UA;
		if (isset($rStreamArguments['user_agent'])) {
			$rUA = ($rStreamArguments['user_agent']['value']
				?: ($rStreamArguments['user_agent']['argument_default_value'] ?? self::DEFAULT_UA));
		}

		$rProxy = '';
		if (!empty($rStreamArguments['proxy']['value'])) {
			$rProxy = (string) $rStreamArguments['proxy']['value'];
		}

		$rCookie = '';
		if (!empty($rStreamArguments['cookie']['value'])) {
			$rCookie = (string) $rStreamArguments['cookie']['value'];
		}

		return [
			'urls'   => $rURLs,
			'ua'     => (string) $rUA,
			'proxy'  => $rProxy,
			'cookie' => $rCookie,
			'ffmpeg' => FfmpegPaths::cpu(),
		];
	}

	/**
	 * Register (or update) a stream's pull config with the daemon.
	 *
	 * @param int   $rStreamID Stream id (the daemon key and the `/live/<id>` path).
	 * @param array $rSource   Config from {@see buildSource()}; empty `urls` is rejected.
	 * @return bool True when the daemon accepted the registration (HTTP 204).
	 */
	public static function register(int $rStreamID, array $rSource): bool {
		if (empty($rSource['urls'])) {
			return false;
		}

		$rBody = json_encode($rSource);
		if ($rBody === false) {
			return false;
		}

		return self::call('PUT', $rStreamID, $rBody);
	}

	/**
	 * Put a stream into push-fed (ingest) mode and return the unix socket the
	 * producer must connect to. Used by StreamProcess for non-proxy live streams:
	 * the daemon starts listening on the returned socket, then StreamProcess
	 * launches the stream's ffmpeg with a `-f tee … unix:<socket>` output.
	 *
	 * Returns null if the daemon is unreachable / declines — the caller then
	 * launches ffmpeg legacy-only (no tee), the daemon-reachability rollback.
	 *
	 * @param int $rStreamID Stream id.
	 * @return string|null The ingest socket path, or null on failure.
	 */
	public static function registerIngest(int $rStreamID): ?string {
		if (!function_exists('curl_init') || !defined('FANOUT_CTL_SOCK') || !file_exists(FANOUT_CTL_SOCK)) {
			return null;
		}

		$rCurl = curl_init();
		curl_setopt_array($rCurl, [
			CURLOPT_UNIX_SOCKET_PATH => FANOUT_CTL_SOCK,
			CURLOPT_URL            => 'http://localhost/ingest/' . $rStreamID,
			CURLOPT_CUSTOMREQUEST  => 'PUT',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 2,
			CURLOPT_TIMEOUT        => 3,
		]);
		$rBody = curl_exec($rCurl);
		$rCode = curl_getinfo($rCurl, CURLINFO_HTTP_CODE);
		curl_close($rCurl);

		if ($rCode < 200 || $rCode >= 300 || !is_string($rBody)) {
			return null;
		}

		$rData = json_decode($rBody, true);
		$rSocket = (is_array($rData) && !empty($rData['socket'])) ? (string) $rData['socket'] : '';

		return ($rSocket !== '' && file_exists($rSocket)) ? $rSocket : null;
	}

	/**
	 * Prewarm a registered (proxy) stream and wait up to $rWaitMs for its source
	 * to produce data, returning whether it did (ADR 0003, Phase C off-air). The
	 * daemon starts the puller and blocks until data or the timeout; PHP shows
	 * the not-on-air page when this returns false, matching the legacy startProxy
	 * behaviour on a dead source.
	 *
	 * @param int $rStreamID Stream id (must already be registered).
	 * @param int $rWaitMs   Max wait for first data, milliseconds.
	 * @return bool True when the daemon reports has_data within the window.
	 */
	public static function probe(int $rStreamID, int $rWaitMs): bool {
		if (!function_exists('curl_init') || !defined('FANOUT_CTL_SOCK') || !file_exists(FANOUT_CTL_SOCK)) {
			return false;
		}

		$rCurl = curl_init();
		curl_setopt_array($rCurl, [
			CURLOPT_UNIX_SOCKET_PATH => FANOUT_CTL_SOCK,
			CURLOPT_URL            => 'http://localhost/probe/' . $rStreamID . '?wait=' . intval($rWaitMs),
			CURLOPT_POST           => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 2,
			CURLOPT_TIMEOUT        => intval(ceil($rWaitMs / 1000)) + 3,
		]);
		$rBody = curl_exec($rCurl);
		$rCode = curl_getinfo($rCurl, CURLINFO_HTTP_CODE);
		curl_close($rCurl);

		if ($rCode !== 200 || !is_string($rBody)) {
			return false;
		}

		$rData = json_decode($rBody, true);

		return is_array($rData) && !empty($rData['has_data']);
	}

	/**
	 * Whether the daemon is currently serving this stream with live data — i.e.
	 * a puller/ingest is producing bytes. Used by live.php to decide whether to
	 * hand a non-proxy viewer to the daemon (A3) or fall back to the legacy feed.
	 *
	 * @param int $rStreamID Stream id.
	 * @return bool True when the daemon reports has_data for the stream.
	 */
	public static function isStreamFed(int $rStreamID): bool {
		if (!function_exists('curl_init') || !defined('FANOUT_CTL_SOCK') || !file_exists(FANOUT_CTL_SOCK)) {
			return false;
		}

		$rCurl = curl_init();
		curl_setopt_array($rCurl, [
			CURLOPT_UNIX_SOCKET_PATH => FANOUT_CTL_SOCK,
			CURLOPT_URL            => 'http://localhost/streams/' . $rStreamID,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 1,
			CURLOPT_TIMEOUT        => 2,
		]);
		$rBody = curl_exec($rCurl);
		$rCode = curl_getinfo($rCurl, CURLINFO_HTTP_CODE);
		curl_close($rCurl);

		if ($rCode !== 200 || !is_string($rBody)) {
			return false;
		}

		$rData = json_decode($rBody, true);

		return is_array($rData) && !empty($rData['has_data']);
	}

	/**
	 * Fetch the daemon's in-RAM HLS playlist for a stream (ADR 0003, Phase B),
	 * from the nginx-facing client socket. The returned m3u8 lists segments by
	 * sequence (`<seq>.ts`); live.php tokenizes those into auth'd URLs.
	 *
	 * @param int $rStreamID Stream id.
	 * @return string|null The raw m3u8, or null if unavailable.
	 */
	public static function hlsPlaylist(int $rStreamID): ?string {
		if (!function_exists('curl_init') || !defined('FANOUT_HTTP_SOCK') || !file_exists(FANOUT_HTTP_SOCK)) {
			return null;
		}

		$rCurl = curl_init();
		curl_setopt_array($rCurl, [
			CURLOPT_UNIX_SOCKET_PATH => FANOUT_HTTP_SOCK,
			CURLOPT_URL            => 'http://localhost/hls/' . $rStreamID . '/index.m3u8',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 1,
			CURLOPT_TIMEOUT        => 2,
		]);
		$rBody = curl_exec($rCurl);
		$rCode = curl_getinfo($rCurl, CURLINFO_HTTP_CODE);
		curl_close($rCurl);

		return ($rCode === 200 && is_string($rBody) && $rBody !== '') ? $rBody : null;
	}

	/**
	 * Unregister a stream (stops its puller / ingest listener, drops it from the
	 * daemon). Works for both pull-fed (proxy) and push-fed (ingest) streams.
	 *
	 * @param int $rStreamID Stream id.
	 * @return bool True when the daemon accepted the removal (HTTP 204).
	 */
	public static function unregister(int $rStreamID): bool {
		return self::call('DELETE', $rStreamID, null);
	}

	/**
	 * Issue one control request over the daemon's unix socket via cURL.
	 *
	 * @param string      $rMethod HTTP method (PUT/DELETE).
	 * @param int         $rStreamID Stream id.
	 * @param string|null $rBody   JSON body for PUT, or null.
	 * @return bool True on a 2xx (specifically 204) response.
	 */
	private static function call(string $rMethod, int $rStreamID, ?string $rBody): bool {
		if (!function_exists('curl_init') || !defined('FANOUT_CTL_SOCK') || !file_exists(FANOUT_CTL_SOCK)) {
			return false;
		}

		$rCurl = curl_init();
		curl_setopt_array($rCurl, [
			CURLOPT_UNIX_SOCKET_PATH => FANOUT_CTL_SOCK,
			// Host is irrelevant over a unix socket, but cURL needs a valid URL.
			CURLOPT_URL            => 'http://localhost/streams/' . $rStreamID,
			CURLOPT_CUSTOMREQUEST  => $rMethod,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 2,
			CURLOPT_TIMEOUT        => 3,
		]);

		if ($rBody !== null) {
			curl_setopt($rCurl, CURLOPT_POSTFIELDS, $rBody);
			curl_setopt($rCurl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
		}

		curl_exec($rCurl);
		$rCode = curl_getinfo($rCurl, CURLINFO_HTTP_CODE);
		curl_close($rCurl);

		return $rCode >= 200 && $rCode < 300;
	}
}
