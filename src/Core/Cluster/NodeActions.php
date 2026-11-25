<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Config\OpensslExtra;
use XcVm\Core\Updates\GitHubReleases;
use XcVm\Domain\Cluster\ClusterRoute;

/**
 * Node Actions
 *
 * Privileged work MAIN asks a node's root side to do: reboot, restart or
 * update the services, apply ports, sysctl or governor settings, renew
 * certificates, install or remove modules, flush the IP blocklist. The
 * node's root cron (RootSignalsCronJob) executes them.
 *
 * Every action is a signed, node-bound `node.root` command for `cluster:root`.
 * Nodes without COMMANDS and root_ready receive no privileged fallback.
 */
final class NodeActions {
	/** Every action RootSignalsCronJob executes. */
	public const ROOT_ACTIONS = [
		'reboot', 'restart_services', 'stop_services', 'reload_nginx',
		'disable_ramdisk', 'enable_ramdisk', 'certbot_generate', 'update_binaries',
		'install_module', 'delete_module', 'update', 'rollback',
		'set_services', 'set_governor', 'set_port', 'flush',
		OpensslExtra::SIGNAL_ACTION, 'rotate_redis', 'rotate_db', 'rotate_sign_key',
		NodeCredentials::STRIP, NodeCredentials::INSTALL, NodeCorePin::ACTION,
		self::SEED_CONNECTIONS,
	];

	/**
	 * Load the node's viewers from MAIN's store into its agent
	 * (`cluster:seed-connections` on the node), the guided cutover's step
	 * before CONNECTIONS. The node's `CLUSTER` system log line starts with
	 * SEEDED or NOT_SEEDED.
	 */
	public const SEED_CONNECTIONS = 'seed_connections';

	public const SEEDED = 'Connections seeded: ';

	public const NOT_SEEDED = 'Connections not seeded: ';

	/**
	 * Actions that run only over the cluster API: never queued as a `signals`
	 * row. Credential rotation carries sealed passwords nothing else could carry;
	 * `rotate_sign_key` is only worth the signature root checks it under;
	 * the credential strip and install carry sealed configs.
	 */
	public const CLUSTER_ONLY = ['rotate_redis', 'rotate_db', 'rotate_sign_key', NodeCredentials::STRIP, NodeCredentials::INSTALL, NodeCorePin::ACTION];

