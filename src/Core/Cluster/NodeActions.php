<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Config\OpensslExtra;
use XcVm\Domain\Cluster\ClusterRoute;

/**
 * Node Actions
 *
 * Privileged work MAIN asks a node's root side to do: reboot, restart or
 * update the services, apply ports, sysctl or governor settings, renew
 * certificates, install or remove modules, flush the IP blocklist. The
 * node's root cron (RootSignalsCronJob) executes them.
 *
 * Callers queued the raw JSON through SignalDispatcher::rootAction() (a
 * `signals` row), which is still the only transport. In API mode (Phase 4)
 * each becomes a signed `node.root{action}` command for `cluster:root`.
 * ROOT_ACTIONS is the catalogue RootSignalsCronJob handles, and a payload
 * outside it is refused rather than queued to be ignored.
 */
final class NodeActions {
	/** Every action RootSignalsCronJob executes. */
	public const ROOT_ACTIONS = [
		'reboot', 'restart_services', 'stop_services', 'reload_nginx',
		'disable_ramdisk', 'enable_ramdisk', 'certbot_generate', 'update_binaries',
		'install_module', 'delete_module', 'update', 'rollback',
		'set_services', 'set_governor', 'set_sysctl', 'set_port', 'flush',
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
	 * row. `rotate_db` carries a sealed password nothing else could carry;
	 * `rotate_sign_key` is only worth the signature root checks it under;
	 * the credential strip and install carry sealed configs.
	 */
	public const CLUSTER_ONLY = ['rotate_db', 'rotate_sign_key', NodeCredentials::STRIP, NodeCredentials::INSTALL, NodeCorePin::ACTION];

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

	public static function setSysctl(int $rServerID, mixed $rSysCtl, ?object $rDb = null): bool {
		return self::send($rServerID, ['action' => 'set_sysctl', 'data' => $rSysCtl], $rDb);
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
		// A node with the COMMANDS flow and its root pin gets a signed node.root (MAIN only).
		if (class_exists(ClusterRoute::class)) {
			[$rRouted, $rQueued] = ClusterRoute::root($rServerID, $rData);
			if ($rRouted) {
				return $rQueued;
			}
		}
		if (in_array($rAction, self::CLUSTER_ONLY, true)) {
			return false;
		}
		return SignalDispatcher::rootAction($rServerID, $rPayload, $rDb);
	}
}
