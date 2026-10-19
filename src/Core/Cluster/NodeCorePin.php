<?php

namespace XcVm\Core\Cluster;

/**
 * The node's pin of MAIN's panel key in `xcvm_core` (`config/cluster/core.pin`,
 * the extension's ADR-002 "Pin"): what the extension checks a lease against,
 * so its compiled lease verdict — and `license_valid()` on a load balancer —
 * can engage at all.
 *
 * MAIN delivers the pin as `node.root pin_core`, in two steps, run by root
 * (RootSignalsCronJob, through cluster:root):
 *
 * 1. Without a `blob`: report what MAIN needs to pack a pin for this node —
 *    its `install_id` (the blob is encrypted to it) — and what is pinned now
 *    (the SHA-256 of the pinned panel signing key, or null).
 * 2. With a `blob` (base64 of `cluster_pack($install_id)`, an XCVT blob at most
 *    an hour old): pin it (`cluster_pin`). The key it pins must be the one
 *    root's own pin trusts (RootPin, `/etc/xc_vm/cluster/`), which is also the
 *    key that signed this command. A pin of another panel (a MAIN replaced
 *    since) is replaced; a blob for any key but root's is refused, and a pin
 *    it left behind is removed, so the node is never left trusting a key
 *    root does not.
 *
 * The SSH install pins over its own verified session instead
 * (LbInstallFlow::provisionConfig, `server:xcvt-pin`). A node whose extension
 * has no cluster API refuses with a reason the ack carries back. Output: one
 * JSON line, `{"core": {install_id?, pinned, verdict, panel_pk?}}` —
 * `verdict` says whether the extension offers the compiled lease verdict
 * (`cluster_lease_state`); step 1's `panel_pk` is the node's XCVT v2 key (the
 * extension's ADR-005): null without a panel.sk, absent from an extension
 * before it. A blob may be an XCVT v1 or v2 pin.
 *
 * @package XC_VM_Core_Cluster
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class NodeCorePin {
	public const ACTION = 'pin_core';

	/** The extension's pin, under the config directory (the agent's `cluster/`). */
	public const FILE = AgentPaths::DIR . 'core.pin';

	/** Largest blob accepted (a pin blob is ~330 bytes). */
	public const MAX_BLOB = 4096;

	/** @var \Closure(string, mixed...): mixed|null Tests: the extension. */
	private static ?\Closure $rExt = null;

	private static ?string $rConfigDir = null;

	/**
	 * Tests: reach the extension through $rCall and use $rConfigDir as the
	 * config directory; null restores `\XC_VM` and CONFIG_PATH.
	 */
	public static function useExtension(?\Closure $rCall, ?string $rConfigDir = null): void {
		self::$rExt = $rCall;
		self::$rConfigDir = $rConfigDir;
	}

	/**
	 * Run one step. Returns the result line; throws with the reason when the
	 * extension lacks the cluster API, the node is not in a state to pin, or
	 * the extension refuses.
	 *
	 * @param array<string, mixed> $rData {action, blob?}
	 * @param array{pub: string, node: string}|null $rRootPin RootPin::read()
	 */
	public static function run(array $rData, ?array $rRootPin): string {
		$rCall = self::extension();
		if (!array_key_exists('blob', $rData)) {
			return (string) json_encode(['core' => self::report($rCall)]);
		}
		if ($rRootPin === null) {
			throw new \RuntimeException(self::ACTION . ': refused: root\'s pin of the panel key is not in place');
		}
		$rBlob = is_string($rData['blob']) && strlen($rData['blob']) <= self::MAX_BLOB * 2 ? base64_decode($rData['blob'], true) : false;
		if ($rBlob === false || strlen($rBlob) < 70 || strlen($rBlob) > self::MAX_BLOB) {
			throw new \RuntimeException(self::ACTION . ': refused: no pin blob in the command');
		}
		$rNow = self::pinnedKey($rCall);
		// Replace only a pin of another panel than root's, or one that no longer
		// opens: a pin of root's panel is kept against a blob for any other key.
		$rReplace = $rNow !== null ? !hash_equals($rRootPin['pub'], $rNow) : self::pinFileExists();
		$rOut = $rCall('cluster_pin', $rBlob, $rReplace);
		if (!is_array($rOut) || !is_string($rOut['panel_sign_pub'] ?? null)) {
			throw new \RuntimeException(self::ACTION . ': refused by xcvm_core: ' . self::lastError($rCall));
		}
		if (!hash_equals($rRootPin['pub'], $rOut['panel_sign_pub'])) {
			// Pinned, but not to the key root trusts: never leave that in place.
			@unlink(self::configDir() . self::FILE);
			throw new \RuntimeException(self::ACTION . ': refused: the blob pins another panel key than root\'s; nothing pinned');
		}
		return (string) json_encode(['core' => ['pinned' => hash('sha256', $rOut['panel_sign_pub']), 'verdict' => self::hasVerdict($rCall)]]);
	}

	/**
	 * What a command's result says: the `core` object of its last JSON line,
	 * or null (root had not finished, `{"queued": true}`, an older node).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function outcome(string $rResult): ?array {
		$rLines = preg_split('/\R/', trim($rResult)) ?: [];
		$rDoc = json_decode((string) end($rLines), true);
		return is_array($rDoc) && is_array($rDoc['core'] ?? null) ? $rDoc['core'] : null;
	}

	/**
	 * Step 1's answer.
	 *
	 * @return array{install_id: string, pinned: string|null, verdict: bool, panel_pk?: string|null}
	 */
	private static function report(\Closure $rCall): array {
		// install_id() creates the file when it is missing, and a root-owned one
		// would lock the panel's user out of config.enc: read only what exists.
		if (!is_file(self::configDir() . 'install_id')) {
			throw new \RuntimeException(self::ACTION . ': refused: this node has no install_id');
		}
		$rID = $rCall('install_id');
		if (!is_string($rID) || !preg_match('/^[0-9A-Za-z-]{8,64}\z/', $rID)) {
			throw new \RuntimeException(self::ACTION . ': refused: xcvm_core gave no install_id');
		}
		$rKey = self::pinnedKey($rCall);
		$rOut = ['install_id' => $rID, 'pinned' => $rKey === null ? null : hash('sha256', $rKey), 'verdict' => self::hasVerdict($rCall)];
		if (self::has($rCall, 'panel_pk')) {
			// The key MAIN seals an XCVT v2 config to (the extension's ADR-005).
			// Read only from a panel.sk that exists: a probe creates nothing. No
			// panel_pk at all says this extension predates it.
			$rPk = is_file(self::configDir() . 'panel.sk') ? $rCall('panel_pk') : null;
			$rOut['panel_pk'] = is_string($rPk) && preg_match('/^[0-9a-f]{64}\z/', $rPk) === 1 ? $rPk : null;
		}
		return $rOut;
	}

	/** The pinned panel signing key, or null (none, or one that does not open). */
	private static function pinnedKey(\Closure $rCall): ?string {
		$rPinned = $rCall('cluster_pinned');
		return is_array($rPinned) && is_string($rPinned['panel_sign_pub'] ?? null) && strlen($rPinned['panel_sign_pub']) === 32 ? $rPinned['panel_sign_pub'] : null;
	}

	private static function pinFileExists(): bool {
		return is_file(self::configDir() . self::FILE);
	}

	private static function hasVerdict(\Closure $rCall): bool {
		return self::has($rCall, 'cluster_lease_state');
	}

	private static function has(\Closure $rCall, string $rMethod): bool {
		return self::$rExt !== null ? (bool) $rCall('has', $rMethod) : method_exists('XC_VM', $rMethod);
	}

	private static function lastError(\Closure $rCall): string {
		$rWhy = $rCall('cluster_last_error');
		return is_string($rWhy) && $rWhy !== '' ? $rWhy : 'unknown';
	}

	private static function configDir(): string {
		return self::$rConfigDir ?? AgentPaths::configDir();
	}

	/** @return \Closure(string, mixed...): mixed */
	private static function extension(): \Closure {
		if (self::$rExt !== null) {
			return self::$rExt;
		}
		foreach (['install_id', 'cluster_pin', 'cluster_pinned'] as $rMethod) {
			if (!class_exists('XC_VM') || !method_exists('XC_VM', $rMethod)) {
				throw new \RuntimeException(self::ACTION . ': refused: this node\'s xcvm_core has no ' . $rMethod . '() (update xcvm_core first)');
			}
		}
		return static fn(string $rMethod, mixed ...$rArgs): mixed => \XC_VM::$rMethod(...$rArgs);
	}
}
