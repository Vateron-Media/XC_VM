<?php

namespace XcVm\Core\Cluster;

/**
 * The node's own half of taking MAIN's credentials off a load balancer (plan,
 * section 10, step 3): the two root actions MAIN sends as signed `node.root`
 * commands, run by RootSignalsCronJob as root on the node.
 *
 * - `strip_db_credentials` rewrites the node's config.enc without MAIN's DB
 *   user and password and its Redis password (`\XC_VM::strip_db_credentials`).
 *   Only on a node in mode 2 (NodeRole::refusesConnects), asked when root
 *   runs it: MAIN may have moved the node down since it queued the command.
 * - `install_config` installs a config MAIN packed for this node
 *   (`\XC_VM::install_config`), credential-free for a node in API mode or
 *   with credentials for a rollback; the extension verifies the blob before it
 *   writes anything.
 *
 * Both live in `xcvm_core` (ADR-002 of the extension, "Credential-free
 * nodes"): the config is encrypted to the machine, and PHP never sees what is
 * in it. An extension without the method refuses the action, with a reason the
 * command's ack carries back to MAIN, rather than pretending it ran. The
 * result is one JSON line, `{"config": {server_id, is_lb, db_credentials,
 * redis_auth, changed}}`, which MAIN reads before it revokes the node's grant
 * (Domain\Cluster\DbCredentials).
 *
 * @package XC_VM_Core_Cluster
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class NodeCredentials {
	public const STRIP = 'strip_db_credentials';

	public const INSTALL = 'install_config';

	/** Largest blob accepted (config.enc is at most 1 MiB; a node's is ~300 bytes). */
	public const MAX_BLOB = 65536;

	/** @var \Closure(string, mixed...): mixed|null Tests: the extension. */
	private static ?\Closure $rExt = null;

	/** Tests: reach the extension through $rCall; null restores `\XC_VM`. */
	public static function useExtension(?\Closure $rCall): void {
		self::$rExt = $rCall;
	}

	/**
	 * Run one of the two actions. Returns the result line; throws with the
	 * reason when the extension lacks the method or refuses, and for a strip
	 * on a node that is not in mode 2.
	 *
	 * @param array<string, mixed> $rData {action, blob?}
	 */
	public static function run(array $rData): string {
		$rAction = $rData['action'] ?? null;
		if ($rAction === self::STRIP) {
			// MAIN judged the node as it queued this; root may run it later. Only
			// mode 2 runs without these credentials, as the agent's file says now.
			if (!NodeRole::refusesConnects()) {
				throw new \RuntimeException(self::STRIP . ': refused: this node is not in mode 2, and below it needs MAIN\'s credentials');
			}
			$rOut = self::call('strip_db_credentials');
		} elseif ($rAction === self::INSTALL) {
			$rBlob = is_string($rData['blob'] ?? null) && strlen($rData['blob']) <= self::MAX_BLOB * 2 ? base64_decode($rData['blob'], true) : false;
			if ($rBlob === false || $rBlob === '' || strlen($rBlob) > self::MAX_BLOB) {
				throw new \RuntimeException(self::INSTALL . ': refused: no config blob in the command');
			}
			$rOut = self::call('install_config', $rBlob);
		} else {
			throw new \InvalidArgumentException('Not a credential action: ' . (is_string($rAction) ? $rAction : 'none'));
		}
		return (string) json_encode(['config' => $rOut]);
	}

	/**
	 * @return array{server_id: int, is_lb: int, db_credentials: bool, redis_auth: bool, changed: bool}
	 */
	private static function call(string $rMethod, mixed ...$rArgs): array {
		$rCall = self::$rExt;
		if ($rCall === null) {
			if (!class_exists('XC_VM') || !method_exists('XC_VM', $rMethod)) {
				throw new \RuntimeException($rMethod . ': refused: this node\'s xcvm_core has no ' . $rMethod . '() (update xcvm_core first)');
			}
			$rCall = static fn(string $rM, mixed ...$rA): mixed => \XC_VM::$rM(...$rA);
		}
		$rOut = $rCall($rMethod, ...$rArgs);
		if (!is_array($rOut)) {
			$rWhy = $rCall('cluster_last_error');
			throw new \RuntimeException($rMethod . ': refused by xcvm_core: ' . (is_string($rWhy) && $rWhy !== '' ? $rWhy : 'unknown'));
		}
		return [
			'server_id' => (int) ($rOut['server_id'] ?? 0),
			'is_lb' => (int) ($rOut['is_lb'] ?? 0),
			'db_credentials' => (bool) ($rOut['db_credentials'] ?? true),
			'redis_auth' => (bool) ($rOut['redis_auth'] ?? true),
			'changed' => (bool) ($rOut['changed'] ?? false),
		];
	}

	/**
	 * What a command's result says about the node's config afterwards: the
	 * `config` object of its last JSON line, or null when there is none (root
	 * had not finished, `{"queued": true}`, or an older node).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function outcome(string $rResult): ?array {
		$rLines = preg_split('/\R/', trim($rResult)) ?: [];
		$rDoc = json_decode((string) end($rLines), true);
		return is_array($rDoc) && is_array($rDoc['config'] ?? null) ? $rDoc['config'] : null;
	}
}
