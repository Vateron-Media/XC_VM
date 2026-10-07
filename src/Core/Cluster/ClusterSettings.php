<?php

namespace XcVm\Core\Cluster;

use XcVm\Domain\Cluster\DbAllowlist;

/**
 * The cluster API settings (MAIN ↔ LB plan, section 11): defaults, bounds
 * and the checks a save must pass.
 *
 * normalize() clamps numbers into their bounds and falls back to the default
 * for an unknown enum value, silently, as the plan asks. It refuses (keeps
 * the stored value and reports an error code) where clamping would change
 * what the admin meant:
 *
 * - `cluster_api_port` colliding with a port MAIN already listens on;
 * - `cluster_main_host` that is not a hostname;
 * - `cluster_transport = https_required` without working HTTPS (the
 *   self-probe, and every active node reporting HTTPS);
 * - `cluster_api_enabled = 1` without the extension's cluster API;
 * - `lb_new_node_mode = api` with the cluster API off, the Redis connection handler on, or an
 *   extension that cannot pack a credential-free config;
 * - a scan root that is not an absolute, normalised path;
 * - a database allowlist entry that is not an IP or CIDR.
 *
 * The class is pure apart from httpsSelfProbe(); SettingsService supplies the
 * main server row, the stored settings and the environment checks.
 */
final class ClusterSettings {
	/** name => [default, min, max] for integers, or [default, allowed values] for enums. */
	public const INTS = [
		'cluster_api_enabled' => [0, 0, 1],
		'lb_token_rotation_min' => [60, 5, 1440],
		'lb_partition_tolerance_h' => [12, 0, 24],
		'lb_fence_drain_min' => [10, 0, 60],
		'lb_lease_fence' => [0, 0, 1],
		'lb_digest_nonce_required' => [0, 0, 1],
		'lb_telemetry_interval_sec' => [2, 1, 3],
		'cluster_offline_after_sec' => [30, 10, 300],
		'cluster_orphan_conn_ttl_sec' => [120, 30, 3600],
		'cluster_kill_on_line_disable' => [1, 0, 1],
		'cluster_ingest_concurrency' => [6, 1, 64],
		'servers_stats_retention_days' => [30, 1, 365],
		'cluster_audit_retention_days' => [30, 1, 365],
		'cluster_db_allowlist' => [0, 0, 1],
	];

	/**
	 * Integers whose 0 means unset when read (int()), not the floor: the
	 * default applies. A stored 0 is out of range for both, and clamping it
	 * would quietly shorten what the admin never set: a fleet beating faster
	 * (ADR 0004, `heartbeat_sec`), a node shown offline after 10 s.
	 */
	public const ZERO_IS_UNSET = ['lb_telemetry_interval_sec', 'cluster_offline_after_sec'];

	public const ENUMS = [
		'cluster_transport' => ['auto', ['auto', 'http', 'https_preferred', 'https_required']],
		'lb_revocation_mode' => ['graceful', ['graceful', 'hard']],
		'lb_offline_admission' => ['local', ['allow', 'local', 'deny']],
		'lb_new_node_mode' => ['legacy', ['legacy', 'api']],
	];

	public const DEFAULT_SCAN_ROOTS = ['/home/xc_vm/content', '/mnt', '/media'];

	/** Ports the cluster API may never use besides MAIN's own listeners (fanout ctl, agent relay, MySQL, Redis). */
	public const RESERVED_PORTS = [31210, 31290, 3306, 6379];

	/** @var (callable(array<string, mixed>): array{ok: bool, reason: string, host: ?string, days_left: ?int})|null */
	private static $rHttpsProbe = null;

	/** Every setting this class owns. */
	public static function keys(): array {
		return array_merge(array_keys(self::INTS), array_keys(self::ENUMS), ['cluster_api_port', 'cluster_main_host', 'lb_scan_roots', 'cluster_db_allowlist_extra']);
	}

	/** The on/off settings (0-1): the settings form's switches, which post nothing when off. */
	public static function switches(): array {
		return array_keys(array_filter(self::INTS, static fn(array $rRange): bool => $rRange[1] === 0 && $rRange[2] === 1));
	}

	/** Grace G for a rotation interval L (both minutes): clamp(L/4, 5, 60). */
	public static function graceMin(int $rRotationMin): int {
		return max(5, min(60, intdiv(self::clampInt('lb_token_rotation_min', $rRotationMin), 4)));
	}

