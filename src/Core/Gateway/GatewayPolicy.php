<?php

namespace XcVm\Core\Gateway;

use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\AgentConnections;
use XcVm\Core\Cluster\AgentPaths;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeLease;
use XcVm\Core\Cluster\ViewerKey;
use XcVm\Core\Config\OpensslExtra;
use XcVm\Core\Config\StreamSecret;
use XcVm\Streaming\Fanout\FanoutMode;

/**
 * The segment gateway's policy (Phase 12, docs/superpowers/specs/2026-10-08-
 * lb-segment-gateway-and-native-restreamer-design.md): what xc_fanout's
 * gateway needs to answer `/hls/` and `/key/` as `segment.php` and `key.php`
 * do, written by PHP so there is one source of truth — the settings and
 * servers caches those endpoints read (the replica's on an API-mode node,
 * MAIN's database's elsewhere). The gateway never reads PHP's caches.
 *
 * `tmp/gateway/policy.json`, 0600, written whole every minute (cron:cache).
 * The gateway re-reads it when it changes and sends every request to PHP
 * while it is missing, unreadable or older than ten minutes, so it never
 * fails a viewer for lack of a policy. Every key is the hex of the exact
 * bytes PHP uses, with the end of its window (null: none), current first.
 *
 * ```json
 * {"v": 1, "server_id": 3, "written_at": 1759900000, "mode": "shadow",
 *  "keys": {"viewer": [{"hex": "…", "until": null}], "shared": […], "context": […], "accept_legacy_cbc": false},
 *  "restrict_same_ip": true, "ip_subnet_match": false, "encrypt_hls": true,
 *  "headers": {"server": "", "protection": true, "altsvc_port": 0},
 *  "serve_until": 0, "paths": {"cons": "…", "streams": "…", "archive": "…", "flood": "…", "signals": "…", "agent_sock": "…", "spool": "…", "flows": "…"},
 *  "live": {"use_buffer": true, "on_demand_instant_off": false, "disallow_2nd_ip_con": false, "disallow_2nd_ip_max": 0, "unique_header": false,
 *           "ts": true, "client_prebuffer": 30, "restreamer_prebuffer": 0, "seg_time": 10, "create_expiration": 5, "admission": true},
 *  "verify_host": false, "allowed_domains": [],
 *  "conn_store": "agent", "time_offset": 0, "redirect": {"5": ["http://lb5.example:8080"]}}
 * ```
 *
 * `paths.flood`, `verify_host` and `allowed_domains` are what
 * StreamingRequestBootstrap checks before any stream endpoint runs (a flood
 * block marker, the request's host); the gateway hands a request either would
 * refuse to PHP, which answers it as before.
 *
 * `conn_store` is where the node keeps its viewers: `agent` with the
 * CONNECTIONS flow on (the gateway hears a catch-up viewer there, as
 * ConnectionTracker::heartbeat does), else `php` (MAIN's Redis or MySQL, which
 * the gateway leaves to PHP).
 *
 * `live` is what live.php reads for a known viewer's playlist refresh and,
 * with `ts` (the fields after it are written), for an MPEG-TS viewer: the
 * seconds of history it joins fanout's stream with (`?prebuffer=`), as
 * live.php picks them, and for its first request, whose connection the
 * gateway creates as live.php does, `create_expiration` (a token older than
 * that on MAIN's clock opens none: live.php answers TOKEN_EXPIRED) and
 * `admission` (the agent admits a viewer with a limit: only on a node MAIN
 * has left active, as AgentConnections::admission() decides). A gateway
 * given no `create_expiration` leaves a first request to live.php.
 *
 * `serve_until` is NodeLease's fence on this host's clock: the gateway serves
 * nothing past it (0: no lease limits this node), and PHP answers from then.
 */
final class GatewayPolicy {
	public const VERSION = 1;

	/**
	 * `gateway_mode`: off; shadow (PHP serves, the gateway judges a mirrored copy
	 * of each segment, key and playlist request); segments (the gateway serves
	 * segments and keys); segments+playlist (and a known viewer's playlist refresh).
	 */
	public const MODES = ['off', 'shadow', 'segments', 'segments+playlist'];

	/** Tests: another file; null restores TMP_PATH's. */
	private static ?string $rFile = null;

	public static function file(): string {
		return self::$rFile ?? TMP_PATH . 'gateway/policy.json';
	}