	public static function reboot(int $rServerID, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => 'reboot'], $rDb);
	}

	public static function restartServices(int $rServerID, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => 'restart_services'], $rDb);
	}

	public static function reloadNginx(int $rServerID, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => 'reload_nginx'], $rDb);
	}

	/**
	 * Update a node to this MAIN's release: it names its version, which the
	 * node installs exactly (UpdateCommand), so the fleet runs MAIN's release
	 * rather than whatever is newest on GitHub. An older node ignores it.
	 */
	public static function update(int $rServerID, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => 'update', 'version' => XC_VM_VERSION], $rDb);
	}

	public static function rollback(int $rServerID, string $rVersion, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => 'rollback', 'version' => $rVersion], $rDb);
	}

	public static function seedConnections(int $rServerID, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => self::SEED_CONNECTIONS], $rDb);
	}

	public static function updateBinaries(int $rServerID, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => 'update_binaries'], $rDb);
	}

	/**
	 * Take MAIN's DB and Redis credentials off the node's config.enc (plan,
	 * section 10, step 3: `node.root strip_db_credentials`). Signed root
	 * command only; the caller checks the node is ready for it (mode 2).
	 */
	public static function stripDbCredentials(int $rServerID, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => NodeCredentials::STRIP], $rDb);
	}

	/**
	 * Install a config MAIN packed for this node (`config_pack`, credential-free
	 * for mode 2, with credentials for a rollback): `node.root install_config`,
	 * the XCVT blob in the payload as base64. Signed root command only.
	 */
	public static function installConfig(int $rServerID, string $rBlob, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => NodeCredentials::INSTALL, 'blob' => base64_encode($rBlob)], $rDb);
	}

	/**
	 * Pin MAIN's panel key in the node's xcvm_core (`node.root pin_core`,
	 * NodeCorePin): without a blob the node reports its install_id, with one
	 * (base64 of MAIN's `cluster_pack` for that install_id) it pins it.
	 * Signed root command only.
	 */
	public static function pinCore(int $rServerID, ?string $rBlob = null, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => NodeCorePin::ACTION] + ($rBlob === null ? [] : ['blob' => base64_encode($rBlob)]), $rDb);
	}

	public static function setRamdisk(int $rServerID, bool $rEnabled, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => $rEnabled ? 'enable_ramdisk' : 'disable_ramdisk'], $rDb);
	}

	/** @param mixed $rPorts As ServerService builds it for the port type. */
	public static function setPorts(int $rServerID, int $rType, mixed $rPorts, mixed $rReload, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => 'set_port', 'type' => $rType, 'ports' => $rPorts, 'reload' => $rReload], $rDb);
	}

	public static function setServices(int $rServerID, int $rCount, mixed $rReload, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => 'set_services', 'count' => $rCount, 'reload' => $rReload], $rDb);
	}

	public static function setGovernor(int $rServerID, mixed $rGovernor, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => 'set_governor', 'data' => $rGovernor], $rDb);
	}

	/** Clear the node's firewall blocklist; RootSignalsCronJob matches this exact payload. */
	public static function flushBlocklist(int $rServerID, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => 'flush'], $rDb);
	}

	/** @param list<string> $rDomains */
	public static function certbot(int $rServerID, array $rDomains, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => 'certbot_generate', 'domain' => array_values($rDomains)], $rDb);
	}

	/**
	 * Queue any catalogued action, for callers that build the payload
	 * themselves (module install/delete, the OPENSSL_EXTRA sync).
	 *
	 * @param array<string, mixed>|string $rPayload Array or its JSON.
	 */
	public static function send(int $rServerID, array|string $rPayload, ?object $rDb = null): bool {
		$rData = is_string($rPayload) ? json_decode($rPayload, true) : $rPayload;
		$rAction = is_array($rData) ? ($rData['action'] ?? null) : null;
		if (!is_string($rAction) || !in_array($rAction, self::ROOT_ACTIONS, true)) {
			throw new \InvalidArgumentException('Unknown node root action: ' . (is_string($rAction) ? $rAction : 'none'));
		}
		if (!self::valid($rData)) {
			throw new \InvalidArgumentException('Invalid node root action payload: ' . $rAction);
		}
		if (class_exists(ClusterRoute::class)) {
			[$rRouted, $rQueued] = ClusterRoute::root($rServerID, $rData);
			if ($rRouted) {
				return $rQueued;
			}
		}
		return false;
	}

	/** Strict shape checked before signing and again by root after verification. */
	public static function valid(array $rData): bool {
		$rAction = $rData['action'] ?? null;
		if (!is_string($rAction) || !in_array($rAction, self::ROOT_ACTIONS, true)) {
			return false;
		}
		$rOnly = static fn(array $rKeys): bool => array_diff(array_keys($rData), array_merge(['action'], $rKeys)) === [];
		$rString = static fn(string $rKey, int $rMax = 4096): bool => is_string($rData[$rKey] ?? null) && $rData[$rKey] !== '' && strlen($rData[$rKey]) <= $rMax;
		$rB64 = static fn(string $rKey, int $rMax): bool => $rString($rKey, $rMax * 2) && ($rDecoded = base64_decode($rData[$rKey], true)) !== false && $rDecoded !== '' && strlen($rDecoded) <= $rMax;
		$rVersion = static fn(string $rKey): bool => is_string($rData[$rKey] ?? null)
			&& (GitHubReleases::isReleaseVersion($rData[$rKey]) || GitHubReleases::isDevVersion($rData[$rKey]));
		$rName = static fn(string $rKey): bool => is_string($rData[$rKey] ?? null) && preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $rData[$rKey]) === 1;

		if (in_array($rAction, ['reboot', 'restart_services', 'stop_services', 'reload_nginx', 'disable_ramdisk', 'enable_ramdisk', 'update_binaries', 'flush', NodeCredentials::STRIP, self::SEED_CONNECTIONS], true)) {
			return $rOnly([]);
		}
		return match ($rAction) {
			'update', 'rollback' => $rOnly(['version']) && $rVersion('version'),
			'certbot_generate' => $rOnly(['domain']) && self::domains($rData['domain'] ?? null),
			'install_module' => $rOnly(['source', 'name', 'version', 'store_id', 'artefact'])
				&& in_array($rData['source'] ?? null, ['local', 'platform'], true) && $rName('name')
				&& is_string($rData['version'] ?? null) && preg_match('/^[0-9A-Za-z._+-]{1,64}$/', $rData['version']) === 1
				&& (!isset($rData['store_id']) || $rName('store_id')) && (!isset($rData['artefact']) || is_array($rData['artefact'])),
			'delete_module' => $rOnly(['name']) && $rName('name'),
			'set_services' => $rOnly(['count', 'reload']) && is_int($rData['count'] ?? null) && $rData['count'] >= 1 && $rData['count'] <= 1024 && is_bool($rData['reload'] ?? null),
			'set_governor' => $rOnly(['data']) && is_string($rData['data'] ?? null) && preg_match('/^[a-z_]{1,32}$/', $rData['data']) === 1,
			'set_port' => $rOnly(['type', 'ports', 'reload', 'backend']) && self::ports($rData) && (!isset($rData['backend']) || is_bool($rData['backend'])),
			OpensslExtra::SIGNAL_ACTION => $rOnly(['value']) && $rString('value'),
			'rotate_redis' => $rOnly(['host', 'port', 'auth_sealed']) && self::host($rData['host'] ?? null)
				&& is_int($rData['port'] ?? null) && $rData['port'] >= 1 && $rData['port'] <= 65535 && $rB64('auth_sealed', 65536),
			'rotate_db' => $rOnly(['auth_sealed']) && $rB64('auth_sealed', 65536),
			'rotate_sign_key' => $rOnly(['new_pub', 'pin_blob']) && is_string($rData['new_pub'] ?? null)
				&& preg_match('/^[0-9a-f]{64}$/', $rData['new_pub']) === 1 && (!isset($rData['pin_blob']) || $rB64('pin_blob', 65536)),
			NodeCredentials::INSTALL => $rOnly(['blob']) && $rB64('blob', NodeCredentials::MAX_BLOB),
			NodeCorePin::ACTION => $rOnly(['blob']) && (!isset($rData['blob']) || $rB64('blob', NodeCorePin::MAX_BLOB)),
		};
	}

	private static function domains(mixed $rDomains): bool {
		if (!is_array($rDomains) || !array_is_list($rDomains) || $rDomains === [] || count($rDomains) > 100) {
			return false;
		}
		foreach ($rDomains as $rDomain) {
			if (!is_string($rDomain) || !preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/', $rDomain)) {
				return false;
			}
		}
		return true;
	}

	/** @param array<string, mixed> $rData */
	private static function ports(array $rData): bool {
		if (!is_int($rData['type'] ?? null) || $rData['type'] < 0 || $rData['type'] > 2 || !is_bool($rData['reload'] ?? null)
			|| !is_array($rData['ports'] ?? null) || !array_is_list($rData['ports']) || $rData['ports'] === []
		) {
			return false;
		}
		foreach ($rData['ports'] as $rPort) {
			if (!is_int($rPort) || $rPort < ($rData['type'] === 2 ? 1 : 80) || $rPort > 65535) {
				return false;
			}
		}
		return true;
	}

	private static function host(mixed $rHost): bool {
		return is_string($rHost) && (filter_var($rHost, FILTER_VALIDATE_IP) !== false
			|| preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/', $rHost) === 1);
	}
}