	/**
	 * Normalise the cluster keys present in a settings save.
	 *
	 * @param array<string, mixed> $rNew     Submitted values (only cluster keys present are touched).
	 * @param array<string, mixed> $rMain    The main server's `servers` row (ports).
	 * @param array<string, mixed> $rCurrent The stored settings.
	 * @param array{https_ok?: bool, nodes_https_ok?: bool, extension_ok?: bool, credential_free_config?: bool} $rEnv
	 * @return array{0: array<string, mixed>, 1: list<array{0: string, 1: string}>} [values to store, errors as [key, code]]
	 */
	public static function normalize(array $rNew, array $rMain, array $rCurrent, array $rEnv = []): array {
		$rOut = [];
		$rErrors = [];

		foreach (self::INTS as $rKey => [$rDefault]) {
			if (array_key_exists($rKey, $rNew)) {
				$rOut[$rKey] = is_numeric($rNew[$rKey]) ? self::clampInt($rKey, (int) $rNew[$rKey]) : $rDefault;
			}
		}
		foreach (self::ENUMS as $rKey => [$rDefault, $rAllowed]) {
			if (array_key_exists($rKey, $rNew)) {
				$rValue = strtolower(trim((string) $rNew[$rKey]));
				$rOut[$rKey] = in_array($rValue, $rAllowed, true) ? $rValue : $rDefault;
			}
		}

		if (array_key_exists('cluster_api_port', $rNew)) {
			$rPort = is_numeric($rNew['cluster_api_port']) ? (int) $rNew['cluster_api_port'] : -1;
			$rCode = self::portProblem($rPort, $rMain);
			if ($rCode === null) {
				$rOut['cluster_api_port'] = $rPort;
			} else {
				$rErrors[] = ['cluster_api_port', $rCode];
			}
		}

		if (array_key_exists('cluster_main_host', $rNew)) {
			$rHost = strtolower(trim((string) $rNew['cluster_main_host']));
			if ($rHost === '' || self::validHostname($rHost)) {
				$rOut['cluster_main_host'] = $rHost;
			} else {
				$rErrors[] = ['cluster_main_host', 'cluster_error_host'];
			}
		}

		if (array_key_exists('lb_scan_roots', $rNew)) {
			[$rRoots, $rBad] = self::scanRoots($rNew['lb_scan_roots']);
			if ($rBad) {
				$rErrors[] = ['lb_scan_roots', 'cluster_error_scan_roots'];
			} else {
				$rOut['lb_scan_roots'] = json_encode($rRoots, JSON_UNESCAPED_SLASHES);
			}
		}

		if (array_key_exists('cluster_db_allowlist_extra', $rNew)) {
			// The parser lives with the firewall it feeds, which is MAIN's alone
			// (the LB build strips Domain\Cluster). A node never saves settings,
			// so there the value is refused rather than stored unchecked.
			[$rCidrs, $rBad] = class_exists(DbAllowlist::class) ? DbAllowlist::parseExtra((string) $rNew['cluster_db_allowlist_extra']) : [[], true];
			$rJoined = implode("\n", $rCidrs);
			if ($rBad || strlen($rJoined) > 1024) {
				$rErrors[] = ['cluster_db_allowlist_extra', 'cluster_error_db_allowlist'];
			} else {
				$rOut['cluster_db_allowlist_extra'] = $rJoined;
			}
		}

		// Guards that refuse rather than clamp.
		if (($rOut['cluster_transport'] ?? null) === 'https_required' && ($rCurrent['cluster_transport'] ?? '') !== 'https_required') {
			if (empty($rEnv['https_ok'])) {
				$rErrors[] = ['cluster_transport', 'cluster_error_https_probe'];
				unset($rOut['cluster_transport']);
			} elseif (!($rEnv['nodes_https_ok'] ?? true)) {
				$rErrors[] = ['cluster_transport', 'cluster_error_https_nodes'];
				unset($rOut['cluster_transport']);
			}
		}
		if (($rOut['cluster_api_enabled'] ?? 0) === 1 && empty($rCurrent['cluster_api_enabled']) && empty($rEnv['extension_ok'])) {
			$rErrors[] = ['cluster_api_enabled', 'cluster_error_extension'];
			unset($rOut['cluster_api_enabled']);
		}
		if (($rOut['lb_new_node_mode'] ?? null) === 'api' && empty($rOut['cluster_api_enabled'] ?? $rCurrent['cluster_api_enabled'] ?? 0)) {
			// Mode 2 is the cluster API's: with it off (as this save leaves it), a
			// new node installs in legacy mode whatever is stored (newNodesInApiMode()).
			$rErrors[] = ['lb_new_node_mode', 'cluster_error_api_mode_api_off'];
			unset($rOut['lb_new_node_mode']);
		} elseif (($rOut['lb_new_node_mode'] ?? null) === 'api' && empty($rEnv['credential_free_config'])) {
			// An API-mode node is installed without MAIN's credentials: an
			// extension that cannot pack such a config would hand them over.
			$rErrors[] = ['lb_new_node_mode', 'cluster_error_api_mode_extension'];
			unset($rOut['lb_new_node_mode']);
		}

		return [$rOut, $rErrors];
	}