	public static function useFile(?string $rPath): void {
		self::$rFile = $rPath;
	}

	/**
	 * The node's mode: the setting as ClusterSettings::enum() takes it (a value
	 * that is not a mode is its default), and off whenever fanout does not
	 * deliver (switched off or unlicensed), since the gateway serves from
	 * fanout. Off too where the setting is missing: a node whose MAIN has not
	 * sent it yet.
	 *
	 * @param array<string, mixed> $rSettings
	 */
	public static function mode(array $rSettings): string {
		if (!array_key_exists('gateway_mode', $rSettings) || FanoutMode::legacyDelivery($rSettings)) {
			return 'off';
		}
		return ClusterSettings::enum('gateway_mode', $rSettings['gateway_mode']);
	}

	/**
	 * @param array<string, mixed> $rSettings
	 * @param array<int|string, array<string, mixed>> $rServers
	 * @param list<string> $rAllowedDomains verify_host's list (the allowed_domains cache), [] for none
	 * @return array<string, mixed>
	 */
	public static function build(array $rSettings, array $rServers, int $rServerID, int $rNow, array $rAllowedDomains = []): array {
		$rHex = static fn(string $rValue, ?int $rUntil): array => ['hex' => bin2hex($rValue), 'until' => $rUntil];
		$rViewer = array_map(static fn(array $rKey): array => $rHex($rKey['value'], $rKey['until']), ViewerKey::held($rNow));
		// readToken tries the replaced secret only after the current one: none without it.
		$rShared = [];
		$rSecret = (string) ($rSettings['live_streaming_pass'] ?? '');
		if ($rSecret !== '') {
			$rShared[] = $rHex($rSecret, null);
			$rOld = StreamSecret::previousEntry($rNow);
			if ($rOld !== null && $rOld['value'] !== $rSecret) {
				$rShared[] = $rHex($rOld['value'], $rOld['valid_until']);
			}
		}
		$rContext = [$rHex(OPENSSL_EXTRA, null)];
		$rOldContext = OpensslExtra::previousEntry($rNow);
		if ($rOldContext !== null) {
			$rContext[] = $rHex($rOldContext['value'], $rOldContext['valid_until']);
		}
		$rOwn = $rServers[$rServerID] ?? [];
		return [
			'v' => self::VERSION,
			'server_id' => $rServerID,
			'written_at' => $rNow,
			'mode' => self::mode($rSettings),
			'keys' => ['viewer' => $rViewer, 'shared' => $rShared, 'context' => $rContext, 'accept_legacy_cbc' => empty($rSettings['secure_stream_tokens'])],
			'restrict_same_ip' => !empty($rSettings['restrict_same_ip']),
			'ip_subnet_match' => !empty($rSettings['ip_subnet_match']),
			'encrypt_hls' => !empty($rSettings['encrypt_hls']),
			'headers' => [
				'server' => (string) ($rSettings['send_server_header'] ?? ''),
				'protection' => !empty($rSettings['send_protection_headers']),
				'altsvc_port' => !empty($rSettings['send_altsvc_header']) ? (int) ($rOwn['https_broadcast_port'] ?? 0) : 0,
			],
			'serve_until' => self::serveUntil($rSettings, $rNow),
			'paths' => [
				'cons' => CONS_TMP_PATH, 'streams' => STREAMS_PATH, 'archive' => ARCHIVE_PATH, 'flood' => FLOOD_TMP_PATH, 'signals' => SIGNALS_TMP_PATH,
				'agent_sock' => AgentPaths::file(AgentPaths::SOCKET), 'spool' => AgentPaths::file(AgentPaths::DIR . 'spool/'), 'flows' => AgentPaths::file(AgentPaths::DIR . 'flows.json'),
			],
			// What live.php reads for a playlist refresh or a TS reconnect, its comparisons made here.
			'live' => [
				'use_buffer' => !(($rSettings['use_buffer'] ?? null) == 0),
				'on_demand_instant_off' => !empty($rSettings['on_demand_instant_off']),
				'disallow_2nd_ip_con' => !empty($rSettings['disallow_2nd_ip_con']),
				'disallow_2nd_ip_max' => (int) ($rSettings['disallow_2nd_ip_max'] ?? 0),
				'unique_header' => !empty($rSettings['send_unique_header']),
				'ts' => true,
				'client_prebuffer' => (int) ($rSettings['client_prebuffer'] ?? 0),
				'restreamer_prebuffer' => (int) ($rSettings['restreamer_prebuffer'] ?? 0),
				'seg_time' => max(1, (int) ($rSettings['seg_time'] ?? 0)),
				// A TS viewer's first request: how long after its mint a token still
				// opens a connection (live.php's `?: 5`), and whether the agent
				// admits a viewer with a limit (AgentConnections::admission()).
				'create_expiration' => ((int) ($rSettings['create_expiration'] ?? 0)) ?: 5,
				'admission' => NodeFlows::current()['state'] === 'active',
			],
			// StreamingRequestBootstrap's host check, before every stream endpoint.
			'verify_host' => !empty($rSettings['verify_host']),
			'allowed_domains' => array_values(array_filter($rAllowedDomains, 'is_string')),
			'conn_store' => AgentConnections::enabled() ? 'agent' : 'php',
			'time_offset' => (int) ($rOwn['time_offset'] ?? 0),
			'redirect' => self::redirects($rServers, $rServerID),
		];
	}

