<?php

namespace XcVm\Core\Gateway;

/**
 * The segment gateway's shadow comparison, PHP's half (ADR 0005). In shadow
 * (`gateway_mode`), nginx mirrors each segment, key and playlist request to
 * the gateway, which judges it, and runs it through PHP as before; both get
 * nginx's request id (GatewayNginxConfig). PHP tells the gateway what it
 * answered once the viewer has the answer (watch()), and the gateway counts
 * the two as agreeing or not (xc_fanout's ShadowBook).
 *
 * The node reports the gateway's counts and the comparison with its audit
 * (report(): `gateway` in audit.json, SettingsAudit::publish), and MAIN shows
 * on Cluster Nodes when a node is ready to serve (readiness()): it has
 * compared for READY_DAYS since its last disagreement, READY_MIN requests at
 * least.
 */
final class GatewayShadow {
	public const READY_DAYS = 7;

	public const READY_MIN = 100;

	/** What the report keeps: the busiest verdicts and the newest disagreements. */
	public const MAX_COUNTS = 48;

	public const MAX_SAMPLES = 5;

	/** Tests: another way to reach the gateway (method, path, headers → body or null); null restores its socket. */
	private static ?\Closure $rCall = null;

	public static function useCall(?\Closure $rCall): void {
		self::$rCall = $rCall;
	}

	/**
	 * For a request nginx flagged (shadow): after PHP answered it, tell the
	 * gateway what it answered. $rHandler is the stream endpoint (segment,
	 * key, live). Nothing for any other request.
	 */
	public static function watch(string $rHandler): void {
		$rID = (string) ($_SERVER['XC_REQUEST_ID'] ?? '');
		if (($_SERVER['XC_GW_SHADOW'] ?? '') !== '1' || !in_array($rHandler, ['segment', 'key', 'live'], true) || !preg_match('/^[0-9a-f]{32}\z/', $rID)) {
			return;
		}
		register_shutdown_function(static function () use ($rID, $rHandler): void {
			$rOutcome = self::outcome(headers_list(), (int) http_response_code());
			// The viewer has the answer before the gateway hears of it.
			if (function_exists('fastcgi_finish_request')) {
				@fastcgi_finish_request();
			}
			self::call('POST', '/shadow/php', ['X-XC-Request-ID' => $rID, 'X-XC-Kind' => $rHandler, 'X-XC-Outcome' => $rOutcome]);
		});
	}

	/**
	 * What PHP answered, in the gateway's words: serve (the bytes handed
	 * over, or a body), redirect, deny (404), blocked (403), status-<code>.
	 *
	 * @param list<string> $rHeaders headers_list()
	 */
	public static function outcome(array $rHeaders, int $rCode): string {
		foreach ($rHeaders as $rHeader) {
			$rName = strtolower(trim(explode(':', $rHeader, 2)[0]));
			if ($rName === 'x-accel-redirect') {
				return 'serve';
			}
			if ($rName === 'location') {
				return 'redirect';
			}
		}
		return match (true) {
			$rCode === 404 => 'deny',
			$rCode === 403 => 'blocked',
			$rCode >= 200 && $rCode < 300 => 'serve',
			default => 'status-' . $rCode,
		};
	}

	/**
	 * The gateway's part of this node's audit: its mode, how it judged the
	 * requests it saw (`<kind> <action> <reason>` counts, since its start) and
	 * the shadow comparison (kept across its restarts). [] when it is not
	 * running.
	 *
	 * @return array{gateway?: array<string, mixed>}
	 */
	public static function report(): array {
		try {
			return self::reportOf(json_decode((string) self::call('GET', '/stats', []), true));
		} catch (\Throwable) {
			return []; // an audit never breaks the call that publishes it
		}
	}

	/** @return array{gateway?: array<string, mixed>} */
	private static function reportOf(mixed $rStats): array {
		if (!is_array($rStats) || !is_array($rStats['counts'] ?? null)) {
			return [];
		}
		$rPolicy = defined('TMP_PATH') ? json_decode((string) @file_get_contents(GatewayPolicy::file()), true) : null;
		$rCounts = array_filter($rStats['counts'], 'is_int');
		arsort($rCounts);
		$rShadow = is_array($rStats['shadow'] ?? null) ? $rStats['shadow'] : [];
		$rOut = ['mode' => (string) ($rPolicy['mode'] ?? 'off'), 'counts' => (object) array_slice($rCounts, 0, self::MAX_COUNTS, true), 'shadow' => []];
		foreach (['since', 'agree', 'disagree', 'deferred', 'unmatched', 'last_disagree'] as $rKey) {
			$rOut['shadow'][$rKey] = (int) ($rShadow[$rKey] ?? 0);
		}
		$rOut['shadow']['samples'] = array_slice(array_values((array) ($rShadow['samples'] ?? [])), -self::MAX_SAMPLES);
		return ['gateway' => $rOut];
	}

	/**
	 * Is the node ready to serve, by its comparison: `ready` (compared for
	 * READY_DAYS since its last disagreement, READY_MIN requests at least),
	 * `comparing` (not long or not many yet), `disagreed` (a disagreement in
	 * the last READY_DAYS), `none` (nothing compared).
	 *
	 * @param array<string, mixed> $rShadow the report's `shadow`
	 */
	public static function readiness(array $rShadow, int $rNow): string {
		$rSince = (int) ($rShadow['since'] ?? 0);
		if ($rSince <= 0 || (int) ($rShadow['agree'] ?? 0) + (int) ($rShadow['disagree'] ?? 0) === 0) {
			return 'none';
		}
		$rLast = (int) ($rShadow['last_disagree'] ?? 0);
		if ($rLast > 0 && $rNow - $rLast < self::READY_DAYS * 86400) {
			return 'disagreed';
		}
		return $rNow - max($rSince, $rLast) >= self::READY_DAYS * 86400 && (int) ($rShadow['agree'] ?? 0) >= self::READY_MIN ? 'ready' : 'comparing';
	}

	/** One request to the gateway's socket: its body, or null when it did not answer. Never throws. */
	private static function call(string $rMethod, string $rPath, array $rHeaders): ?string {
		if (self::$rCall !== null) {
			return (self::$rCall)($rMethod, $rPath, $rHeaders);
		}
		if (!function_exists('curl_init') || !file_exists(GatewayNginxConfig::SOCKET)) {
			return null;
		}
		$rCurl = curl_init('http://gw' . $rPath);
		$rLines = [];
		foreach ($rHeaders as $rName => $rValue) {
			$rLines[] = $rName . ': ' . str_replace(["\r", "\n"], '', (string) $rValue);
		}
		curl_setopt_array($rCurl, [
			CURLOPT_UNIX_SOCKET_PATH => GatewayNginxConfig::SOCKET,
			CURLOPT_CUSTOMREQUEST => $rMethod,
			CURLOPT_HTTPHEADER => $rLines,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT_MS => 200,
			CURLOPT_TIMEOUT_MS => 1000,
		]);
		$rBody = curl_exec($rCurl);
		$rCode = (int) curl_getinfo($rCurl, CURLINFO_HTTP_CODE);
		curl_close($rCurl);
		return is_string($rBody) && $rCode >= 200 && $rCode < 300 ? $rBody : null;
	}
}