	/**
	 * Is a new load balancer installed in API mode (plan, section 12: mode 2
	 * from its first boot, no DB grant, a credential-free config.enc)? Only
	 * with the cluster API on and `lb_new_node_mode = api` — which normalize()
	 * refuses with the cluster API off and without an extension that packs such a config (CredentialFreeConfig).
	 *
	 * @param array<string, mixed> $rSettings
	 */
	public static function newNodesInApiMode(array $rSettings): bool {
		return !empty($rSettings['cluster_api_enabled']) && self::enum('lb_new_node_mode', $rSettings['lb_new_node_mode'] ?? null) === 'api';
	}

	/** Why a cluster API port is refused, or null when it is usable (0 = share http_broadcast_port). */
	public static function portProblem(int $rPort, array $rMain): ?string {
		if ($rPort === 0) {
			return null;
		}
		if ($rPort < 1024 || $rPort > 65535) {
			return 'cluster_error_port_range';
		}
		return in_array($rPort, self::takenPorts($rMain), true) ? 'cluster_error_port_taken' : null;
	}

	/** @return list<int> Ports MAIN already uses, plus the reserved ones. */
	public static function takenPorts(array $rMain): array {
		$rPorts = self::RESERVED_PORTS;
		foreach (['http_broadcast_port', 'https_broadcast_port', 'rtmp_port'] as $rKey) {
			if (!empty($rMain[$rKey])) {
				$rPorts[] = (int) $rMain[$rKey];
			}
		}
		foreach (['http_ports_add', 'https_ports_add'] as $rKey) {
			foreach (explode(',', (string) ($rMain[$rKey] ?? '')) as $rExtra) {
				if (is_numeric(trim($rExtra))) {
					$rPorts[] = (int) trim($rExtra);
				}
			}
		}
		return array_values(array_unique($rPorts));
	}

	public static function validHostname(string $rHost): bool {
		if (strlen($rHost) > 253 || filter_var($rHost, FILTER_VALIDATE_IP)) {
			return false;
		}
		return (bool) preg_match('/^(?=.{1,253}\z)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+\z/', $rHost);
	}