	/** Write the policy from the caches the stream endpoints read; false when they are not there yet or it could not be written. */
	public static function write(?int $rNow = null): bool {
		$rSettings = FileCache::getCache('settings');
		$rServers = FileCache::getCache('servers');
		if (!is_array($rSettings) || !is_array($rServers) || !defined('SERVER_ID')) {
			return false;
		}
		$rDomains = FileCache::getCache('allowed_domains');
		$rJson = (string) json_encode(self::build($rSettings, $rServers, (int) SERVER_ID, $rNow ?? time(), is_array($rDomains) ? $rDomains : []), JSON_UNESCAPED_SLASHES);
		$rPath = self::file();
		$rDir = dirname($rPath);
		if (!is_dir($rDir) && !@mkdir($rDir, 0700, true)) {
			return false;
		}
		// 0600 before a byte of key material is in it, then renamed into place whole.
		$rTmp = $rDir . '/.' . basename($rPath) . '.' . getmypid() . '.tmp';
		@unlink($rTmp);
		if (!@touch($rTmp) || !@chmod($rTmp, 0600) || @file_put_contents($rTmp, $rJson) !== strlen($rJson) || !@rename($rTmp, $rPath)) {
			@unlink($rTmp);
			return false;
		}
		return true;
	}

	/**
	 * When the gateway stops serving: NodeLease's fence (the end of its
	 * drain, on MAIN's clock) moved onto this host's clock; now when the
	 * node is fenced already; 0 when no lease limits it.
	 *
	 * @param array<string, mixed> $rSettings
	 */
	private static function serveUntil(array $rSettings, int $rNow): int {
		$rLease = NodeLease::verdict($rSettings);
		if ($rLease['source'] === 'none' && $rLease['state'] === NodeLease::SERVING) {
			return 0;
		}
		// A fence MAIN commanded has no anchor on MAIN's clock to move its drain
		// onto this host's: PHP serves the drain, and stops at its end.
		if ($rLease['state'] === NodeLease::FENCED || $rLease['anchor'] <= 0) {
			return $rNow;
		}
		return $rNow + max(0, $rLease['drain_until'] - $rLease['anchor']);
	}

	/**
	 * Where a token another server minted is sent (segment.php's Location):
	 * each server's base URLs, one drawn at random per request.
	 *
	 * @param array<int|string, array<string, mixed>> $rServers
	 * @return array<string, list<string>>
	 */
	private static function redirects(array $rServers, int $rServerID): array {
		$rOut = [];
		foreach ($rServers as $rID => $rServer) {
			if ((int) $rID === $rServerID || !is_array($rServer)) {
				continue;
			}
			$rUrls = $rServer['domains']['urls'] ?? [];
			if (!empty($rServer['random_ip']) && is_array($rUrls) && count($rUrls) > 0) {
				$rOut[(string) $rID] = array_values(array_map(static fn($rHost): string => $rServer['domains']['protocol'] . '://' . $rHost . ':' . $rServer['domains']['port'], $rUrls));
			} else {
				$rOut[(string) $rID] = [rtrim((string) ($rServer['site_url'] ?? ''), '/')];
			}
		}
		return $rOut;
	}
}
