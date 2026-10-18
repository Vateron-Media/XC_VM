<?php

namespace XcVm\Core\Cluster;

/**
 * The credential-free node config (plan, section 10, step 3; xcvm_core's
 * ADR-002, "Credential-free nodes"): what a load balancer installed in API
 * mode (`lb_new_node_mode = api`, mode 2) gets as its config.enc — MAIN's DB
 * host, port and name, and none of its DB user, DB password or Redis password.
 *
 * xcvm_core packs it with `config_pack($install_id, $params + ['db_credentials'
 * => false])`. An older extension does not know the key, and must never be
 * asked: it would pack MAIN's credentials as before. The release that knows it
 * is the one that also has `install_config()` and `strip_db_credentials()`
 * (found by method_exists, no API version bump), so supported() asks for
 * that, and a panel without it refuses API mode rather than installing a node
 * with MAIN's credentials in it.
 */
final class CredentialFreeConfig {
	/** The method whose presence says config_pack understands `db_credentials`. */
	public const MARKER = 'install_config';

	/** @var (callable(): bool)|null */
	private static $rSupported = null;

	/**
	 * Tests: another feature check; null restores the extension.
	 *
	 * @param (callable(): bool)|null $rSupported
	 */
	public static function useSeams(?callable $rSupported): void {
		self::$rSupported = $rSupported;
	}

	/** Does this panel's xcvm_core pack a credential-free config? */
	public static function supported(): bool {
		if (self::$rSupported !== null) {
			return (self::$rSupported)();
		}
		return class_exists('XC_VM', false) && method_exists('XC_VM', self::MARKER) && method_exists('XC_VM', 'config_pack');
	}

	/**
	 * config_pack's server params without credentials, for MAIN's one packer
	 * (Domain\Cluster\NodeConfigPack, the extension's ADR-005); null when this
	 * extension cannot pack a credential-free config at all.
	 *
	 * @param array<string, mixed> $rParams config_pack's server params
	 * @return array<string, mixed>|null
	 */
	public static function params(array $rParams): ?array {
		return self::supported() ? ['db_credentials' => false] + array_diff_key($rParams, ['include_redis' => true]) : null;
	}
}