	/**
	 * Is a path inside the roots a node may read from?
	 *
	 * The node system API takes paths from MAIN (`scandir`, `scandir_recursive`,
	 * `getFile`). Callers name VOD sources under the scan roots, and panel files
	 * under MAIN_HOME (certbot logs, subtitles, module archives), so MAIN_HOME is
	 * passed as an extra root by the `getFile` handler only.
	 *
	 * Resolved with realpath on both sides, so `..` and symlinks out of a root are
	 * refused rather than string-matched. A path that does not exist is refused.
	 *
	 * @param string       $rPath   Absolute path from the request.
	 * @param mixed        $rRoots  lb_scan_roots as stored (JSON, lines, array), or null for the default.
	 * @param list<string> $rExtra  Extra roots this caller allows.
	 * @return bool True when the path resolves inside one of the roots.
	 */
	public static function pathAllowed(string $rPath, mixed $rRoots = null, array $rExtra = []): bool {
		$rReal = realpath($rPath);
		if ($rReal === false) {
			return false;
		}

		[$rAllowed] = self::scanRoots($rRoots);
		foreach ($rExtra as $rRoot) {
			$rAllowed[] = rtrim((string) $rRoot, '/');
		}

		foreach ($rAllowed as $rRoot) {
			$rRootReal = realpath($rRoot);
			if ($rRootReal === false) {
				continue;
			}
			if ($rReal === $rRootReal || str_starts_with($rReal, rtrim($rRootReal, '/') . '/')) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Parse scan roots from a textarea (one path per line) or a JSON/array value.
	 *
	 * @return array{0: list<string>, 1: bool} [roots, any invalid]
	 */
	public static function scanRoots(mixed $rValue): array {
		if (is_string($rValue)) {
			$rDecoded = json_decode($rValue, true);
			$rValue = is_array($rDecoded) ? $rDecoded : preg_split('/[\r\n]+/', $rValue);
		}
		$rRoots = [];
		foreach ((array) $rValue as $rPath) {
			$rPath = trim((string) $rPath);
			if ($rPath === '') {
				continue;
			}
			$rPath = rtrim($rPath, '/');
			if ($rPath === '' || $rPath[0] !== '/' || preg_match('#(^|/)\.\.?(/|$)|//|[\x00-\x1f]#', $rPath)) {
				return [[], true];
			}
			$rRoots[] = $rPath;
		}
		$rRoots = array_values(array_unique($rRoots));
		return [$rRoots === [] ? self::DEFAULT_SCAN_ROOTS : $rRoots, false];
	}

	/**
	 * Does MAIN's own HTTPS work for agents? A non-IP name in domain_name, HTTPS
	 * enabled, and a certificate that verifies against the system roots with more
	 * than 7 days left.
	 *
	 * @return array{ok: bool, reason: string, host: ?string, days_left: ?int}
	 */
	public static function httpsSelfProbe(array $rMain, int $rTimeout = 3): array {
		if (self::$rHttpsProbe !== null) {
			return (self::$rHttpsProbe)($rMain);
		}
		$rOut = ['ok' => false, 'reason' => '', 'host' => null, 'days_left' => null];
		if (!in_array((int) ($rMain['enable_https'] ?? 0), [1, 2], true)) {
			$rOut['reason'] = 'https_disabled';
			return $rOut;
		}
		foreach (explode(',', (string) ($rMain['domain_name'] ?? '')) as $rName) {
			$rName = strtolower(trim($rName));
			if ($rName !== '' && self::validHostname($rName)) {
				$rOut['host'] = $rName;
				break;
			}
		}
		if ($rOut['host'] === null || !function_exists('curl_init')) {
			$rOut['reason'] = 'no_domain';
			return $rOut;
		}
		$rCurl = curl_init('https://' . $rOut['host'] . ':' . intval($rMain['https_broadcast_port'] ?? 443) . '/');
		curl_setopt_array($rCurl, [
			CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CERTINFO => true,
			CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_CONNECTTIMEOUT => $rTimeout, CURLOPT_TIMEOUT => $rTimeout,
		]);
		if (!empty($rMain['server_ip'])) {
			curl_setopt($rCurl, CURLOPT_RESOLVE, [$rOut['host'] . ':' . intval($rMain['https_broadcast_port'] ?? 443) . ':' . $rMain['server_ip']]);
		}
		$rOK = curl_exec($rCurl) !== false;
		$rInfo = curl_getinfo($rCurl, CURLINFO_CERTINFO);
		curl_close($rCurl);
		if (!$rOK) {
			$rOut['reason'] = 'tls_failed';
			return $rOut;
		}
		$rExpire = is_array($rInfo) && isset($rInfo[0]['Expire date']) ? strtotime($rInfo[0]['Expire date']) : false;
		$rOut['days_left'] = $rExpire ? intdiv($rExpire - time(), 86400) : null;
		if ($rOut['days_left'] !== null && $rOut['days_left'] <= 7) {
			$rOut['reason'] = 'cert_expiring';
			return $rOut;
		}
		$rOut['ok'] = true;
		$rOut['reason'] = 'OK';
		return $rOut;
	}

	/** Tests: answer the HTTPS self-probe without the network. Null restores it. */
	public static function useHttpsProbe(?callable $rProbe): void {
		self::$rHttpsProbe = $rProbe;
	}

	/**
	 * An integer setting as its readers take it, from the value as stored
	 * (settings row, replica, SettingsManager::get()): clamped into its bounds
	 * as normalize() stores it, or its default when unset or not a number (and
	 * at 0 for ZERO_IS_UNSET). Every reader goes through here, so a default
	 * or a bound lives in INTS alone.
	 *
	 * @param string $rKey   A key of INTS.
	 * @param mixed  $rValue The stored value; null when unset.
	 * @return int The value to use.
	 */
	public static function int(string $rKey, mixed $rValue): int {
		[$rDefault] = self::INTS[$rKey];
		if (!is_numeric($rValue) || ((int) $rValue === 0 && in_array($rKey, self::ZERO_IS_UNSET, true))) {
			return $rDefault;
		}
		return self::clampInt($rKey, (int) $rValue);
	}

	/**
	 * An enum setting as its readers take it, from the value as stored: the
	 * value when it is one of the allowed ones exactly (normalize() stores
	 * them trimmed and lower-case), else its default, unset included. Every
	 * reader goes through here, so a default lives in ENUMS alone.
	 *
	 * @param string $rKey   A key of ENUMS.
	 * @param mixed  $rValue The stored value; null when unset.
	 * @return string The value to use.
	 */
	public static function enum(string $rKey, mixed $rValue): string {
		[$rDefault, $rAllowed] = self::ENUMS[$rKey];
		return is_scalar($rValue) && in_array((string) $rValue, $rAllowed, true) ? (string) $rValue : $rDefault;
	}

	public static function clampInt(string $rKey, int $rValue): int {
		[, $rMin, $rMax] = self::INTS[$rKey];
		return max($rMin, min($rMax, $rValue));
	}
}
