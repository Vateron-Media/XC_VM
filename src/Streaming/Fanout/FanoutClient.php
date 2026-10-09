<?php

namespace XcVm\Streaming\Fanout;

use XcVm\Core\Util\AtomicFile;
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

	/** What the last monitorStates() call reported the daemon can be handed. */
	private static ?array $features = null;

	private static ?string $rFilesDir = null;

	private static ?string $rFeaturesCache = null;

	/**
	 * Whether the control socket may be used: fanout is switched on (FanoutMode)
	 * and the daemon's socket exists. With fanout off every call below behaves
	 * as if the daemon were down, which is the path the panel already takes.
	 */
	private static function socketReady(): bool {
		return FanoutMode::enabled() && defined('FANOUT_CTL_SOCK') && file_exists(FANOUT_CTL_SOCK);
	}

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

		// The daemon takes host:port and adds http:// itself, so the scheme a
		// panel value may carry (http://user:pass@host:port) is cut here.
		$rProxy = '';
		if (!empty($rStreamArguments['proxy']['value'])) {
			$rProxy = rtrim((string) preg_replace('#^https?://#i', '', trim((string) $rStreamArguments['proxy']['value'])), '/');
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
	 * @param int         $rStreamID Stream id.
	 * @param string|null $rKeyHex   Hex AES-128 key for encrypted HLS, or null.
	 * @param string|null $rIVHex    Hex AES-128-CBC IV, or null.
	 * @return string|null The ingest socket path, or null on failure.
	 */
	public static function registerIngest(int $rStreamID, ?string $rKeyHex = null, ?string $rIVHex = null): ?string {
		if (!function_exists('curl_init') || !self::socketReady()) {
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

		if (!empty($rKeyHex) && !empty($rIVHex)) {
			curl_setopt($rCurl, CURLOPT_POSTFIELDS, json_encode(['key' => $rKeyHex, 'iv' => $rIVHex]));
			curl_setopt($rCurl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
		}
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
		if (!function_exists('curl_init') || !self::socketReady()) {
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
		if (!function_exists('curl_init') || !self::socketReady()) {
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
	 * Whether the daemon is reachable but has NO record of this stream (control
	 * GET /streams/<id> → 404). This is the orphaned-ingest signal after a daemon
	 * restart (ADR 0003, C-ops): the daemon wiped its in-memory registry, so a
	 * still-running ffmpeg tee writes into a now-dead socket (onfail=ignore) and
	 * the stream is off the daemon until re-registered. Distinct from
	 * {@see isStreamFed()} (which is false for 404, unreachable AND no-data
	 * alike): here only a real 404 from a reachable daemon returns true. Returns
	 * false when the daemon is unreachable (legacy is then the intended path,
	 * nothing to re-feed) or already knows the stream.
	 *
	 * @param int $rStreamID Stream id.
	 * @return bool True only when a reachable daemon answered 404 for this stream.
	 */
	public static function daemonStreamMissing(int $rStreamID): bool {
		if (!function_exists('curl_init') || !self::socketReady()) {
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
		curl_exec($rCurl);
		$rCode = curl_getinfo($rCurl, CURLINFO_HTTP_CODE);
		$rErrno = curl_errno($rCurl);
		curl_close($rCurl);

		// A connect failure (daemon down / stale socket) leaves rErrno set and
		// rCode 0 → NOT "missing" (don't churn streams when the daemon is simply
		// off). Only a clean 404 from a live control socket is the re-feed signal.
		return $rErrno === 0 && $rCode === 404;
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
		// Fanout switched off: the daemon serves no playlist (as socketReady()).
		if (!function_exists('curl_init') || !FanoutMode::enabled() || !defined('FANOUT_HTTP_SOCK') || !file_exists(FANOUT_HTTP_SOCK)) {
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
	 * Health probe for the admin "Service Status" panel: is the local xc_fanout
	 * daemon up — control socket present AND answering? Reuses the /connections
	 * control call, so a live socket that responds means the daemon is serving.
	 *
	 * @return array{running:bool,socket:bool,connections:int|null,memory:array|null}
	 *   running     — socket answered (daemon up),
	 *   socket      — the control socket file exists (binary started at all),
	 *   connections — live TS viewers on the daemon, or null when unreachable,
	 *   memory      — where the daemon's memory is (see memory()), or null.
	 */
	public static function status(): array {
		$rSocket = defined('FANOUT_CTL_SOCK') && file_exists(FANOUT_CTL_SOCK);
		$rConns = self::activeConnections();
		$rRunning = $rConns !== null;
		return [
			'running'     => $rRunning,
			'socket'      => $rSocket,
			'connections' => is_array($rConns) ? count($rConns) : null,
			// Where the daemon's memory actually is. The join rings dominate its
			// RSS by design — one watched channel holds its whole prebuffer — so
			// a node running hot is answered by "the rings are as big as the
			// tuning says" or "something else is growing", and nothing else in
			// the panel could tell those apart. Skipped when the daemon is down,
			// so a dead socket costs no second request.
			'memory'      => $rRunning ? self::memory() : null,
		];
	}

	/**
	 * Where the daemon's memory is: the per-stream join rings against the Go
	 * heap as a whole.
	 *
	 * @return array{streams:int,ring_bytes:int,heap_in_use_bytes:int,mapped_bytes:int,released_bytes:int,largest:array}|null
	 *   null when the daemon is unreachable or answers something unreadable.
	 */
	public static function memory(): ?array {
		$rResponse = self::request('GET', '/memory', null, 1, 3);
		if ($rResponse['code'] !== 200 || $rResponse['body'] === null) {
			return null;
		}
		$rData = json_decode($rResponse['body'], true);
		return is_array($rData) ? $rData : null;
	}

	/**
	 * Tear down EVERY stream on the daemon in one call — a maintenance drain,
	 * or a node being decommissioned — and report how many were removed.
	 *
	 * The daemon logs each teardown, so this is an auditable operator action.
	 * Streams the panel still wants come back on the next request that
	 * registers them, since registration happens per request; this stops the
	 * fan-out now rather than scripting a DELETE per id.
	 *
	 * @return int|null Streams removed, or null when the daemon is unreachable.
	 */
	public static function unregisterAll(): ?int {
		$rResponse = self::request('DELETE', '/streams', null, 2, 5);
		if ($rResponse['code'] < 200 || $rResponse['code'] >= 300 || $rResponse['body'] === null) {
			return null;
		}
		$rData = json_decode($rResponse['body'], true);
		return is_array($rData) && isset($rData['unregistered']) ? intval($rData['unregistered']) : null;
	}

	/**
	 * Every live-TS viewer uuid currently connected to the daemon (across all
	 * streams), for the fanout_sync reconciler. Returns null when the daemon is
	 * unreachable — the caller must then skip reconciliation (an empty array
	 * would wrongly close every daemon connection).
	 *
	 * @return string[]|null Active connection uuids, or null on failure.
	 */
	public static function activeConnections(): ?array {
		if (!function_exists('curl_init') || !self::socketReady()) {
			return null;
		}

		$rCurl = curl_init();
		curl_setopt_array($rCurl, [
			CURLOPT_UNIX_SOCKET_PATH => FANOUT_CTL_SOCK,
			CURLOPT_URL            => 'http://localhost/connections',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 2,
			CURLOPT_TIMEOUT        => 3,
		]);
		$rBody = curl_exec($rCurl);
		$rCode = curl_getinfo($rCurl, CURLINFO_HTTP_CODE);
		curl_close($rCurl);

		if ($rCode !== 200 || !is_string($rBody)) {
			return null;
		}

		$rData = json_decode($rBody, true);

		return is_array($rData) ? $rData : null;
	}

	/**
	 * Disconnect a daemon-served live-TS viewer by its connection uuid (control
	 * DELETE /connections/<uuid>). Under X-Accel the PHP worker that admitted the
	 * viewer returned at hand-off, so this is the only way a connection-limit
	 * eviction or an admin kick can end the session — deleting the viewer's row
	 * alone left it streaming, untracked.
	 *
	 * @param string $rUUID Viewer connection uuid (the X-Accel `?c=` value).
	 * @return bool True when the daemon dropped a connection (204); false when the
	 *              uuid is not connected to this node's daemon, the daemon predates
	 *              the endpoint, or it is unreachable.
	 */
	public static function dropConnection(string $rUUID): bool {
		if ($rUUID === '') {
			return false;
		}
		return self::request('DELETE', '/connections/' . rawurlencode($rUUID), null, 1, 2)['code'] === 204;
	}

	/**
	 * Per-viewer average delivery rate (KB/s since attach), keyed by connection
	 * uuid, across all daemon streams (control GET /rates). This is the daemon
	 * replacement for the legacy chase-read loop's DIVERGENCE_TMP_PATH speed
	 * files: fanout_sync compares each rate to the stream's expected bitrate and
	 * records the divergence for daemon-served viewers (ADR 0003, P4). Returns
	 * null when the daemon is unreachable (the caller then skips the write).
	 *
	 * @return array<string,int>|null uuid => KB/s, or null on failure.
	 */
	public static function connectionRates(): ?array {
		if (!function_exists('curl_init') || !self::socketReady()) {
			return null;
		}

		$rCurl = curl_init();
		curl_setopt_array($rCurl, [
			CURLOPT_UNIX_SOCKET_PATH => FANOUT_CTL_SOCK,
			CURLOPT_URL            => 'http://localhost/rates',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 2,
			CURLOPT_TIMEOUT        => 3,
		]);
		$rBody = curl_exec($rCurl);
		$rCode = curl_getinfo($rCurl, CURLINFO_HTTP_CODE);
		curl_close($rCurl);

		if ($rCode !== 200 || !is_string($rBody)) {
			return null;
		}

		$rData = json_decode($rBody, true);

		return is_array($rData) ? $rData : null;
	}

	/**
	 * Queue an admin "send message" text overlay for one viewer on the daemon
	 * (control POST /signal/<uuid>). The daemon burns the banner onto that viewer's
	 * next HLS segment and a short live-TS window, then clears it — reproducing the
	 * legacy PHP byte-path overlay now that clients are served only by the daemon.
	 * Best-effort: a daemon that's down / has no font just drops it.
	 *
	 * @param string $rUUID   Viewer connection uuid.
	 * @param array  $rSignal Signal fields (message, font_size, font_color, xy_offset).
	 * @return bool True when the daemon accepted the signal (HTTP 2xx).
	 */
	public static function sendSignal(string $rUUID, array $rSignal): bool {
		if ($rUUID === '' || !function_exists('curl_init') || !self::socketReady()) {
			return false;
		}

		$rCurl = curl_init();
		curl_setopt_array($rCurl, [
			CURLOPT_UNIX_SOCKET_PATH => FANOUT_CTL_SOCK,
			CURLOPT_URL            => 'http://localhost/signal/' . rawurlencode($rUUID),
			CURLOPT_CUSTOMREQUEST  => 'POST',
			CURLOPT_POSTFIELDS     => json_encode([
				'message'    => (string) ($rSignal['message'] ?? ''),
				'font_size'  => intval($rSignal['font_size'] ?? 0),
				'font_color' => (string) ($rSignal['font_color'] ?? ''),
				'xy_offset'  => (string) ($rSignal['xy_offset'] ?? ''),
			]),
			CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 2,
			CURLOPT_TIMEOUT        => 3,
		]);
		curl_exec($rCurl);
		$rCode = curl_getinfo($rCurl, CURLINFO_HTTP_CODE);
		curl_close($rCurl);

		return $rCode >= 200 && $rCode < 300;
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

	// ── Encoder supervision (XC_VM_Fanout ADR 0002) ─────────────────────────
	//
	// The daemon's supervisor runs and watches a stream's producer — the ffmpeg
	// command StreamProcess::buildLive composes, or the native remuxer
	// (`xc_fanout remux`) StreamProcess::buildNativeLive composes — in place of the
	// per-stream PHP watchdog (`console.php monitor`, MonitorCommand). PHP still
	// builds every command and still owns every database write; the daemon runs
	// what it is handed and reports back.

	/** Path of the daemon binary, which is also the native remuxer (`xc_fanout remux`). */
	public static function binaryPath(): string {
		return BIN_PATH . 'xc_fanout/xc_fanout';
	}

	/**
	 * Hand a stream to the daemon's supervisor (PUT /monitor/<id>). Re-handing a
	 * supervised stream replaces its spec and restarts its producer.
	 *
	 * @param int   $rStreamID Stream id.
	 * @param array $rSpec     Spec: sources (label/cmd/fallback_cmd/probe_cmd),
	 *                         policy, health, pid/errors/log paths, server_id,
	 *                         adopt_match — see StreamProcess::buildSupervisorSpec().
	 * @return bool True when the daemon took it (204); false when it is
	 *              unreachable, not accepting hand-overs (501) or refused the spec.
	 */
	public static function supervise(int $rStreamID, array $rSpec): bool {
		$rBody = json_encode($rSpec, JSON_UNESCAPED_SLASHES);
		if ($rBody === false) {
			return false;
		}
		return self::request('PUT', '/monitor/' . $rStreamID, $rBody, 2, 5)['code'] === 204;
	}

	/**
	 * Stop supervising a stream and kill its producer (DELETE /monitor/<id>).
	 * Idempotent: a stream the daemon is not supervising answers 204 too.
	 *
	 * This MUST come before anything kills the producer by pid — killing it
	 * first is precisely what the supervisor exists to react to, so it would
	 * start a replacement and the stream would refuse to stop.
	 *
	 * @param int $rStreamID Stream id.
	 * @return bool True when the daemon answered (whether or not it was supervising).
	 */
	public static function release(int $rStreamID): bool {
		$rCode = self::request('DELETE', '/monitor/' . $rStreamID, null, 2, 5)['code'];
		return $rCode >= 200 && $rCode < 300;
	}

	/**
	 * Every supervised stream's state in one call (GET /monitors/state), for the
	 * streams_servers reconcile.
	 *
	 * Returns null — never an empty list — when the daemon cannot be reached, so
	 * a dead socket is never mistaken for "supervising nothing" (which would have
	 * the caller start a PHP monitor for every stream on the node).
	 *
	 * @return array{accepting:bool,daemon_pid:int,streams:array<string,array>}|null
	 *   accepting  — whether a new hand-over would be taken now;
	 *   daemon_pid — the daemon's pid, a supervised stream's monitor_pid;
	 *   streams   — id => state (running, pid, source, source_idx, restarts,
	 *               failures, uptime_ms, gave_up, adopted, fallback, last_error,
	 *               daemon_pid, meta{…}).
	 */
	public static function monitorStates(): ?array {
		$rRes = self::request('GET', '/monitors/state', null, 1, 3);
		if ($rRes['code'] !== 200 || !is_string($rRes['body'])) {
			return null;
		}
		$rData = json_decode($rRes['body'], true);
		if (!is_array($rData) || !isset($rData['streams']) || !is_array($rData['streams'])) {
			return null;
		}
		$rFeatures = (isset($rData['features']) && is_array($rData['features'])) ? array_map('strval', $rData['features']) : [];
		self::$features = $rFeatures;
		return ['accepting' => !empty($rData['accepting']), 'daemon_pid' => intval($rData['daemon_pid'] ?? 0), 'features' => $rFeatures, 'streams' => $rData['streams']];
	}

	/**
	 * Whether the running daemon understands `xc_fanout remux` — the native
	 * remuxer command the panel composes for a copy-only stream.
	 *
	 * A daemon from before it does not reject such a command, it MISPARSES it:
	 * `remux` reads as a positional argument to the daemon's own flag set, the
	 * process tries to become a second daemon on sockets the running one holds,
	 * and the stream never starts. So a panel that is newer than the node's
	 * binary must ask first — on a half-upgraded node the streams simply keep
	 * running ffmpeg, which is the whole point of asking.
	 *
	 * @return bool False when the daemon has not been asked yet, or says no.
	 */
	public static function supportsRemux(): bool {
		return self::supportsLive('remux');
	}

	/**
	 * Whether the running daemon has $rFeature, from what this process last
	 * asked it (monitorStates(), which a hand-over has just called; asked here
	 * when it has not been yet). Unlike supports(), no file cache a minute
	 * old: what a stream's command line may use is decided on the daemon
	 * that will run it, so a downgrade is seen at the next hand-over.
	 *
	 * @return bool False when the daemon cannot be reached, or says no.
	 */
	public static function supportsLive(string $rFeature): bool {
		if (self::$features === null) {
			self::monitorStates();
		}
		return is_array(self::$features) && in_array($rFeature, self::$features, true);
	}

	/**
	 * Whether the daemon serves files (its "files" feature): a movie or a run
	 * of archive minutes handed over with handOverFile(). vod.php and
	 * timeshift.php ask on every request, so the answer is kept a minute
	 * (TMP_PATH/fanout_features): an upgraded daemon is used within the minute,
	 * and until then this worker serves the file itself, as before.
	 */
	public static function supportsFiles(?int $rNow = null): bool {
		return self::supports('files', $rNow);
	}

	/**
	 * Whether the daemon names $rFeature among its features ("files",
	 * "file_urls" for a direct-proxy movie's source), the answer kept a
	 * minute as supportsFiles() says.
	 */
	public static function supports(string $rFeature, ?int $rNow = null): bool {
		$rNow ??= time();
		$rCache = self::$rFeaturesCache ?? TMP_PATH . 'fanout_features';
		$rKept = json_decode((string) @file_get_contents($rCache), true);
		if (!is_array($rKept) || !is_array($rKept['features'] ?? null) || (int) ($rKept['at'] ?? 0) < $rNow - 60) {
			self::$features = null;
			self::monitorStates();
			if (!is_array(self::$features)) {
				return false;
			}
			$rKept = ['at' => $rNow, 'features' => self::$features];
			AtomicFile::write($rCache, (string) json_encode($rKept));
		}
		return in_array($rFeature, $rKept['features'], true);
	}

	/** Where the daemon reads file manifests: its -filesdir, <sockets>/files. */
	public static function filesDir(): string {
		return self::$rFilesDir ?? dirname(FANOUT_CTL_SOCK) . '/files/';
	}

	/** Tests: another files dir and features cache; null restores the defaults. */
	public static function useFilesPaths(?string $rFilesDir, ?string $rFeaturesCache = null): void {
		self::$rFilesDir = $rFilesDir;
		self::$rFeaturesCache = $rFeaturesCache;
	}

	/**
	 * Hand a file response to the daemon (supportsFiles()): the manifest naming
	 * its parts goes into filesDir() under a random name, and nginx is told to
	 * fetch /file/<id> with it (`X-Accel-Redirect: /xc_fanout_file/…`), so this
	 * worker is freed at once. The daemon serves the parts back to back with
	 * HTTP ranges, throttled to $rRate bytes/s past $rLimitPerc of the
	 * response (0 = never), counts the viewer by $rUUID like a live one, and
	 * drops it when the panel does. No path is ever in a URL.
	 *
	 * @param list<array{path?: string, url?: string, offset: int, length: int}> $rParts length -1 = to the file's end;
	 *        a direct-proxy movie: one part naming its source's `url` (supports('file_urls'))
	 * @param string $rRange A range the token carried, used when the request has none.
	 * @return bool False when the manifest could not be written: nothing was sent.
	 */
	public static function handOverFile(int $rStreamID, string $rUUID, array $rParts, string $rType, int $rLimitPerc, int $rRate, string $rRange = '', ?int $rNow = null): bool {
		$rName = bin2hex(random_bytes(16));
		$rDoc = json_encode(['type' => $rType, 'parts' => $rParts, 'limit_perc' => max(0, min(100, $rLimitPerc)), 'rate' => max(0, $rRate), 'range' => $rRange, 'expires' => ($rNow ?? time()) + 60]);
		if (!is_string($rDoc) || !AtomicFile::write(self::filesDir() . $rName . '.json', $rDoc, 0600)) {
			return false;
		}
		header('X-Accel-Redirect: /xc_fanout_file/' . $rStreamID . '?c=' . rawurlencode($rUUID) . '&m=' . $rName);
		return true;
	}

	/**
	 * Whether the daemon is supervising this stream.
	 *
	 * @param int $rStreamID Stream id.
	 * @return bool|null True/false from a reachable daemon; null when it cannot
	 *                   be asked, which callers must treat as "unknown", not "no".
	 */
	public static function isSupervised(int $rStreamID): ?bool {
		$rRes = self::request('GET', '/monitor/' . $rStreamID, null, 1, 2);
		if ($rRes['errno'] !== 0 || $rRes['code'] === 0) {
			return null;
		}
		if ($rRes['code'] === 404) {
			return false;
		}
		if ($rRes['code'] !== 200 || !is_string($rRes['body'])) {
			return null;
		}
		$rData = json_decode($rRes['body'], true);
		return is_array($rData) && !empty($rData['supervised']);
	}

	/**
	 * Force a supervised stream onto one of its sources (POST
	 * /monitor/<id>/source) — the supervised form of the `<id>.force` signal file,
	 * which only the PHP monitor ever read.
	 *
	 * @param int $rStreamID Stream id.
	 * @param int $rIndex    Index into the stream's source list.
	 * @return bool True when the daemon queued the switch.
	 */
	public static function forceSource(int $rStreamID, int $rIndex): bool {
		$rCode = self::request('POST', '/monitor/' . $rStreamID . '/source', json_encode(['index' => $rIndex]), 1, 3)['code'];
		return $rCode >= 200 && $rCode < 300;
	}

	/**
	 * One control request over the daemon's unix socket.
	 *
	 * @param string      $rMethod  HTTP method.
	 * @param string      $rPath    Request path.
	 * @param string|null $rBody    JSON body, or null.
	 * @param int         $rConnect Connect timeout, seconds.
	 * @param int         $rTimeout Whole-request timeout, seconds.
	 * @return array{code:int,body:string|null,errno:int} code 0 / errno set when unreachable.
	 */
	private static function request(string $rMethod, string $rPath, ?string $rBody, int $rConnect, int $rTimeout): array {
		if (!function_exists('curl_init') || !self::socketReady()) {
			return ['code' => 0, 'body' => null, 'errno' => -1];
		}
		$rCurl = curl_init();
		curl_setopt_array($rCurl, [
			CURLOPT_UNIX_SOCKET_PATH => FANOUT_CTL_SOCK,
			CURLOPT_URL            => 'http://localhost' . $rPath,
			CURLOPT_CUSTOMREQUEST  => $rMethod,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => $rConnect,
			CURLOPT_TIMEOUT        => $rTimeout,
		]);
		if ($rBody !== null) {
			curl_setopt($rCurl, CURLOPT_POSTFIELDS, $rBody);
			curl_setopt($rCurl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
		}
		$rResponse = curl_exec($rCurl);
		$rCode = curl_getinfo($rCurl, CURLINFO_HTTP_CODE);
		$rErrno = curl_errno($rCurl);
		curl_close($rCurl);
		return ['code' => $rCode, 'body' => is_string($rResponse) ? $rResponse : null, 'errno' => $rErrno];
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
		if (!function_exists('curl_init') || !self::socketReady()) {
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
