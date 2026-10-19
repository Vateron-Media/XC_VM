<?php

namespace XcVm\Domain\Cluster;

/**
 * MAIN's one packer of a load balancer's config.enc (xcvm_core's ADR-005,
 * Decision 9): every `config_pack` goes through pack() — the SSH install
 * (LbInstallFlow), API mode (CredentialFreeConfig's params) and a
 * `node.root install_config` (DbCredentials).
 *
 * - The node is a v2 target (XcvtTargets::ready): an XCVT v2 blob, signed
 *   with MAIN's cluster root and sealed to the node's panel key, for the
 *   install_id recorded with that key.
 * - Otherwise, in release N, the v1 blob, with a `node.config_v1` warning in
 *   the cluster audit (why: XcvtTargets::REASONS). Not for a node MAIN once
 *   pinned with v2 and has not probed since: a pinned node refuses v1, so
 *   it gets nothing until it is probed again (`node.config_refused`).
 *
 * Release N+1 sets V1_ALLOWED false (v1Allowed()): no v1 blob from MAIN at
 * all, and a node whose v2 pin fails at install fails the install
 * (LbInstallFlow).
 *
 * @package XC_VM_Domain_Cluster
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class NodeConfigPack {
	/** Release N: v1 for a node that is not a v2 target. N+1: false (gated on server:xcvt-report). */
	public const V1_ALLOWED = true;

	private static ?bool $rV1Allowed = null;

	/** Does MAIN still emit v1 (V1_ALLOWED)? */
	public static function v1Allowed(): bool {
		return self::$rV1Allowed ?? self::V1_ALLOWED;
	}

	/** Tests: release N+1's behaviour (false) or N's (true); null restores V1_ALLOWED. */
	public static function useV1Allowed(?bool $rAllowed): void {
		self::$rV1Allowed = $rAllowed;
	}

	/** @var (\Closure(string, array<string, mixed>, ?string): (string|false))|null */
	private static ?\Closure $rPack = null;

	/** Tests: pack through $rPack (install_id, params, panel key or null for v1); null restores `\XC_VM::config_pack`. */
	public static function usePack(?\Closure $rPack): void {
		self::$rPack = $rPack;
	}

	/**
	 * The XCVT blob of $rServerID's config, or false (nothing to pack for, the
	 * node needs a probe, or the extension refused).
	 *
	 * @param array<string, mixed> $rParams config_pack's server params
	 * @param (callable(string, array<string, mixed>, ?string): (string|false))|null $rPack tests: `config_pack`
	 */
	public static function pack(int $rServerID, array $rParams, ?callable $rPack = null): string|false {
		$rPack ??= self::$rPack ?? static fn(string $rTarget, array $rP, ?string $rKey): string|false => $rKey === null ? @\XC_VM::config_pack($rTarget, $rP) : @\XC_VM::config_pack($rTarget, $rP, $rKey);
		$rRec = XcvtTargets::get($rServerID);
		$rWhy = XcvtTargets::reason($rServerID);
		if ($rWhy === null && $rRec !== null) {
			$rBlob = $rPack((string) $rRec['install_id'], $rParams, (string) $rRec['panel_pk']);
			return is_string($rBlob) && $rBlob !== '' ? $rBlob : false;
		}
		if (!self::v1Allowed() || ($rRec !== null && $rRec['v2'])) {
			ClusterAudit::log('node.config_refused', $rServerID, ['why' => $rWhy, 'v1_allowed' => self::v1Allowed()], 'system');
			return false;
		}
		$rID = $rRec['install_id'] ?? CorePins::installId($rServerID);
		if ($rID === null) {
			return false;
		}
		if (self::pinnedBeforeV2($rServerID) && ($rRec['probe'] ?? null) !== 'ext_old') {
			// Pinned before MAIN knew its key (pin_core, an earlier install): a
			// release-N extension with a pin refuses v1, and a credential-bearing
			// v1 blob in the queue is readable. Nothing sent; it is asked for its
			// key now, which makes it a v2 target.
			ClusterAudit::log('node.config_refused', $rServerID, ['why' => 'pinned_v1', 'v1_allowed' => true, 'probed' => CorePins::askKey($rServerID)], 'system');
			return false;
		}
		ClusterAudit::log('node.config_v1', $rServerID, ['why' => $rWhy], 'system');
		$rBlob = $rPack($rID, $rParams, null);
		return is_string($rBlob) && $rBlob !== '' ? $rBlob : false;
	}

	/** Did root report the node pinned (CorePins: a pin recorded, any key)? */
	private static function pinnedBeforeV2(int $rServerID): bool {
		try {
			$rState = CorePins::state($rServerID);
		} catch (\Throwable) {
			return false; // no cluster_meta: no pin was ever recorded
		}
		return $rState !== null && $rState['pinned_at'] !== null && $rState['fp'] !== '';
	}
}
